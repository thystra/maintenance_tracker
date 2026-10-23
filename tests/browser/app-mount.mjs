/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Headless browser check: log in, open the app, visit every page, run the
 * main create/delete flows (vendor, part, item with a task, item deletion,
 * item groups, fuel log with regimes and fill-ups, profile builder with
 * save-as-profile and copy-setup-from, dashboard Log fuel creating the
 * odometer meter and moving a distance task into the due list),
 * and assert that nothing from this app logs a console error, throws, or
 * gets an HTTP error. Run by tests/integration/nextcloud-smoke.sh when
 * NC_SMOKE_BROWSER=1, inside the Playwright image on the Nextcloud
 * container's network.
 *
 * Environment: NC_BASE_URL, NC_USER, NC_PASSWORD, optional NC_SCREENSHOT
 * (dashboard screenshot) and NC_SCREENSHOT_DIR (one screenshot per page).
 */

import { mkdirSync } from 'node:fs'
import { join } from 'node:path'
import { chromium } from 'playwright-core'

const base = process.env.NC_BASE_URL ?? 'http://127.0.0.1'
const user = process.env.NC_USER
const password = process.env.NC_PASSWORD
const shots = process.env.NC_SCREENSHOT_DIR ?? ''
const stamp = Date.now().toString(36)
// Only problems caused by this app count; other Nextcloud apps on the page
// (user status, recommendations, ...) may log their own unrelated errors.
const ours = (text) => /\/(custom_)?apps\/maintenance_tracker\//.test(text ?? '')

const problems = []
const note = (message) => problems.push(message)
const steps = []

if (shots !== '') {
	mkdirSync(shots, { recursive: true })
}

