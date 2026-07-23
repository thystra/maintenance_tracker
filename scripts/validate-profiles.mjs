/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import addFormats from 'ajv-formats'
import Ajv2020 from 'ajv/dist/2020.js'
import { readdir, readFile } from 'node:fs/promises'
import { resolve } from 'node:path'

const schemaPath = resolve('schemas/profile-v1.schema.json')
const profilesPath = resolve('profiles')
const schema = JSON.parse(await readFile(schemaPath, 'utf8'))
const profileNames = (await readdir(profilesPath, { withFileTypes: true }))
	.filter((entry) => entry.isFile() && entry.name.endsWith('.json'))
	.map((entry) => entry.name)
	.sort()

if (profileNames.length === 0) {
	throw new Error('No profile JSON files were found.')
}

const ajv = new Ajv2020({
	allErrors: true,
	strict: true,
})
addFormats(ajv)

const validate = ajv.compile(schema)
let invalid = false

for (const profileName of profileNames) {
	const profilePath = resolve(profilesPath, profileName)
	const profile = JSON.parse(await readFile(profilePath, 'utf8'))

	if (validate(profile)) {
		console.log(`${profileName}: valid`)
		continue
	}

	invalid = true
	console.error(`${profileName}: ${ajv.errorsText(validate.errors, {
		separator: '\n',
	})}`)
}

if (invalid) {
	process.exitCode = 1
}
