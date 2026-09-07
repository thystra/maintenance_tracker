/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import addFormats from 'ajv-formats'
import Ajv2020 from 'ajv/dist/2020.js'
import { createHash } from 'node:crypto'
import { readdir, readFile } from 'node:fs/promises'
import { resolve } from 'node:path'

const MAX_PACK_BYTES = 8 * 1024 * 1024

const ajv = new Ajv2020({ allErrors: true, strict: true })
addFormats(ajv)

const vocabularySchema = JSON.parse(await readFile(resolve('schemas/fitment-vocabulary-v1.schema.json'), 'utf8'))
const packSchema = JSON.parse(await readFile(resolve('schemas/fitment-pack-v1.schema.json'), 'utf8'))
const validateVocabulary = ajv.compile(vocabularySchema)
const validatePack = ajv.compile(packSchema)

const vocabulary = JSON.parse(await readFile(resolve('fitment/core-v1.json'), 'utf8'))
if (!validateVocabulary(vocabulary)) {
	throw new Error(`fitment/core-v1.json: ${ajv.errorsText(validateVocabulary.errors, { separator: '\n' })}`)
}

function normalize(value) {
	return value.normalize('NFKC').trim().replace(/\s+/gu, ' ').toLocaleLowerCase('en-US')
}

function duplicateValues(values) {
	const seen = new Set()
	const duplicates = []
	for (const value of values) {
		if (seen.has(value)) {
			duplicates.push(value)
		} else {
			seen.add(value)
		}
	}
	return duplicates
}

function isReverseDnsNamespace(key) {
	return /^[a-z0-9][a-z0-9-]*(?:\.[a-z0-9][a-z0-9-]*)+$/u.test(key)
}

function isNamespacedExtension(key) {
	return /^[a-z0-9][a-z0-9-]*(?:\.[a-z0-9][a-z0-9_-]*){2,}$/u.test(key)
}

function sortStrings(values) {
	return [...values].sort((a, b) => a.localeCompare(b, 'en'))
}

function sortedObject(value) {
	if (Array.isArray(value)) {
		return value.map(sortedObject)
	}
	if (value === null || typeof value !== 'object') {
		return value
	}
	return Object.fromEntries(Object.keys(value).sort().map((key) => [key, sortedObject(value[key])]))
}

function canonicalPack(pack) {
	const normalized = structuredClone(pack)
	for (const equipment of normalized.equipment) {
		equipment.aliases.manufacturer = sortStrings(equipment.aliases.manufacturer)
		equipment.aliases.model = sortStrings(equipment.aliases.model)
		equipment.identifiers.sort((a, b) => `${a.namespace}\u0000${a.value}`.localeCompare(`${b.namespace}\u0000${b.value}`, 'en'))
		for (const qualifier of equipment.qualifiers) {
			qualifier.aliases = sortStrings(qualifier.aliases)
		}
		equipment.qualifiers.sort((a, b) => a.key.localeCompare(b.key, 'en'))
	}
	normalized.equipment.sort((a, b) => a.key.localeCompare(b.key, 'en'))
	for (const slot of normalized.slots) {
		slot.aliases = sortStrings(slot.aliases)
	}
	normalized.slots.sort((a, b) => a.key.localeCompare(b.key, 'en'))
	normalized.parts.sort((a, b) => a.key.localeCompare(b.key, 'en'))
	normalized.offers.sort((a, b) => a.key.localeCompare(b.key, 'en'))
	for (const fitment of normalized.fitments) {
		fitment.evidence.sort((a, b) => `${a.kind}\u0000${a.referenceUrl ?? ''}\u0000${a.note ?? ''}`.localeCompare(`${b.kind}\u0000${b.referenceUrl ?? ''}\u0000${b.note ?? ''}`, 'en'))
	}
	normalized.fitments.sort((a, b) => `${a.equipmentKey}\u0000${a.slotKey}\u0000${a.partKey}`.localeCompare(`${b.equipmentKey}\u0000${b.slotKey}\u0000${b.partKey}`, 'en'))
	return JSON.stringify(sortedObject(normalized))
}

function contentHash(pack) {
	return createHash('sha256').update(canonicalPack(pack), 'utf8').digest('hex')
}