const browser = await chromium.launch()
try {
	const context = await browser.newContext({ viewport: { width: 1360, height: 1100 } })
	const page = await context.newPage()
	page.on('console', (message) => {
		if (message.type() === 'error' && (ours(message.location()?.url) || ours(message.text()))) {
			note(`console error: ${message.text()}`)
		}
	})
	page.on('pageerror', (error) => {
		if (ours(error.stack) || ours(error.message)) {
			note(`page error: ${error.message}`)
		}
	})
	page.on('requestfailed', (request) => {
		if (request.url().includes('maintenance_tracker') && request.failure()?.errorText !== 'net::ERR_ABORTED') {
			note(`request failed: ${request.url()} ${request.failure()?.errorText ?? ''}`)
		}
	})
	page.on('response', (response) => {
		if (response.status() >= 400 && response.url().includes('maintenance_tracker')) {
			note(`HTTP ${response.status()}: ${response.request().method()} ${response.url()}`)
		}
	})

	const heading = (name) => page.locator('#maintenance-main [data-page-heading]', { hasText: name })
	const shot = async (name) => {
		if (shots !== '') {
			await page.waitForTimeout(300)
			await page.screenshot({ path: join(shots, `${name}.png`), fullPage: true })
		}
	}
	const step = async (name, action) => {
		try {
			await action()
			steps.push(`ok   ${name}`)
		} catch (error) {
			steps.push(`FAIL ${name}`)
			note(`${name}: ${error.message.split('\n')[0]}`)
			await shot(`fail-${name.replace(/[^a-z0-9]+/gi, '-')}`).catch(() => {})
		}
	}
	const nav = (name) => page.locator('.app-navigation').getByRole('link', { name, exact: true })
	const dialog = () => page.getByRole('dialog').last()

	await page.goto(`${base}/index.php/login`)
	await page.fill('#user', user)
	await page.fill('#password', password)
	await Promise.all([page.waitForURL((url) => !url.pathname.includes('/login')), page.click('button[type=submit]')])

	await page.goto(`${base}/index.php/apps/maintenance_tracker/`, { waitUntil: 'networkidle' })
	const script = page.locator('script[src*="/maintenance_tracker/js/maintenance_tracker-main.mjs"]')
	if (await script.count() !== 1) {
		note('the app script tag is missing (are js/ and css/ installed?)')
	}

	await step('dashboard renders', async () => {
		await page.locator('#maintenance_tracker .app-navigation').waitFor({ state: 'visible', timeout: 15000 })
		await heading('Dashboard').waitFor({ state: 'visible', timeout: 10000 })
		await page.getByRole('heading', { name: 'Dashboard', level: 1 }).waitFor({ state: 'attached', timeout: 5000 })
		await page.waitForLoadState('networkidle')
		if (process.env.NC_SCREENSHOT) {
			await page.screenshot({ path: process.env.NC_SCREENSHOT, fullPage: true })
		}
		await shot('01-dashboard')
	})

	await step('items page', async () => {
		await nav('Items').click()
		await heading('Items').waitFor({ state: 'visible', timeout: 10000 })
		await page.waitForLoadState('networkidle')
		await shot('02-items')
	})

	await step('item detail and tabs', async () => {
		// Prefer an item that the API smoke test filled with tasks and history.
		const rows = page.locator('#maintenance-main .list-item')
		const preferred = rows.filter({ hasText: 'Workhorse' })
		const first = await preferred.count() > 0 ? preferred.first() : rows.first()
		if (await first.count() === 0) {
			return
		}
		await first.click()
		await page.getByRole('tablist').waitFor({ state: 'visible', timeout: 10000 })
		await page.waitForLoadState('networkidle')
		await shot('03-item-overview')
		for (const [tab, file] of [['Tasks', '04-item-tasks'], ['History', '05-item-history'], ['Readings', '06-item-readings'], ['Costs', '07-item-costs'], ['Files', '08-item-files'], ['Setup', '09-item-setup']]) {
			await page.getByRole('tab', { name: tab, exact: true }).click()
			await page.getByRole('tab', { name: tab, exact: true, selected: true }).waitFor({ timeout: 5000 })
			await page.waitForLoadState('networkidle')
			await shot(file)
		}
	})

	await step('add vendor', async () => {
		await nav('Vendors').click()
		await heading('Vendors').waitFor({ state: 'visible', timeout: 10000 })
		await page.waitForLoadState('networkidle')
		await page.locator('#maintenance-main').getByRole('button', { name: 'Add vendor' }).first().click()
		await dialog().getByLabel('Name', { exact: true }).fill(`Browser vendor ${stamp}`)
		await dialog().getByLabel('Phone', { exact: true }).fill('+1 555 0100')
		await dialog().getByLabel('Contact person', { exact: true }).fill('Pat Example')
		await dialog().getByRole('button', { name: 'Add vendor' }).click()
		await page.locator('#maintenance-main .list-item', { hasText: `Browser vendor ${stamp}` }).waitFor({ timeout: 10000 })
		await shot('10-vendors')
	})

	await step('add part', async () => {
		await nav('Parts').click()
		await heading('Parts').waitFor({ state: 'visible', timeout: 10000 })
		await page.waitForLoadState('networkidle')
		await page.locator('#maintenance-main').getByRole('button', { name: 'Add part' }).first().click()
		await dialog().getByLabel('Name', { exact: true }).fill(`Browser part ${stamp}`)
		await dialog().getByLabel('Manufacturer', { exact: true }).fill('Browser Co')
		await dialog().getByLabel('Part number', { exact: true }).fill(`BP-${stamp}`)
		await dialog().getByLabel('Typical price (optional)', { exact: true }).fill('9.95')
		await dialog().getByRole('button', { name: 'Add part' }).click()
		await page.locator('#maintenance-main .list-item', { hasText: `Browser part ${stamp}` }).waitFor({ timeout: 10000 })
		await shot('11-parts')
	})

	await step('add and delete item', async () => {
		const name = `Browser item ${stamp}`
		await nav('Items').click()
		await heading('Items').waitFor({ state: 'visible', timeout: 10000 })
		await page.locator('#maintenance-main').getByRole('button', { name: 'Add item' }).first().click()
		await dialog().getByLabel('Name', { exact: true }).fill(name)
		await dialog().getByRole('button', { name: 'Add item' }).click()
		await heading(name).waitFor({ state: 'visible', timeout: 10000 })
		await page.waitForLoadState('networkidle')
		await page.getByRole('tab', { name: 'Tasks', exact: true }).click()
		await page.locator('#maintenance-main').getByRole('button', { name: 'Add task' }).click()
		await dialog().getByLabel('Task', { exact: true }).fill('Browser check task')
		await dialog().getByRole('button', { name: 'Add task' }).click()
		await page.locator('#maintenance-main .list-item', { hasText: 'Browser check task' }).waitFor({ timeout: 10000 })
		await page.getByRole('tab', { name: 'Overview', exact: true }).click()
		await page.locator('#maintenance-main', { hasText: 'Browser check task' }).waitFor({ timeout: 10000 })
		await page.waitForLoadState('networkidle')
		await shot('03b-new-item-with-task')
		await page.getByRole('button', { name: `More actions for ${name}` }).click()
		await page.getByRole('menuitem', { name: 'Delete item' }).click()
		await dialog().getByRole('button', { name: 'Delete' }).click()
		await heading('Items').waitFor({ state: 'visible', timeout: 10000 })
		await page.waitForLoadState('networkidle')
		if (await page.locator('#maintenance-main .list-item', { hasText: name }).count() !== 0) {
			throw new Error('the deleted item is still listed')
		}
	})

	await step('item groups', async () => {
		const group = `Browser group ${stamp}`
		const subgroup = `Browser subgroup ${stamp}`
		const item = `Grouped item ${stamp}`
		await nav('Items').click()
		await heading('Items').waitFor({ state: 'visible', timeout: 10000 })
		await page.locator('#maintenance-main').getByRole('button', { name: 'New group' }).click()
		await dialog().getByLabel('Group name', { exact: true }).fill(group)
		await dialog().getByRole('button', { name: 'Create group' }).click()
		const sidebarGroup = page.locator('.app-navigation').getByText(group, { exact: true })
		await sidebarGroup.waitFor({ timeout: 10000 })
		await sidebarGroup.click()
		await heading(group).waitFor({ state: 'visible', timeout: 10000 })
		await page.locator('#maintenance-main').getByRole('button', { name: 'Add subgroup' }).click()
		await dialog().getByLabel('Group name', { exact: true }).fill(subgroup)
		await dialog().getByRole('button', { name: 'Create group' }).click()
		await page.locator('#maintenance-main').getByRole('link', { name: new RegExp(subgroup) }).waitFor({ timeout: 10000 })
		// A new item added from a group page lands in that group.
		await page.locator('#maintenance-main').getByRole('button', { name: 'Add item' }).first().click()
		await dialog().getByLabel('Name', { exact: true }).fill(item)
		await dialog().getByRole('button', { name: 'Add item' }).click()
		await heading(item).waitFor({ state: 'visible', timeout: 10000 })
		await page.getByRole('navigation', { name: 'Breadcrumbs' }).getByRole('link', { name: group }).waitFor({ timeout: 10000 })
		await page.waitForLoadState('networkidle')
		await shot('21-item-in-group')
		await page.getByRole('navigation', { name: 'Breadcrumbs' }).getByRole('link', { name: group }).click()
		await heading(group).waitFor({ state: 'visible', timeout: 10000 })
		await page.locator('#maintenance-main .list-item', { hasText: item }).waitFor({ timeout: 10000 })
		await page.waitForLoadState('networkidle')
		await shot('22-group-items')
		await nav('Dashboard').click()
		await page.locator('#groups-heading').waitFor({ timeout: 10000 })
		await page.waitForLoadState('networkidle')
		await shot('23-dashboard-by-group')
		// Deleting a group keeps its items: they move up to the parent.
		await page.locator('.app-navigation').getByText(subgroup, { exact: true }).click()
		await heading(subgroup).waitFor({ state: 'visible', timeout: 10000 })
		await page.getByRole('button', { name: `More actions for group ${subgroup}` }).click()
		await page.getByRole('menuitem', { name: 'Delete group' }).click()
		await shot('24-delete-group')
		await dialog().getByRole('button', { name: 'Delete' }).click()
		await page.locator('.app-navigation').getByText(subgroup, { exact: true }).waitFor({ state: 'detached', timeout: 10000 })
	})

	await step('fuel log', async () => {
		const name = `Fuel van ${stamp}`
		const local = (daysAgo) => {
			const date = new Date(Date.now() - daysAgo * 86400000)
			const pad = (value) => String(value).padStart(2, '0')
			return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T09:00`
		}
		await nav('Items').click()
		await heading('Items').waitFor({ state: 'visible', timeout: 10000 })
		await page.locator('#maintenance-main').getByRole('button', { name: 'Add item' }).first().click()
		await dialog().getByLabel('Name', { exact: true }).fill(name)
		await dialog().getByRole('button', { name: 'Add item' }).click()
		await heading(name).waitFor({ state: 'visible', timeout: 10000 })
		await page.waitForLoadState('networkidle')
		// Vehicles get the Fuel tab automatically; other items switch it on.
		await page.getByRole('button', { name: `More actions for ${name}` }).click()
		await page.getByRole('menuitem', { name: 'Edit item' }).waitFor({ timeout: 10000 })
		const showFuel = page.getByRole('menuitem', { name: 'Show the Fuel tab' })
		if (await showFuel.count() > 0) {
			await showFuel.click()
		} else {
			await page.keyboard.press('Escape')
		}
		await page.getByRole('tab', { name: 'Fuel', exact: true }).click()
		await page.getByText('No fill-ups yet').waitFor({ timeout: 10000 })
		await page.getByRole('button', { name: 'Fuel options' }).click()
		await page.getByRole('menuitem', { name: 'Operating regimes' }).click()
		for (const regime of ['Empty', 'Towing']) {
			await dialog().getByLabel('New regime', { exact: true }).fill(regime)
			await dialog().getByRole('button', { name: 'Add regime' }).click()
			await dialog().getByLabel(`Name of regime ${regime}`).waitFor({ timeout: 10000 })
		}
		await shot('33-fuel-regimes')
		await dialog().getByRole('button', { name: 'Close' }).last().click()
		await page.getByRole('dialog').waitFor({ state: 'detached', timeout: 10000 })
		const fills = [[6, '1000', '12', '45.00'], [1, '1330', '11', '42.35']]
		for (const [daysAgo, odometer, volume, total] of fills) {
			await page.locator('#maintenance-main').getByRole('button', { name: 'Add fill-up' }).click()
			await dialog().locator('input[type=datetime-local]').fill(local(daysAgo))
			await dialog().getByLabel('Odometer', { exact: true }).fill(odometer)
			await dialog().getByLabel('Fuel added', { exact: true }).fill(volume)
			await dialog().getByLabel('Total price (optional)').fill(total)
			if (odometer === '1330') {
				await shot('32-add-fill-up')
			}
			await dialog().getByRole('button', { name: 'Add fill-up' }).click()
			await page.getByRole('dialog').waitFor({ state: 'detached', timeout: 10000 })
			await page.locator('#maintenance-main', { hasText: `at ${odometer}` }).waitFor({ timeout: 10000 })
		}
		await page.locator('#maintenance-main', { hasText: '30.0 MPG' }).first().waitFor({ timeout: 10000 })
		await page.waitForLoadState('networkidle')
		await shot('31-fuel-tab')
		await nav('Dashboard').click()
		await page.locator('#fuel-heading').waitFor({ timeout: 10000 })
		await page.waitForLoadState('networkidle')
		await shot('34-dashboard-fuel')
	})

	await step('profile builder', async () => {
		const source = `Fuel van ${stamp}`
		const profile = `Van setup ${stamp}`
		const copy = `Copied van ${stamp}`
		// Save the fuel van's setup (with its regimes) as a workspace profile.
		await nav('Items').click()
		await heading('Items').waitFor({ state: 'visible', timeout: 10000 })
		await page.locator('#maintenance-main .list-item', { hasText: source }).first().click()
		await heading(source).waitFor({ state: 'visible', timeout: 10000 })
		await page.getByRole('tab', { name: 'Setup', exact: true }).click()
		await page.getByRole('button', { name: 'Save as profile' }).click()
		await dialog().getByLabel('Profile name', { exact: true }).fill(profile)
		await shot('43-save-as-profile')
		await dialog().getByRole('button', { name: 'Save profile' }).click()
		await page.getByRole('dialog').waitFor({ state: 'detached', timeout: 10000 })
		await page.getByRole('link', { name: 'Open in the profile editor' }).click()
		await heading(profile).waitFor({ state: 'visible', timeout: 10000 })
		await page.getByRole('button', { name: 'Fuel (2)' }).waitFor({ timeout: 10000 })
		// Edit it: add a spec and save.
		await page.getByRole('button', { name: 'Add spec' }).click()
		const spec = page.locator('#profile-specs fieldset.profile-row').last()
		await spec.getByLabel('Name', { exact: true }).fill('Tyre pressure')
		await spec.getByLabel('Value', { exact: true }).fill('50')
		await spec.getByLabel('Unit (optional)', { exact: true }).fill('psi')
		await page.getByRole('button', { name: 'Save profile' }).click()
		await page.locator('.profile-editor__saved', { hasText: `Saved ${profile}` }).waitFor({ timeout: 10000 })
		await page.locator('#profile-specs').scrollIntoViewIfNeeded()
		await shot('42-profile-editor')
		await nav('Profiles').click()
		await heading('Profiles').waitFor({ state: 'visible', timeout: 10000 })
		await page.locator('#maintenance-main', { hasText: profile }).waitFor({ timeout: 10000 })
		await page.waitForLoadState('networkidle')
		await shot('41-profiles')
		// Add an item that copies the van's setup: regimes yes, fill-ups no.
		await nav('Items').click()
		await heading('Items').waitFor({ state: 'visible', timeout: 10000 })
		await page.locator('#maintenance-main').getByRole('button', { name: 'Add item' }).first().click()
		await dialog().getByLabel('Name', { exact: true }).fill(copy)
		await dialog().getByLabel('Copy setup from', { exact: true }).fill(source)
		await page.getByRole('option', { name: source }).first().waitFor({ timeout: 5000 })
		await page.keyboard.press('Enter')
		await shot('44-add-item-copy')
		await dialog().getByRole('button', { name: 'Add item' }).click()
		await heading(copy).waitFor({ state: 'visible', timeout: 10000 })
		await page.getByRole('tab', { name: 'Fuel', exact: true }).click()
		await page.getByText('No fill-ups yet').waitFor({ timeout: 10000 })
		await page.getByRole('button', { name: 'Fuel options' }).click()
		await page.getByRole('menuitem', { name: 'Operating regimes' }).click()
		await dialog().getByLabel('Name of regime Towing').waitFor({ timeout: 10000 })
		await dialog().getByRole('button', { name: 'Close' }).last().click()
		await page.getByRole('dialog').waitFor({ state: 'detached', timeout: 10000 })
		await nav('Dashboard').click()
		await page.locator('#maintenance-main', { hasText: 'Latest tank:' }).first().waitFor({ timeout: 10000 })
		await page.waitForLoadState('networkidle')
		await shot('45-dashboard-fuel-label')
	})

	await step('dashboard log fuel', async () => {
		// An item with the Fuel tab and no meter: the first quick fill-up
		// creates the Odometer meter; a distance task on it then follows the
		// next fill-up onto the dashboard's due list without a page reload.
		const name = `Quick car ${stamp}`
		const task = `Odometer task ${stamp}`
		const local = (hoursAgo) => {
			const date = new Date(Date.now() - hoursAgo * 3600000)
			const pad = (value) => String(value).padStart(2, '0')
			return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
		}
		const api = (method, path, body) => page.evaluate(async ([method, path, body]) => {
			const response = await fetch(`${window.OC.webroot}/ocs/v2.php/apps/maintenance_tracker/api/v1${path}${path.includes('?') ? '&' : '?'}format=json`, {
				method,
				headers: { 'OCS-APIRequest': 'true', Accept: 'application/json', 'Content-Type': 'application/json', requesttoken: window.OC.requestToken },
				body: body === null ? undefined : JSON.stringify(body),
			})
			return { status: response.status, data: (await response.json()).ocs?.data }
		}, [method, path, body ?? null])
		const pickVehicle = async () => {
			const picker = dialog().getByLabel('Vehicle', { exact: true })
			if (await picker.count() > 0) {
				await picker.fill(name)
				await page.getByRole('option', { name }).first().click()
			}
			await dialog().getByText(name).first().waitFor({ timeout: 5000 }).catch(() => {})
		}
		await nav('Items').click()
		await heading('Items').waitFor({ state: 'visible', timeout: 10000 })
		await page.locator('#maintenance-main').getByRole('button', { name: 'Add item' }).first().click()
		await dialog().getByLabel('Name', { exact: true }).fill(name)
		await dialog().getByRole('button', { name: 'Add item' }).click()
		await heading(name).waitFor({ state: 'visible', timeout: 10000 })
		await page.waitForLoadState('networkidle')
		const assetUuid = /items\/([0-9a-f-]{36})/.exec(page.url())?.[1]
		if (!assetUuid) {
			throw new Error(`no item uuid in ${page.url()}`)
		}
		await page.getByRole('button', { name: `More actions for ${name}` }).click()
		await page.getByRole('menuitem', { name: 'Edit item' }).waitFor({ timeout: 10000 })
		const showFuel = page.getByRole('menuitem', { name: 'Show the Fuel tab' })
		if (await showFuel.count() > 0) {
			await showFuel.click()
		} else {
			await page.keyboard.press('Escape')
		}
		await page.getByRole('tab', { name: 'Fuel', exact: true }).waitFor({ timeout: 10000 })

		// First fill-up from the dashboard: the dialog says a meter will be made.
		await nav('Dashboard').click()
		await heading('Dashboard').waitFor({ state: 'visible', timeout: 10000 })
		await page.locator('[data-test="dashboard-log-fuel"]').click()
		await dialog().getByLabel('Odometer', { exact: true }).waitFor({ timeout: 10000 })
		await pickVehicle()
		await dialog().locator('[data-test="fuel-meter-hint"]', { hasText: 'creates an “Odometer” meter' }).waitFor({ timeout: 10000 })
		await dialog().locator('input[type=datetime-local]').fill(local(3))
		await dialog().getByLabel('Odometer', { exact: true }).fill('30000')
		await dialog().getByLabel('Fuel added', { exact: true }).fill('10')
		await shot('51-dashboard-log-fuel-new-meter')
		await dialog().getByRole('button', { name: 'Add fill-up' }).click()
		await page.getByRole('dialog').waitFor({ state: 'detached', timeout: 10000 })
		const meters = await api('GET', `/assets/${assetUuid}/meters`)
		const meter = (meters.data?.items ?? []).find((candidate) => candidate.key === 'odometer')
		if (!meter || meter.dimension !== 'distance' || meter.monotonic !== true) {
			throw new Error(`no monotonic odometer meter after the first fill-up: ${JSON.stringify(meters)}`)
		}
		// A distance task, done at 30000, due every 5000 mi.
		const definition = crypto.randomUUID()
		const created = await api('POST', `/assets/${assetUuid}/work-definitions`, { definition: { uuid: definition, key: `odo_${stamp}`, title: task, kind: 'maintenance', schedule: { combination: 'any', rules: [{ type: 'meter', meterUuid: meter.uuid, interval: { value: 5000, unit: 'mi' } }] } } })
		const done = await api('POST', `/assets/${assetUuid}/activities`, { activity: { uuid: crypto.randomUUID(), performedAt: new Date(Date.now() - 2 * 3600000).toISOString().replace(/\.\d{3}Z$/, 'Z'), summary: 'Oil change', items: [{ uuid: crypto.randomUUID(), definitionUuid: definition }], meters: [{ uuid: crypto.randomUUID(), meterUuid: meter.uuid, reading: { uuid: crypto.randomUUID(), value: '30000', unit: 'mi' } }] } })
		if (created.status >= 300 || done.status >= 300) {
			throw new Error(`task setup failed: ${created.status} ${done.status}`)
		}
		await page.goto(`${base}/index.php/apps/maintenance_tracker/#/`, { waitUntil: 'networkidle' })
		await heading('Dashboard').waitFor({ state: 'visible', timeout: 10000 })
		const attention = page.locator('section[aria-labelledby="attention-heading"]')
		await attention.waitFor({ timeout: 10000 })
		if (await attention.getByText(task).count() > 0) {
			throw new Error('the task is due before the second fill-up')
		}

		// Second fill-up from the fuel card: the vehicle is preselected and the
		// reading goes to the new meter; the task becomes overdue in place.
		await page.locator('[data-test="fuel-card-log-fuel"]').click()
		await dialog().locator('[data-test="fuel-meter-hint"]', { hasText: 'Also records an odometer reading on “Odometer”' }).waitFor({ timeout: 10000 })
		if (await dialog().getByLabel('Vehicle', { exact: true }).count() > 0) {
			await dialog().getByText(name).first().waitFor({ timeout: 5000 })
		}
		await dialog().locator('input[type=datetime-local]').fill(local(1))
		await dialog().getByLabel('Odometer', { exact: true }).fill('35100')
		await dialog().getByLabel('Fuel added', { exact: true }).fill('11')
		await shot('52-dashboard-log-fuel-existing-meter')
		await dialog().getByRole('button', { name: 'Add fill-up' }).click()
		await page.getByRole('dialog').waitFor({ state: 'detached', timeout: 10000 })
		await attention.getByText(task).waitFor({ timeout: 10000 })
		await page.waitForLoadState('networkidle')
		await shot('53-dashboard-due-after-fill-up')

		// The item: header Log fuel, the meter's value and the readings.
		await page.locator('#maintenance-main .list-item', { hasText: task }).first().click()
		await heading(name).waitFor({ state: 'visible', timeout: 10000 })
		await page.locator('[data-test="item-log-fuel"]').waitFor({ timeout: 10000 })
		await page.getByRole('tab', { name: 'Readings', exact: true }).click()
		await page.locator('#maintenance-main', { hasText: /35,?100/ }).first().waitFor({ timeout: 10000 })
		await page.waitForLoadState('networkidle')
		await shot('54-item-readings-from-fuel')
		// Log fuel from the item header while the Fuel tab is open: the tab,
		// the readings and the forecast refresh without a reload.
		await page.getByRole('tab', { name: 'Fuel', exact: true }).click()
		await page.locator('#maintenance-main', { hasText: 'at 35100' }).first().waitFor({ timeout: 10000 })
		await page.locator('[data-test="item-log-fuel"]').click()
		await dialog().getByLabel('Odometer', { exact: true }).fill('35400')
		await dialog().getByLabel('Fuel added', { exact: true }).fill('9')
		await shot('55-item-header-log-fuel')
		await dialog().getByRole('button', { name: 'Add fill-up' }).click()
		await page.getByRole('dialog').waitFor({ state: 'detached', timeout: 10000 })
		await page.locator('#maintenance-main', { hasText: 'at 35400' }).first().waitFor({ timeout: 10000 })
		await page.getByRole('tab', { name: 'Readings', exact: true }).click()
		await page.locator('#maintenance-main', { hasText: /35,?400/ }).first().waitFor({ timeout: 10000 })
		await page.waitForLoadState('networkidle')
		await shot('56-item-readings-after-header-log')
	})

	await step('settings sections', async () => {
		await nav('Settings').click()
		await heading('Settings').waitFor({ state: 'visible', timeout: 10000 })
		const sections = [['Workspaces', '12-settings-workspaces'], ['Members and ownership', '13-settings-members'], ['Costs and currency', '14-settings-costs'], ['Due soon window', '15-settings-forecast'], ['Reminders and calendar', '16-settings-reminders'], ['Categories', '17-settings-categories'], ['Export and restore', '18-settings-data']]
		for (const [label, file] of sections) {
			await page.getByRole('navigation', { name: 'Settings sections' }).getByRole('link', { name: label, exact: true }).click()
			await page.getByRole('heading', { name: label, level: 2, exact: true }).waitFor({ timeout: 5000 })
			await page.waitForLoadState('networkidle')
			await shot(file)
		}
	})

	await step('narrow screen', async () => {
		await page.setViewportSize({ width: 390, height: 844 })
		await page.goto(`${base}/index.php/apps/maintenance_tracker/#/`, { waitUntil: 'networkidle' })
		await heading('Dashboard').waitFor({ state: 'visible', timeout: 10000 })
		await shot('19-mobile-dashboard')
		await page.goto(`${base}/index.php/apps/maintenance_tracker/#/items`, { waitUntil: 'networkidle' })
		await heading('Items').waitFor({ state: 'visible', timeout: 10000 })
		await shot('20-mobile-items')
	})
} finally {
	await browser.close()
}

console.log(steps.join('\n'))
if (problems.length > 0) {
	console.error('Browser check failed:\n- ' + problems.join('\n- '))
	process.exit(1)
}
console.log('Browser check passed: every page rendered and the main flows worked with no app errors in the console.')
