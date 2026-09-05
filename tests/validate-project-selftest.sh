#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Alan Johnson
# SPDX-License-Identifier: AGPL-3.0-or-later

set -Eeuo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
tmp="$(mktemp -d)"
cleanup() { rm -rf "$tmp"; }
trap cleanup EXIT

fixture_paths=(
	.gitattributes .nextcloudignore appinfo/info.xml composer.json package.json package-lock.json
	profiles/generic-car.json schemas/profile-v1.schema.json lib/AppInfo/Application.php
	.forgejo/workflows/ci.yml ci/images/qualified-images.json lib/Capability.php
	lib/Migration/Version1000Date20260723000000.php
	lib/Migration/Version1010Date20260902000000.php
	lib/Migration/Version1020Date20260903000000.php
	lib/Migration/Version1030Date20260904000000.php
	lib/Migration/Version1040Date20260905000000.php
	lib/Service/UserLifecycleService.php lib/Service/WorkspaceService.php
	lib/Service/AuthorizationCatalog.php lib/Service/AuditService.php
	lib/Service/AuditEventCatalog.php lib/Db/AuditMapper.php
	lib/Db/ReadingMapper.php lib/Service/MeterValueConverter.php lib/Service/MeterService.php
	lib/Controller docs AGENTS.md README.md
)

copy_fixture() {
	rm -rf "$tmp/fixture"; mkdir -p "$tmp/fixture"
	(cd "$root" && tar -cf - "${fixture_paths[@]}") | (cd "$tmp/fixture" && tar -xf -)
}
run_validator() { (cd "$tmp/fixture" && node "$root/scripts/validate-project.mjs"); }
expect_rejected() {
	local label=$1 expected=$2 outfile=$3
	if run_validator >"$outfile" 2>&1; then
		echo "Validator accepted ${label}." >&2; exit 1
	fi
	grep -Fq "$expected" "$outfile"
}

copy_fixture
run_validator >/dev/null

copy_fixture
sed -i 's/runs-on: forgejo-workstation/runs-on: ubuntu-latest/' "$tmp/fixture/.forgejo/workflows/ci.yml"
expect_rejected 'an invalid Forgejo runner authority' 'Authoritative Forgejo CI must not target GitHub-hosted ubuntu-latest runners.' "$tmp/runner.out"

copy_fixture
python3 - "$tmp/fixture/.forgejo/workflows/ci.yml" "$tmp/fixture/ci/images/qualified-images.json" <<'PY'
import json, sys
from pathlib import Path
workflow_path, images_path = map(Path, sys.argv[1:])
images = json.loads(images_path.read_text())
php82 = images['images']['php82']
text = workflow_path.read_text()
if text.count(php82['reference']) != 1: raise SystemExit('Expected exactly one PHP 8.2 digest reference.')
workflow_path.write_text(text.replace(php82['reference'], php82['tag'], 1))
PY
expect_rejected 'a mutable CI image tag' 'Routine CI must pin the qualified PHP 8.2 image digest.' "$tmp/image-digest.out"

copy_fixture
python3 - "$tmp/fixture/package.json" <<'PY'
import json, sys
from pathlib import Path
p=Path(sys.argv[1]); data=json.loads(p.read_text()); data['version']='9.9.9'; p.write_text(json.dumps(data))
PY
expect_rejected 'inconsistent application versions' 'App version must match appinfo/info.xml, Application::APP_VERSION, package.json, and package-lock.json.' "$tmp/version.out"

copy_fixture
sed -i "/'maint_specs',/d" "$tmp/fixture/lib/Service/UserLifecycleService.php"
expect_rejected 'an incomplete account-deletion purge registry' 'Account deletion purge registry must cover workspace-scoped table maint_specs.' "$tmp/lifecycle-purge.out"

copy_fixture
sed -i '/\$this->serializeWorkspacePurge(\$workspaceId);/d' "$tmp/fixture/lib/Service/UserLifecycleService.php"
expect_rejected 'account deletion without workspace serialization' 'Account deletion must serialize each personal workspace before purging child rows.' "$tmp/lifecycle-serialization.out"

copy_fixture
python3 - "$tmp/fixture/lib/Service/WorkspaceService.php" <<'PY'
from pathlib import Path
import sys
p=Path(sys.argv[1]); s=p.read_text(); marker='final class WorkspaceService {'
p.write_text(s.replace(marker, marker+"\n\tprivate const ROLE_RANK = ['viewer' => 10, 'manager' => 20, 'owner' => 30];", 1))
PY
expect_rejected 'the legacy role-rank authorization gate' 'Workspace authorization must not restore the legacy role-rank gate.' "$tmp/role-rank.out"

copy_fixture
sed -i 's/runWithCapability(/runWithAccess(/' "$tmp/fixture/lib/Controller/AssetController.php"
expect_rejected 'controller raw-role authorization' 'Controllers must authorize through capabilities, not raw role names.' "$tmp/controller-auth.out"

copy_fixture
python3 - "$tmp/fixture/lib/Service/AuthorizationCatalog.php" <<'PY'
from pathlib import Path
import sys
p=Path(sys.argv[1]); s=p.read_text()
start=s.index("\t\t'manager' => [")
end=s.index("\n\t\t],\n\t\t'contributor'", start)
block=s[start:end]
needle="\t\t\tself::WORKSPACE_MEMBERS_READ,\n"
if needle not in block: raise SystemExit('manager fixture marker missing')
block=block.replace(needle, needle+"\t\t\tself::WORKSPACE_MEMBERS_MANAGE,\n",1)
p.write_text(s[:start]+block+s[end:])
PY
expect_rejected 'Manager membership administration' 'Manager must not receive workspace.members.manage.' "$tmp/manager-cap.out"

