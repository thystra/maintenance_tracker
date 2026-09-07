/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import addFormats from 'ajv-formats'
import Ajv2020 from 'ajv/dist/2020.js'
import { readdir, readFile } from 'node:fs/promises'
import { resolve } from 'node:path'

const profilesPath = resolve('profiles')
const profileNames = (await readdir(profilesPath, { withFileTypes: true }))
	.filter((entry) => entry.isFile() && entry.name.endsWith('.json'))
	.map((entry) => entry.name)
	.sort()

if (profileNames.length === 0) {
	throw new Error('No profile JSON files were found.')
}

const ajv = new Ajv2020({ allErrors: true, strict: true })
addFormats(ajv)
const validators = new Map()
for (const version of [1, 2]) {
	const schema = JSON.parse(await readFile(resolve(`schemas/profile-v${version}.schema.json`), 'utf8'))
	validators.set(version, ajv.compile(schema))
}

const displayUnits = {
	distance: new Set(['mi', 'km', 'm', 'mm']),
	runtime: new Set(['hour', 'min', 's']),
	usage_count: new Set(['use']),
}

const meterIntervalUnits = {
	distance: new Set(['mi', 'km', 'm', 'mm']),
	runtime: new Set(['hour', 'min', 's']),
	usage_count: new Set(['use', 'count']),
}

function duplicates(values) {
	const seen = new Set()
	return values.filter((value) => {
		if (seen.has(value)) {
			return true
		}
		seen.add(value)
		return false
	})
}

function semanticErrorsV2(profile) {
	const errors = []
	const meterByKey = new Map(profile.meters.map((meter) => [meter.key, meter]))
	const componentKeys = new Set(profile.components.map((component) => component.key))
	const componentByKey = new Map(profile.components.map((component) => [component.key, component]))
	const partKeys = new Set(profile.parts.map((part) => part.key))
	const groupKeys = new Set(profile.workGroups.map((group) => group.key))

	for (const [label, values] of [
		['meter', profile.meters.map((item) => item.key)],
		['component', profile.components.map((item) => item.key)],
		['part', profile.parts.map((item) => item.key)],
		['work group', profile.workGroups.map((item) => item.key)],
		['work definition', profile.workDefinitions.map((item) => item.key)],
	]) {
		for (const key of duplicates(values)) {
			errors.push(`duplicate ${label} key: ${key}`)
		}
	}

	for (const meter of profile.meters) {
		if (!displayUnits[meter.dimension]?.has(meter.displayUnit)) {
			errors.push(`meter ${meter.key} displayUnit is incompatible with ${meter.dimension}`)
		}
	}
	for (const component of profile.components) {
		if (component.parentKey && !componentKeys.has(component.parentKey)) {
			errors.push(`component ${component.key} references unknown parentKey ${component.parentKey}`)
		} else if (component.parentKey && componentByKey.get(component.parentKey)?.quantity !== 1) {
			errors.push(`component ${component.key} parentKey ${component.parentKey} is ambiguous because parent quantity is greater than one`)
		}
		for (const partKey of component.compatiblePartKeys) {
			if (!partKeys.has(partKey)) {
				errors.push(`component ${component.key} references unknown part ${partKey}`)
			}
		}
	}

	for (const component of profile.components) {
		const seen = new Set([component.key])
		let current = component
		while (current?.parentKey) {
			if (seen.has(current.parentKey)) {
				errors.push(`component parent cycle includes ${component.key}`)
				break
			}
			seen.add(current.parentKey)
			current = componentByKey.get(current.parentKey)
		}
	}

	for (const definition of profile.workDefinitions) {
		if (!Object.hasOwn(definition, 'schedule')) {
			errors.push(`work definition ${definition.key} is missing required schedule`)
		}
		if (definition.groupKey && !groupKeys.has(definition.groupKey)) {
			errors.push(`work definition ${definition.key} references unknown group ${definition.groupKey}`)
		}
		if (definition.componentKey && !componentKeys.has(definition.componentKey)) {
			errors.push(`work definition ${definition.key} references unknown component ${definition.componentKey}`)
		} else if (definition.componentKey && componentByKey.get(definition.componentKey)?.quantity !== 1) {
			errors.push(`work definition ${definition.key} component ${definition.componentKey} is ambiguous because component quantity is greater than one`)
		}
		for (const partKey of definition.compatiblePartKeys) {
			if (!partKeys.has(partKey)) {
				errors.push(`work definition ${definition.key} references unknown part ${partKey}`)
			}
		}
		if (definition.schedule === 'none') {
			continue
		}
		for (const rule of definition.schedule.rules) {
			if (rule.type !== 'meter') {
				continue
			}
			const meter = meterByKey.get(rule.meterKey)
			if (!meter) {
				errors.push(`work definition ${definition.key} references unknown meter ${rule.meterKey}`)
				continue
			}
			if (!meterIntervalUnits[meter.dimension]?.has(rule.interval.unit)) {
				errors.push(`work definition ${definition.key} uses ${rule.interval.unit} with ${meter.dimension} meter ${rule.meterKey}`)
			}
		}
	}
	return errors
}

let invalid = false
for (const profileName of profileNames) {
	const profilePath = resolve(profilesPath, profileName)
	const profile = JSON.parse(await readFile(profilePath, 'utf8'))
	const validate = validators.get(profile.schemaVersion)
	if (!validate) {
		invalid = true
		console.error(`${profileName}: unsupported schemaVersion ${String(profile.schemaVersion)}`)
		continue
	}

	if (!validate(profile)) {
		invalid = true
		console.error(`${profileName}: ${ajv.errorsText(validate.errors, { separator: '\n' })}`)
		continue
	}

	const semanticErrors = profile.schemaVersion === 2 ? semanticErrorsV2(profile) : []
	if (semanticErrors.length > 0) {
		invalid = true
		console.error(`${profileName}: ${semanticErrors.join('\n')}`)
		continue
	}
	console.log(`${profileName}: valid`)
}

if (invalid) {
	process.exitCode = 1
}
