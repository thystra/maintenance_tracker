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
	public function __construct(
		string $appName,
		IRequest $request,
		private CurrentUser $currentUser,
		private WorkspaceService $workspaceService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Return the current user's personal workspace.
	 *
	 * @return DataResponse<Http::STATUS_OK, array{
	 *     items: list<array{
	 *         uuid: string,
	 *         name: string,
	 *         kind: 'personal',
	 *         role: 'owner',
	 *         revision: int,
	 *         createdAt: string,
	 *         updatedAt: string
	 *     }>
	 * }, array{}>
	 *
	 * 200: Workspace returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/workspaces')]
	public function index(): DataResponse {
		$workspace = $this->workspaceService->getOrCreatePersonal(
			$this->currentUser->uid(),
		);

		return new DataResponse([
			'items' => [$workspace->toApi('owner')],
		]);
	}
}
