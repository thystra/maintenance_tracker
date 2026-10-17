<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Controller;

use OCA\MaintenanceTracker\Service\AuthorizationCatalog;
use OCA\MaintenanceTracker\Service\CurrentUser;
use OCA\MaintenanceTracker\Service\OwnershipTransferService;
use OCA\MaintenanceTracker\Service\WorkspaceService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * Workspace ownership transfer. Only the offer runs inside a capability
 * transaction; everything that reads or moves Files (quota preview, the
 * transfer steps, share re-creation) runs outside any database transaction.
 */
final class OwnershipTransferController extends OCSController {
	use OcsErrorMapping;

	public function __construct(
		string $appName,
		IRequest $request,
		private CurrentUser $currentUser,
		private WorkspaceService $workspaces,
		private OwnershipTransferService $service,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * List ownership transfers
	 *
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 *
	 * 200: Success
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/ownership-transfers')]
	public function index(): DataResponse {
		return $this->mapErrors(fn (): DataResponse => new DataResponse($this->service->listForUser($this->currentUser->uid())));
	}

	/**
	 * Create ownership transfer
	 *
	 * @param string $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @param array<string, mixed> $transfer Transfer fields
	 * @return DataResponse<Http::STATUS_CREATED, array<string, mixed>, array{}>
	 *
	 * 201: Ownership transfer created
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/workspaces/{workspace}/ownership-transfers')]
	public function create(string $workspace, array $transfer): DataResponse {
		return $this->mapErrors(function () use ($workspace, $transfer): DataResponse {
			$actor = $this->currentUser->uid();
			$toUid = is_string($transfer['toUid'] ?? null) ? trim($transfer['toUid']) : '';
			$affected = $toUid !== '' && $toUid !== $actor ? [$toUid] : [];
			$uuid = $this->workspaces->runWithCapabilityForUsers(
				$actor,
				$affected,
				$workspace,
				AuthorizationCatalog::WORKSPACE_OWNERSHIP_TRANSFER,
				fn ($context): string => $this->service->offer($context, $actor, $transfer),
			);
			return new DataResponse($this->service->show($actor, $uuid), Http::STATUS_CREATED);
		});
	}

	/**
	 * Get ownership transfer
	 *
	 * @param string $uuid UUID of the ownership transfer
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 *
	 * 200: Success
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/ownership-transfers/{uuid}')]
	public function show(string $uuid): DataResponse {
		return $this->mapErrors(fn (): DataResponse => new DataResponse($this->service->show($this->currentUser->uid(), $uuid)));
	}

	/**
	 * Accept (ownership transfer)
	 *
	 * @param string $uuid UUID of the ownership transfer
	 * @param bool $acknowledgeQuota Acknowledge quota
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 *
	 * 200: Success
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/ownership-transfers/{uuid}/accept')]
	public function accept(string $uuid, bool $acknowledgeQuota = false): DataResponse {
		return $this->mapErrors(fn (): DataResponse => new DataResponse($this->service->accept($this->currentUser->uid(), $uuid, ['acknowledgeQuota' => $acknowledgeQuota])));
	}

	/**
	 * Decline (ownership transfer)
	 *
	 * @param string $uuid UUID of the ownership transfer
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 *
	 * 200: Success
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/ownership-transfers/{uuid}/decline')]
	public function decline(string $uuid): DataResponse {
		return $this->mapErrors(fn (): DataResponse => new DataResponse($this->service->decline($this->currentUser->uid(), $uuid)));
	}

	/**
	 * Cancel (ownership transfer)
	 *
	 * @param string $uuid UUID of the ownership transfer
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 *
	 * 200: Success
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/ownership-transfers/{uuid}')]
	public function cancel(string $uuid): DataResponse {
		return $this->mapErrors(fn (): DataResponse => new DataResponse($this->service->cancel($this->currentUser->uid(), $uuid)));
	}

	/**
	 * Resume (ownership transfer)
	 *
	 * @param string $uuid UUID of the ownership transfer
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 *
	 * 200: Success
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/ownership-transfers/{uuid}/resume')]
	public function resume(string $uuid): DataResponse {
		return $this->mapErrors(fn (): DataResponse => new DataResponse($this->service->resume($this->currentUser->uid(), $uuid)));
	}

	/**
	 * Abort (ownership transfer)
	 *
	 * @param string $uuid UUID of the ownership transfer
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 *
	 * 200: Success
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/ownership-transfers/{uuid}/abort')]
	public function abort(string $uuid): DataResponse {
		return $this->mapErrors(fn (): DataResponse => new DataResponse($this->service->abort($this->currentUser->uid(), $uuid)));
	}
}
