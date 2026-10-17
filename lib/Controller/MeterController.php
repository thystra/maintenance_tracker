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
use OCA\MaintenanceTracker\Service\AuthorizationCatalog;
use OCA\MaintenanceTracker\Service\CurrentUser;
use OCA\MaintenanceTracker\Service\MeterService;
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

final class MeterController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private CurrentUser $currentUser,
		private WorkspaceService $workspaces,
		private MeterService $service,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * List meters
	 *
	 * @param string $assetUuid UUID of the asset
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 * @throws OCSForbiddenException Not allowed
	 * @throws OCSNotFoundException Not found
	 *
	 * 200: Success
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/assets/{assetUuid}/meters')]
	public function index(string $assetUuid, ?string $workspace = null): DataResponse {
		try {
			return $this->workspaces->runWithCapability(
				$this->currentUser->uid(),
				$workspace,
				AuthorizationCatalog::METER_READ,
				fn ($context): DataResponse => new DataResponse([
					'workspace' => $context->workspace()->getUuid(),
					'items' => $this->service->list($context, $assetUuid),
				]),
			);
		} catch (AccessDeniedException $exception) {
			throw new OCSForbiddenException($exception->getMessage(), $exception);
		} catch (NotFoundException $exception) {
			throw new OCSNotFoundException($exception->getMessage(), $exception);
		}
	}

	/**
	 * Get meter
	 *
	 * @param string $uuid UUID of the meter
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 * @throws OCSBadRequestException Invalid input
	 * @throws OCSForbiddenException Not allowed
	 * @throws OCSNotFoundException Not found
	 *
	 * 200: Success
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/meters/{uuid}')]
	public function show(string $uuid, ?string $workspace = null): DataResponse {
		try {
			return $this->workspaces->runWithCapability(
				$this->currentUser->uid(),
				$workspace,
				AuthorizationCatalog::METER_READ,
				fn ($context): DataResponse => new DataResponse($this->service->show($context, $uuid)),
			);
		} catch (AccessDeniedException $exception) {
			throw new OCSForbiddenException($exception->getMessage(), $exception);
		} catch (NotFoundException $exception) {
			throw new OCSNotFoundException($exception->getMessage(), $exception);
		} catch (ValidationException $exception) {
			throw new OCSBadRequestException($exception->getMessage(), $exception);
		}
	}

	/**
	 * Create meter
	 *
	 * @param string $assetUuid UUID of the asset
	 * @param array<string, mixed> $meter Meter fields
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_CREATED, array<string, mixed>, array{}>
	 * @throws OCSBadRequestException Invalid input
	 * @throws OCSForbiddenException Not allowed
	 * @throws OCSNotFoundException Not found
	 *
	 * 201: Meter created
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/assets/{assetUuid}/meters')]
	public function create(string $assetUuid, array $meter, ?string $workspace = null): DataResponse {
		try {
			return $this->workspaces->runWithCapability(
				$this->currentUser->uid(),
				$workspace,
				AuthorizationCatalog::METER_MANAGE,
				fn ($context): DataResponse => new DataResponse(
					$this->service->create($context, $assetUuid, $meter),
					Http::STATUS_CREATED,
				),
			);
		} catch (AccessDeniedException $exception) {
			throw new OCSForbiddenException($exception->getMessage(), $exception);
		} catch (NotFoundException $exception) {
			throw new OCSNotFoundException($exception->getMessage(), $exception);
		} catch (ValidationException $exception) {
			throw new OCSBadRequestException($exception->getMessage(), $exception);
		} catch (RevisionConflictException $exception) {
			throw new OCSPreconditionFailedException($exception->getMessage(), $exception);
		}
	}

	/**
	 * Update meter
	 *
	 * @param string $uuid UUID of the meter
	 * @param int $expectedRevision Revision the client last read, for optimistic concurrency
	 * @param array<string, mixed> $meter Meter fields
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 * @throws OCSBadRequestException Invalid input
	 * @throws OCSForbiddenException Not allowed
	 * @throws OCSNotFoundException Not found
	 *
	 * 200: Success
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PATCH', url: '/api/v1/meters/{uuid}')]
	public function update(string $uuid, int $expectedRevision, array $meter, ?string $workspace = null): DataResponse {
		try {
			return $this->workspaces->runWithCapability(
				$this->currentUser->uid(),
				$workspace,
				AuthorizationCatalog::METER_MANAGE,
				fn ($context): DataResponse => new DataResponse(
					$this->service->update($context, $uuid, $expectedRevision, $meter),
				),
			);
		} catch (AccessDeniedException $exception) {
			throw new OCSForbiddenException($exception->getMessage(), $exception);
		} catch (NotFoundException $exception) {
			throw new OCSNotFoundException($exception->getMessage(), $exception);
		} catch (ValidationException $exception) {
			throw new OCSBadRequestException($exception->getMessage(), $exception);
		} catch (RevisionConflictException $exception) {
			throw new OCSPreconditionFailedException($exception->getMessage(), $exception);
		}
	}

	/**
	 * Delete meter
	 *
	 * @param string $uuid UUID of the meter
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
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/meters/{uuid}')]
	public function destroy(string $uuid, int $expectedRevision, ?string $workspace = null): DataResponse {
		try {
			return $this->workspaces->runWithCapability(
				$this->currentUser->uid(),
				$workspace,
				AuthorizationCatalog::METER_MANAGE,
				fn ($context): DataResponse => new DataResponse(
					$this->service->archive($context, $uuid, $expectedRevision),
				),
			);
		} catch (AccessDeniedException $exception) {
			throw new OCSForbiddenException($exception->getMessage(), $exception);
		} catch (NotFoundException $exception) {
			throw new OCSNotFoundException($exception->getMessage(), $exception);
		} catch (ValidationException $exception) {
			throw new OCSBadRequestException($exception->getMessage(), $exception);
		} catch (RevisionConflictException $exception) {
			throw new OCSPreconditionFailedException($exception->getMessage(), $exception);
		}
	}
}