copy_fixture
sed -i "/'report.share.create' =>/d" "$tmp/fixture/lib/Service/AuthorizationCatalog.php"
expect_rejected 'reserved capability removal' 'Reserved capability report.share.create must remain present and unimplemented.' "$tmp/reserved-cap.out"

copy_fixture
python3 - "$tmp/fixture/lib/Db/AuditMapper.php" <<'PY'
from pathlib import Path
import sys
p=Path(sys.argv[1]); s=p.read_text(); marker='final class AuditMapper {'
p.write_text(s.replace(marker, marker+"\n\tpublic function mutateForFixture(): void { $this->db->getQueryBuilder()->delete('maint_audit'); }",1))
PY
expect_rejected 'a mutable audit mapper' 'Audit mapper must remain append/read-only.' "$tmp/audit-mutable.out"

copy_fixture
sed -i 's/MAX_DETAILS_BYTES = 4096/MAX_DETAILS_BYTES = 8192/' "$tmp/fixture/lib/Service/AuditService.php"
expect_rejected 'expanded audit detail storage' 'Audit detail storage must retain the reviewed 4096-byte bound.' "$tmp/audit-bound.out"

copy_fixture
sed -i "/createNamedParameter('editor'/s/editor/legacy_editor/" "$tmp/fixture/lib/Migration/Version1030Date20260904000000.php"
expect_rejected 'removal of editor-to-manager migration' 'v0.1.3 migration must persist editor-to-manager role normalization.' "$tmp/editor-migration.out"

copy_fixture
sed -i "/'maint_audit',/d" "$tmp/fixture/lib/Service/UserLifecycleService.php"
expect_rejected 'audit cleanup omission' 'Account deletion purge registry must cover workspace-scoped table maint_audit.' "$tmp/audit-purge.out"

copy_fixture
sed -i "/'maint_readings',/d" "$tmp/fixture/lib/Service/UserLifecycleService.php"
expect_rejected 'reading cleanup omission' 'Account deletion purge registry must cover workspace-scoped table maint_readings.' "$tmp/reading-purge.out"

copy_fixture
python3 - "$tmp/fixture/lib/Db/ReadingMapper.php" <<'PY'
from pathlib import Path
import sys
p=Path(sys.argv[1]); s=p.read_text(); marker='final class ReadingMapper {'
p.write_text(s.replace(marker, marker+"\n\tpublic function mutateForFixture(): void { $this->db->getQueryBuilder()->delete('maint_readings'); }",1))
PY
expect_rejected 'a mutable reading mapper' 'Reading mapper must remain append/read-only.' "$tmp/reading-mutable.out"

copy_fixture
sed -i "s/'mi' => 1609344/'mi' => 1609000/" "$tmp/fixture/lib/Service/MeterValueConverter.php"
expect_rejected 'an altered mile conversion factor' 'Meter canonical conversion factors must retain exact mile-to-mm and hour-to-second factors.' "$tmp/meter-factor.out"

copy_fixture
sed -i 's/MAX_CANONICAL_VALUE = 9007199254740991/MAX_CANONICAL_VALUE = 9223372036854775807/' "$tmp/fixture/lib/Service/MeterValueConverter.php"
expect_rejected 'an unsafe JSON canonical bound' 'Meter canonical values must remain within the JavaScript JSON safe-integer range.' "$tmp/meter-safe-int.out"

copy_fixture
python3 - "$tmp/fixture/lib/Service/MeterService.php" <<'PY'
from pathlib import Path
import sys
p=Path(sys.argv[1]); s=p.read_text()
needle="\t\tif (!$meter->getMonotonic() && $monotonic) {\n\t\t\t$this->assertHistoryCanBeMonotonic($meter);\n\t\t}"
if needle not in s: raise SystemExit('monotonic-enable fixture marker missing')
p.write_text(s.replace(needle, '', 1))
PY
expect_rejected 'monotonic enable without history validation' 'Enabling monotonic mode must validate all existing effective readings first.' "$tmp/meter-monotonic-enable.out"

copy_fixture
python3 - "$tmp/fixture/lib/Service/AuthorizationCatalog.php" <<'PY'
from pathlib import Path
import sys
p=Path(sys.argv[1]); s=p.read_text()
needle="\t\t\tself::METER_READ,\n\t\t\tself::READING_CREATE,\n\t\t],\n\t\t'viewer'"
replacement="\t\t\tself::METER_READ,\n\t\t\tself::READING_CREATE,\n\t\t\tself::READING_CORRECT,\n\t\t],\n\t\t'viewer'"
if needle not in s: raise SystemExit('contributor fixture marker missing')
p.write_text(s.replace(needle,replacement,1))
PY
expect_rejected 'Contributor historical correction' 'Contributor must not configure meters or correct historical readings.' "$tmp/contributor-correct.out"

copy_fixture
sed -i "/'meters-readings',/d" "$tmp/fixture/lib/Capability.php"
expect_rejected 'meter/read feature removal' 'Capability discovery must advertise implemented feature meters-readings.' "$tmp/meter-feature.out"

copy_fixture
sed -i 's/schedule: none/schedule: disabled/g' "$tmp/fixture/docs/architecture.md" "$tmp/fixture/docs/domain-model.md" "$tmp/fixture/docs/product-architecture.md" "$tmp/fixture/docs/roadmap.md" "$tmp/fixture/docs/security.md" "$tmp/fixture/docs/api.md" "$tmp/fixture/AGENTS.md" "$tmp/fixture/README.md" || true
expect_rejected 'loss of schedule-none terminology' 'Architecture documentation must preserve schedule: none as the unscheduled work-definition policy.' "$tmp/schedule-none.out"

echo 'Project validator self-tests passed.'
