<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Controller;

use OCA\MaintenanceTracker\Exception\AccessDeniedException;
use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Service\CurrentUser;
use OCA\MaintenanceTracker\Service\ImportPolicy;
use OCA\MaintenanceTracker\Service\ImportService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

final class ImportController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private CurrentUser $currentUser,
		private ImportService $imports,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Restore a complete owner export (JSON or ZIP, multipart field "file")
	 * into a new workspace owned by the caller.
	 *
	 * @param string|null $name Name of the new workspace; defaults to "<original> (restored)"
	 * @return DataResponse<Http::STATUS_CREATED, array<string, mixed>, array{}>
	 *
	 * 201: Workspace restored
	 * 400: Invalid or unsupported export
	 * 403: Account is not active
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/import')]
	public function import(?string $name = null): DataResponse {
		try {
			$file = $this->request->getUploadedFile('file');
			if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_file($file['tmp_name'])) {
				throw new ValidationException('A multipart upload named file is required');
			}
			if (is_int($file['size'] ?? null) && $file['size'] > ImportPolicy::MAX_UPLOAD_BYTES) {
				throw new ValidationException('The import file exceeds the 640 MiB bound');
			}
			return new DataResponse($this->imports->import($this->currentUser->uid(), $file['tmp_name'], $name), Http::STATUS_CREATED);
		} catch (AccessDeniedException $exception) {
			throw new OCSForbiddenException($exception->getMessage(), $exception);
		} catch (ValidationException $exception) {
			throw new OCSBadRequestException($exception->getMessage(), $exception);
		}
	}
}
