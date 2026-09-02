/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { access, readFile } from 'node:fs/promises'

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
	nextcloudIgnore.split(/\r?\n/).includes('/.forgejo'),
	'.nextcloudignore must exclude Forgejo contributor workflows.',
)
expect(
	nextcloudIgnore.split(/\r?\n/).includes('/AGENTS.md'),
	'.nextcloudignore must exclude project agent guidance.',
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
	!forgejoCi.includes('npm audit'),
	'Network-dependent npm advisory checks must remain outside deterministic CI.',
)

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
