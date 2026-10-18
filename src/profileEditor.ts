/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Helpers for the profile builder: blank documents, keys from names,
// tidying a draft before it is sent (the server validates and normalizes),
// and downloading a profile as a JSON file.
import type { ProfileDocumentV2, ProfileRule } from './profileTypes.ts'

/**
 * A lowercase key from a name: "Oil filter #2" becomes "oil_filter_2".
 *
 * @param text Name to derive the key from.
 * @param fallback Key used when the name has no usable characters.
 */
export function slugKey(text: string, fallback = 'item'): string {
	const key = text.toLowerCase().normalize('NFKD').replace(/[^a-z0-9_-]+/g, '_').replace(/^[_-]+|[_-]+$/g, '').slice(0, 56)
	return key === '' ? fallback : key
}

/**
 * Give every row without a key one derived from its name, unique in the list.
 *
 * @param rows Rows of one list (meters, components, ...).
 * @param nameOf Text the key is derived from.
 * @param fallback Base key for rows without a usable name.
 */
export function fillKeys<T extends { key: string }>(rows: T[], nameOf: (row: T) => string, fallback: string): void {
	const used = new Set(rows.map((row) => row.key.trim()).filter((key) => key !== ''))
	for (const row of rows) {
		row.key = row.key.trim()
		if (row.key !== '') {
			continue
		}
		const base = slugKey(nameOf(row), fallback)
		let key = base
		for (let n = 2; used.has(key); n++) {
			key = `${base}_${n}`
		}
		used.add(key)
		row.key = key
	}
}

/**
 * A new, empty workspace profile.
 *
 * @param author Default author (the workspace name).
 */
export function blankProfile(author: string): ProfileDocumentV2 {
	return {
		schemaVersion: 2,
		id: '',
		version: '1.0.0',
		name: '',
		category: 'vehicle',
		description: '',
		dataLicense: 'NOASSERTION',
		provenance: { author },
		applicability: {},
		meters: [],
		components: [],
		parts: [],
		workGroups: [],
		workDefinitions: [],
		specifications: [],
	}
}

/**
 * Fill in a document loaded from the server or a file so every list the
 * editor shows exists.
 *
 * @param input Profile document.
 */
export function editable(input: ProfileDocumentV2): ProfileDocumentV2 {
	const doc = structuredClone(input)
	doc.applicability = Array.isArray(doc.applicability) ? {} : (doc.applicability ?? {})
	doc.specifications ??= []
	for (const component of doc.components) {
		component.compatiblePartKeys ??= []
	}
	for (const definition of doc.workDefinitions) {
		definition.compatiblePartKeys ??= []
	}
	return doc
}

function optionalText(value: string | undefined): string | undefined {
	const trimmed = value?.trim() ?? ''
	return trimmed === '' ? undefined : trimmed
}

/**
 * Tidy a draft for saving: derive missing keys, drop empty optional fields
 * and dangling references. The server still validates everything.
 *
 * @param draft Draft from the editor.
 */
