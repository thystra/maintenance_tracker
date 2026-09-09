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
	profiles/generic-car.json schemas/profile-v1.schema.json schemas/profile-v2.schema.json schemas/fitment-vocabulary-v1.schema.json schemas/fitment-pack-v1.schema.json scripts/validate-profiles.mjs scripts/validate-fitment-packs.mjs fitment lib/AppInfo/Application.php
	.forgejo/workflows/ci.yml ci/images/qualified-images.json lib/Capability.php
	lib/Migration/Version1000Date20260723000000.php
	lib/Migration/Version1010Date20260902000000.php
	lib/Migration/Version1020Date20260903000000.php
	lib/Migration/Version1030Date20260904000000.php
	lib/Migration/Version1040Date20260905000000.php
	lib/Migration/Version1050Date20260905020000.php
	lib/Migration/Version1060Date20260906090000.php
	lib/Migration/Version1070Date20260907080000.php
	lib/Migration/Version1080Date20260907130000.php
	lib/Migration/Version1090Date20260907193000.php
	lib/Service/UserLifecycleService.php lib/Service/WorkspaceService.php
	lib/Service/AuthorizationCatalog.php lib/Service/AuditService.php
	lib/Service/AuditEventCatalog.php lib/Db/AuditMapper.php
	lib/Db/ReadingMapper.php lib/Service/ReadingService.php lib/Service/MeterValueConverter.php lib/Service/MeterService.php
	lib/Db/WorkDefinition.php lib/Db/WorkScheduleRule.php lib/Service/WorkDefinitionService.php lib/Service/WorkSchedulePolicy.php lib/Db/WorkScheduleRuleMapper.php
	lib/Db/Activity.php lib/Service/ActivityService.php lib/Db/ActivityItemMapper.php lib/Db/ActivityMeterMapper.php
	lib/Service/DueStatePolicy.php lib/Service/MaintenanceStatusService.php
	lib/Service/ForecastPolicy.php lib/Service/MaintenanceForecastService.php lib/Service/ReminderPolicyService.php lib/Service/MaintenanceOccurrenceService.php
	lib/Db/MaintenanceOccurrenceMapper.php
	lib/Service/ProfileValidator.php lib/Service/ProfileCatalog.php lib/Service/ProfileRepository.php lib/Service/ProfileInstallationService.php
	lib/Service/FitmentPackValidator.php lib/Service/FitmentRepository.php lib/Service/AssetFitmentDescriptorService.php lib/Service/FitmentService.php
	lib/Controller docs AGENTS.md README.md CHANGELOG.md
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
python3 - "$tmp/fixture/ci/images/qualified-images.json" <<'PY'
import json, sys
from pathlib import Path
p=Path(sys.argv[1]); data=json.loads(p.read_text())
data['images']['php82']['sourceRevision']='not-a-git-revision'
p.write_text(json.dumps(data))
PY
expect_rejected 'invalid per-image CI source provenance' 'Qualified PHP 8.2 CI image must record the exact 40-character source revision used to build that image.' "$tmp/image-source-revision.out"

copy_fixture
python3 - "$tmp/fixture/.forgejo/workflows/ci.yml" <<'PY'
from pathlib import Path
import sys
p=Path(sys.argv[1]); s=p.read_text()
old='["dom", "libxml", "mbstring", "xml", "xmlwriter", "zip"]'
if old not in s: raise SystemExit('qualified extension invariant missing')
p.write_text(s.replace(old, '["dom", "libxml", "mbstring", "xml", "xmlwriter"]', 1))
PY
expect_rejected 'PHP CI without ext-zip enforcement' 'Routine PHP CI must fail closed unless the qualified image provides ext-zip.' "$tmp/image-zip-extension.out"

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
start=s.index("\t\t'contributor' => [")
end=s.index("\n\t\t],\n\t\t'viewer'", start)
block=s[start:end]
needle="\t\t\tself::READING_CREATE,\n"
if needle not in block: raise SystemExit('contributor fixture marker missing')
block=block.replace(needle, needle+"\t\t\tself::READING_CORRECT,\n", 1)
p.write_text(s[:start]+block+s[end:])
PY
expect_rejected 'Contributor historical correction' 'Contributor must not configure meters or correct historical readings.' "$tmp/contributor-correct.out"

