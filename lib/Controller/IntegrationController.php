<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Controller;

use OCA\MaintenanceTracker\Service\CurrentUser;
use OCA\MaintenanceTracker\Service\IntegrationSettingsService;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * The authenticated user's own integration preferences (reminders and the
 * calendar receiving projected due dates). These are not workspace data and
 * never accept a caller-supplied user; calendar lookups go through the
 * public Calendar API outside any app database transaction.
 */
final class IntegrationController extends OCSController {
	use OcsErrorMapping;

	public function __construct(
		string $appName,
		IRequest $request,
		private CurrentUser $currentUser,
		private IntegrationSettingsService $settings,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/integrations/settings')]
	public function show(): DataResponse {
		return $this->mapErrors(fn (): DataResponse => new DataResponse($this->settings->get($this->currentUser->uid())));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/integrations/settings')]
	public function update(array $settings = []): DataResponse {
		return $this->mapErrors(fn (): DataResponse => new DataResponse($this->settings->update($this->currentUser->uid(), $settings)));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/integrations/calendars')]
	public function calendars(): DataResponse {
		return $this->mapErrors(fn (): DataResponse => new DataResponse(['items' => $this->settings->writableCalendars($this->currentUser->uid())]));
	}
}
