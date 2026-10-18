/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Headless browser check: log in, open the app, visit every page, run the
 * main create/delete flows (vendor, part, item with a task, item deletion),
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