copy_fixture
sed -i "/'meters-readings',/d" "$tmp/fixture/lib/Capability.php"
expect_rejected 'meter/read feature removal' 'Capability discovery must advertise implemented feature meters-readings.' "$tmp/meter-feature.out"

copy_fixture
sed -i 's/schedule: none/schedule: disabled/g' "$tmp/fixture/docs/architecture.md" "$tmp/fixture/docs/domain-model.md" "$tmp/fixture/docs/product-architecture.md" "$tmp/fixture/docs/roadmap.md" "$tmp/fixture/docs/security.md" "$tmp/fixture/docs/api.md" "$tmp/fixture/AGENTS.md" "$tmp/fixture/README.md" || true
expect_rejected 'loss of schedule-none terminology' 'Architecture documentation must preserve schedule: none as the unscheduled work-definition policy.' "$tmp/schedule-none.out"


copy_fixture
python3 - "$tmp/fixture/lib/Service/WorkDefinitionService.php" <<'PY'
from pathlib import Path
import sys
p=Path(sys.argv[1]); s=p.read_text()
s=s.replace("foreach (['key', 'title', 'kind', 'schedule'] as $required)", "foreach (['key', 'title', 'kind'] as $required)", 1)
p.write_text(s)
PY
expect_rejected 'optional work-definition schedule' 'Work-definition creation must explicitly require schedule.' "$tmp/work-definition-schedule-required.out"

copy_fixture
python3 - "$tmp/fixture/schemas/profile-v2.schema.json" <<'PY'
import json, sys
from pathlib import Path
p=Path(sys.argv[1]); data=json.loads(p.read_text())
data['$defs']['workDefinition']['required'].remove('schedule')
p.write_text(json.dumps(data))
PY
expect_rejected 'profile-v2 without required schedule' 'Profile-v2 work definitions must require schedule.' "$tmp/profile-v2-schedule-required.out"

copy_fixture
python3 - "$tmp/fixture/lib/Service/WorkDefinitionService.php" <<'PY'
from pathlib import Path
import sys
p=Path(sys.argv[1]); s=p.read_text(); marker='public function create(WorkspaceContext $context, string $assetUuid, array $input): array {'
p.write_text(s.replace(marker, marker+"\n\t\t$scheduleFixture = $input['schedule'] ?? 'none';", 1))
PY
expect_rejected 'implicit schedule none default' 'Work-definition service must never infer schedule: none for a missing schedule.' "$tmp/work-definition-schedule-default.out"

copy_fixture
python3 - "$tmp/fixture/lib/Db/WorkDefinition.php" <<'PY2'
from pathlib import Path
import sys
p=Path(sys.argv[1]); s=p.read_text()
s=s.replace('protected ?string $scheduleType = null;', "protected ?string $scheduleType = 'none';", 1)
p.write_text(s)
PY2
expect_rejected 'entity-level implicit schedule none default' 'Work-definition entity must not carry an implicit schedule: none default.' "$tmp/work-definition-entity-schedule-default.out"

copy_fixture
python3 - "$tmp/fixture/lib/Db/WorkDefinition.php" <<'PY2'
from pathlib import Path
import sys
p=Path(sys.argv[1]); s=p.read_text()
s=s.replace('protected ?string $scheduleType = null;', 'protected string $scheduleType;', 1)
p.write_text(s)
PY2
expect_rejected 'uninitialized work-definition schedule property' 'Work-definition entity must use a null pre-persistence schedule sentinel so Nextcloud Entity setters never read an uninitialized typed property.' "$tmp/work-definition-entity-uninitialized-schedule.out"

