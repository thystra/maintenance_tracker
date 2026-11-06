#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Alan Johnson
# SPDX-License-Identifier: AGPL-3.0-or-later
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
mode="${1:-frontend}"
if [ "$mode" = frontend ]; then
 python3 - "$root" <<'CHECK'
import json,sys
from pathlib import Path
root=Path(sys.argv[1]); s=(root/'.forgejo/workflows/release.yml').read_text()
images=json.loads((root/'ci/images/qualified-images.json').read_text())['images']
assert images['nextcloud']['reference'] in s and images['php82']['reference'] in s
assert 'workflow_dispatch:' in s and 'publish_appstore:' in s and 'default: false' in s
assert 'needs: frontend' in s and 'path: release-source' in s
assert 'ref: ${{ needs.frontend.outputs.commit }}' in s
assert 'RELEASE_TAG_INPUT: ${{ forgejo.event.inputs.tag }}' in s
assert "tag='${{" not in s
assert 'npm ci' in s.split('  release:',1)[0]
assert 'npm ci' not in s.split('  release:',1)[1]
assert "trap 'rm -f" in s
CHECK
 mkdir -p "$tmp/repo/appinfo"
 cd "$tmp/repo"
 git init -q
 printf '<info><version>1.2.3</version></info>\n' > appinfo/info.xml
 printf '## [1.2.3]\n\nTagged notes.\n' > CHANGELOG.md
 git add .
 git -c user.name=Fixture -c user.email=fixture@example.invalid -c commit.gpgSign=false commit -qm fixture
 commit="$(git rev-parse HEAD)"
 git -c tag.gpgSign=false tag v1.2.3
 git -c tag.gpgSign=false tag v1.2.4
 git update-ref refs/remotes/origin/main "$commit"
 export RELEASE_EVENT=workflow_dispatch WORKFLOW_REF=refs/heads/main RELEASE_TAG_INPUT=v1.2.3 GITHUB_OUTPUT="$tmp/output"
 bash "$root/scripts/resolve-release-tag.sh"
 grep -Fx "commit=$commit" "$tmp/output" >/dev/null
 [ "$(git rev-parse 'refs/tags/v1.2.3^{commit}')" = "$commit" ]
 RELEASE_TAG_INPUT='v1.2.3; touch injected' bash "$root/scripts/resolve-release-tag.sh" >"$tmp/rejected" 2>&1 && exit 1
 test ! -e injected
 RELEASE_TAG_INPUT=v1.2.4 bash "$root/scripts/resolve-release-tag.sh" >"$tmp/rejected" 2>&1 && exit 1
 WORKFLOW_REF=refs/heads/untrusted bash "$root/scripts/resolve-release-tag.sh" >"$tmp/rejected" 2>&1 && exit 1
 RELEASE_EVENT=push WORKFLOW_REF=refs/tags/v1.2.3 bash "$root/scripts/resolve-release-tag.sh"
 echo 'Release frontend/tag safety self-tests passed.'
 exit 0
fi
[ "$mode" = php ] || exit 2
mkdir -p "$tmp/bin" "$tmp/artifacts"
printf 'archive fixture\n' > "$tmp/artifacts/maintenance_tracker.tar.gz"
printf 'c2lnbmF0dXJl\n' > "$tmp/artifacts/maintenance_tracker.tar.gz.sig"
(cd "$tmp/artifacts" && sha256sum maintenance_tracker.tar.gz > maintenance_tracker.tar.gz.sha256)
cat > "$tmp/bin/curl" <<'CURL'
#!/usr/bin/env bash
set -euo pipefail
out=''; write=0; method=GET; url=''; follow=0
while [ $# -gt 0 ]; do
 case "$1" in
  -o) out="$2"; shift 2 ;;
  -w) write=1; shift 2 ;;
  -X) method="$2"; shift 2 ;;
  -L) follow=1; shift ;;
  -H|-F|-d) shift 2 ;;
  -sS|--fail-with-body) shift ;;
  https://*) url="$1"; shift ;;
  *) echo "Unexpected mock argument: $1" >&2; exit 2 ;;
 esac
done
printf '%s %s\n' "$method" "$url" >> "$MOCK_LOG"
status=200; body='{}'
case "$url" in
 */apps/releases) status="${MOCK_APPSTORE_STATUS:-201}" ;;
 */releases/tags/*)
  if [ "$MOCK_MODE" = new ]; then status=404
  elif [ "$MOCK_MODE" = denied ]; then status=403
  else body='{"id":42,"tag_name":"v1.2.3"}'; fi ;;
 */releases/42/assets) body='[]'
  if [ "$MOCK_MODE" != new ]; then body='[{"name":"maintenance_tracker.tar.gz"},{"name":"maintenance_tracker.tar.gz.sha256"},{"name":"maintenance_tracker.tar.gz.sig"}]'; fi ;;
 */releases/download/*)
  [ "$follow" = 1 ] || { echo "Public asset redirects must be followed." >&2; exit 22; }
  if [ "$MOCK_MODE" = mismatch ]; then printf 'different bytes\n' > "$out"
  else cp "$MOCK_ARTIFACTS/${url##*/}" "$out"; fi
  exit 0 ;;
 */releases) body='{"id":42,"tag_name":"v1.2.3"}' ;;
 */assets\?name=*) body='{"id":1}' ;;
 *) echo "Unexpected mock URL: $url" >&2; exit 2 ;;
esac
printf '%s' "$body" > "$out"
[ "$write" -eq 0 ] || printf '%s' "$status"
CURL
chmod +x "$tmp/bin/curl"
export PATH="$tmp/bin:$PATH" TOKEN=fixture-token API=https://forgejo.example.invalid/api/v1/repos/owner/app TAG=v1.2.3 VERSION=1.2.3 ARTIFACT_DIR="$tmp/artifacts" MOCK_ARTIFACTS="$tmp/artifacts" MOCK_LOG="$tmp/requests"
cd "$tmp"
printf '## [1.2.3]\n\nFixture notes.\n' > CHANGELOG.md
MOCK_MODE=new bash "$root/scripts/publish-forgejo-release.sh"
[ "$(grep -c '^POST ' "$MOCK_LOG")" = 4 ]
: > "$MOCK_LOG"
MOCK_MODE=resume bash "$root/scripts/publish-forgejo-release.sh"
if grep -q '^POST ' "$MOCK_LOG"; then exit 1; fi
: > "$MOCK_LOG"
MOCK_MODE=mismatch bash "$root/scripts/publish-forgejo-release.sh" > "$tmp/rejected" 2>&1 && exit 1
if grep -q '^POST ' "$MOCK_LOG"; then exit 1; fi
MOCK_MODE=denied bash "$root/scripts/publish-forgejo-release.sh" > "$tmp/rejected" 2>&1 && exit 1
export APPSTORE_TOKEN=fixture-token MOCK_MODE=new
bash "$root/scripts/publish-appstore.sh" --download https://forgejo.example.invalid/app.tar.gz --signature "$ARTIFACT_DIR/maintenance_tracker.tar.gz.sig" --dry-run
bash "$root/scripts/publish-appstore.sh" --download https://forgejo.example.invalid/app.tar.gz --signature "$ARTIFACT_DIR/maintenance_tracker.tar.gz.sig"
MOCK_APPSTORE_STATUS=403 bash "$root/scripts/publish-appstore.sh" --download https://forgejo.example.invalid/app.tar.gz --signature "$ARTIFACT_DIR/maintenance_tracker.tar.gz.sig" > "$tmp/rejected" 2>&1 && exit 1
echo 'Release publication self-tests passed (mock transport only).'
