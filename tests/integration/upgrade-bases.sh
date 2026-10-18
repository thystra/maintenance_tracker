#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Alan Johnson
# SPDX-License-Identifier: AGPL-3.0-or-later

# Resolve the upgrade test's starting points, one git commit per line.
#
#   NC_UPGRADE_FROM        space-separated list of git refs (tags once releases
#                          exist; default 6fc011d, the v0.1.10 completion merge)
#   NC_UPGRADE_LATEST_TAG  1 = also add the newest release tag (vX.Y.Z reachable
#                          from HEAD) when its app version is newer than every
#                          listed base; the explicit list is the fallback
#
# Policy (docs/development.md#upgrade-policy): the list holds the latest
# tagged release plus the oldest supported release.

set -Eeuo pipefail

list="${NC_UPGRADE_FROM:-6fc011d}"
latest_tag="${NC_UPGRADE_LATEST_TAG:-0}"

app_version() { git show "$1:appinfo/info.xml" 2>/dev/null | sed -n 's:.*<version>\(.*\)</version>.*:\1:p' | head -n 1; }
newer() { [ "$1" != "$2" ] && [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | tail -n 1)" = "$1" ]; }

commits=()
newest=''
for ref in $list; do
	commit=$(git rev-parse --verify --quiet "${ref}^{commit}") || {
		echo "Upgrade base ${ref} is not in this checkout (fetch full history and tags)." >&2
		exit 1
	}
	version=$(app_version "$commit")
	[ -n "$version" ] || { echo "Upgrade base ${ref} has no appinfo/info.xml version." >&2; exit 1; }
	if [[ " ${commits[*]} " != *" ${commit} "* ]]; then
		commits+=("$commit")
		echo "upgrade base ${ref} -> ${commit:0:12} (app ${version})" >&2
	fi
	if [ -z "$newest" ] || newer "$version" "$newest"; then
		newest=$version
	fi
done

if [ "$latest_tag" = '1' ]; then
	tag=$(git tag --list 'v[0-9]*.[0-9]*.[0-9]*' --merged HEAD --sort=-v:refname | head -n 1)
	if [ -z "$tag" ]; then
		echo "no release tag found; using the explicit list" >&2
	else
		commit=$(git rev-parse "${tag}^{commit}")
		version=$(app_version "$commit")
		if [[ " ${commits[*]} " == *" ${commit} "* ]]; then
			echo "latest tag ${tag} is already listed" >&2
		elif [ -n "$version" ] && newer "$version" "$newest"; then
			commits+=("$commit")
			echo "upgrade base ${tag} (latest tag) -> ${commit:0:12} (app ${version})" >&2
		else
			echo "latest tag ${tag} (app ${version:-unknown}) is not newer than the listed bases (${newest}); using the explicit list" >&2
		fi
	fi
fi

printf '%s\n' "${commits[@]}"
