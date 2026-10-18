#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Alan Johnson
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Build the Nextcloud App Store archive build/artifacts/maintenance_tracker.tar.gz.
#
# Only git-tracked runtime files are packaged, under a top-level
# maintenance_tracker/ directory, with sorted entries, fixed ownership and a
# fixed modification time (SOURCE_DATE_EPOCH, default: the last commit time), so
# the same commit always gives the same archive bytes.
#
# Optional signing (see docs/releasing.md):
#   APP_SIGNING_KEY   path to the app's private key (PEM). Writes
#                     maintenance_tracker.tar.gz.sig, the base64 SHA-512
#                     signature the App Store asks for when uploading a release.
#   APP_SIGNING_CERT  path to the app certificate issued by Nextcloud. Together
#   NC_OCC            with APP_SIGNING_KEY and a command that runs occ of a
#                     Nextcloud install (for example "php /var/www/html/occ"),
#                     also writes appinfo/signature.json (code signing) into the
#                     archive with occ integrity:sign-app.
set -euo pipefail

app=maintenance_tracker
root=$(git rev-parse --show-toplevel)
cd "$root"

build="$root/build"
stage="$build/stage"
artifacts="$build/artifacts"
archive="$artifacts/$app.tar.gz"

# Runtime allowlist. Everything else (src, tests, docs, scripts, tooling,
# workflows, vendor-bin, node_modules, fitment/examples) stays out.
include=(appinfo lib templates img js css l10n profiles schemas fitment/core-v1.json openapi.json LICENSE README.md CHANGELOG.md)

if [ -n "$(git status --porcelain -- "${include[@]}")" ]; then
	echo "Runtime files have uncommitted changes; commit or stash them first." >&2
	git status --short -- "${include[@]}" >&2
	exit 1
fi

export SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-$(git log -1 --format=%ct)}"

rm -rf "$stage" "$artifacts"
mkdir -p "$stage/$app" "$artifacts"

git ls-files -z -- "${include[@]}" | while IFS= read -r -d '' file; do
	case "$file" in
		*/.gitkeep | *.map) continue ;;
	esac
	mkdir -p "$stage/$app/$(dirname "$file")"
	cp -p "$file" "$stage/$app/$file"
done
mkdir -p "$stage/$app/l10n"

for required in appinfo/info.xml lib/AppInfo/Application.php js/maintenance_tracker-main.mjs css/maintenance_tracker-main.css fitment/core-v1.json; do
	test -f "$stage/$app/$required" || { echo "Missing runtime file: $required" >&2; exit 1; }
done
for forbidden in src tests docs scripts node_modules vendor-bin .forgejo .github package.json composer.json AGENTS.md fitment/examples; do
	test ! -e "$stage/$app/$forbidden" || { echo "Development file in package: $forbidden" >&2; exit 1; }
done

if [ -n "${APP_SIGNING_KEY:-}" ] && [ -n "${APP_SIGNING_CERT:-}" ] && [ -n "${NC_OCC:-}" ]; then
	echo "Code signing with occ integrity:sign-app"
	# shellcheck disable=SC2086 # NC_OCC is a command line on purpose.
	$NC_OCC integrity:sign-app --privateKey="$APP_SIGNING_KEY" --certificate="$APP_SIGNING_CERT" --path="$stage/$app"
	test -f "$stage/$app/appinfo/signature.json"
fi

find "$stage/$app" -exec touch -h -d "@$SOURCE_DATE_EPOCH" {} +
find "$stage/$app" -type d -exec chmod 0755 {} +
find "$stage/$app" -type f -exec chmod 0644 {} +

tar --sort=name --format=gnu --mtime="@$SOURCE_DATE_EPOCH" \
	--owner=0 --group=0 --numeric-owner \
	-C "$stage" -cf - "$app" | gzip -9 -n > "$archive"

(cd "$artifacts" && sha256sum "$app.tar.gz" > "$app.tar.gz.sha256")

if [ -n "${APP_SIGNING_KEY:-}" ]; then
	openssl dgst -sha512 -sign "$APP_SIGNING_KEY" "$archive" | openssl base64 -A > "$archive.sig"
	echo >> "$archive.sig"
	echo "Signed: $archive.sig"
fi

echo "Built $archive ($(wc -c < "$archive") bytes, $(tar -tzf "$archive" | wc -l) entries)"
cat "$artifacts/$app.tar.gz.sha256"