function reversedSetOrder(pack) {
	const copy = structuredClone(pack)
	copy.equipment.reverse()
	for (const equipment of copy.equipment) {
		equipment.aliases.manufacturer.reverse()
		equipment.aliases.model.reverse()
		equipment.identifiers.reverse()
		equipment.qualifiers.reverse()
		for (const qualifier of equipment.qualifiers) {
			qualifier.aliases.reverse()
		}
	}
	copy.slots.reverse()
	for (const slot of copy.slots) {
		slot.aliases.reverse()
	}
	copy.parts.reverse()
	copy.offers.reverse()
	copy.fitments.reverse()
	for (const fitment of copy.fitments) {
		fitment.evidence.reverse()
	}
	return copy
}

const coreSlots = new Map(vocabulary.slots.map((slot) => [slot.key, slot]))
const coreQualifiers = new Set(vocabulary.qualifiers.map((qualifier) => qualifier.key))
const reservedSlotRoots = new Set(vocabulary.slots.map((slot) => slot.key.split('.')[0]))

for (const label of ['slot', 'qualifier']) {
	const items = label === 'slot' ? vocabulary.slots : vocabulary.qualifiers
	for (const key of duplicateValues(items.map((item) => item.key))) {
		throw new Error(`fitment/core-v1.json: duplicate ${label} key: ${key}`)
	}
}

function semanticErrors(pack) {
	const errors = []
	const equipmentByKey = new Map(pack.equipment.map((item) => [item.key, item]))
	const slotByKey = new Map(pack.slots.map((item) => [item.key, item]))
	const partByKey = new Map(pack.parts.map((item) => [item.key, item]))

	for (const [label, keys] of [
		['equipment', pack.equipment.map((item) => item.key)],
		['slot', pack.slots.map((item) => item.key)],
		['part', pack.parts.map((item) => item.key)],
		['offer', pack.offers.map((item) => item.key)],
	]) {
		for (const key of duplicateValues(keys)) {
			errors.push(`duplicate ${label} key: ${key}`)
		}
	}

	for (const equipment of pack.equipment) {
		const hasFrom = Object.hasOwn(equipment, 'yearFrom')
		const hasTo = Object.hasOwn(equipment, 'yearTo')
		if (hasFrom !== hasTo) {
			errors.push(`equipment ${equipment.key} must set yearFrom and yearTo together`)
		} else if (hasFrom && equipment.yearFrom > equipment.yearTo) {
			errors.push(`equipment ${equipment.key} has yearFrom after yearTo`)
		}

		for (const [field, aliases] of Object.entries(equipment.aliases)) {
			for (const alias of duplicateValues(aliases.map(normalize))) {
				errors.push(`equipment ${equipment.key} ${field} aliases repeat ${alias}`)
			}
		}

		for (const identifier of equipment.identifiers) {
			if (!isReverseDnsNamespace(identifier.namespace)) {
				errors.push(`equipment ${equipment.key} identifier namespace ${identifier.namespace} must be reverse-DNS-style`)
			}
		}
		for (const identity of duplicateValues(equipment.identifiers.map((item) => `${item.namespace}\u0000${item.value}`))) {
			errors.push(`equipment ${equipment.key} repeats model/configuration identifier ${identity.replace('\u0000', ':')}`)
		}

		for (const key of duplicateValues(equipment.qualifiers.map((item) => item.key))) {
			errors.push(`equipment ${equipment.key} repeats qualifier ${key}`)
		}
		for (const qualifier of equipment.qualifiers) {
			if (!coreQualifiers.has(qualifier.key) && !isNamespacedExtension(qualifier.key)) {
				errors.push(`equipment ${equipment.key} qualifier ${qualifier.key} is neither a core qualifier nor a reverse-DNS-style extension`)
			}
			const aliasValues = qualifier.aliases.map(normalize)
			for (const alias of duplicateValues(aliasValues)) {
				errors.push(`equipment ${equipment.key} qualifier ${qualifier.key} has duplicate alias ${alias}`)
			}
		}
	}

	for (const slot of pack.slots) {
		const core = coreSlots.get(slot.key)
		if (core) {
			if (core.kind !== slot.kind) {
				errors.push(`slot ${slot.key} kind ${slot.kind} disagrees with core vocabulary kind ${core.kind}`)
			}
		} else {
			const root = slot.key.split('.')[0]
			if (reservedSlotRoots.has(root) || !isNamespacedExtension(slot.key)) {
				errors.push(`slot ${slot.key} must use an existing core key or a reverse-DNS-style extension namespace`)
			}
		}
		for (const alias of duplicateValues(slot.aliases.map(normalize))) {
			errors.push(`slot ${slot.key} has duplicate alias ${alias}`)
		}
	}

	const normalizedPartIdentities = pack.parts.map((part) => `${normalize(part.manufacturer)}\u0000${normalize(part.partNumber)}`)
	for (const identity of duplicateValues(normalizedPartIdentities)) {
		const [manufacturer, partNumber] = identity.split('\u0000')
		errors.push(`duplicate part identity after conservative case/whitespace normalization: ${manufacturer} / ${partNumber}`)
	}

	for (const offer of pack.offers) {
		if (!partByKey.has(offer.partKey)) {
			errors.push(`offer ${offer.key} references unknown part ${offer.partKey}`)
		}
	}

	const fitmentTuples = []
	for (const fitment of pack.fitments) {
		if (!equipmentByKey.has(fitment.equipmentKey)) {
			errors.push(`fitment references unknown equipment ${fitment.equipmentKey}`)
		}
		if (!slotByKey.has(fitment.slotKey)) {
			errors.push(`fitment references unknown slot ${fitment.slotKey}`)
		}
		if (!partByKey.has(fitment.partKey)) {
			errors.push(`fitment references unknown part ${fitment.partKey}`)
		}
		const evidenceKeys = fitment.evidence.map((item) => `${item.kind}\u0000${item.referenceUrl ?? ''}\u0000${item.note ?? ''}`)
		for (const evidence of duplicateValues(evidenceKeys)) {
			errors.push(`fitment ${fitment.equipmentKey}/${fitment.slotKey}/${fitment.partKey} repeats evidence ${evidence.replaceAll('\u0000', ':')}`)
		}
		if (fitment.verification === 'verified' && fitment.evidence.length === 0) {
			errors.push(`verified fitment ${fitment.equipmentKey}/${fitment.slotKey}/${fitment.partKey} requires evidence`)
		}
		fitmentTuples.push(`${fitment.equipmentKey}\u0000${fitment.slotKey}\u0000${fitment.partKey}`)
	}
	for (const tuple of duplicateValues(fitmentTuples)) {
		const [equipmentKey, slotKey, partKey] = tuple.split('\u0000')
		errors.push(`duplicate fitment tuple: ${equipmentKey}/${slotKey}/${partKey}`)
	}

	return errors
}

