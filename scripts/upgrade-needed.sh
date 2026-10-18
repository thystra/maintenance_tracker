#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Alan Johnson
# SPDX-License-Identifier: AGPL-3.0-or-later

# Decide whether CI must run the upgrade-migration test. Prints
# `upgrade=true` or `upgrade=false` (for $GITHUB_OUTPUT) and the reason on
# stderr.
#
#   scripts/upgrade-needed.sh            uses EVENT_NAME and BASE_SHA (CI)
#   scripts/upgrade-needed.sh --paths    reads changed paths from stdin
#
# Pull requests run it only when they touch the schema, the database layer,
# the app version, the upgrade harness or the workflow. Every other event
# (push to main, schedule, workflow_dispatch) always runs it, and so does a
# pull request whose base cannot be resolved.

set -Eeuo pipefail

upgrade_paths_regex='^(lib/Migration/|lib/Db/|appinfo/info\.xml$|tests/integration/(upgrade-smoke\.sh|upgrade-bases\.sh|pull-images\.sh|lib/)|scripts/upgrade-needed\.sh$|\.forgejo/workflows/ci\.yml$)'

decide() {
	local paths matched
	paths=$(cat)
	matched=$(printf '%s\n' "$paths" | grep -E "$upgrade_paths_regex" || true)
	if [ -n "$matched" ]; then
		echo "upgrade-relevant changes:" >&2
		printf '%s\n' "$matched" | sed 's/^/  /' >&2
		echo 'upgrade=true'
	else
		echo 'no upgrade-relevant changes; the upgrade test will be skipped' >&2
		echo 'upgrade=false'
	fi
}

if [ "${1:-}" = '--paths' ]; then
	decide
	exit 0
fi

event="${EVENT_NAME:-}"
if [ "$event" != 'pull_request' ]; then
	echo "event ${event:-unknown}: the upgrade test always runs" >&2
	echo 'upgrade=true'
	exit 0
fi
if [ -z "${BASE_SHA:-}" ] || ! git rev-parse --verify --quiet "${BASE_SHA}^{commit}" >/dev/null; then
	echo "pull request base ${BASE_SHA:-unknown} is not available; running the upgrade test to be safe" >&2
	echo 'upgrade=true'
	exit 0
fi
git diff --name-only "${BASE_SHA}...HEAD" | decide
