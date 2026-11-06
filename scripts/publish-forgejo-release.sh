#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Alan Johnson
# SPDX-License-Identifier: AGPL-3.0-or-later
# Resume safely: existing assets must be byte-identical; never replace/delete.
# shellcheck disable=SC2016 # PHP variables are intentionally single-quoted.
set -euo pipefail
: "${TOKEN:?}" "${API:?}" "${TAG:?}" "${VERSION:?}"
[[ "$TAG" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] && [ "$TAG" = "v$VERSION" ] || exit 2
[[ "$API" =~ ^https://[^/]+/api/v1/repos/[^/]+/[^/]+$ ]] || { echo 'Expected a repository HTTPS API URL.' >&2; exit 2; }
artifacts="${ARTIFACT_DIR:-build/artifacts}"
archive=maintenance_tracker.tar.gz
files=("$archive" "$archive.sha256")
[ ! -f "$artifacts/$archive.sig" ] || files+=("$archive.sig")
(cd "$artifacts" && sha256sum -c "$archive.sha256")
for file in "${files[@]}"; do test -f "$artifacts/$file"; done
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
code="$(curl -sS -o "$tmp/release.json" -w '%{http_code}' -H "Authorization: token $TOKEN" "$API/releases/tags/$TAG")"
case "$code" in
 200) echo "Resuming existing release $TAG" ;;
 404)
  notes="$(awk -v v="$VERSION" '$0 ~ "^## \\[" v "\\]" {on=1; next} /^## \[/ {on=0} on' CHANGELOG.md)"
  body="$(php -r 'echo json_encode(["tag_name"=>$argv[1],"name"=>$argv[1],"body"=>$argv[2],"draft"=>false,"prerelease"=>str_starts_with($argv[3],"0.")]);' "$TAG" "$notes" "$VERSION")"
  curl -sS --fail-with-body -X POST "$API/releases" -H "Authorization: token $TOKEN" -H 'Content-Type: application/json' -d "$body" -o "$tmp/release.json"
  ;;
 *) echo "Cannot inspect release: HTTP $code" >&2; exit 1 ;;
esac
id="$(php -r '$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); if(($d["tag_name"]??null)!==$argv[2]||!is_int($d["id"]??null)||$d["id"]<1)exit(1); echo $d["id"];' "$tmp/release.json" "$TAG")"
curl -sS --fail-with-body -H "Authorization: token $TOKEN" "$API/releases/$id/assets" -o "$tmp/assets.json"
server="${API%%/api/v1/repos/*}"
repo="${API#*/api/v1/repos/}"
for file in "${files[@]}"; do
 existing="$(php -r '$a=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); if(!is_array($a))exit(1); $m=array_filter($a,fn($v)=>($v["name"]??null)===$argv[2]); if(count($m)>1)exit(1); echo count($m)?"yes":"no";' "$tmp/assets.json" "$file")"
 if [ "$existing" = yes ]; then
  # Public URL is constructed locally; never send a token to an API-supplied URL.
  curl -sS -L --fail-with-body "$server/$repo/releases/download/$TAG/$file" -o "$tmp/existing"
  cmp -s "$tmp/existing" "$artifacts/$file" || { echo "Existing $file differs; refusing to overwrite it." >&2; exit 1; }
  echo "Already attached identical $file"
 else
  curl -sS --fail-with-body -X POST "$API/releases/$id/assets?name=$file" -H "Authorization: token $TOKEN" -F "attachment=@$artifacts/$file" -o "$tmp/upload.json"
  echo "Attached $file"
 fi
done
