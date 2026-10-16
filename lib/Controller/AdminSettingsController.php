<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Controller;

use OCA\MaintenanceTracker\AppInfo\Application;
use OCA\MaintenanceTracker\Service\OutsideLinkSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Admin-only and CSRF-checked through the framework defaults: this controller
 * deliberately carries no attribute that relaxes either check.
 */
final class AdminSettingsController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private OutsideLinkSettings $outsideLinks,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Update outside links (admin settings)
	 *
	 * @param string $enabled Enabled
	 * @return RedirectResponse
	 */
	#[FrontpageRoute(verb: 'POST', url: '/admin/outside-links')]
	public function updateOutsideLinks(string $enabled = '0'): RedirectResponse {
		$value = $enabled === '1';
		$this->outsideLinks->setEnabled($value);
		$this->logger->info('Maintenance Tracker outside links ' . ($value ? 'enabled' : 'disabled') . ' by an administrator', ['app' => Application::APP_ID]);
		return new RedirectResponse($this->urlGenerator->linkToRoute('settings.AdminSettings.index', ['section' => Application::APP_ID]));
	}
}
