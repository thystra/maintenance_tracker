<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Controller;

use OCA\MaintenanceTracker\Service\AuthorizationCatalog;
use OCA\MaintenanceTracker\Service\CurrentUser;
use OCA\MaintenanceTracker\Service\VendorService;
use OCA\MaintenanceTracker\Service\WorkspaceService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

final class VendorController extends OCSController {
	use OcsErrorMapping;

	public function __construct(
		string $appName,
		IRequest $request,
		private CurrentUser $currentUser,
		private WorkspaceService $workspaces,
		private VendorService $service,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * List vendors
	 *
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @param bool $includeArchived Include archived
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 *
	 * 200: Success
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/vendors')]
	public function index(?string $workspace = null, bool $includeArchived = false): DataResponse {
		return $this->run(AuthorizationCatalog::VENDOR_READ, $workspace, fn ($c): DataResponse => new DataResponse([
			'workspace' => $c->workspace()->getUuid(),
			'items' => $this->service->list($c, $includeArchived),
		]));
	}

	/**
	 * Get vendor
	 *
	 * @param string $uuid UUID of the vendor
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 *
	 * 200: Success
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/vendors/{uuid}')]
	public function show(string $uuid, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::VENDOR_READ, $workspace, fn ($c): DataResponse => new DataResponse($this->service->show($c, $uuid)));
	}

	/**
	 * Create vendor
	 *
	 * @param array<string, mixed> $vendor Vendor fields
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_CREATED, array<string, mixed>, array{}>
	 *
	 * 201: Vendor created
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/vendors')]
	public function create(array $vendor, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::VENDOR_MANAGE, $workspace, fn ($c): DataResponse => new DataResponse($this->service->create($c, $vendor), Http::STATUS_CREATED));
	}

	/**
	 * Update vendor
	 *
	 * @param string $uuid UUID of the vendor
	 * @param int $expectedRevision Revision the client last read, for optimistic concurrency
	 * @param array<string, mixed> $vendor Vendor fields
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 *
	 * 200: Success
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PATCH', url: '/api/v1/vendors/{uuid}')]
	public function update(string $uuid, int $expectedRevision, array $vendor, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::VENDOR_MANAGE, $workspace, fn ($c): DataResponse => new DataResponse($this->service->update($c, $uuid, $expectedRevision, $vendor)));
	}

	/**
	 * Delete vendor
	 *
	 * @param string $uuid UUID of the vendor
	 * @param int $expectedRevision Revision the client last read, for optimistic concurrency
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 *
	 * 200: Success
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/vendors/{uuid}')]
	public function destroy(string $uuid, int $expectedRevision, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::VENDOR_MANAGE, $workspace, fn ($c): DataResponse => new DataResponse($this->service->archive($c, $uuid, $expectedRevision)));
	}
}
