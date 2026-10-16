<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Controller;

use OCA\MaintenanceTracker\Service\AuthorizationCatalog;
use OCA\MaintenanceTracker\Service\CurrentUser;
use OCA\MaintenanceTracker\Service\LinkService;
use OCA\MaintenanceTracker\Service\WorkspaceService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * Store/vendor links for canonical parts and vendors. Reading follows the
 * subject's read capability; adding or archiving follows its manage
 * capability.
 */
final class LinkController extends OCSController {
	use OcsErrorMapping;

	public function __construct(
		string $appName,
		IRequest $request,
		private CurrentUser $currentUser,
		private WorkspaceService $workspaces,
		private LinkService $service,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/parts/{uuid}/links')]
	public function partLinks(string $uuid, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::PART_READ, $workspace, fn ($c): DataResponse => new DataResponse($this->service->list($c, 'part', $uuid)));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/parts/{uuid}/links')]
	public function createPartLink(string $uuid, array $link, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::PART_MANAGE, $workspace, fn ($c): DataResponse => new DataResponse($this->service->create($c, 'part', $uuid, $link), Http::STATUS_CREATED));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/parts/{uuid}/links/{linkUuid}')]
	public function archivePartLink(string $uuid, string $linkUuid, int $expectedRevision, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::PART_MANAGE, $workspace, fn ($c): DataResponse => new DataResponse($this->service->archive($c, 'part', $uuid, $linkUuid, $expectedRevision)));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/vendors/{uuid}/links')]
	public function vendorLinks(string $uuid, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::VENDOR_READ, $workspace, fn ($c): DataResponse => new DataResponse($this->service->list($c, 'vendor', $uuid)));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/vendors/{uuid}/links')]
	public function createVendorLink(string $uuid, array $link, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::VENDOR_MANAGE, $workspace, fn ($c): DataResponse => new DataResponse($this->service->create($c, 'vendor', $uuid, $link), Http::STATUS_CREATED));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/vendors/{uuid}/links/{linkUuid}')]
	public function archiveVendorLink(string $uuid, string $linkUuid, int $expectedRevision, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::VENDOR_MANAGE, $workspace, fn ($c): DataResponse => new DataResponse($this->service->archive($c, 'vendor', $uuid, $linkUuid, $expectedRevision)));
	}
}
