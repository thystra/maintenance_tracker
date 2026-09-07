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
use OCA\MaintenanceTracker\Service\MaintenanceOccurrenceService;
use OCA\MaintenanceTracker\Service\WorkspaceService;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCS\OCSPreconditionFailedException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

final class MaintenanceOccurrenceController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private CurrentUser $currentUser,
		private WorkspaceService $workspaces,
		private MaintenanceOccurrenceService $service,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/assets/{assetUuid}/maintenance-occurrences')]
	public function index(string $assetUuid, ?string $includeClosed = null, ?string $asOf = null, ?string $workspace = null): DataResponse {
		return $this->run(
			AuthorizationCatalog::MAINTENANCE_OCCURRENCE_READ,
			$workspace,
			fn ($context): DataResponse => new DataResponse($this->service->list($context, $assetUuid, $this->boolean($includeClosed), $asOf)),
		);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/assets/{assetUuid}/maintenance-occurrences/reconcile')]
	public function reconcile(string $assetUuid, ?string $asOf = null, ?string $workspace = null): DataResponse {
		return $this->run(
			AuthorizationCatalog::MAINTENANCE_OCCURRENCE_RECONCILE,
			$workspace,
			fn ($context): DataResponse => new DataResponse($this->service->reconcile($context, $assetUuid, $asOf)),
		);
	}

	private function boolean(?string $value): bool {
		if ($value === null || $value === '') {
			return false;
		}
		return match (strtolower(trim($value))) {
			'true', '1' => true,
			'false', '0' => false,
			default => throw new ValidationException('includeClosed must be true or false'),
		};
	}

	private function run(string $capability, ?string $workspace, callable $operation): DataResponse {
		try {
			return $this->workspaces->runWithCapability($this->currentUser->uid(), $workspace, $capability, $operation);
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
