#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Alan Johnson
# SPDX-License-Identifier: AGPL-3.0-or-later
# Run in the trusted workflow checkout, not the application checkout.
set -euo pipefail
if [ "${RELEASE_EVENT:-}" = workflow_dispatch ]; then
 [ "${WORKFLOW_REF:-}" = refs/heads/main ] || { echo 'Dispatch the release workflow from main.' >&2; exit 1; }
 tag="${RELEASE_TAG_INPUT:-}"
elif [ "${RELEASE_EVENT:-}" = push ] && [[ "${WORKFLOW_REF:-}" == refs/tags/* ]]; then
 tag="${WORKFLOW_REF#refs/tags/}"
else
 echo 'Only a version-tag push or a dispatch from main may release.' >&2; exit 1
fi
[[ "$tag" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo 'Expected an existing vMAJOR.MINOR.PATCH tag.' >&2; exit 1; }
commit="$(git rev-parse --verify "refs/tags/$tag^{commit}")"
git merge-base --is-ancestor "$commit" refs/remotes/origin/main || { echo 'Release tag must be on main history.' >&2; exit 1; }
version="$(git show "$commit:appinfo/info.xml" | python3 -c 'import sys,xml.etree.ElementTree as E; print(E.fromstring(sys.stdin.read()).findtext("version"))')"
[ "$tag" = "v$version" ] || { echo 'Tag does not match the tagged app version.' >&2; exit 1; }
# Read the changelog from that exact tag too, never from the newer main checkout.
git show "$commit:CHANGELOG.md" | grep -F "## [$version]" >/dev/null || { echo 'Tagged version needs a CHANGELOG section.' >&2; exit 1; }
printf 'tag=%s\nversion=%s\ncommit=%s\n' "$tag" "$version" "$commit" >> "${GITHUB_OUTPUT:?}"
printf 'Validated %s at %s\n' "$tag" "$commit"
