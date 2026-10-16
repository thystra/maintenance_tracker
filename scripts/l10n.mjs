/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Localization tooling.
//   node scripts/l10n.mjs check    fail on user-facing text that bypasses l10n
//   node scripts/l10n.mjs extract  write translationfiles/templates/maintenance_tracker.pot
// Nextcloud's own translationtool (used by the Transifex sync) extracts the
// same calls: PHP $l->t()/$l->n() and JS t()/n() with a literal app id.

import { mkdirSync, readdirSync, readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { parse } from 'vue/compiler-sfc'

const APP = 'maintenance_tracker'
const root = new URL('..', import.meta.url).pathname
const mode = process.argv[2] ?? 'check'

/**
 * @param {string} dir Directory to walk.
 * @param {RegExp} pattern File name pattern.
 * @return {string[]}
 */
function files(dir, pattern) {
	const out = []
	for (const entry of readdirSync(join(root, dir), { withFileTypes: true })) {
		const path = join(dir, entry.name)
		if (entry.isDirectory()) {
			out.push(...files(path, pattern))
		} else if (pattern.test(entry.name)) {
			out.push(path)
		}
	}
	return out
}

const frontend = files('src', /\.(vue|ts)$/)
const backend = [...files('lib', /\.php$/), ...files('templates', /\.php$/)]
const STRING = String.raw`'((?:[^'\\\n]|\\.)*)'`
const unescape = (s) => s.replace(/\\(.)/g, '$1')

/**
 * Collect translatable calls from source text.
 *
 * @param {string} text Source text.
 * @param {RegExp} singular Pattern for t(); group 1 is the text.
 * @param {RegExp} plural Pattern for n(); groups 1 and 2.
 * @return {Array<{line: number, id: string, plural?: string}>}
 */
function calls(text, singular, plural) {
	const found = []
	const lineOf = (index) => text.slice(0, index).split('\n').length
	for (const m of text.matchAll(singular)) {
		found.push({ line: lineOf(m.index), id: unescape(m[1]) })
	}
	for (const m of text.matchAll(plural)) {
		found.push({ line: lineOf(m.index), id: unescape(m[1]), plural: unescape(m[2]) })
	}
	return found
}

const jsT = new RegExp(String.raw`(?<![\w.$])t\(\s*'${APP}',\s*${STRING}`, 'g')
const jsN = new RegExp(String.raw`(?<![\w.$])n\(\s*'${APP}',\s*${STRING},\s*${STRING}`, 'g')
const phpT = new RegExp(String.raw`->t\(\s*${STRING}`, 'g')
const phpN = new RegExp(String.raw`->n\(\s*${STRING},\s*${STRING}`, 'g')

if (mode === 'extract') {
	const entries = new Map()
	const add = (file, found) => {
		for (const { line, id, plural } of found) {
			const key = `${id}\u0004${plural ?? ''}`
			const entry = entries.get(key) ?? { id, plural, refs: [] }
			entry.refs.push(`${file}:${line}`)
			entries.set(key, entry)
		}
	}
	for (const file of frontend) {
		add(file, calls(readFileSync(join(root, file), 'utf8'), jsT, jsN))
	}
	for (const file of backend) {
		add(file, calls(readFileSync(join(root, file), 'utf8'), phpT, phpN))
	}
	const po = (s) => `"${s.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, '\\n')}"`
	let pot = `msgid ""\nmsgstr ""\n"Project-Id-Version: ${APP}\\n"\n"Content-Type: text/plain; charset=UTF-8\\n"\n"Content-Transfer-Encoding: 8bit\\n"\n\n`
	for (const entry of [...entries.values()].sort((a, b) => a.id.localeCompare(b.id))) {
		pot += `#: ${entry.refs.join(' ')}\n`
		if (/%[ns]|\{\w+\}/.test(entry.id)) {
			pot += entry.id.includes('%') ? '#, php-format\n' : ''
		}
		pot += `msgid ${po(entry.id)}\n`
		pot += entry.plural === undefined ? 'msgstr ""\n\n' : `msgid_plural ${po(entry.plural)}\nmsgstr[0] ""\nmsgstr[1] ""\n\n`
	}
	mkdirSync(join(root, 'translationfiles/templates'), { recursive: true })
	writeFileSync(join(root, `translationfiles/templates/${APP}.pot`), pot)
	console.log(`Extracted ${entries.size} strings to translationfiles/templates/${APP}.pot`)
	process.exit(0)
}

// ---- check ----
const problems = []
const report = (file, line, rule, message) => problems.push(`${file}:${line} [${rule}] ${message}`)
const HUMAN_ATTRS = ['placeholder', 'aria-label', 'title', 'label', 'alt']

/**
 * Whether a string literal reads as user-facing English.
 *
 * @param {string} s Literal content.
 */
function human(s) {
	if (!/^[A-Z]/.test(s) || !/[a-z]/.test(s)) {
		return false
	}
	if (/^[A-Z][a-zA-Z]*$/.test(s) && s.length < 3) {
		return false
	}
	return s.includes(' ') || /[.…!?:]$/.test(s) || /^[A-Z][a-z]+$/.test(s)
}

/**
 * Report untranslated template text and attributes.
 *
 * @param {string} file File path.
 * @param {object} node Template AST node.
 */
function walk(file, node) {
	if (node.type !== 1 && node.type !== 0) {
		return
	}
	if (node.type === 1 && (node.tag === 'code' || node.tag === 'pre')) {
		return
	}
	if (node.type === 1) {
		for (const p of node.props) {
			if (p.type === 6 && HUMAN_ATTRS.includes(p.name) && p.value && /[A-Za-z]{2}/.test(p.value.content)) {
				report(file, p.loc.start.line, 'template-attribute', `${p.name}="${p.value.content}" must use :${p.name}="t('${APP}', ...)"`)
			}
		}
	}
	for (const child of node.children ?? []) {
		if (child.type === 2 && /[A-Za-z]{2}/.test(child.content)) {
			report(file, child.loc.start.line, 'template-text', `"${child.content.trim().slice(0, 60)}" must use {{ t('${APP}', ...) }}`)
		}
		walk(file, child)
	}
}

const literal = /(?<![\w.])'((?:[^'\\\n]|\\.)*)'/g
for (const file of frontend) {
	const text = readFileSync(join(root, file), 'utf8')
	if (file.endsWith('.vue')) {
		const { descriptor } = parse(text, { filename: file })
		if (descriptor.template) {
			walk(file, descriptor.template.ast)
		}
	}
	const lines = text.split('\n')
	lines.forEach((line, index) => {
		if (/^\s*(import|\/\/|\*|\/\*)/.test(line) || file === 'src/l10n.ts') {
			return
		}
		for (const m of line.matchAll(/(?<![\w.$])[tn]\(\s*(?!'maintenance_tracker',\s*')([^)]{0,40})/g)) {
			if (/^\s*$/.test(m[1])) {
				continue
			}
			report(file, index + 1, 'literal-call', `t()/n() needs the literal app id '${APP}' and a literal string: ${m[0]}`)
		}
		for (const m of line.matchAll(literal)) {
			const before = line.slice(0, m.index)
			if (!human(m[1]) || /[tn]\(\s*'maintenance_tracker',(\s*'(?:[^'\\]|\\.)*',)?\s*$/.test(before)
				|| /(===|!==|case)\s*$/.test(before) || /\b(class|type|value|key|role)="?$/.test(before)) {
				continue
			}
			report(file, index + 1, 'script-string', `'${m[1].slice(0, 60)}' looks user-facing; wrap it in t('${APP}', ...)`)
		}
	})
}

for (const file of files('templates', /\.php$/)) {
	const html = readFileSync(join(root, file), 'utf8').replace(/<\?php[\s\S]*?(\?>|$)/g, '').replace(/<[^>]*>/g, ' ')
	for (const chunk of html.split('\n')) {
		if (/[A-Za-z]{2}/.test(chunk)) {
			report(file, 0, 'php-template-text', `raw text "${chunk.trim().slice(0, 60)}" must be printed with p($l->t(...))`)
		}
	}
}

if (problems.length > 0) {
	console.error(problems.join('\n'))
	console.error(`\n${problems.length} localization problem(s).`)
	process.exit(1)
}
console.log(`Localization checks passed (${frontend.length} frontend, ${backend.length} backend files).`)
