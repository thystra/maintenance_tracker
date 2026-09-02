#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Alan Johnson
# SPDX-License-Identifier: AGPL-3.0-or-later

set -Eeuo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
tmp="$(mktemp -d)"
cleanup() {
	rm -rf "$tmp"
}
trap cleanup EXIT

fixture_paths=(
	.gitattributes
	.nextcloudignore
	appinfo/info.xml
	composer.json
	package.json
	profiles/generic-car.json
	schemas/profile-v1.schema.json
	lib/AppInfo/Application.php
	.forgejo/workflows/ci.yml
)

copy_fixture() {
	rm -rf "$tmp/fixture"
	mkdir -p "$tmp/fixture"
	(
		cd "$root"
		tar -cf - "${fixture_paths[@]}"
	) | (
		cd "$tmp/fixture"
		tar -xf -
	)
}

run_validator() {
	(
		cd "$tmp/fixture"
		node "$root/scripts/validate-project.mjs"
	)
}

copy_fixture
run_validator >/dev/null

sed -i 's/runs-on: forgejo-workstation/runs-on: ubuntu-latest/' \
	"$tmp/fixture/.forgejo/workflows/ci.yml"
if run_validator >"$tmp/runner.out" 2>&1; then
	echo 'Validator accepted an invalid Forgejo runner authority.' >&2
	exit 1
fi
grep -Fq 'Authoritative Forgejo CI must not target GitHub-hosted ubuntu-latest runners.' \
	"$tmp/runner.out"

copy_fixture
python3 - "$tmp/fixture/package.json" <<'PY'
import json
from pathlib import Path
import sys

path = Path(sys.argv[1])
data = json.loads(path.read_text())
data['version'] = '9.9.9'
path.write_text(json.dumps(data))
PY
if run_validator >"$tmp/version.out" 2>&1; then
	echo 'Validator accepted inconsistent application versions.' >&2
	exit 1
fi
grep -Fq 'App version must match appinfo/info.xml, Application::APP_VERSION, and package.json.' \
	"$tmp/version.out"

echo 'Project validator self-tests passed.'
