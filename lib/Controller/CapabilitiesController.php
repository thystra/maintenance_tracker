<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Controller;

use OCA\MaintenanceTracker\AppInfo\Application;
use OCA\MaintenanceTracker\Capability;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

final class CapabilitiesController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private Capability $capability,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Describe the pre-release server API.
	 *
	 * @return DataResponse<Http::STATUS_OK, array{
	 *     app: string,
	 *     appVersion: string,
	 *     apiVersion: string,
	 *     apiStability: 'experimental',
	 *     features: list<string>
	 * }, array{}>
	 *
	 * 200: Capability information returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/capabilities')]
	public function index(): DataResponse {
		$capabilities = $this->capability->getCapabilities();

		return new DataResponse([
			'app' => Application::APP_ID,
			...$capabilities[Application::APP_ID],
		]);
	}
}
