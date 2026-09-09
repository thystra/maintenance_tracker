/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { access, readdir, readFile } from 'node:fs/promises'

const projectUrl = 'https://forgejo.argentwolf.org/alan/maintenance_tracker_for_nextcloud'
const failures = []

const read = async (path) => readFile(path, 'utf8')
function expect(condition, message) {
	if (!condition) {
		failures.push(message)
	}
}

const info = await read('appinfo/info.xml')
const application = await read('lib/AppInfo/Application.php')
const composer = JSON.parse(await read('composer.json'))
const packageJson = JSON.parse(await read('package.json'))
const packageLock = JSON.parse(await read('package-lock.json'))
const genericProfile = JSON.parse(await read('profiles/generic-car.json'))
const profileSchema = JSON.parse(await read('schemas/profile-v1.schema.json'))
const profileSchemaV2 = JSON.parse(await read('schemas/profile-v2.schema.json'))
const fitmentVocabularySchema = JSON.parse(await read('schemas/fitment-vocabulary-v1.schema.json'))
const fitmentPackSchema = JSON.parse(await read('schemas/fitment-pack-v1.schema.json'))
const fitmentVocabulary = JSON.parse(await read('fitment/core-v1.json'))
const fitmentExample = JSON.parse(await read('fitment/examples/example-service-parts.json'))
const attributes = await read('.gitattributes')
const nextcloudIgnore = await read('.nextcloudignore')
const forgejoCi = await read('.forgejo/workflows/ci.yml')
const qualifiedImages = JSON.parse(await read('ci/images/qualified-images.json'))
const userLifecycle = await read('lib/Service/UserLifecycleService.php')
const workspaceService = await read('lib/Service/WorkspaceService.php')
const authorizationCatalog = await read('lib/Service/AuthorizationCatalog.php')
const auditMapper = await read('lib/Db/AuditMapper.php')
const auditService = await read('lib/Service/AuditService.php')
const auditEvents = await read('lib/Service/AuditEventCatalog.php')
const migration1030 = await read('lib/Migration/Version1030Date20260904000000.php')
const migration1040 = await read('lib/Migration/Version1040Date20260905000000.php')
const migration1050 = await read('lib/Migration/Version1050Date20260905020000.php')
const migration1060 = await read('lib/Migration/Version1060Date20260906090000.php')
const activityEntity = await read('lib/Db/Activity.php')
const activityService = await read('lib/Service/ActivityService.php')
const activityItemMapper = await read('lib/Db/ActivityItemMapper.php')
const activityMeterMapper = await read('lib/Db/ActivityMeterMapper.php')
const readingMapper = await read('lib/Db/ReadingMapper.php')
const readingService = await read('lib/Service/ReadingService.php')
const meterValueConverter = await read('lib/Service/MeterValueConverter.php')
const meterService = await read('lib/Service/MeterService.php')
const workDefinitionEntity = await read('lib/Db/WorkDefinition.php')
const workDefinitionService = await read('lib/Service/WorkDefinitionService.php')
const workSchedulePolicy = await read('lib/Service/WorkSchedulePolicy.php')
const workScheduleRuleEntity = await read('lib/Db/WorkScheduleRule.php')
const workScheduleRuleMapper = await read('lib/Db/WorkScheduleRuleMapper.php')
const dueStatePolicy = await read('lib/Service/DueStatePolicy.php')
const maintenanceStatusService = await read('lib/Service/MaintenanceStatusService.php')
const maintenanceStatusController = await read('lib/Controller/MaintenanceStatusController.php')
const migration1070 = await read('lib/Migration/Version1070Date20260907080000.php')
const forecastPolicy = await read('lib/Service/ForecastPolicy.php')
const maintenanceForecastService = await read('lib/Service/MaintenanceForecastService.php')
const maintenanceForecastController = await read('lib/Controller/MaintenanceForecastController.php')
const reminderPolicyService = await read('lib/Service/ReminderPolicyService.php')
const reminderPolicyController = await read('lib/Controller/ReminderPolicyController.php')
const occurrenceMapper = await read('lib/Db/MaintenanceOccurrenceMapper.php')
const occurrenceService = await read('lib/Service/MaintenanceOccurrenceService.php')
const occurrenceController = await read('lib/Controller/MaintenanceOccurrenceController.php')
const migration1080 = await read('lib/Migration/Version1080Date20260907130000.php')
const profileValidator = await read('lib/Service/ProfileValidator.php')
const profileCatalog = await read('lib/Service/ProfileCatalog.php')
const profileRepository = await read('lib/Service/ProfileRepository.php')
const profileInstallationService = await read('lib/Service/ProfileInstallationService.php')
const profileController = await read('lib/Controller/ProfileController.php')
const migration1090 = await read('lib/Migration/Version1090Date20260907193000.php')
const fitmentPackValidator = await read('lib/Service/FitmentPackValidator.php')
const fitmentRepository = await read('lib/Service/FitmentRepository.php')
const assetFitmentDescriptorService = await read('lib/Service/AssetFitmentDescriptorService.php')
const fitmentService = await read('lib/Service/FitmentService.php')
const fitmentController = await read('lib/Controller/FitmentController.php')
const validateProfiles = await read('scripts/validate-profiles.mjs')
const validateFitmentPacks = await read('scripts/validate-fitment-packs.mjs')
const capabilities = await read('lib/Capability.php')
const architecture = await read('docs/architecture.md')
const domainModel = await read('docs/domain-model.md')
const productArchitecture = await read('docs/product-architecture.md')
const roadmap = await read('docs/roadmap.md')
const security = await read('docs/security.md')
const api = await read('docs/api.md')
const profileFormat = await read('docs/profile-format.md')
const fitmentFormat = await read('docs/fitment-pack-v1.md')
const changelog = await read('CHANGELOG.md')
const agents = await read('AGENTS.md')
const readme = await read('README.md')

const controllerNames = (await readdir('lib/Controller')).filter((name) => name.endsWith('.php')).sort()
const controllerSources = await Promise.all(controllerNames.map((name) => read(`lib/Controller/${name}`)))
const allControllers = controllerSources.join('\n')

const migrationNames = (await readdir('lib/Migration')).filter((name) => name.endsWith('.php')).sort()
const workspaceTables = new Set()
for (const migrationName of migrationNames) {
	const migration = await read(`lib/Migration/${migrationName}`)
	const createTable = /#\[CreateTable\(\s*table:\s*'([^']+)',\s*columns:\s*\[([\s\S]*?)\],\s*description:/g
	for (const match of migration.matchAll(createTable)) {
		if (match[2].includes("'workspace_id'")) {
			workspaceTables.add(match[1])
		}
	}
}