copy_fixture
python3 - "$tmp/fixture/lib/Db/WorkScheduleRule.php" <<'PY'
from pathlib import Path
import sys
p=Path(sys.argv[1])
s=p.read_text()
s=s.replace('protected ?int $position = null;', 'protected int $position = 0;', 1)
p.write_text(s)
PY
expect_rejected 'work schedule rule zero position default' 'Work schedule rules must not default position to zero because Nextcloud Entity setters would omit the first rule position from inserts.' "$tmp/work-schedule-rule-position-default.out"

copy_fixture
python3 - "$tmp/fixture/lib/Service/AuthorizationCatalog.php" <<'PY'
from pathlib import Path
import sys
p=Path(sys.argv[1]); s=p.read_text()
start=s.index("\t\t'contributor' => [")
end=s.index("\n\t\t],\n\t\t'viewer'", start)
block=s[start:end]
needle="\t\t\tself::MAINTENANCE_DEFINITION_READ,"
if needle not in block: raise SystemExit('contributor work-definition fixture marker missing')
block=block.replace(needle, needle+"\n\t\t\tself::MAINTENANCE_DEFINITION_MANAGE,", 1)
p.write_text(s[:start]+block+s[end:])
PY
expect_rejected 'Contributor work-definition management' 'Contributor must read but not manage work definitions.' "$tmp/contributor-work-definition-manage.out"

copy_fixture
python3 - "$tmp/fixture/lib/Service/MeterService.php" <<'PY'
from pathlib import Path
import sys
p=Path(sys.argv[1]); s=p.read_text()
start=s.find("\t\tif ($this->scheduleRules->countActiveForMeter(")
if start < 0: raise SystemExit('meter schedule reference guard marker missing')
end=s.find("\n\t\t}", start)
if end < 0: raise SystemExit('meter schedule reference guard end missing')
p.write_text(s[:start]+s[end+4:])
PY
expect_rejected 'meter archive without work-definition reference guard' 'Active work-definition meter references must block meter archival.' "$tmp/meter-work-definition-guard.out"

copy_fixture
sed -i "/'maint_work_defs',/d" "$tmp/fixture/lib/Service/UserLifecycleService.php"
expect_rejected 'work-definition cleanup omission' 'Account deletion purge registry must cover workspace-scoped table maint_work_defs.' "$tmp/work-definition-purge.out"

copy_fixture
sed -i "/'work-definitions-schedules',/d" "$tmp/fixture/lib/Capability.php"
expect_rejected 'work-definition feature removal' 'Capability discovery must advertise implemented feature work-definitions-schedules.' "$tmp/work-definition-feature.out"


copy_fixture
python3 - "$tmp/fixture/lib/Service/AuthorizationCatalog.php" <<'PY2'
from pathlib import Path
import sys
p=Path(sys.argv[1]); s=p.read_text(); start=s.index("\t\t'contributor' => ["); end=s.index("\n\t\t],\n\t\t'viewer'", start); block=s[start:end]; needle="\t\t\tself::ACTIVITY_CREATE,"
if needle not in block: raise SystemExit('contributor activity marker missing')
block=block.replace(needle, needle+"\n\t\t\tself::ACTIVITY_MANAGE,", 1); p.write_text(s[:start]+block+s[end:])
PY2
expect_rejected 'Contributor activity management' 'Contributor must read/create but not manage activities.' "$tmp/contributor-activity-manage.out"

copy_fixture
python3 - "$tmp/fixture/lib/Db/ActivityItemMapper.php" <<'PY2'
from pathlib import Path
import sys
p=Path(sys.argv[1]); s=p.read_text(); marker='final class ActivityItemMapper {'; p.write_text(s.replace(marker, marker+" public function mutateForFixture(): void { $this->db->getQueryBuilder()->delete('maint_activity_items'); }",1))
PY2
expect_rejected 'mutable activity work items' 'Activity item mapper must remain append/read-only.' "$tmp/activity-item-mutable.out"

