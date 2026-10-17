<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Controller;

use OCA\MaintenanceTracker\Exception\AccessDeniedException;
use OCA\MaintenanceTracker\Exception\NotFoundException;
use OCA\MaintenanceTracker\Exception\RevisionConflictException;
use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Service\ActivityService;
use OCA\MaintenanceTracker\Service\AuthorizationCatalog;
use OCA\MaintenanceTracker\Service\CurrentUser;
use OCA\MaintenanceTracker\Service\WorkspaceService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCS\OCSPreconditionFailedException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

final class ActivityController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private CurrentUser $currentUser,
		private WorkspaceService $workspaces,
		private ActivityService $service,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * List activitys
	 *
	 * @param string $assetUuid UUID of the asset
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 * @throws OCSBadRequestException Invalid input
	 * @throws OCSForbiddenException Not allowed
	 * @throws OCSNotFoundException Not found
	 *
	 * 200: Success
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/assets/{assetUuid}/activities')]
	public function index(string $assetUuid, ?string $workspace = null): DataResponse {
		return $this->run(
			fn ($c) => new DataResponse(['workspace' => $c->workspace()->getUuid(), 'items' => $this->service->list($c, $assetUuid)]),
			$workspace,
			AuthorizationCatalog::ACTIVITY_READ,
		);
	}

	/**
	 * Get activity
	 *
	 * @param string $uuid UUID of the activity
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 * @throws OCSBadRequestException Invalid input
	 * @throws OCSForbiddenException Not allowed
	 * @throws OCSNotFoundException Not found
	 *
	 * 200: Success
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/activities/{uuid}')]
	public function show(string $uuid, ?string $workspace = null): DataResponse {
		return $this->run(
			fn ($c) => new DataResponse($this->service->show($c, $uuid)),
			$workspace,
			AuthorizationCatalog::ACTIVITY_READ,
		);
	}

	/**
	 * Create activity
	 *
	 * @param string $assetUuid UUID of the asset
	 * @param array<string, mixed> $activity Activity fields
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_CREATED, array<string, mixed>, array{}>
	 * @throws OCSBadRequestException Invalid input
	 * @throws OCSForbiddenException Not allowed
	 * @throws OCSNotFoundException Not found
	 *
	 * 201: Activity created
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/assets/{assetUuid}/activities')]
	public function create(string $assetUuid, array $activity, ?string $workspace = null): DataResponse {
		return $this->run(
			fn ($c) => new DataResponse($this->service->create($c, $assetUuid, $activity), Http::STATUS_CREATED),
			$workspace,
			AuthorizationCatalog::ACTIVITY_CREATE,
		);
	}

	/**
	 * Update activity
	 *
	 * @param string $uuid UUID of the activity
	 * @param int $expectedRevision Revision the client last read, for optimistic concurrency
	 * @param array<string, mixed> $activity Activity fields
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 * @throws OCSBadRequestException Invalid input
	 * @throws OCSForbiddenException Not allowed
	 * @throws OCSNotFoundException Not found
	 *
	 * 200: Success
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PATCH', url: '/api/v1/activities/{uuid}')]
	public function update(string $uuid, int $expectedRevision, array $activity, ?string $workspace = null): DataResponse {
		return $this->run(
			fn ($c) => new DataResponse($this->service->update($c, $uuid, $expectedRevision, $activity)),
			$workspace,
			AuthorizationCatalog::ACTIVITY_MANAGE,
		);
	}

	/**
	 * Delete activity
	 *
	 * @param string $uuid UUID of the activity
	 * @param int $expectedRevision Revision the client last read, for optimistic concurrency
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 * @throws OCSBadRequestException Invalid input
	 * @throws OCSForbiddenException Not allowed
	 * @throws OCSNotFoundException Not found
	 *
	 * 200: Success
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/activities/{uuid}')]
	public function destroy(string $uuid, int $expectedRevision, ?string $workspace = null): DataResponse {
		return $this->run(
			fn ($c) => new DataResponse($this->service->archive($c, $uuid, $expectedRevision)),
			$workspace,
			AuthorizationCatalog::ACTIVITY_MANAGE,
		);
	}

	private function run(callable $op, ?string $workspace, string $cap): DataResponse {
		try {
			return $this->workspaces->runWithCapability($this->currentUser->uid(), $workspace, $cap, $op);
		} catch (AccessDeniedException $e) {
			throw new OCSForbiddenException($e->getMessage(), $e);
		} catch (NotFoundException $e) {
			throw new OCSNotFoundException($e->getMessage(), $e);
		} catch (ValidationException $e) {
			throw new OCSBadRequestException($e->getMessage(), $e);
		} catch (RevisionConflictException $e) {
			throw new OCSPreconditionFailedException($e->getMessage(), $e);
		}
	}
}
