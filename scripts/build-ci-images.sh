#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REGISTRY="${CI_IMAGE_REGISTRY:-forgejo.argentwolf.org}"
OWNER_PATH="${CI_IMAGE_OWNER_PATH:-alan/maintenance_tracker_for_nextcloud}"
PUSH=0

# Reviewed upstream multi-platform identities. Rebuild/review this script when
# advancing any base/toolchain version.
PHP82_BASE='php:8.2.33-cli-bookworm@sha256:6ca4b01d84082465358c5d541a2bef0edd9d0be494802aeb40f2d7c7a8d73adb'
PHP85_BASE='php:8.5.5-cli-bookworm@sha256:36664e49e4bdc669d9e2cba2e708c288b7fafade442dd1cd3c30d6cb97370b82'
NODE_BASE='node:24.20.0-bookworm@sha256:be23f54a88d34e8824c741b19b91064094f92c1c97b194144bfc8b50d67258e2'
COMPOSER_BASE='composer:2.10.3@sha256:4d045ea9f71d5d111a95e608400da61d187e487adf9eaf2dfe068998a8d4f584'

usage() {
    cat <<'USAGE'
Usage: scripts/build-ci-images.sh [--push]

Builds and qualifies the Maintenance Tracker CI images. With --push, publishes
qualified tags to the configured Forgejo registry and prints their RepoDigests.
After publication, record those exact identities in
ci/images/qualified-images.json before making routine CI consume them.

Environment overrides:
  CI_IMAGE_REGISTRY    default: forgejo.argentwolf.org
  CI_IMAGE_OWNER_PATH  default: alan/maintenance_tracker_for_nextcloud
USAGE
}

case "${1:-}" in
    '') ;;
    --push) PUSH=1 ;;
    -h|--help)
        usage
        exit 0
        ;;
    *)
        usage >&2
        exit 2
        ;;
esac
[[ $# -le 1 ]] || {
    usage >&2
    exit 2
}

command -v docker >/dev/null 2>&1 || {
    echo 'ERROR: docker is required.' >&2
    exit 1
}
[[ -d "$ROOT/.git" ]] || {
    echo "ERROR: not a Git checkout: $ROOT" >&2
    exit 1
}
[[ -z "$(git -C "$ROOT" status --porcelain=v1 --untracked-files=all)" ]] || {
    echo 'ERROR: CI images must be built from a clean reviewed tree.' >&2
    git -C "$ROOT" status --short >&2
    exit 1
}

revision="$(git -C "$ROOT" rev-parse HEAD)"
php82_image="${REGISTRY}/${OWNER_PATH}/ci-php:8.2-v1"
php85_image="${REGISTRY}/${OWNER_PATH}/ci-php:8.5-v1"
nextcloud_image="${REGISTRY}/${OWNER_PATH}/ci-nextcloud:v1"

build_php() {
    local series="$1"
    local base="$2"
    local image="$3"

    docker build \
        --file "$ROOT/ci/images/php/Dockerfile" \
        --build-arg "PHP_BASE=$base" \
        --build-arg "NODE_BASE=$NODE_BASE" \
        --build-arg "COMPOSER_BASE=$COMPOSER_BASE" \
        --build-arg "PHP_SERIES=$series" \
        --label "org.opencontainers.image.revision=$revision" \
        --tag "$image" \
        "$ROOT/ci/images"

    docker run --rm "$image" bash -ceu '
        expected="$1"
        actual="$(php -r "echo PHP_MAJOR_VERSION . chr(46) . PHP_MINOR_VERSION;")"
        test "$actual" = "$expected"
        test "$(node --version | cut -d. -f1)" = v24
        composer --version
        git --version
        php -r '\''foreach (["dom", "libxml", "mbstring", "xml", "xmlwriter"] as $ext) { if (!extension_loaded($ext)) { fwrite(STDERR, "Missing PHP extension: {$ext}\\n"); exit(1); } }'\''
    ' -- "$series"
}

build_php '8.2' "$PHP82_BASE" "$php82_image"
build_php '8.5' "$PHP85_BASE" "$php85_image"

docker build \
    --file "$ROOT/ci/images/nextcloud/Dockerfile" \
    --build-arg "NODE_BASE=$NODE_BASE" \
    --label "org.opencontainers.image.revision=$revision" \
    --tag "$nextcloud_image" \
    "$ROOT/ci/images"

docker run --rm "$nextcloud_image" bash -ceu '
    test "$(node --version | cut -d. -f1)" = v24
    docker --version
    git --version
    test -r /etc/ssl/certs/ca-certificates.crt
'

echo 'CI image local qualification: PASS'
printf '  %s\n  %s\n  %s\n' "$php82_image" "$php85_image" "$nextcloud_image"

if [[ "$PUSH" -eq 0 ]]; then
    echo 'No registry publication requested.'
    exit 0
fi

for image in "$php82_image" "$php85_image" "$nextcloud_image"; do
    docker push "$image"
    docker pull "$image" >/dev/null
    digest="$(docker image inspect --format '{{range .RepoDigests}}{{println .}}{{end}}' "$image" \
        | grep -F "${REGISTRY}/${OWNER_PATH}/" \
        | head -n 1)"
    [[ -n "$digest" ]] || {
        echo "ERROR: registry digest not available after push: $image" >&2
        exit 1
    }
    printf 'PUBLISHED %s\n' "$digest"
done
