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
use OCA\MaintenanceTracker\Service\ReadingService;
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

final class ReadingController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private CurrentUser $currentUser,
		private WorkspaceService $workspaces,
		private ReadingService $service,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/meters/{meterUuid}/readings')]
	public function index(string $meterUuid, ?string $workspace = null): DataResponse {
		try {
			return $this->workspaces->runWithCapability(
				$this->currentUser->uid(),
				$workspace,
				AuthorizationCatalog::METER_READ,
				fn ($context): DataResponse => new DataResponse([
					'workspace' => $context->workspace()->getUuid(),
					'items' => $this->service->list($context, $meterUuid),
				]),
			);
		} catch (AccessDeniedException $exception) {
			throw new OCSForbiddenException($exception->getMessage(), $exception);
		} catch (NotFoundException $exception) {
			throw new OCSNotFoundException($exception->getMessage(), $exception);
		} catch (ValidationException $exception) {
			throw new OCSBadRequestException($exception->getMessage(), $exception);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/meters/{meterUuid}/readings')]
	public function create(string $meterUuid, array $reading, ?string $workspace = null): DataResponse {
		try {
			return $this->workspaces->runWithCapability(
				$this->currentUser->uid(),
				$workspace,
				AuthorizationCatalog::READING_CREATE,
				fn ($context): DataResponse => new DataResponse(
					$this->service->create($context, $meterUuid, $reading),
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

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/readings/{readingUuid}/corrections')]
	public function correct(string $readingUuid, array $reading, ?string $workspace = null): DataResponse {
		try {
			return $this->workspaces->runWithCapability(
				$this->currentUser->uid(),
				$workspace,
				AuthorizationCatalog::READING_CORRECT,
				fn ($context): DataResponse => new DataResponse(
					$this->service->correct($context, $readingUuid, $reading),
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
}
