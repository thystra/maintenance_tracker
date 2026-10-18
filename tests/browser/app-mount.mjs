/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Headless browser check: log in, open the app, and assert that the Vue app
 * mounted and rendered its UI with no console errors, page errors, or failed
 * app asset/API requests. Run by tests/integration/nextcloud-smoke.sh when
 * NC_SMOKE_BROWSER=1, inside the Playwright image on the Nextcloud
 * container's network.
 *
 * Environment: NC_BASE_URL, NC_USER, NC_PASSWORD, optional NC_SCREENSHOT.
 */

import { chromium } from 'playwright-core'

const base = process.env.NC_BASE_URL ?? 'http://127.0.0.1'
const user = process.env.NC_USER
const password = process.env.NC_PASSWORD
// Only problems caused by this app count; other Nextcloud apps on the page
// (user status, recommendations, ...) may log their own unrelated errors.
const ours = (text) => /\/(custom_)?apps\/maintenance_tracker\//.test(text ?? '')

const problems = []
const note = (message) => problems.push(message)

const browser = await chromium.launch()
try {
	const page = await browser.newPage()
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
		if (request.url().includes('maintenance_tracker')) {
			note(`request failed: ${request.url()} ${request.failure()?.errorText ?? ''}`)
		}
	})
	page.on('response', (response) => {
		if (response.status() >= 400 && response.url().includes('maintenance_tracker')) {
			note(`HTTP ${response.status()}: ${response.url()}`)
		}
	})

	await page.goto(`${base}/index.php/login`)
	await page.fill('#user', user)
	await page.fill('#password', password)
	await Promise.all([page.waitForURL((url) => !url.pathname.includes('/login')), page.click('button[type=submit]')])

	await page.goto(`${base}/index.php/apps/maintenance_tracker/`, { waitUntil: 'networkidle' })
	const script = page.locator('script[src*="/maintenance_tracker/js/maintenance_tracker-main.mjs"]')
	if (await script.count() !== 1) {
		problems.push('the app script tag is missing (are js/ and css/ installed?)')
	}
	const mounted = page.locator('#maintenance_tracker .page-shell')
	try {
		await mounted.waitFor({ state: 'visible', timeout: 15000 })
		await page.getByRole('heading', { name: 'Maintenance Tracker', level: 1 }).waitFor({ state: 'visible', timeout: 5000 })
		await page.getByRole('heading', { name: 'Add maintained item' }).waitFor({ state: 'visible', timeout: 5000 })
	} catch (error) {
		problems.push(`app UI did not render: ${error.message.split('\n')[0]}`)
	}
	if (process.env.NC_SCREENSHOT) {
		await page.screenshot({ path: process.env.NC_SCREENSHOT, fullPage: true })
	}
} finally {
	await browser.close()
}

if (problems.length > 0) {
	console.error('Browser check failed:\n- ' + problems.join('\n- '))
	process.exit(1)
}
console.log('Browser check passed: the app mounted with no app errors in the console.')
