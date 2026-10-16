<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Controller;

use OCA\MaintenanceTracker\Service\AuthorizationCatalog;
use OCA\MaintenanceTracker\Service\CurrentUser;
use OCA\MaintenanceTracker\Service\PartService;
use OCA\MaintenanceTracker\Service\WorkspaceService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

final class PartController extends OCSController {
	use OcsErrorMapping;

	public function __construct(
		string $appName,
		IRequest $request,
		private CurrentUser $currentUser,
		private WorkspaceService $workspaces,
		private PartService $service,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/parts')]
	public function index(?string $workspace = null, bool $includeArchived = false): DataResponse {
		return $this->run(AuthorizationCatalog::PART_READ, $workspace, fn ($c): DataResponse => new DataResponse([
			'workspace' => $c->workspace()->getUuid(),
			'items' => $this->service->list($c, $includeArchived),
		]));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/parts/{uuid}')]
	public function show(string $uuid, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::PART_READ, $workspace, fn ($c): DataResponse => new DataResponse($this->service->show($c, $uuid)));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/assets/{assetUuid}/compatible-parts')]
	public function compatibleParts(string $assetUuid, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::PART_READ, $workspace, fn ($c): DataResponse => new DataResponse([
			'assetUuid' => $assetUuid,
			'items' => $this->service->compatibilityForAsset($c, $assetUuid),
		]));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/parts')]
	public function create(array $part, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::PART_MANAGE, $workspace, function ($c) use ($part): DataResponse {
			$result = $this->service->create($c, $part);
			return new DataResponse($result['part'], $result['created'] ? Http::STATUS_CREATED : Http::STATUS_OK);
		});
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PATCH', url: '/api/v1/parts/{uuid}')]
	public function update(string $uuid, int $expectedRevision, array $part, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::PART_MANAGE, $workspace, fn ($c): DataResponse => new DataResponse($this->service->update($c, $uuid, $expectedRevision, $part)));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/parts/{uuid}')]
	public function destroy(string $uuid, int $expectedRevision, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::PART_MANAGE, $workspace, fn ($c): DataResponse => new DataResponse($this->service->archive($c, $uuid, $expectedRevision)));
	}
}
