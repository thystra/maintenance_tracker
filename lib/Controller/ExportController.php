<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Controller;

use OCA\MaintenanceTracker\Exception\AccessDeniedException;
use OCA\MaintenanceTracker\Exception\NotFoundException;
use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Service\AuthorizationCatalog;
use OCA\MaintenanceTracker\Service\CurrentUser;
use OCA\MaintenanceTracker\Service\ExportService;
use OCA\MaintenanceTracker\Service\WorkspaceService;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

final class ExportController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private CurrentUser $currentUser,
		private WorkspaceService $workspaces,
		private ExportService $exports,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Download a role-filtered workspace export as JSON, or as a ZIP with
	 * CSV tables and optionally the evidence files the caller can open.
	 * Authorization runs first; the export reads Files outside any app
	 * database transaction.
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/export')]
	public function export(?string $workspace = null, string $format = 'zip', bool $includeFiles = false): Response {
		try {
			$uid = $this->currentUser->uid();
			$context = $this->workspaces->authorize($uid, $workspace, AuthorizationCatalog::WORKSPACE_EXPORT);
			$result = $this->exports->export($context, $uid, $format, $includeFiles);
		} catch (AccessDeniedException $exception) {
			throw new OCSForbiddenException($exception->getMessage(), $exception);
		} catch (NotFoundException $exception) {
			throw new OCSNotFoundException($exception->getMessage(), $exception);
		} catch (ValidationException $exception) {
			throw new OCSBadRequestException($exception->getMessage(), $exception);
		}
		if (isset($result['json'])) {
			$response = new DataDownloadResponse($result['json'], $result['fileName'], $result['mime']);
		} else {
			$handle = fopen((string)$result['path'], 'rb');
			$response = new StreamResponse($handle === false ? (string)$result['path'] : $handle);
			$response->addHeader('Content-Type', $result['mime']);
			$response->addHeader('Content-Disposition', 'attachment; filename="' . $result['fileName'] . '"');
			$response->addHeader('Content-Length', (string)filesize((string)$result['path']));
		}
		$response->addHeader('Cache-Control', 'no-store');
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		return $response;
	}
}
