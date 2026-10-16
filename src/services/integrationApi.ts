/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { IntegrationSettings, WritableCalendar } from '../integrationTypes.ts'
import type { OcsEnvelope } from './api.ts'

import axios from '@nextcloud/axios'
import { data, endpoint, requestOptions } from './api.ts'

export async function getIntegrationSettings(): Promise<IntegrationSettings> {
	return data(await axios.get<OcsEnvelope<IntegrationSettings>>(endpoint('/integrations/settings'), requestOptions))
}

export async function updateIntegrationSettings(settings: Partial<Pick<IntegrationSettings, 'reminders' | 'calendarUri'>>): Promise<IntegrationSettings> {
	return data(await axios.put<OcsEnvelope<IntegrationSettings>>(endpoint('/integrations/settings'), { settings }, requestOptions))
}

export async function listWritableCalendars(): Promise<WritableCalendar[]> {
	return (await data(await axios.get<OcsEnvelope<{ items: WritableCalendar[] }>>(endpoint('/integrations/calendars'), requestOptions))).items
}