copy_fixture
python3 - "$tmp/fixture/lib/Service/ActivityService.php" <<'PY2'
from pathlib import Path
import sys
p=Path(sys.argv[1]); s=p.read_text(); s=s.replace("['summary','notes']", "['performedAt','summary','notes']", 1); p.write_text(s)
PY2
expect_rejected 'mutable activity execution time' 'Activity updates must not allow performedAt or immutable child facts to be rewritten.' "$tmp/activity-performed-at-mutable.out"

copy_fixture
python3 - "$tmp/fixture/lib/Service/ActivityService.php" <<'PY2'
from pathlib import Path
import sys
p=Path(sys.argv[1]); s=p.read_text(); s=s.replace("elseif($rawComponent===null&&$du===null&&$stored->getComponentUuid()!==null)return false;", "", 1); p.write_text(s)
PY2
expect_rejected 'activity retry component omission' 'Ad-hoc activity retries must not silently drop component context.' "$tmp/activity-component-retry.out"

copy_fixture
sed -i "/'maint_activity_items',/d" "$tmp/fixture/lib/Service/UserLifecycleService.php"
expect_rejected 'activity child cleanup omission' 'Account deletion purge registry must cover workspace-scoped table maint_activity_items.' "$tmp/activity-purge.out"

copy_fixture
sed -i "/'activity-ledger',/d" "$tmp/fixture/lib/Capability.php"
expect_rejected 'activity feature removal' 'Capability discovery must advertise implemented feature activity-ledger.' "$tmp/activity-feature.out"

copy_fixture
python3 - "$tmp/fixture/lib/Db/Activity.php" <<'PY2'
from pathlib import Path
import sys
p=Path(sys.argv[1]); s=p.read_text(); s=s.replace('protected ?int $performedAt=null;', 'protected int $performedAt=0;', 1).replace('protected ?int $performedAt = null;', 'protected int $performedAt = 0;', 1); p.write_text(s)
PY2
expect_rejected 'activity epoch-zero dirty-field regression' 'Activity performedAt must use a null pre-persistence sentinel so Unix epoch zero is written by Nextcloud QBMapper inserts.' "$tmp/activity-performed-at-sentinel.out"

copy_fixture
python3 - "$tmp/fixture/lib/Service/ReadingService.php" <<'PY2'
from pathlib import Path
import sys
p=Path(sys.argv[1]); s=p.read_text(); s=s.replace(', bool $allowArchivedMeter = false', '', 1).replace('$this->meters->find($context, $meterUuid, $allowArchivedMeter)', '$this->meters->find($context, $meterUuid)', 1); p.write_text(s)
PY2
expect_rejected 'offline activity ingestion without archived-meter reading support' 'Offline activity ingestion must be able to create a reading against a meter retired after field capture without relaxing the standalone reading endpoint.' "$tmp/activity-archived-meter.out"


copy_fixture
sed -i "/'maintenance-due-state',/d" "$tmp/fixture/lib/Capability.php"
expect_rejected 'maintenance due-state feature removal' 'Capability discovery must advertise implemented feature maintenance-due-state.' "$tmp/maintenance-status-feature.out"

copy_fixture
python3 - "$tmp/fixture/lib/Service/MaintenanceStatusService.php" <<'PY2'
from pathlib import Path
import sys
p=Path(sys.argv[1]); s=p.read_text(); marker='final class MaintenanceStatusService {'
p.write_text(s.replace(marker, marker+"\n\tpublic function persistFixture(): void { $this->definitions->update('maint_due_state'); }",1))
PY2
expect_rejected 'persisted maintenance due state' 'Maintenance due state must remain derived and must not persist mutable status rows.' "$tmp/maintenance-status-persisted.out"

