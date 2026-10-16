/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Static accessibility checks for the Vue templates. Complements (does not
// replace) manual keyboard and screen-reader testing; see docs/accessibility.md.

import { readdirSync, readFileSync } from 'node:fs'
import { join, relative } from 'node:path'
import { parse } from 'vue/compiler-sfc'

const ELEMENT = 1
const TEXT = 2
const INTERPOLATION = 5
const ATTRIBUTE = 6
const DIRECTIVE = 7

const root = new URL('..', import.meta.url).pathname
const files = []
function walkDir(dir) {
	for (const entry of readdirSync(dir, { withFileTypes: true })) {
		const path = join(dir, entry.name)
		if (entry.isDirectory()) {
			walkDir(path)
		} else if (entry.name.endsWith('.vue')) {
			files.push(path)
		}
	}
}
walkDir(join(root, 'src'))

const problems = []
const INTERACTIVE = new Set(['a', 'button', 'input', 'select', 'textarea', 'summary', 'details', 'option', 'label'])
const CONTROLS = new Set(['input', 'select', 'textarea'])

function attr(node, name) {
	return node.props.find((p) => (p.type === ATTRIBUTE && p.name === name)
		|| (p.type === DIRECTIVE && p.name === 'bind' && p.arg?.content === name))
}
const staticValue = (node, name) => node.props.find((p) => p.type === ATTRIBUTE && p.name === name)?.value?.content
const on = (node, event) => node.props.some((p) => p.type === DIRECTIVE && p.name === 'on' && p.arg?.content?.startsWith(event))
function hasText(node) {
	return node.children?.some((c) => (c.type === TEXT && c.content.trim() !== '') || c.type === INTERPOLATION
		|| (c.type === ELEMENT && (hasText(c) || attr(c, 'alt') || attr(c, 'aria-label'))))
}

function check(file, node, ancestors, ids) {
	if (node.type !== ELEMENT) {
		return
	}
	const where = `${relative(root, file)}:${node.loc.start.line}`
	const report = (rule, message) => problems.push(`${where} [${rule}] ${message}`)
	const tag = node.tag
	const type = staticValue(node, 'type')

	if (CONTROLS.has(tag) && type !== 'hidden' && type !== 'submit' && type !== 'button') {
		const inLabel = ancestors.some((a) => a.tag === 'label')
		const id = staticValue(node, 'id')
		const labelledFor = id !== undefined && ids.labelFor.has(id)
		if (!inLabel && !labelledFor && !attr(node, 'aria-label') && !attr(node, 'aria-labelledby')) {
			report('control-label', `<${tag}> has no associated label`)
		}
	}
	if (tag === 'button' && !hasText(node) && !attr(node, 'aria-label') && !attr(node, 'aria-labelledby') && !attr(node, 'title')) {
		report('button-name', '<button> has no accessible name')
	}
	if (tag === 'button' && type === undefined && !attr(node, 'type')) {
		report('button-type', '<button> must declare type="button" or type="submit"')
	}
	if (tag === 'img' && !attr(node, 'alt')) {
		report('img-alt', '<img> needs alt text (alt="" for decorative images)')
	}
	if (tag === 'a' && staticValue(node, 'target') === '_blank' && !/noopener/.test(staticValue(node, 'rel') ?? '') && !attr(node, 'rel')) {
		report('link-noopener', 'target="_blank" links need rel="noopener noreferrer"')
	}
	if (tag === 'a' && !attr(node, 'href')) {
		report('anchor-href', '<a> without href is not keyboard-focusable; use a <button>')
	}
	const tabindex = staticValue(node, 'tabindex')
	if (tabindex !== undefined && Number(tabindex) > 0) {
		report('tabindex', 'positive tabindex breaks the natural focus order')
	}
	if (on(node, 'click') && !INTERACTIVE.has(tag) && /^[a-z]/.test(tag)) {
		if (!attr(node, 'role') || !attr(node, 'tabindex') || !on(node, 'keydown')) {
			report('click-keyboard', `@click on <${tag}> needs role, tabindex and a keydown handler (or use a <button>)`)
		}
	}
	const cls = staticValue(node, 'class') ?? ''
	if (/\bnotice--error\b/.test(cls) && attr(node, 'role') === undefined) {
		const dynamic = node.props.some((p) => p.type === DIRECTIVE && (p.name === 'if' || p.name === 'show'))
		if (dynamic) {
			report('error-alert', 'conditionally shown error notices need role="alert"')
		}
	}
	if (/^h[1-6]$/.test(tag) && !hasText(node)) {
		report('heading-content', `<${tag}> is empty`)
	}
	for (const child of node.children ?? []) {
		check(file, child, [...ancestors, node], ids)
	}
}

function collectIds(node, ids) {
	if (node.type !== ELEMENT && node.type !== 0) {
		return
	}
	if (node.type === ELEMENT && node.tag === 'label') {
		const target = staticValue(node, 'for')
		if (target !== undefined) {
			ids.labelFor.add(target)
		}
	}
	for (const child of node.children ?? []) {
		collectIds(child, ids)
	}
}

for (const file of files) {
	const { descriptor, errors } = parse(readFileSync(file, 'utf8'), { filename: file })
	if (errors.length > 0 || descriptor.template === null) {
		problems.push(`${relative(root, file)} [parse] ${errors[0]?.message ?? 'no template'}`)
		continue
	}
	const ast = descriptor.template.ast
	const ids = { labelFor: new Set() }
	collectIds(ast, ids)
	for (const child of ast.children) {
		check(file, child, [], ids)
	}
}

if (problems.length > 0) {
	console.error(problems.join('\n'))
	console.error(`\n${problems.length} accessibility problem(s) in ${files.length} component(s).`)
	process.exit(1)
}
console.log(`Accessibility static checks passed (${files.length} components).`)
