<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Controller;

use OCA\MaintenanceTracker\Service\AuthorizationCatalog;
use OCA\MaintenanceTracker\Service\EvidenceService;
use OCA\MaintenanceTracker\Service\CurrentUser;
use OCA\MaintenanceTracker\Service\WorkspaceService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * Evidence references into Nextcloud Files plus the app-managed workspace
 * evidence folder. File bytes never pass through these endpoints.
 */
final class EvidenceController extends OCSController {
	use OcsErrorMapping;

	public function __construct(
		string $appName,
		IRequest $request,
		private CurrentUser $currentUser,
		private WorkspaceService $workspaces,
		private EvidenceService $service,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/evidence-folder')]
	public function folder(?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::EVIDENCE_READ, $workspace, fn ($c): DataResponse => new DataResponse($this->service->folderStatus($c, $this->currentUser->uid())));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/evidence-folder/upload-target')]
	public function uploadTarget(array $target, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::EVIDENCE_CREATE, $workspace, fn ($c): DataResponse => new DataResponse($this->service->uploadTarget($c, $this->currentUser->uid(), $target)));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/evidence')]
	public function index(string $targetType, string $targetUuid, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::EVIDENCE_READ, $workspace, fn ($c): DataResponse => new DataResponse($this->service->listForTarget($c, $this->currentUser->uid(), $targetType, $targetUuid)));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/evidence')]
	public function create(array $evidence, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::EVIDENCE_CREATE, $workspace, fn ($c): DataResponse => new DataResponse($this->service->create($c, $this->currentUser->uid(), $evidence), Http::STATUS_CREATED));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/evidence/{uuid}')]
	public function show(string $uuid, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::EVIDENCE_READ, $workspace, fn ($c): DataResponse => new DataResponse($this->service->show($c, $this->currentUser->uid(), $uuid)));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PATCH', url: '/api/v1/evidence/{uuid}')]
	public function update(string $uuid, int $expectedRevision, array $evidence, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::EVIDENCE_CREATE, $workspace, fn ($c): DataResponse => new DataResponse($this->service->update($c, $this->currentUser->uid(), $uuid, $expectedRevision, $evidence)));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/evidence/{uuid}')]
	public function destroy(string $uuid, int $expectedRevision, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::EVIDENCE_CREATE, $workspace, fn ($c): DataResponse => new DataResponse($this->service->archive($c, $this->currentUser->uid(), $uuid, $expectedRevision)));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/evidence/{uuid}/links')]
	public function link(string $uuid, string $targetType, string $targetUuid, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::EVIDENCE_CREATE, $workspace, fn ($c): DataResponse => new DataResponse($this->service->link($c, $this->currentUser->uid(), $uuid, $targetType, $targetUuid)));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/evidence/{uuid}/links/{targetType}/{targetUuid}')]
	public function unlink(string $uuid, string $targetType, string $targetUuid, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::EVIDENCE_CREATE, $workspace, fn ($c): DataResponse => new DataResponse($this->service->unlink($c, $this->currentUser->uid(), $uuid, $targetType, $targetUuid)));
	}
}
