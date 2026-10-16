<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Controller;

use OCA\MaintenanceTracker\Service\AuthorizationCatalog;
use OCA\MaintenanceTracker\Service\CostService;
use OCA\MaintenanceTracker\Service\CurrentUser;
use OCA\MaintenanceTracker\Service\WorkspaceService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

final class CostController extends OCSController {
	use OcsErrorMapping;

	public function __construct(
		string $appName,
		IRequest $request,
		private CurrentUser $currentUser,
		private WorkspaceService $workspaces,
		private CostService $service,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/cost-settings')]
	public function settings(?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::COST_READ, $workspace, fn ($c): DataResponse => new DataResponse($this->service->settingsToApi($c)));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PATCH', url: '/api/v1/cost-settings')]
	public function updateSettings(int $expectedRevision, array $settings, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::WORKSPACE_SETTINGS_MANAGE, $workspace, fn ($c): DataResponse => new DataResponse($this->service->updateSettings($c, $expectedRevision, $settings)));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/costs/totals')]
	public function totals(?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::COST_READ, $workspace, fn ($c): DataResponse => new DataResponse($this->service->workspaceTotals($c)));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/assets/{assetUuid}/costs')]
	public function index(string $assetUuid, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::COST_READ, $workspace, fn ($c): DataResponse => new DataResponse($this->service->listForAsset($c, $assetUuid)));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/assets/{assetUuid}/costs')]
	public function create(string $assetUuid, array $cost, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::COST_CREATE, $workspace, fn ($c): DataResponse => new DataResponse($this->service->create($c, $assetUuid, $cost), Http::STATUS_CREATED));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/costs/{uuid}')]
	public function show(string $uuid, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::COST_READ, $workspace, fn ($c): DataResponse => new DataResponse($this->service->show($c, $uuid)));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PATCH', url: '/api/v1/costs/{uuid}')]
	public function update(string $uuid, int $expectedRevision, array $cost, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::COST_MANAGE, $workspace, fn ($c): DataResponse => new DataResponse($this->service->update($c, $uuid, $expectedRevision, $cost)));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/costs/{uuid}')]
	public function destroy(string $uuid, int $expectedRevision, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::COST_MANAGE, $workspace, fn ($c): DataResponse => new DataResponse($this->service->archive($c, $uuid, $expectedRevision)));
	}
}
