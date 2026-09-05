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
const readingMapper = await read('lib/Db/ReadingMapper.php')
const meterValueConverter = await read('lib/Service/MeterValueConverter.php')
const meterService = await read('lib/Service/MeterService.php')
const capabilities = await read('lib/Capability.php')
const architecture = await read('docs/architecture.md')
const domainModel = await read('docs/domain-model.md')
const productArchitecture = await read('docs/product-architecture.md')
const roadmap = await read('docs/roadmap.md')
const security = await read('docs/security.md')
const api = await read('docs/api.md')
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
expect(attributes.includes('/.forgejo export-ignore'), '.gitattributes must exclude Forgejo contributor workflows from release archives.')
expect(attributes.includes('/AGENTS.md export-ignore'), '.gitattributes must exclude project agent guidance from release archives.')
expect(attributes.includes('/ci export-ignore'), '.gitattributes must exclude CI image definitions from release archives.')
expect(nextcloudIgnore.split(/\r?\n/).includes('/.forgejo'), '.nextcloudignore must exclude Forgejo contributor workflows.')
expect(nextcloudIgnore.split(/\r?\n/).includes('/AGENTS.md'), '.nextcloudignore must exclude project agent guidance.')
expect(nextcloudIgnore.split(/\r?\n/).includes('/ci'), '.nextcloudignore must exclude CI image definitions.')
expect(forgejoCi.includes('runs-on: forgejo-workstation'), 'Authoritative CI must target forgejo-workstation runners.')
expect(!forgejoCi.includes('runs-on: ubuntu-latest'), 'Authoritative Forgejo CI must not target GitHub-hosted ubuntu-latest runners.')
expect(forgejoCi.includes('https://data.forgejo.org/actions/checkout@'), 'Authoritative CI checkout must use an explicitly Forgejo-hosted action URL.')
expect(qualifiedImages.schemaVersion === 1, 'Qualified CI image metadata must use the supported schema version.')
expect(/^[0-9a-f]{40}$/.test(qualifiedImages.sourceRevision ?? ''), 'Qualified CI image metadata must record the exact 40-character source revision.')
for (const key of ['php82', 'php85', 'nextcloud']) {
	expect(qualifiedImages.images?.[key] !== undefined, `Qualified CI image metadata must define ${key}.`)
}
for (const [key, image] of Object.entries(qualifiedImages.images ?? {})) {
	const label = image.label ?? key
	const repository = image.tag?.slice(0, image.tag.lastIndexOf(':'))
	expect(/^sha256:[0-9a-f]{64}$/.test(image.digest ?? ''), `Qualified ${label} CI image must record a sha256 registry digest.`)
	expect(image.reference === `${repository}@${image.digest}`, `Qualified ${label} CI image reference must bind its repository to its recorded digest.`)
	expect(forgejoCi.includes(image.reference), `Routine CI must pin the qualified ${label} image digest.`)
	expect(!forgejoCi.includes(image.tag), `Routine CI must not consume the mutable ${label} image tag.`)
}
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
for (const feature of ['capability-authorization', 'workspace-membership', 'append-only-audit', 'meters-readings']) {
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
	'workspace.member.added',
	'workspace.member.role_changed',
	'workspace.member.removed',
]) {
	expect(auditEvents.includes(`'${eventType}'`), `Audit event vocabulary must retain ${eventType}.`)
}
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

for (const table of [...workspaceTables].sort()) {
	expect(userLifecycle.includes(`'${table}'`), `Account deletion purge registry must cover workspace-scoped table ${table}.`)
}
expect(userLifecycle.includes('$this->serializeWorkspacePurge($workspaceId);'), 'Account deletion must serialize each personal workspace before purging child rows.')
expect(userLifecycle.includes('runForActiveUsers(') && userLifecycle.includes('sort($userUids, SORT_STRING);'), 'Multi-user lifecycle locks must be acquired through deterministic UID ordering.')
const assetPurgePosition = userLifecycle.indexOf("'maint_assets'")
for (const table of ['maint_readings', 'maint_meters', 'maint_assignments', 'maint_relationships', 'maint_specs', 'maint_components', 'maint_categories', 'maint_changes', 'maint_audit']) {
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
expect(roadmap.includes('[x] v0.1.3') && roadmap.includes('v0.1.4 meters'), 'Roadmap must record qualified v0.1.3 and the v0.1.4 meter/readings tranche.')

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