copy_fixture
python3 - "$tmp/fixture/lib/Service/MaintenanceStatusService.php" <<'PY2'
from pathlib import Path
import sys
p=Path(sys.argv[1]); s=p.read_text(); s=s.replace("if (in_array('unknown', $states, true)) {\n\t\t\treturn 'unknown';", "if (in_array('unknown', $states, true)) {\n\t\t\treturn 'upcoming';",1); p.write_text(s)
PY2
expect_rejected 'unknown schedule rule reported as upcoming' 'combination:any status aggregation must prioritize overdue/due before unknown and must not falsely report upcoming with incomplete rule data.' "$tmp/maintenance-status-unknown.out"

copy_fixture
sed -i 's#assets/{assetUuid}/maintenance-status#assets/{assetUuid}/maintenance-summary#' "$tmp/fixture/lib/Controller/MaintenanceStatusController.php"
expect_rejected 'maintenance status endpoint removal' 'Maintenance status endpoint must remain read-only behind maintenance.definition.read.' "$tmp/maintenance-status-endpoint.out"


copy_fixture
sed -i "/'maintenance-occurrences',/d" "$tmp/fixture/lib/Capability.php"
expect_rejected 'maintenance occurrence feature removal' 'Capability discovery must advertise implemented feature maintenance-occurrences.' "$tmp/maintenance-occurrence-feature.out"

copy_fixture
sed -i 's/final class MaintenanceOccurrenceMapper extends QBMapper {/final class MaintenanceOccurrenceMapper extends QBMapper { private string $due_state = "fixture";/' "$tmp/fixture/lib/Db/MaintenanceOccurrenceMapper.php"
expect_rejected 'persisted occurrence due state' 'Occurrence mapper must not persist derived due dates, due state, or meter thresholds.' "$tmp/occurrence-due-state.out"

copy_fixture
sed -i 's#maintenance-occurrences/reconcile#maintenance-occurrences/refresh#' "$tmp/fixture/lib/Controller/MaintenanceOccurrenceController.php"
expect_rejected 'occurrence reconcile endpoint removal' 'Occurrence reconciliation must be an explicit serialized write operation.' "$tmp/occurrence-reconcile-endpoint.out"

copy_fixture
sed -i "/'maint_occurrences',/d" "$tmp/fixture/lib/Service/UserLifecycleService.php"
expect_rejected 'occurrence cleanup omission' 'Account deletion purge registry must cover workspace-scoped table maint_occurrences.' "$tmp/occurrence-purge.out"


copy_fixture
sed -i "/'profile-installation',/d" "$tmp/fixture/lib/Capability.php"
expect_rejected 'profile-installation feature removal' 'Capability discovery must advertise implemented feature profile-installation.' "$tmp/profile-feature.out"

copy_fixture
python3 - "$tmp/fixture/lib/Service/ProfileValidator.php" <<'PY2'
from pathlib import Path
import sys
p=Path(sys.argv[1]); s=p.read_text()
old="if ($input['schemaVersion'] !== 2)"
if old not in s: raise SystemExit('profile-v2 runtime guard marker missing')
p.write_text(s.replace(old, "if ($input['schemaVersion'] !== 1)", 1))
PY2
expect_rejected 'profile-v1 runtime reinterpretation' 'Runtime profile installation must explicitly reject non-v2 documents instead of silently mapping profile v1.' "$tmp/profile-v2-runtime.out"

copy_fixture
python3 - "$tmp/fixture/lib/Service/AuthorizationCatalog.php" <<'PY2'
from pathlib import Path
import sys
p=Path(sys.argv[1]); s=p.read_text(); start=s.index("\t\t'contributor' => ["); end=s.index("\n\t\t],\n\t\t'viewer'", start); block=s[start:end]; needle="\t\t\tself::PROFILE_READ,"
if needle not in block: raise SystemExit('contributor profile-read marker missing')
block=block.replace(needle, needle+"\n\t\t\tself::PROFILE_INSTALL,", 1); p.write_text(s[:start]+block+s[end:])
PY2
expect_rejected 'Contributor profile installation' 'Contributor must read profiles without installing them.' "$tmp/profile-contributor-install.out"