const infoVersion = info.match(/<version>([^<]+)<\/version>/)?.[1]
const applicationVersion = application.match(/APP_VERSION\s*=\s*'([^']+)'/)?.[1]

expect(infoVersion !== undefined, 'appinfo/info.xml must declare an app version.')
expect(
	infoVersion === applicationVersion && infoVersion === packageJson.version && infoVersion === packageLock.version && infoVersion === packageLock.packages?.['']?.version,
	'App version must match appinfo/info.xml, Application::APP_VERSION, package.json, and package-lock.json.',
)
expect(
	composer.authors?.[0]?.homepage === 'https://forgejo.argentwolf.org/alan',
	'Composer author homepage must use the Forgejo profile.',
)
expect(info.includes(`<website>${projectUrl}</website>`), 'Nextcloud app website must use the authoritative Forgejo repository.')
expect(info.includes(`<repository>${projectUrl}</repository>`), 'Nextcloud app repository must use the authoritative Forgejo repository.')
expect(info.includes(`<bugs>${projectUrl}/issues</bugs>`), 'Nextcloud app issue URL must use Forgejo.')
expect(genericProfile.provenance?.sourceUrl === projectUrl, 'Bundled first-party profile provenance must use the authoritative repository URL.')
expect(profileSchema.$id === `${projectUrl}/src/branch/main/schemas/profile-v1.schema.json`, 'Profile schema identity must use the authoritative Forgejo repository URL.')
expect(profileSchemaV2.$id === `${projectUrl}/src/branch/main/schemas/profile-v2.schema.json`, 'Profile-v2 schema identity must use the authoritative Forgejo repository URL.')
expect(fitmentVocabularySchema.$id === `${projectUrl}/src/branch/main/schemas/fitment-vocabulary-v1.schema.json`, 'Fitment-vocabulary schema identity must use the authoritative Forgejo repository URL.')
expect(fitmentPackSchema.$id === `${projectUrl}/src/branch/main/schemas/fitment-pack-v1.schema.json`, 'Fitment-pack schema identity must use the authoritative Forgejo repository URL.')
expect(fitmentVocabulary.schemaVersion === 1 && fitmentPackSchema.properties?.schemaVersion?.const === 1, 'Fitment vocabulary/pack contracts must use explicit schema version 1.')
expect(fitmentExample.schemaVersion === 1 && fitmentExample.vocabularyVersion === 1, 'Bundled fitment example must use fitment-pack/vocabulary v1.')
expect(packageJson.scripts?.['validate:fitment'] === 'node scripts/validate-fitment-packs.mjs', 'package.json must expose the fitment contract validator.')
expect(genericProfile.schemaVersion === 2, 'Bundled generic-car profile must use profile schema v2.')
expect(attributes.includes('/.forgejo export-ignore'), '.gitattributes must exclude Forgejo contributor workflows from release archives.')
expect(attributes.includes('/AGENTS.md export-ignore'), '.gitattributes must exclude project agent guidance from release archives.')
expect(attributes.includes('/ci export-ignore'), '.gitattributes must exclude CI image definitions from release archives.')
expect(attributes.includes('/fitment/examples export-ignore'), '.gitattributes must exclude fitment example data from runtime release archives.')
expect(nextcloudIgnore.split(/\r?\n/).includes('/.forgejo'), '.nextcloudignore must exclude Forgejo contributor workflows.')
expect(nextcloudIgnore.split(/\r?\n/).includes('/AGENTS.md'), '.nextcloudignore must exclude project agent guidance.')
expect(nextcloudIgnore.split(/\r?\n/).includes('/ci'), '.nextcloudignore must exclude CI image definitions.')
expect(nextcloudIgnore.split(/\r?\n/).includes('/fitment/examples'), '.nextcloudignore must exclude fitment example data.')
expect(forgejoCi.includes('runs-on: forgejo-workstation'), 'Authoritative CI must target forgejo-workstation runners.')
expect(!forgejoCi.includes('runs-on: ubuntu-latest'), 'Authoritative Forgejo CI must not target GitHub-hosted ubuntu-latest runners.')
expect(forgejoCi.includes('https://data.forgejo.org/actions/checkout@'), 'Authoritative CI checkout must use an explicitly Forgejo-hosted action URL.')
expect(qualifiedImages.schemaVersion === 2, 'Qualified CI image metadata must use the supported schema version.')
for (const key of ['php82', 'php85', 'nextcloud']) {
	expect(qualifiedImages.images?.[key] !== undefined, `Qualified CI image metadata must define ${key}.`)
}
for (const [key, image] of Object.entries(qualifiedImages.images ?? {})) {
	const label = image.label ?? key
	const repository = image.tag?.slice(0, image.tag.lastIndexOf(':'))
	expect(/^[0-9a-f]{40}$/.test(image.sourceRevision ?? ''), `Qualified ${label} CI image must record the exact 40-character source revision used to build that image.`)
	expect(/^sha256:[0-9a-f]{64}$/.test(image.digest ?? ''), `Qualified ${label} CI image must record a sha256 registry digest.`)
	expect(image.reference === `${repository}@${image.digest}`, `Qualified ${label} CI image reference must bind its repository to its recorded digest.`)
	expect(forgejoCi.includes(image.reference), `Routine CI must pin the qualified ${label} image digest.`)
	expect(!forgejoCi.includes(image.tag), `Routine CI must not consume the mutable ${label} image tag.`)
}
expect(forgejoCi.includes('[\"dom\", \"libxml\", \"mbstring\", \"xml\", \"xmlwriter\", \"zip\"]'), 'Routine PHP CI must fail closed unless the qualified image provides ext-zip.')
expect(!forgejoCi.includes('shivammathur/setup-php'), 'Routine CI must use the qualified PHP images instead of rebuilding PHP with setup-php.')
expect(!forgejoCi.includes('Install Docker client'), 'Routine Nextcloud CI must use the qualified Docker-client image instead of reinstalling Docker.')
expect(!forgejoCi.includes('npm audit'), 'Network-dependent npm advisory checks must remain outside deterministic CI.')

