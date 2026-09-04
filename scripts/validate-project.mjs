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
const genericProfile = JSON.parse(await read('profiles/generic-car.json'))
const profileSchema = JSON.parse(await read('schemas/profile-v1.schema.json'))
const attributes = await read('.gitattributes')
const nextcloudIgnore = await read('.nextcloudignore')
const forgejoCi = await read('.forgejo/workflows/ci.yml')
const qualifiedImages = JSON.parse(await read('ci/images/qualified-images.json'))
const userLifecycle = await read('lib/Service/UserLifecycleService.php')
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
	infoVersion === applicationVersion && infoVersion === packageJson.version,
	'App version must match appinfo/info.xml, Application::APP_VERSION, and package.json.',
)
expect(
	composer.authors?.[0]?.homepage === 'https://forgejo.argentwolf.org/alan',
	'Composer author homepage must use the Forgejo profile.',
)
expect(
	info.includes(`<website>${projectUrl}</website>`),
	'Nextcloud app website must use the authoritative Forgejo repository.',
)
expect(
	info.includes(`<repository>${projectUrl}</repository>`),
	'Nextcloud app repository must use the authoritative Forgejo repository.',
)
expect(
	info.includes(`<bugs>${projectUrl}/issues</bugs>`),
	'Nextcloud app issue URL must use Forgejo.',
)
expect(
	genericProfile.provenance?.sourceUrl === projectUrl,
	'Bundled first-party profile provenance must use the authoritative repository URL.',
)
expect(
	profileSchema.$id === `${projectUrl}/src/branch/main/schemas/profile-v1.schema.json`,
	'Profile schema identity must use the authoritative Forgejo repository URL.',
)
expect(
	attributes.includes('/.forgejo export-ignore'),
	'.gitattributes must exclude Forgejo contributor workflows from release archives.',
)
expect(
	attributes.includes('/AGENTS.md export-ignore'),
	'.gitattributes must exclude project agent guidance from release archives.',
)
expect(
	attributes.includes('/ci export-ignore'),
	'.gitattributes must exclude CI image definitions from release archives.',
)
expect(
	nextcloudIgnore.split(/\r?\n/).includes('/.forgejo'),
	'.nextcloudignore must exclude Forgejo contributor workflows.',
)
expect(
	nextcloudIgnore.split(/\r?\n/).includes('/AGENTS.md'),
	'.nextcloudignore must exclude project agent guidance.',
)
expect(
	nextcloudIgnore.split(/\r?\n/).includes('/ci'),
	'.nextcloudignore must exclude CI image definitions.',
)
expect(
	forgejoCi.includes('runs-on: forgejo-workstation'),
	'Authoritative CI must target forgejo-workstation runners.',
)
expect(
	!forgejoCi.includes('runs-on: ubuntu-latest'),
	'Authoritative Forgejo CI must not target GitHub-hosted ubuntu-latest runners.',
)
expect(
	forgejoCi.includes('https://data.forgejo.org/actions/checkout@'),
	'Authoritative CI checkout must use an explicitly Forgejo-hosted action URL.',
)
expect(
	qualifiedImages.schemaVersion === 1,
	'Qualified CI image metadata must use the supported schema version.',
)
expect(
	/^[0-9a-f]{40}$/.test(qualifiedImages.sourceRevision ?? ''),
	'Qualified CI image metadata must record the exact 40-character source revision.',
)
const requiredQualifiedImages = ['php82', 'php85', 'nextcloud']
for (const key of requiredQualifiedImages) {
	expect(
		qualifiedImages.images?.[key] !== undefined,
		`Qualified CI image metadata must define ${key}.`,
	)
}
for (const [key, image] of Object.entries(qualifiedImages.images ?? {})) {
	const label = image.label ?? key
	const repository = image.tag?.slice(0, image.tag.lastIndexOf(':'))
	expect(
		/^sha256:[0-9a-f]{64}$/.test(image.digest ?? ''),
		`Qualified ${label} CI image must record a sha256 registry digest.`,
	)
	expect(
		image.reference === `${repository}@${image.digest}`,
		`Qualified ${label} CI image reference must bind its repository to its recorded digest.`,
	)
	expect(
		forgejoCi.includes(image.reference),
		`Routine CI must pin the qualified ${label} image digest.`,
	)
	expect(
		!forgejoCi.includes(image.tag),
		`Routine CI must not consume the mutable ${label} image tag.`,
	)
}
expect(
	!forgejoCi.includes('shivammathur/setup-php'),
	'Routine CI must use the qualified PHP images instead of rebuilding PHP with setup-php.',
)
expect(
	!forgejoCi.includes('Install Docker client'),
	'Routine Nextcloud CI must use the qualified Docker-client image instead of reinstalling Docker.',
)
expect(
	!forgejoCi.includes('npm audit'),
	'Network-dependent npm advisory checks must remain outside deterministic CI.',
)

for (const table of [...workspaceTables].sort()) {
	expect(
		userLifecycle.includes(`'${table}'`),
		`Account deletion purge registry must cover workspace-scoped table ${table}.`,
	)
}
expect(
	userLifecycle.includes('$this->serializeWorkspacePurge($workspaceId);'),
	'Account deletion must serialize each personal workspace before purging child rows.',
)
const assetPurgePosition = userLifecycle.indexOf("'maint_assets'")
for (const table of ['maint_assignments', 'maint_relationships', 'maint_specs', 'maint_components', 'maint_categories', 'maint_changes']) {
	expect(
		userLifecycle.indexOf(`'${table}'`) !== -1
		&& userLifecycle.indexOf(`'${table}'`) < assetPurgePosition,
		`${table} must be purged before maint_assets.`,
	)
}

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
