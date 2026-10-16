<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Settings;

use OCA\MaintenanceTracker\AppInfo\Application;
use OCA\MaintenanceTracker\Service\OutsideLinkSettings;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;

/**
 * Administrator controls. Rendered server-side as a plain form so the switch
 * works without the app's JavaScript bundle; the POST is CSRF-protected and
 * admin-only through the framework defaults.
 */
final class AdminSettings implements ISettings {
	public function __construct(
		private OutsideLinkSettings $outsideLinks,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getForm(): TemplateResponse {
		return new TemplateResponse(Application::APP_ID, 'admin-settings', [
			'outsideLinksEnabled' => $this->outsideLinks->enabled(),
			'action' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.adminSettings.updateOutsideLinks'),
		], TemplateResponse::RENDER_AS_BLANK);
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}
}