// Authorization architecture: capabilities, not role ranks.
expect(!workspaceService.includes('ROLE_RANK'), 'Workspace authorization must not restore the legacy role-rank gate.')
expect(!workspaceService.includes('runWithAccess('), 'Workspace authorization must use capability gates, not runWithAccess role gates.')
expect(!allControllers.includes('runWithAccess('), 'Controllers must authorize through capabilities, not raw role names.')
expect(!/runWithCapability\([\s\S]{0,500}?'(?:viewer|editor|owner|manager|contributor)'/.test(allControllers), 'Controller capability calls must not pass workspace role names.')
expect(authorizationCatalog.includes("if ($role === 'editor')") && authorizationCatalog.includes("return 'manager';"), 'Legacy editor role compatibility must normalize to manager.')

const managerMatch = authorizationCatalog.match(/'manager'\s*=>\s*\[([\s\S]*?)\n\s*\],\n\s*'contributor'/)
expect(managerMatch !== null, 'Authorization catalog must define an explicit Manager capability bundle.')
expect(managerMatch !== null && !managerMatch[1].includes('WORKSPACE_MEMBERS_MANAGE'), 'Manager must not receive workspace.members.manage.')
const contributorMatch = authorizationCatalog.match(/'contributor'\s*=>\s*\[([\s\S]*?)\n\s*\],\n\s*'viewer'/)
const viewerMatch = authorizationCatalog.match(/'viewer'\s*=>\s*\[([\s\S]*?)\n\s*\],/)
expect(managerMatch !== null && managerMatch[1].includes('METER_MANAGE') && managerMatch[1].includes('READING_CREATE') && managerMatch[1].includes('READING_CORRECT'), 'Manager must explicitly receive meter management, reading creation, and reading correction.')
expect(contributorMatch !== null && contributorMatch[1].includes('METER_READ') && contributorMatch[1].includes('READING_CREATE'), 'Contributor must be able to read meters and create readings.')
expect(contributorMatch !== null && !contributorMatch[1].includes('METER_MANAGE') && !contributorMatch[1].includes('READING_CORRECT'), 'Contributor must not configure meters or correct historical readings.')
expect(viewerMatch !== null && viewerMatch[1].includes('METER_READ') && !viewerMatch[1].includes('READING_CREATE'), 'Viewer must remain read-only for meters/readings.')
expect(managerMatch !== null && managerMatch[1].includes('MAINTENANCE_DEFINITION_READ') && managerMatch[1].includes('MAINTENANCE_DEFINITION_MANAGE'), 'Manager must explicitly receive work-definition read/manage capabilities.')
expect(contributorMatch !== null && contributorMatch[1].includes('MAINTENANCE_DEFINITION_READ') && !contributorMatch[1].includes('MAINTENANCE_DEFINITION_MANAGE'), 'Contributor must read but not manage work definitions.')
expect(viewerMatch !== null && viewerMatch[1].includes('MAINTENANCE_DEFINITION_READ') && !viewerMatch[1].includes('MAINTENANCE_DEFINITION_MANAGE'), 'Viewer must read but not manage work definitions.')
expect(managerMatch !== null && managerMatch[1].includes('ACTIVITY_READ') && managerMatch[1].includes('ACTIVITY_CREATE') && managerMatch[1].includes('ACTIVITY_MANAGE'), 'Manager must explicitly receive activity read/create/manage capabilities.')
expect(contributorMatch !== null && contributorMatch[1].includes('ACTIVITY_READ') && contributorMatch[1].includes('ACTIVITY_CREATE') && !contributorMatch[1].includes('ACTIVITY_MANAGE'), 'Contributor must read/create but not manage activities.')
expect(viewerMatch !== null && viewerMatch[1].includes('ACTIVITY_READ') && !viewerMatch[1].includes('ACTIVITY_CREATE') && !viewerMatch[1].includes('ACTIVITY_MANAGE'), 'Viewer must be read-only for activities.')
expect(managerMatch !== null && managerMatch[1].includes('MAINTENANCE_OCCURRENCE_RECONCILE') && managerMatch[1].includes('REMINDER_POLICY_MANAGE'), 'Manager must be able to reconcile occurrences and manage reminder policy.')
expect(contributorMatch !== null && contributorMatch[1].includes('MAINTENANCE_FORECAST_READ') && contributorMatch[1].includes('MAINTENANCE_OCCURRENCE_READ') && !contributorMatch[1].includes('MAINTENANCE_OCCURRENCE_RECONCILE') && !contributorMatch[1].includes('REMINDER_POLICY_MANAGE'), 'Contributor must read forecast/occurrences without managing the projection policy.')
expect(viewerMatch !== null && viewerMatch[1].includes('MAINTENANCE_FORECAST_READ') && viewerMatch[1].includes('MAINTENANCE_OCCURRENCE_READ') && viewerMatch[1].includes('REMINDER_POLICY_READ') && !viewerMatch[1].includes('MAINTENANCE_OCCURRENCE_RECONCILE'), 'Viewer must be read-only for forecast, occurrences, and reminder policy.')
expect(managerMatch !== null && managerMatch[1].includes('PROFILE_READ') && managerMatch[1].includes('PROFILE_INSTALL'), 'Manager must explicitly receive profile read/install capabilities.')
expect(contributorMatch !== null && contributorMatch[1].includes('PROFILE_READ') && !contributorMatch[1].includes('PROFILE_INSTALL'), 'Contributor must read profiles without installing them.')
expect(viewerMatch !== null && viewerMatch[1].includes('PROFILE_READ') && !viewerMatch[1].includes('PROFILE_INSTALL'), 'Viewer must remain read-only for profiles.')
expect(managerMatch !== null && managerMatch[1].includes('FITMENT_READ') && managerMatch[1].includes('FITMENT_IMPORT') && managerMatch[1].includes('FITMENT_MAP'), 'Manager must explicitly receive fitment read/import/map capabilities.')
expect(contributorMatch !== null && contributorMatch[1].includes('FITMENT_READ') && !contributorMatch[1].includes('FITMENT_IMPORT') && !contributorMatch[1].includes('FITMENT_MAP'), 'Contributor must read fitment data without importing or mapping it.')
expect(viewerMatch !== null && viewerMatch[1].includes('FITMENT_READ') && !viewerMatch[1].includes('FITMENT_IMPORT') && !viewerMatch[1].includes('FITMENT_MAP'), 'Viewer must remain read-only for fitment data.')

for (const capability of [
	'maintenance_definition.*',
	'activity.*',
	'evidence.*',
	'checkout.*',
	'retention.manage',
	'report.share.create',
	'report.share.revoke',
	'external_submission.read',
	'external_submission.review',
	'workspace.settings.manage',
	'workspace.delete',
]) {
	expect(authorizationCatalog.includes(`'${capability}' => ['implemented' => false`), `Reserved capability ${capability} must remain present and unimplemented.`)
}
for (const feature of ['capability-authorization', 'workspace-membership', 'append-only-audit', 'meters-readings', 'work-definitions-schedules', 'activity-ledger', 'maintenance-due-state', 'maintenance-forecast-policy', 'maintenance-occurrences', 'profile-installation', 'fitment-packs']) {
	expect(capabilities.includes(`'${feature}'`), `Capability discovery must advertise implemented feature ${feature}.`)
}

// Audit is append-only, bounded, versioned, and cleanup-aware.
expect(!auditMapper.includes('->update(') && !auditMapper.includes('->delete('), 'Audit mapper must remain append/read-only.')
expect(auditService.includes('MAX_DETAILS_BYTES = 4096'), 'Audit detail storage must retain the reviewed 4096-byte bound.')
for (const eventType of [
	'asset.created',
	'asset.updated',
	'asset.archived',
	'category.created',
	'component.created',
	'specification.created',
	'relationship.created',
	'relationship.updated',
	'relationship.archived',
	'assignment.created',
	'assignment.updated',
	'assignment.archived',
	'meter.created',
	'meter.updated',
	'meter.archived',
	'reading.created',
	'reading.corrected',
	'work_group.created',
	'work_group.updated',
	'work_group.archived',
	'work_definition.created',
	'work_definition.updated',
	'work_definition.archived',
	'activity.created',
	'activity.updated',
	'activity.archived',
	'workspace.member.added',
	'workspace.member.role_changed',
	'workspace.member.removed',
]) {
	expect(auditEvents.includes(`'${eventType}'`), `Audit event vocabulary must retain ${eventType}.`)
}
expect(auditEvents.includes("'profile.installed'") && auditEvents.includes("'profileKey', 'profileVersion', 'contentHash'"), 'Profile installation audit events must retain bounded provenance detail keys.')
expect(auditEvents.includes("'fitment_pack.imported'") && auditEvents.includes("'packKey', 'packVersion', 'contentHash'"), 'Fitment import audit events must retain bounded pack provenance detail keys.')
expect(auditEvents.includes("'fitment_mapping.created'") && auditEvents.includes("'targetKey', 'matchState'"), 'Fitment mapping audit events must retain bounded target/match detail keys.')
expect(migration1030.includes("table: 'maint_audit'"), 'v0.1.3 migration must create the audit table.')
expect(migration1030.includes("createNamedParameter('manager'") && migration1030.includes("createNamedParameter('editor'"), 'v0.1.3 migration must persist editor-to-manager role normalization.')

expect(migration1040.includes("table: 'maint_meters'") && migration1040.includes("table: 'maint_readings'"), 'v0.1.4 migration must create meter and reading tables.')
expect(!readingMapper.includes('extends QBMapper'), 'Reading mapper must not inherit mutable QBMapper update/delete methods.')
expect(!readingMapper.includes('->update(') && !readingMapper.includes('->delete('), 'Reading mapper must remain append/read-only.')
expect(readingMapper.includes('public function append(') && readingMapper.includes("->insert('maint_readings')"), 'Reading mapper must expose an explicit append persistence path.')
expect(meterValueConverter.includes("'distance'") && meterValueConverter.includes("'runtime'") && meterValueConverter.includes("'usage_count'"), 'Meter conversion must retain the reviewed initial dimensions.')
expect(meterValueConverter.includes("'mi' => 1609344") && meterValueConverter.includes("'hour' => 3600"), 'Meter canonical conversion factors must retain exact mile-to-mm and hour-to-second factors.')
expect(meterValueConverter.includes('MAX_CANONICAL_VALUE = 9007199254740991'), 'Meter canonical values must remain within the JavaScript JSON safe-integer range.')
expect(meterService.includes('!$meter->getMonotonic() && $monotonic') && meterService.includes('$this->assertHistoryCanBeMonotonic($meter);'), 'Enabling monotonic mode must validate all existing effective readings first.')

expect(migration1050.includes("table: 'maint_work_groups'") && migration1050.includes("table: 'maint_work_defs'") && migration1050.includes("table: 'maint_work_sched'"), 'v0.1.5 migration must create work-group, work-definition, and schedule-rule tables.')
expect(workDefinitionService.includes("foreach (['key', 'title', 'kind', 'schedule'] as $required)"), 'Work-definition creation must explicitly require schedule.')
expect(workDefinitionService.includes('throw new ValidationException("{$required} is required")'), 'Missing work-definition schedule must be rejected explicitly.')
expect(!workDefinitionService.includes("['schedule'] ?? 'none'"), 'Work-definition service must never infer schedule: none for a missing schedule.')
expect(!workDefinitionEntity.includes("$scheduleType = 'none'"), 'Work-definition entity must not carry an implicit schedule: none default.')
expect(workDefinitionEntity.includes('protected ?string $scheduleType = null;'), 'Work-definition entity must use a null pre-persistence schedule sentinel so Nextcloud Entity setters never read an uninitialized typed property.')
expect(workScheduleRuleEntity.includes('protected ?int $position = null;'), 'Work schedule rules must use a null pre-persistence position sentinel so zero is written by Nextcloud QBMapper inserts.')
expect(!workScheduleRuleEntity.includes('protected int $position = 0;'), 'Work schedule rules must not default position to zero because Nextcloud Entity setters would omit the first rule position from inserts.')
expect(migration1050.includes("addColumn('schedule_type', Types::STRING, ['notnull' => true"), 'Persisted work definitions must require a non-null schedule type.')
expect(workSchedulePolicy.includes("if ($schedule === 'none')") && workSchedulePolicy.includes("'combination'] !== 'any'"), 'Schedule policy must retain explicit none and reviewed any/OR semantics.')
expect(workSchedulePolicy.includes("'calendar'") && workSchedulePolicy.includes("'business_days'") && workSchedulePolicy.includes("'meter'"), 'Schedule policy must retain calendar, business-day, and meter rule types.')
expect(workScheduleRuleMapper.includes('countActiveForMeter') && meterService.includes('countActiveForMeter') && meterService.includes('Meter is referenced by an active work-definition schedule'), 'Active work-definition meter references must block meter archival.')
const profileV2WorkDefinition = profileSchemaV2.$defs?.workDefinition
expect(profileV2WorkDefinition?.required?.includes('schedule') === true, 'Profile-v2 work definitions must require schedule.')
expect(profileSchemaV2.$defs?.schedulePolicy?.oneOf?.some((entry) => entry.const === 'none') === true, 'Profile-v2 schedule policy must explicitly support schedule: none.')

expect(migration1060.includes("table: 'maint_activities'") && migration1060.includes("table: 'maint_activity_items'") && migration1060.includes("table: 'maint_activity_meters'"), 'v0.1.6 migration must create activity header, work-item, and meter-snapshot tables.')
expect(activityEntity.includes('protected ?int $performedAt=null;') || activityEntity.includes('protected ?int $performedAt = null;'), 'Activity performedAt must use a null pre-persistence sentinel so Unix epoch zero is written by Nextcloud QBMapper inserts.')
expect(migration1060.includes("addColumn('component_name', Types::STRING"), 'Activity work items must persist a component-name snapshot for historical display.')
expect(activityService.includes('setComponentName($component?->getName())') && activityService.includes("'componentName'=>$r->getComponentName()"), 'Activity work items must capture and expose the component display-name snapshot.')
expect(readingService.includes('bool $allowArchivedMeter = false') && activityService.includes('$this->readings->create($c,$meter->getUuid(),$payload,true)'), 'Offline activity ingestion must be able to create a reading against a meter retired after field capture without relaxing the standalone reading endpoint.')
expect(activityService.includes("['uuid','value','unit','notes'],'activity-created reading'"), 'Activity-created reading payloads must reject caller-supplied observation/source overrides and unknown fields.')
expect(activityService.includes("['summary','notes']") && !activityService.includes("['performedAt','summary','notes']"), 'Activity updates must not allow performedAt or immutable child facts to be rewritten.')
expect(activityService.includes('elseif($rawComponent===null&&$du===null&&$stored->getComponentUuid()!==null)return false;'), 'Ad-hoc activity retries must not silently drop component context.')
expect(activityService.includes('activity-created readings require an explicit unit'), 'Activity-created meter readings must require an explicit unit.')
expect(activityService.includes("source']=['type'=>'activity','reference'=>$a->getUuid()]") || activityService.includes("['type'=>'activity','reference'=>$a->getUuid()]"), 'Activity-created readings must carry activity source provenance.')
expect(!activityItemMapper.includes('->update(') && !activityItemMapper.includes('->delete('), 'Activity item mapper must remain append/read-only.')
expect(!activityMeterMapper.includes('->update(') && !activityMeterMapper.includes('->delete('), 'Activity meter snapshot mapper must remain append/read-only.')

// v0.1.7 maintenance status is a deterministic read-time projection, never stored truth.
expect(maintenanceStatusController.includes('/maintenance-status') && maintenanceStatusController.includes('MAINTENANCE_DEFINITION_READ'), 'Maintenance status endpoint must remain read-only behind maintenance.definition.read.')
expect(!maintenanceStatusService.includes('->insert(') && !maintenanceStatusService.includes('->update(') && !maintenanceStatusService.includes('->delete('), 'Maintenance due state must remain derived and must not persist mutable status rows.')
expect(maintenanceStatusService.includes("'baseline_required'") && maintenanceStatusService.includes("'unknown'") && maintenanceStatusService.includes("'overdue'"), 'Maintenance status projection must retain baseline-required, unknown, and overdue states.')
expect(maintenanceStatusService.includes("if (in_array('overdue', $states, true))") && maintenanceStatusService.includes("if (in_array('due', $states, true))") && maintenanceStatusService.includes("if (in_array('unknown', $states, true)) {\n\t\t\treturn 'unknown';"), 'combination:any status aggregation must prioritize overdue/due before unknown and must not falsely report upcoming with incomplete rule data.')
expect(maintenanceStatusService.includes('findEffectivePredecessor') && maintenanceStatusService.includes('findForActivity'), 'Maintenance status must derive meter baselines/current values from effective readings and immutable activity snapshots.')
expect(dueStatePolicy.includes("format('t')") && !dueStatePolicy.includes('cal_days_in_month'), 'Calendar due-state arithmetic must clamp month/year boundaries without depending on the optional PHP calendar extension.')
expect(dueStatePolicy.includes('$fullWeeks = intdiv($interval, $daysPerWeek);'), 'Business-day due-state calculation must skip complete weeks instead of iterating unbounded configured intervals day-by-day.')

// v0.1.8 forecast policy and materialized work queue sit on top of derived due truth.
expect(migration1070.includes("table: 'maint_reminder_policy'") && migration1070.includes("table: 'maint_occurrences'"), 'v0.1.8 migration must create reminder-policy and maintenance-occurrence tables.')
expect(migration1070.includes("addUniqueIndex(['workspace_id','definition_id','open_marker']"), 'Occurrence storage must enforce at most one open row per work definition.')
expect(forecastPolicy.includes("'due_soon'") && forecastPolicy.includes("'calendar_lead'") && forecastPolicy.includes("'meter_lead'"), 'Forecast policy must keep due-soon classification in the policy layer.')
expect(maintenanceForecastService.includes('MaintenanceStatusService') && maintenanceForecastService.includes('ForecastPolicy'), 'Forecasting must consume the v0.1.7 derived status projection rather than reimplement due truth.')
expect(maintenanceForecastController.includes('/maintenance-forecast') && maintenanceForecastController.includes('MAINTENANCE_FORECAST_READ'), 'Maintenance forecast endpoint must remain read-only behind maintenance_forecast.read.')
expect(reminderPolicyService.includes('DEFAULT_CALENDAR_LEAD_DAYS = 14') && reminderPolicyService.includes('DEFAULT_METER_LEAD_PERCENT = 10'), 'Reminder policy must expose explicit reviewed defaults rather than hidden client heuristics.')
expect(reminderPolicyController.includes('/reminder-policy') && reminderPolicyController.includes('REMINDER_POLICY_MANAGE'), 'Reminder policy must expose an explicit authorized management endpoint.')
expect(occurrenceController.includes('/maintenance-occurrences/reconcile') && occurrenceController.includes('MAINTENANCE_OCCURRENCE_RECONCILE'), 'Occurrence reconciliation must be an explicit serialized write operation.')
expect(occurrenceService.includes('No due date, threshold, or due-state value is copied into the occurrence row.'), 'Occurrence materialization must not persist maintenance truth.')
expect(!occurrenceMapper.includes('due_on') && !occurrenceMapper.includes('due_state') && !occurrenceMapper.includes('canonical_value'), 'Occurrence mapper must not persist derived due dates, due state, or meter thresholds.')

// v0.1.9 validates profile-v2 input and materializes it only through canonical domain services.
for (const table of ['maint_profiles', 'maint_prof_revs', 'maint_asset_prof', 'maint_prof_bind']) {
	expect(migration1080.includes(`table: '${table}'`), `v0.1.9 migration must create ${table}.`)
}
expect(migration1080.includes("addUniqueIndex(['workspace_id','asset_id']"), 'v0.1.9 must permit at most one materialized profile installation per asset.')
expect(migration1080.includes("addUniqueIndex(['workspace_id','installation_uuid']"), 'Profile installation UUIDs must be unique per workspace for idempotent retries.')
expect(profileValidator.includes('MAX_PROFILE_BYTES = 1048576'), 'Runtime profile validation must retain the reviewed 1 MiB encoded-size bound.')
expect(profileValidator.includes("if ($input['schemaVersion'] !== 2)") && profileValidator.includes('schemaVersion must be 2 for runtime installation'), 'Runtime profile installation must explicitly reject non-v2 documents instead of silently mapping profile v1.')
expect(profileValidator.includes("hash('sha256', $canonicalJson)"), 'Profile revision identity must use canonical JSON with SHA-256 content hashing.')
expect(profileValidator.includes('$this->positiveDecimal(') && profileValidator.includes('$this->meterValues->toCanonical('), 'Profile revision canonicalization must normalize meter-interval decimals and validate them through the canonical meter converter.')
expect(validateProfiles.includes('const displayUnits =') && validateProfiles.includes('const meterIntervalUnits =') && validateProfiles.includes("usage_count: new Set(['use', 'count'])"), 'Repository profile validation must distinguish usage-count display units from accepted schedule interval units.')
expect(profileCatalog.includes("hash_equals($v['contentHash'], $contentHash)") && profileCatalog.includes("'trustState' => 'first_party'"), 'First-party profile trust must require an exact bundled content-hash match.')
expect(profileInstallationService.includes('Profile contains part definitions that cannot be materialized until the parts subsystem is implemented'), 'v0.1.9 profile installation must fail closed for part definitions until canonical parts persistence exists.')
for (const serviceCall of ['$this->components->create(', '$this->meters->create(', '$this->groups->create(', '$this->definitions->create(', '$this->assets->update(']) {
	expect(profileInstallationService.includes(serviceCall), `Profile installation must materialize through canonical domain service call ${serviceCall}.`)
}
expect(profileInstallationService.includes('runtimeSchedule(') && profileInstallationService.includes("'meterUuid'=>$meterUuids[$r['meterKey']]"), 'Profile meterKey schedule references must resolve to actual materialized meter UUIDs.')
expect(profileRepository.includes("insert('maint_prof_revs')") && profileRepository.includes("insert('maint_prof_bind')"), 'Profile repository must persist immutable revision snapshots and source bindings.')
expect(profileController.includes("url: '/api/v1/profiles'") && profileController.includes("url: '/api/v1/profiles/validate'") && profileController.includes("url: '/api/v1/assets/{assetUuid}/profiles/preview'") && profileController.includes("url: '/api/v1/assets/{assetUuid}/profiles/install'"), 'Profile controller must expose bundled catalog, validation, preview, and install endpoints.')
expect(profileController.includes('AuthorizationCatalog::PROFILE_READ') && profileController.includes('AuthorizationCatalog::PROFILE_INSTALL'), 'Profile API must preserve the read/install capability split.')
expect(genericProfile.version === '0.3.0', 'Bundled generic-car profile must use the reviewed v0.1.9 profile revision 0.3.0.')
for (const key of ['inspect_tires', 'rotate_tires', 'inspect_wipers']) {
	const definition = genericProfile.workDefinitions?.find((item) => item.key === key)
	expect(definition !== undefined && !Object.hasOwn(definition, 'componentKey'), `Generic ${key} must remain asset-scoped because its source component template is multi-instance.`)
}

// v0.1.10 JSON fitment runtime materializes portable compatibility knowledge without silently mapping it to local assets.
for (const table of ['maint_fit_packs', 'maint_fit_revs', 'maint_fit_imports', 'maint_fit_bind', 'maint_fit_targets', 'maint_fit_slots', 'maint_parts', 'maint_offers', 'maint_fitments', 'maint_asset_fit']) {
	expect(migration1090.includes(`table: '${table}'`), `v0.1.10 fitment migration must create ${table}.`)
}
expect(migration1090.includes("addColumn('content_json', Types::TEXT, ['notnull' => true, 'length' => 8388608])"), 'Fitment revision JSON storage must retain the reviewed 8 MiB bound.')
expect(migration1090.includes("addUniqueIndex(['workspace_id','revision_id']") && migration1090.includes("addUniqueIndex(['workspace_id','import_uuid']"), 'Fitment imports must enforce one imported operation per immutable revision and unique client operation UUIDs.')
expect(migration1090.includes("addColumn('active_marker', Types::STRING, ['notnull' => false") && migration1090.includes("addUniqueIndex(['workspace_id','target_id','active_marker']"), 'Fitment target mappings must use the nullable-active-marker uniqueness contract for one active mapping per target.')
expect(fitmentPackValidator.includes('MAX_PACK_BYTES = 8 * 1024 * 1024') && fitmentPackValidator.includes("if ($input['schemaVersion'] !== 1)"), 'Runtime fitment validation must enforce exact schema v1 and the reviewed 8 MiB bound.')
expect(fitmentPackValidator.includes('partIdentityHash(') && fitmentPackValidator.includes('normalizeIdentity($manufacturer)') && fitmentPackValidator.includes('normalizeIdentity($partNumber)'), 'Canonical part identity must conservatively retain part-number punctuation.')
expect(fitmentPackValidator.includes("$verification === 'verified' && $evidence === []") && fitmentPackValidator.includes('requires evidence'), 'Verified fitment assertions must require evidence.')
expect(validateFitmentPacks.includes('function byteCompare(') && validateFitmentPacks.includes('Buffer.compare') && !validateFitmentPacks.includes('localeCompare'), 'JavaScript fitment canonical ordering must use explicit UTF-8 byte comparison rather than locale-sensitive sorting.')
expect(fitmentService.includes('already imported under a different import UUID') && fitmentService.includes('findImportByRevision('), 'The same fitment revision must not be aliased under a second import UUID.')
expect(fitmentService.includes('already has an active local-asset mapping under a different mapping UUID') && fitmentService.includes('findActiveMappingForTarget('), 'An active target/asset mapping must not be aliased under a second mapping UUID.')
expect(fitmentService.includes("in_array($match['state'], ['conflict','insufficient'], true) && !$acceptConflict") && fitmentService.includes('acceptConflict=true'), 'Conflict/insufficient target matching must require explicit Owner/Manager confirmation.')
expect(assetFitmentDescriptorService.includes("'state' => $state") && assetFitmentDescriptorService.includes("'candidate'") && assetFitmentDescriptorService.includes("'insufficient'"), 'Asset fitment matching must expose reasoned exact/candidate/conflict/insufficient states.')
expect(assetFitmentDescriptorService.includes("'qualifiers' => []") && !assetFitmentDescriptorService.includes('getSerialNumber()') && !assetFitmentDescriptorService.includes('getNotes()'), 'Local fitment descriptors must exclude private unit identity and notes.')
for (const route of ['/fitment-packs/validate', '/fitment-packs/preview', '/fitment-packs/import', '/fitment-packs/{importUuid}/export', '/fitment-packs/{importUuid}/targets', '/fitment-targets/{targetUuid}/matches', '/fitment-targets/{targetUuid}/map', '/assets/{assetUuid}/fitments', '/assets/{assetUuid}/fitment-export/community']) {
	expect(fitmentController.includes(route), `Fitment controller must expose ${route}.`)
}
expect(fitmentController.includes('AuthorizationCatalog::FITMENT_READ') && fitmentController.includes('AuthorizationCatalog::FITMENT_IMPORT') && fitmentController.includes('AuthorizationCatalog::FITMENT_MAP'), 'Fitment API must preserve the read/import/map capability split.')
expect(fitmentRepository.includes("insert('maint_fit_revs')") && fitmentRepository.includes("insert('maint_fit_bind')") && fitmentRepository.includes("insert('maint_fitments')"), 'Fitment repository must persist immutable revision provenance, source bindings, and source-specific fitment assertions.')
expect(fitmentService.includes("'offers' => []") && fitmentService.includes("'omitted_pending_explicit_selection'"), 'Community fitment export must omit offers until an explicit reviewed offer-selection policy exists.')
expect(fitmentService.includes('$v = $this->validator->validate($pack)') && fitmentService.includes('communityExport('), 'Community fitment export must be rebuilt through the same runtime validator/canonicalizer.')
expect(profileInstallationService.includes('Profile contains part definitions that cannot be materialized until the parts subsystem is implemented'), 'This JSON fitment checkpoint must keep profile-v2 part materialization fail-closed until the canonical profile-parts bridge is implemented.')

for (const table of [...workspaceTables].sort()) {
	expect(userLifecycle.includes(`'${table}'`), `Account deletion purge registry must cover workspace-scoped table ${table}.`)
}
expect(userLifecycle.includes('$this->serializeWorkspacePurge($workspaceId);'), 'Account deletion must serialize each personal workspace before purging child rows.')
expect(userLifecycle.includes('runForActiveUsers(') && userLifecycle.includes('sort($userUids, SORT_STRING);'), 'Multi-user lifecycle locks must be acquired through deterministic UID ordering.')
const assetPurgePosition = userLifecycle.indexOf("'maint_assets'")
for (const table of ['maint_asset_fit', 'maint_fitments', 'maint_offers', 'maint_fit_bind', 'maint_fit_targets', 'maint_fit_imports', 'maint_fit_revs', 'maint_fit_packs', 'maint_parts', 'maint_fit_slots', 'maint_prof_bind', 'maint_asset_prof', 'maint_prof_revs', 'maint_profiles', 'maint_occurrences', 'maint_reminder_policy', 'maint_activity_meters', 'maint_activity_items', 'maint_activities', 'maint_work_sched', 'maint_work_defs', 'maint_work_groups', 'maint_readings', 'maint_meters', 'maint_assignments', 'maint_relationships', 'maint_specs', 'maint_components', 'maint_categories', 'maint_changes', 'maint_audit']) {
	expect(userLifecycle.indexOf(`'${table}'`) !== -1 && userLifecycle.indexOf(`'${table}'`) < assetPurgePosition, `${table} must be purged before maint_assets.`)
}

// Documentation vocabulary is part of the contract while these subsystems are designed.
const docs = [architecture, domainModel, productArchitecture, roadmap, security, api, agents, readme].join('\n')
expect(docs.includes('schedule: none'), 'Architecture documentation must preserve schedule: none as the unscheduled work-definition policy.')
expect(docs.includes('Owner') && docs.includes('Manager') && docs.includes('Contributor') && docs.includes('Viewer'), 'Documentation must describe the four workspace roles.')
expect(productArchitecture.includes('Vue') && productArchitecture.includes('Capacitor'), 'Mobile architecture must remain Vue offline-first PWA with Capacitor packaging.')
expect(!roadmap.includes('Kotlin/Compose') && !roadmap.includes('Jetpack Compose') && !roadmap.includes('Room entities'), 'Roadmap must not restore the obsolete native Kotlin/Compose/Room client plan.')
expect(security.includes('append-only audit'), 'Security documentation must describe the append-only audit boundary.')
expect(api.includes('GET /audit') && api.includes('/members'), 'API documentation must cover audit and workspace membership endpoints.')
expect(domainModel.includes('distance -> millimetres (`mm`)') && domainModel.includes('runtime/engine hours -> seconds (`s`)') && domainModel.includes('usage/event counts -> integer count (`count`)'), 'Domain documentation must define integer canonical meter units.')
expect(domainModel.includes('supersede') && security.includes('append-only observations'), 'Documentation must preserve immutable reading correction-by-supersession semantics.')
expect(api.includes('/meters/{meterUuid}/readings') && api.includes('/readings/{readingUuid}/corrections'), 'API documentation must cover meter readings and immutable corrections.')
expect(roadmap.includes('[x] v0.1.4') && roadmap.includes('CI #13') && roadmap.includes('v0.1.5'), 'Roadmap must record qualified v0.1.4 and the v0.1.5 work-definition tranche.')
expect(docs.includes('missing') && docs.includes('never') && docs.includes('schedule'), 'Documentation must state that missing schedule is invalid and never implicitly defaulted.')
expect(api.includes('/work-definitions') && api.includes('/work-groups'), 'API documentation must cover work groups and work definitions.')
expect(api.includes('/activities') && domainModel.includes('maint_activities') && domainModel.includes('maint_activity_items') && domainModel.includes('maint_activity_meters'), 'Documentation must cover the activity execution ledger.')
expect(docs.includes('immutable') && docs.includes('activity'), 'Documentation must preserve immutable activity execution-fact semantics.')
expect(api.includes('/maintenance-status') && docs.includes('baseline_required') && docs.includes('unknown'), 'Documentation must cover the derived maintenance-status projection and incomplete-baseline states.')
expect(docs.includes('not persisted') || docs.includes('never written') || docs.includes('not stored'), 'Documentation must state that maintenance due state is derived rather than persisted.')
expect(api.includes('/maintenance-forecast') && api.includes('/maintenance-occurrences') && api.includes('/reminder-policy'), 'API documentation must cover v0.1.8 forecasting, occurrence reconciliation, and reminder policy.')
expect(docs.includes('due_soon') && docs.includes('one open occurrence'), 'Documentation must describe policy-layer due-soon classification and one-open-occurrence materialization.')
expect(api.includes('GET /profiles') && api.includes('POST /profiles/validate') && api.includes('/profiles/preview') && api.includes('/profiles/install') && api.includes('/profile-installation'), 'API documentation must cover the complete v0.1.9 profile catalog/validation/preview/install/read surface.')
expect(api.includes('profile.read') && api.includes('profile.install') && api.includes('installationUuid'), 'API documentation must describe profile authorization and idempotent installation UUID semantics.')
expect(profileFormat.includes('canonicalizes the normalized profile') && profileFormat.includes('SHA-256') && architecture.includes('maint_prof_revs') && domainModel.includes('maint_prof_bind'), 'Profile documentation must preserve immutable revision hashing and source-binding provenance.')
expect(profileFormat.includes('semantically equivalent encodings') && architecture.includes('representation-only numeric differences') && domainModel.includes('Representation-only decimal differences'), 'Profile documentation must define representation-stable decimal canonicalization for content hashes.')
expect(security.includes('Profile installation boundary') && security.includes('does not fetch') && security.includes('profile.install'), 'Security documentation must describe profile trust, no-fetch, and write-capability boundaries.')
expect(roadmap.includes('[x] v0.1.8') && roadmap.includes('PR #10') && roadmap.includes('CI #22') && roadmap.includes('v0.1.9 validated local profile installation'), 'Roadmap must close qualified v0.1.8 and identify the v0.1.9 profile-installation tranche.')
expect(changelog.includes('v0.1.9') && readme.includes('profile-v2 installation'), 'User-facing documentation must advertise the v0.1.9 profile-installation capability.')
expect(docs.includes('part') && docs.includes('v0.1.10') && docs.includes('fail'), 'Documentation must state that v0.1.9 does not silently discard profile part definitions.')
expect(roadmap.includes('[x] v0.1.9') && roadmap.includes('PR #11') && roadmap.includes('CI #23') && roadmap.includes('CI #24') && roadmap.includes('79fc23158c29240c63ad315bcfd0100881f98608'), 'Roadmap must close v0.1.9 with PR/feature-CI/merge/main-CI evidence.')
expect(roadmap.includes('portable fitment packs') && roadmap.includes('compatibility matrix import/export'), 'Roadmap v0.1.10 must include portable fitment import/export rather than only local part rows.')
expect(fitmentFormat.includes('Stable slot identity') && fitmentFormat.includes('engine.oil_filter') && fitmentFormat.includes('reverse-DNS-style'), 'Fitment contract must define standardized slot identity and namespaced extensions.')
expect(fitmentFormat.includes('portable `assetClass`') && fitmentPackSchema.$defs?.equipment?.properties?.assetClass?.enum?.includes('vehicle'), 'Fitment equipment matching must use portable assetClass rather than workspace-local category identity.')
expect(fitmentFormat.includes('8 MiB') && fitmentFormat.includes('representation-stable') && fitmentFormat.includes('Reordering rows'), 'Fitment contract must define bounded, row-order-stable canonical source identity.')
expect(validateFitmentPacks.includes('MAX_PACK_BYTES = 8 * 1024 * 1024') && validateFitmentPacks.includes('identifier namespace'), 'Fitment validator must enforce the v1 source-size and external-identifier namespace boundaries.')
expect(fitmentFormat.includes('Conservative matching and explicit mapping') && fitmentFormat.includes('Owner/Manager') && fitmentFormat.includes('do not rename either'), 'Fitment contract must require explicit mapping and preserve both source/local identity.')
expect(fitmentFormat.includes('Community export') && fitmentFormat.includes('UUIDs') && fitmentFormat.includes('VINs or serial numbers'), 'Fitment community export must define privacy-minimized output.')
expect(fitmentFormat.includes('CSV/ZIP') && fitmentFormat.includes('formula injection') && fitmentFormat.includes('path traversal'), 'Fitment CSV/ZIP contract must cover spreadsheet and archive safety.')
expect(security.includes('Fitment-pack boundary') && security.includes('formula-injection') && security.includes('never fetched server-side'), 'Security documentation must enforce the fitment import/export trust boundary.')
expect(architecture.includes('Fitment interoperability') && domainModel.includes('standardized **fitment slot**') && productArchitecture.includes('compatibility matrices'), 'Architecture/domain/product docs must carry the fitment interoperability model.')
expect(agents.includes('v0.1.10 fitment interoperability invariant') && agents.includes('Core slot/qualifier keys') && agents.includes('are append-only'), 'AGENTS must preserve fitment interoperability invariants.')
expect(validateFitmentPacks.includes('duplicate fitment tuple') && validateFitmentPacks.includes('verified fitment') && validateFitmentPacks.includes('reverse-DNS-style extension'), 'Fitment validator must enforce referential uniqueness, evidence, and extension-key rules.')
expect(api.includes('/fitment-packs/validate') && api.includes('/fitment-packs/import') && api.includes('/fitment-targets/{targetUuid}/map') && api.includes('/fitment-export/community'), 'API documentation must cover the implemented v0.1.10 JSON fitment validation/import/mapping/export surface.')
expect(docs.includes('JSON') && docs.includes('fitment') && docs.includes('CSV/ZIP') && (docs.includes('pending') || docs.includes('not yet implemented')), 'Documentation must distinguish implemented JSON fitment runtime from pending CSV/ZIP runtime support.')
expect(roadmap.includes('[ ] v0.1.10') || roadmap.includes('10. [ ]'), 'Roadmap must keep v0.1.10 open while CSV/ZIP, profile-part materialization, activity parts, vendors, and costs remain pending.')
expect(changelog.includes('0.1.10') && readme.includes('fitment'), 'User-facing documentation must describe the v0.1.10 JSON fitment foundation without claiming the whole tranche complete.')

try {
	await access('.github/workflows/ci.yml')
	failures.push('GitHub mirror must not retain a competing automatic CI workflow.')
} catch {
	// Expected: the downstream mirror has no authoritative CI workflow.
}

if (failures.length > 0) {
	for (const failure of failures) {
		console.error(`ERROR: ${failure}`)
	}
	process.exitCode = 1
} else {
	console.log(`Project metadata/authority validation passed (${infoVersion}).`)
}