export function forSave(draft: ProfileDocumentV2): ProfileDocumentV2 {
	const doc = structuredClone(draft)
	if (doc.id.trim() === '') {
		doc.id = `workspace.${slugKey(doc.name, 'profile')}`
	}
	fillKeys(doc.meters, (m) => m.name, 'meter')
	fillKeys(doc.components, (c) => c.name, 'component')
	fillKeys(doc.parts, (p) => `${p.manufacturer} ${p.partNumber}`, 'part')
	fillKeys(doc.workGroups, (g) => g.name, 'group')
	fillKeys(doc.workDefinitions, (d) => d.title, 'task')
	fillKeys(doc.specifications ?? [], (s) => s.label, 'spec')
	if (doc.fuel) {
		fillKeys(doc.fuel.regimes, (r) => r.name, 'regime')
		if (doc.fuel.regimes.length > 0 && !doc.fuel.regimes.some((r) => r.isDefault)) {
			doc.fuel.regimes[0]!.isDefault = true
		}
		if (doc.fuel.settings) {
			for (const field of ['distanceUnit', 'volumeUnit', 'economyUnit', 'fuelType'] as const) {
				if (!doc.fuel.settings[field]) {
					delete doc.fuel.settings[field]
				}
			}
		}
	}
	const componentKeys = new Set(doc.components.map((c) => c.key))
	const partKeys = new Set(doc.parts.map((p) => p.key))
	const groupKeys = new Set(doc.workGroups.map((g) => g.key))
	const meterKeys = new Set(doc.meters.map((m) => m.key))

	doc.description = doc.description.trim() === '' ? doc.name : doc.description
	doc.provenance = { author: doc.provenance.author, sourceUrl: optionalText(doc.provenance.sourceUrl), sourceRevision: optionalText(doc.provenance.sourceRevision) }
	const app = doc.applicability
	doc.applicability = {
		manufacturer: optionalText(app.manufacturer),
		model: optionalText(app.model),
		yearFrom: app.yearFrom ? Number(app.yearFrom) : undefined,
		yearTo: app.yearTo ? Number(app.yearTo) : undefined,
		tags: app.tags && app.tags.length > 0 ? app.tags : undefined,
	}
	for (const c of doc.components) {
		c.quantity = Number(c.quantity) || 1
		c.parentKey = c.parentKey && componentKeys.has(c.parentKey) && c.parentKey !== c.key ? c.parentKey : undefined
		c.notes = optionalText(c.notes)
		c.compatiblePartKeys = c.compatiblePartKeys.filter((k) => partKeys.has(k))
	}
	for (const p of doc.parts) {
		p.description = p.description.trim() === '' ? `${p.manufacturer} ${p.partNumber}`.trim() : p.description
		p.offers ??= []
	}
	for (const g of doc.workGroups) {
		g.sortOrder = Number(g.sortOrder) || 0
		g.description = optionalText(g.description)
	}
	for (const d of doc.workDefinitions) {
		d.groupKey = d.groupKey && groupKeys.has(d.groupKey) ? d.groupKey : undefined
		d.componentKey = d.componentKey && componentKeys.has(d.componentKey) ? d.componentKey : undefined
		d.instructions = optionalText(d.instructions)
		d.notes = optionalText(d.notes)
		d.compatiblePartKeys = d.compatiblePartKeys.filter((k) => partKeys.has(k))
		if (d.schedule !== 'none') {
			const rules: ProfileRule[] = d.schedule.rules
				.filter((r) => r.type !== 'meter' || meterKeys.has(r.meterKey))
				.map((r) => r.type === 'meter' ? { ...r, interval: { value: String(r.interval.value).trim(), unit: r.interval.unit } } : { ...r, interval: { value: Number(r.interval.value), unit: r.interval.unit } })
			d.schedule = rules.length === 0 ? 'none' : { combination: 'any', rules }
		}
	}
	for (const s of doc.specifications ?? []) {
		s.unit = optionalText(s.unit)
		s.componentKey = s.componentKey && componentKeys.has(s.componentKey) ? s.componentKey : undefined
		s.regime = optionalText(s.regime)
	}
	return JSON.parse(JSON.stringify(doc)) as ProfileDocumentV2
}

/**
 * Offer a profile as a JSON file download.
 *
 * @param profile Profile document.
 * @param profile.id Profile ID, used in the file name.
 * @param profile.version Profile version, used in the file name.
 */
export function downloadProfile(profile: { id?: unknown, version?: unknown }): void {
	const blob = new Blob([JSON.stringify(profile, null, 2) + '\n'], { type: 'application/json' })
	const url = URL.createObjectURL(blob)
	const link = document.createElement('a')
	link.href = url
	link.download = `${slugKey(String(profile.id ?? 'profile'), 'profile')}-${String(profile.version ?? '1.0.0')}.json`
	document.body.appendChild(link)
	link.click()
	link.remove()
	URL.revokeObjectURL(url)
}

type KeyKind = 'meter' | 'component' | 'part' | 'group'

/**
 * Point references at a renamed key (keys of new rows follow their name).
 *
 * @param doc Draft profile.
 * @param kind Which list the key belongs to.
 * @param from Old key.
 * @param to New key.
 */
export function renameReferences(doc: ProfileDocumentV2, kind: KeyKind, from: string, to: string): void {
	if (from === '' || from === to) {
		return
	}
	const swap = (keys: string[]): string[] => keys.map((key) => key === from ? to : key)
	if (kind === 'component') {
		for (const c of doc.components) {
			c.parentKey = c.parentKey === from ? to : c.parentKey
		}
		for (const d of doc.workDefinitions) {
			d.componentKey = d.componentKey === from ? to : d.componentKey
		}
		for (const s of doc.specifications ?? []) {
			s.componentKey = s.componentKey === from ? to : s.componentKey
		}
	} else if (kind === 'part') {
		for (const c of doc.components) {
			c.compatiblePartKeys = swap(c.compatiblePartKeys)
		}
		for (const d of doc.workDefinitions) {
			d.compatiblePartKeys = swap(d.compatiblePartKeys)
		}
	} else if (kind === 'group') {
		for (const d of doc.workDefinitions) {
			d.groupKey = d.groupKey === from ? to : d.groupKey
		}
	} else {
		for (const d of doc.workDefinitions) {
			for (const r of d.schedule === 'none' ? [] : d.schedule.rules) {
				if (r.type === 'meter' && r.meterKey === from) {
					r.meterKey = to
				}
			}
		}
	}
}

/**
 * A key from a name that no other row of the list uses.
 *
 * @param name Row name.
 * @param others Keys of the other rows.
 * @param fallback Base key when the name has no usable characters.
 */
export function uniqueSlug(name: string, others: string[], fallback: string): string {
	const base = slugKey(name, fallback)
	let key = base
	for (let n = 2; others.includes(key); n++) {
		key = `${base}_${n}`
	}
	return key
}
