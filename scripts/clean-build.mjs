/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { rm } from 'node:fs/promises'
import { resolve } from 'node:path'

for (const directory of ['css', 'js']) {
	await rm(resolve(directory), {
		force: true,
		recursive: true,
	})
}
