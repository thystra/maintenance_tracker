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
use OCA\MaintenanceTracker\Service\AssetService;
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

final class AssetController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private CurrentUser $currentUser,
		private WorkspaceService $workspaceService,
		private AssetService $assetService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * List active assets in an accessible workspace.
	 *
	 * @param string|null $workspace Workspace UUID; defaults to the personal workspace
	 * @param string|null $cursor Opaque cursor returned by the previous page
	 * @param int $limit Number of records to return, from 1 to 100
	 *
	 * @return DataResponse<Http::STATUS_OK, array{
	 *     workspace: string,
	 *     items: list<array<string, mixed>>,
	 *     nextCursor: string|null
	 * }, array{}>
	 *
	 * 200: Assets returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/assets')]
	public function index(
		?string $workspace = null,
		?string $cursor = null,
		int $limit = 100,
	): DataResponse {
		try {
			return $this->workspaceService->runWithCapability(
				$this->currentUser->uid(),
				$workspace,
				'inventory.read',
				function ($context) use ($cursor, $limit): DataResponse {
					$page = $this->assetService->findPage($context, $cursor, $limit);

					return new DataResponse([
						'workspace' => $context->workspace()->getUuid(),
						'items' => array_map(
							static fn ($asset): array => $asset->toApi(),
							$page['items'],
						),
						'nextCursor' => $page['nextCursor'],
					]);
				},
			);
		} catch (AccessDeniedException $exception) {
			throw new OCSForbiddenException($exception->getMessage(), $exception);
		} catch (ValidationException $exception) {
			throw new OCSBadRequestException($exception->getMessage(), $exception);
		}
	}

	/**
	 * Return one asset.
	 *
	 * @param string $uuid Asset UUID
	 * @param string|null $workspace Workspace UUID; defaults to the personal workspace
	 *
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 *
	 * 200: Asset returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/assets/{uuid}')]
	public function show(string $uuid, ?string $workspace = null): DataResponse {
		try {
			return $this->workspaceService->runWithCapability(
				$this->currentUser->uid(),
				$workspace,
				'inventory.read',
				fn ($context): DataResponse => new DataResponse(
					$this->assetService->find($context, $uuid)->toApi(),
				),
			);
		} catch (AccessDeniedException $exception) {
			throw new OCSForbiddenException($exception->getMessage(), $exception);
		} catch (NotFoundException $exception) {
			throw new OCSNotFoundException($exception->getMessage(), $exception);
		}
	}

	/**
	 * Create an asset. The optional client UUID makes offline retries safe.
	 *
	 * @param array<string, mixed> $asset Asset fields
	 * @param string|null $workspace Workspace UUID; defaults to the personal workspace
	 *
	 * @return DataResponse<Http::STATUS_CREATED, array<string, mixed>, array{}>
	 *
	 * 201: Asset created
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/assets')]
	public function create(array $asset, ?string $workspace = null): DataResponse {
		try {
			return $this->workspaceService->runWithCapability(
				$this->currentUser->uid(),
				$workspace,
				'inventory.manage',
				function ($context) use ($asset): DataResponse {
					$created = $this->assetService->create($context, $asset);

					return new DataResponse($created->toApi(), Http::STATUS_CREATED);
				},
			);
		} catch (AccessDeniedException $exception) {
			throw new OCSForbiddenException($exception->getMessage(), $exception);
		} catch (ValidationException $exception) {
			throw new OCSBadRequestException($exception->getMessage(), $exception);
		} catch (RevisionConflictException $exception) {
			throw new OCSPreconditionFailedException($exception->getMessage(), $exception);
		}
	}

	/**
	 * Patch an asset using optimistic revision checking.
	 *
	 * @param string $uuid Asset UUID
	 * @param int $expectedRevision Revision last seen by the client
	 * @param array<string, mixed> $asset Fields to change
	 * @param string|null $workspace Workspace UUID; defaults to the personal workspace
	 *
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 *
	 * 200: Asset updated
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PATCH', url: '/api/v1/assets/{uuid}')]
	public function update(
		string $uuid,
		int $expectedRevision,
		array $asset,
		?string $workspace = null,
	): DataResponse {
		try {
			return $this->workspaceService->runWithCapability(
				$this->currentUser->uid(),
				$workspace,
				'inventory.manage',
				function ($context) use ($uuid, $expectedRevision, $asset): DataResponse {
					$updated = $this->assetService->update(
						$context,
						$uuid,
						$expectedRevision,
						$asset,
					);

					return new DataResponse($updated->toApi());
				},
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
	 * Soft-delete an asset while retaining a sync tombstone.
	 *
	 * @param string $uuid Asset UUID
	 * @param int $expectedRevision Revision last seen by the client
	 * @param string|null $workspace Workspace UUID; defaults to the personal workspace
	 *
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 *
	 * 200: Asset archived
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/assets/{uuid}')]
	public function destroy(
		string $uuid,
		int $expectedRevision,
		?string $workspace = null,
	): DataResponse {
		try {
			return $this->workspaceService->runWithCapability(
				$this->currentUser->uid(),
				$workspace,
				'inventory.manage',
				function ($context) use ($uuid, $expectedRevision): DataResponse {
					$archived = $this->assetService->archive(
						$context,
						$uuid,
						$expectedRevision,
					);

					return new DataResponse($archived->toApi());
				},
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
