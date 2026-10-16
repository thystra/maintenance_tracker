/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export interface IntegrationSettings {
	reminders: boolean
	calendarUri: string | null
	calendarAvailable: boolean
}

export interface WritableCalendar {
	uri: string
	displayName: string
	color: string | null
}