const packDirectory = resolve('fitment/examples')
const packNames = (await readdir(packDirectory, { withFileTypes: true }))
	.filter((entry) => entry.isFile() && entry.name.endsWith('.json'))
	.map((entry) => entry.name)
	.sort()

if (packNames.length === 0) {
	throw new Error('No fitment-pack examples were found.')
}

console.log('fitment/core-v1.json: valid')
let invalid = false
for (const packName of packNames) {
	const rawPack = await readFile(resolve(packDirectory, packName))
	if (rawPack.byteLength > MAX_PACK_BYTES) {
		invalid = true
		console.error(`${packName}: exceeds ${MAX_PACK_BYTES} byte fitment-pack limit`)
		continue
	}
	const pack = JSON.parse(rawPack.toString('utf8'))
	if (!validatePack(pack)) {
		invalid = true
		console.error(`${packName}: ${ajv.errorsText(validatePack.errors, { separator: '\n' })}`)
		continue
	}
	const errors = semanticErrors(pack)
	if (errors.length > 0) {
		invalid = true
		console.error(`${packName}: ${errors.join('\n')}`)
		continue
	}
	const hash = contentHash(pack)
	if (contentHash(reversedSetOrder(pack)) !== hash) {
		invalid = true
		console.error(`${packName}: canonical hash changes when set-like rows are reordered`)
		continue
	}
	console.log(`${packName}: valid sha256=${hash}`)
}

if (invalid) {
	process.exitCode = 1
}