copy_fixture
sed -i "/'maint_prof_bind',/d" "$tmp/fixture/lib/Service/UserLifecycleService.php"
expect_rejected 'profile binding cleanup omission' 'Account deletion purge registry must cover workspace-scoped table maint_prof_bind.' "$tmp/profile-purge.out"

copy_fixture
sed -i 's#assets/{assetUuid}/profiles/install#assets/{assetUuid}/profiles/apply#' "$tmp/fixture/lib/Controller/ProfileController.php"
expect_rejected 'profile install endpoint removal' 'Profile controller must expose bundled catalog, validation, preview, and install endpoints.' "$tmp/profile-install-endpoint.out"

copy_fixture
python3 - "$tmp/fixture/lib/Service/ProfileValidator.php" <<'PY2'
from pathlib import Path
import sys
p=Path(sys.argv[1]); s=p.read_text(); marker='$this->positiveDecimal('
if marker not in s: raise SystemExit('profile decimal canonicalization marker missing')
p.write_text(s.replace(marker, '$this->rawProfileDecimal(', 1))
PY2
expect_rejected 'profile decimal canonicalization removal' 'Profile revision canonicalization must normalize meter-interval decimals and validate them through the canonical meter converter.' "$tmp/profile-decimal-canonicalization.out"

copy_fixture
sed -i 's/semantically equivalent encodings/representation variants/g' "$tmp/fixture/docs/profile-format.md"
expect_rejected 'profile decimal canonicalization documentation removal' 'Profile documentation must define representation-stable decimal canonicalization for content hashes.' "$tmp/profile-decimal-doc.out"

copy_fixture
sed -i 's#POST /profiles/validate#POST /profiles/check#' "$tmp/fixture/docs/api.md"
expect_rejected 'profile validation API documentation removal' 'API documentation must cover the complete v0.1.9 profile catalog/validation/preview/install/read surface.' "$tmp/profile-api-doc.out"

copy_fixture
sed -i 's/engine.oil_filter/engine.lube_filter/g' "$tmp/fixture/docs/fitment-pack-v1.md"
expect_rejected 'fitment standardized slot documentation removal' 'Fitment contract must define standardized slot identity and namespaced extensions.' "$tmp/fitment-slot-doc.out"

copy_fixture
sed -i 's/Conservative matching and explicit mapping/Automatic matching/' "$tmp/fixture/docs/fitment-pack-v1.md"
expect_rejected 'fitment explicit mapping contract removal' 'Fitment contract must require explicit mapping and preserve both source/local identity.' "$tmp/fitment-mapping-doc.out"

copy_fixture
sed -i 's/duplicate fitment tuple/duplicate compatibility row/' "$tmp/fixture/scripts/validate-fitment-packs.mjs"
expect_rejected 'fitment semantic duplicate guard removal' 'Fitment validator must enforce referential uniqueness, evidence, and extension-key rules.' "$tmp/fitment-validator-duplicate.out"

copy_fixture
sed -i 's/PR #11/PR #99/' "$tmp/fixture/docs/roadmap.md"
expect_rejected 'v0.1.9 closeout evidence removal' 'Roadmap must close v0.1.9 with PR/feature-CI/merge/main-CI evidence.' "$tmp/v019-closeout.out"


copy_fixture
sed -i "/'fitment-packs',/d" "$tmp/fixture/lib/Capability.php"
expect_rejected 'fitment feature removal' 'Capability discovery must advertise implemented feature fitment-packs.' "$tmp/fitment-feature.out"

copy_fixture
python3 - "$tmp/fixture/lib/Service/FitmentPackValidator.php" <<'PY2'
from pathlib import Path
import sys
p=Path(sys.argv[1]); s=p.read_text(); old="if ($input['schemaVersion'] !== 1)"
if old not in s: raise SystemExit('fitment v1 runtime marker missing')
p.write_text(s.replace(old, "if ($input['schemaVersion'] !== 2)", 1))
PY2
expect_rejected 'fitment schema reinterpretation' 'Runtime fitment validation must enforce exact schema v1 and the reviewed 8 MiB bound.' "$tmp/fitment-v1-runtime.out"

