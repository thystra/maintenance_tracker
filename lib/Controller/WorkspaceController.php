<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Controller;

use OCA\MaintenanceTracker\Service\CurrentUser;
use OCA\MaintenanceTracker\Service\WorkspaceService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

final class WorkspaceController extends OCSController {
	use OcsErrorMapping;

	public function __construct(
		string $appName,
		IRequest $request,
		private CurrentUser $currentUser,
		private WorkspaceService $workspaces,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * List workspaces
	 *
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 *
	 * 200: Success
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/workspaces')]
	public function index(): DataResponse {
		return new DataResponse([
			'items' => $this->workspaces->listAccessible($this->currentUser->uid()),
		]);
	}

	/**
	 * Create a shared workspace owned by the caller
	 *
	 * @param string $name Workspace name, 1 to 120 characters
	 * @return DataResponse<Http::STATUS_CREATED, array<string, mixed>, array{}>
	 *
	 * 201: Workspace created
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/workspaces')]
	public function create(string $name): DataResponse {
		return $this->mapErrors(fn (): DataResponse => new DataResponse($this->workspaces->createShared($this->currentUser->uid(), $name), Http::STATUS_CREATED));
	}

	/**
	 * Rename a workspace (owner only)
	 *
	 * @param string $uuid UUID of the workspace
	 * @param int $expectedRevision Revision the client last read, for optimistic concurrency
	 * @param string $name New workspace name, 1 to 120 characters
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 *
	 * 200: Workspace renamed
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PATCH', url: '/api/v1/workspaces/{uuid}')]
	public function update(string $uuid, int $expectedRevision, string $name): DataResponse {
		return $this->mapErrors(fn (): DataResponse => new DataResponse($this->workspaces->rename($this->currentUser->uid(), $uuid, $expectedRevision, $name)));
	}
}
