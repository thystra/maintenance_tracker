/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Thin wrappers around @nextcloud/l10n for text rendered through Vue
// interpolation, which escapes output itself. Keep the call shape
// t('maintenance_tracker', 'literal', vars) so Nextcloud's translation tooling
// (xgettext keywords t:2 and n:2,3) extracts every string.

import { translate, translatePlural } from '@nextcloud/l10n'

const options = { escape: false, sanitize: false }

type Vars = Record<string, string | number | null | undefined>

function clean(vars?: Vars): Record<string, string> {
	return Object.fromEntries(Object.entries(vars ?? {}).map(([key, value]) => [key, value === null || value === undefined ? '' : String(value)]))
}

/**
 * Translate a singular string.
 *
 * @param app Always 'maintenance_tracker'.
 * @param text English source text, a string literal.
 * @param vars Values for {placeholders}.
 */
export function t(app: string, text: string, vars?: Vars): string {
	return translate(app, text, clean(vars) as never, options)
}

/**
 * Translate a plural string; %n is replaced with the count.
 *
 * @param app Always 'maintenance_tracker'.
 * @param singular English singular text.
 * @param plural English plural text.
 * @param count The number deciding the plural form.
 * @param vars Values for {placeholders}.
 */
export function n(app: string, singular: string, plural: string, count: number, vars?: Vars): string {
	return translatePlural(app, singular, plural, count, clean(vars) as never, options)
}