copy_fixture
python3 - "$tmp/fixture/lib/Service/AuthorizationCatalog.php" <<'PY2'
from pathlib import Path
import sys
p=Path(sys.argv[1]); s=p.read_text(); start=s.index("\t\t'contributor' => ["); end=s.index("\n\t\t],\n\t\t'viewer'", start); block=s[start:end]; needle="\t\t\tself::FITMENT_READ,"
if needle not in block: raise SystemExit('contributor fitment-read marker missing')
block=block.replace(needle, needle+"\n\t\t\tself::FITMENT_IMPORT,", 1); p.write_text(s[:start]+block+s[end:])
PY2
expect_rejected 'Contributor fitment import' 'Contributor must read fitment data without importing or mapping it.' "$tmp/fitment-contributor-import.out"

copy_fixture
sed -i "/'maint_fit_bind',/d" "$tmp/fixture/lib/Service/UserLifecycleService.php"
expect_rejected 'fitment binding cleanup omission' 'Account deletion purge registry must cover workspace-scoped table maint_fit_bind.' "$tmp/fitment-purge.out"

copy_fixture
sed -i 's#fitment-targets/{targetUuid}/map#fitment-targets/{targetUuid}/attach#' "$tmp/fixture/lib/Controller/FitmentController.php"
expect_rejected 'fitment map endpoint removal' 'Fitment controller must expose /fitment-targets/{targetUuid}/map.' "$tmp/fitment-map-endpoint.out"

copy_fixture
python3 - "$tmp/fixture/lib/Service/FitmentService.php" <<'PY2'
from pathlib import Path
import sys
p=Path(sys.argv[1]); s=p.read_text(); old="in_array($match['state'], ['conflict','insufficient'], true) && !$acceptConflict"
if old not in s: raise SystemExit('fitment conflict confirmation marker missing')
p.write_text(s.replace(old, 'false', 1))
PY2
expect_rejected 'fitment conflict confirmation removal' 'Conflict/insufficient target matching must require explicit Owner/Manager confirmation.' "$tmp/fitment-conflict-confirm.out"

copy_fixture
python3 - "$tmp/fixture/scripts/validate-fitment-packs.mjs" <<'PY2'
from pathlib import Path
import sys
p=Path(sys.argv[1]); s=p.read_text(); old="return [...values].sort(byteCompare)"
if old not in s: raise SystemExit('byte comparator marker missing')
p.write_text(s.replace(old, "return [...values].sort((a, b) => a.localeCompare(b, 'en'))", 1))
PY2
expect_rejected 'locale-sensitive fitment canonical ordering' 'JavaScript fitment canonical ordering must use explicit UTF-8 byte comparison rather than locale-sensitive sorting.' "$tmp/fitment-locale-order.out"

copy_fixture
python3 - "$tmp/fixture/lib/Service/FitmentService.php" <<'PY2'
from pathlib import Path
import sys
p=Path(sys.argv[1]); s=p.read_text(); old="throw new RevisionConflictException('This fitment pack revision is already imported under a different import UUID');"
if old not in s: raise SystemExit('import UUID alias guard missing')
p.write_text(s.replace(old, "return $this->importToApi($byUuid ?? [], $v['summary']);", 1))
PY2
expect_rejected 'fitment revision import UUID aliasing' 'The same fitment revision must not be aliased under a second import UUID.' "$tmp/fitment-import-alias.out"

copy_fixture
sed -i "s/'length' => 8388608/'length' => 65535/" "$tmp/fixture/lib/Migration/Version1090Date20260907193000.php"
expect_rejected 'undersized fitment revision JSON storage' 'Fitment revision JSON storage must retain the reviewed 8 MiB bound.' "$tmp/fitment-json-bound.out"

echo 'Project validator self-tests passed.'
