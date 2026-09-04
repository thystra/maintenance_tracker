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
	ci/images/qualified-images.json
	lib/Migration/Version1000Date20260723000000.php
	lib/Migration/Version1010Date20260902000000.php
	lib/Migration/Version1020Date20260903000000.php
	lib/Service/UserLifecycleService.php
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
python3 - "$tmp/fixture/.forgejo/workflows/ci.yml" \
	"$tmp/fixture/ci/images/qualified-images.json" <<'PY'
import json
from pathlib import Path
import sys

workflow_path = Path(sys.argv[1])
images_path = Path(sys.argv[2])
images = json.loads(images_path.read_text())
php82 = images['images']['php82']
text = workflow_path.read_text()
if text.count(php82['reference']) != 1:
    raise SystemExit('Expected exactly one PHP 8.2 digest reference in workflow fixture.')
workflow_path.write_text(text.replace(php82['reference'], php82['tag'], 1))
PY
if run_validator >"$tmp/image-digest.out" 2>&1; then
	echo 'Validator accepted a mutable CI image tag.' >&2
	exit 1
fi
grep -Fq 'Routine CI must pin the qualified PHP 8.2 image digest.' \
	"$tmp/image-digest.out"

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

copy_fixture
sed -i "/'maint_specs',/d" "$tmp/fixture/lib/Service/UserLifecycleService.php"
if run_validator >"$tmp/lifecycle-purge.out" 2>&1; then
	echo 'Validator accepted an incomplete account-deletion purge registry.' >&2
	exit 1
fi
grep -Fq 'Account deletion purge registry must cover workspace-scoped table maint_specs.' \
	"$tmp/lifecycle-purge.out"

copy_fixture
sed -i '/\$this->serializeWorkspacePurge(\$workspaceId);/d' "$tmp/fixture/lib/Service/UserLifecycleService.php"
if run_validator >"$tmp/lifecycle-serialization.out" 2>&1; then
	echo 'Validator accepted account deletion without workspace serialization.' >&2
	exit 1
fi
grep -Fq 'Account deletion must serialize each personal workspace before purging child rows.' \
	"$tmp/lifecycle-serialization.out"

echo 'Project validator self-tests passed.'
