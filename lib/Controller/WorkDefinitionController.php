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
use OCA\MaintenanceTracker\Service\WorkDefinitionService;
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

final class WorkDefinitionController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private CurrentUser $currentUser,
		private WorkspaceService $workspaces,
		private WorkDefinitionService $service,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/assets/{assetUuid}/work-definitions')]
	public function index(string $assetUuid, ?string $scheduled = null, ?string $workspace = null): DataResponse {
		return $this->run(
			fn ($context): DataResponse => new DataResponse([
				'workspace' => $context->workspace()->getUuid(),
				'items' => $this->service->list($context, $assetUuid, $this->scheduledFilter($scheduled)),
			]),
			$workspace,
			AuthorizationCatalog::MAINTENANCE_DEFINITION_READ,
		);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/work-definitions/{uuid}')]
	public function show(string $uuid, ?string $workspace = null): DataResponse {
		return $this->run(
			fn ($context): DataResponse => new DataResponse($this->service->show($context, $uuid)),
			$workspace,
			AuthorizationCatalog::MAINTENANCE_DEFINITION_READ,
		);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/assets/{assetUuid}/work-definitions')]
	public function create(string $assetUuid, array $definition, ?string $workspace = null): DataResponse {
		return $this->run(
			fn ($context): DataResponse => new DataResponse(
				$this->service->create($context, $assetUuid, $definition),
				Http::STATUS_CREATED,
			),
			$workspace,
			AuthorizationCatalog::MAINTENANCE_DEFINITION_MANAGE,
		);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PATCH', url: '/api/v1/work-definitions/{uuid}')]
	public function update(string $uuid, int $expectedRevision, array $definition, ?string $workspace = null): DataResponse {
		return $this->run(
			fn ($context): DataResponse => new DataResponse($this->service->update($context, $uuid, $expectedRevision, $definition)),
			$workspace,
			AuthorizationCatalog::MAINTENANCE_DEFINITION_MANAGE,
		);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/work-definitions/{uuid}')]
	public function destroy(string $uuid, int $expectedRevision, ?string $workspace = null): DataResponse {
		return $this->run(
			fn ($context): DataResponse => new DataResponse($this->service->archive($context, $uuid, $expectedRevision)),
			$workspace,
			AuthorizationCatalog::MAINTENANCE_DEFINITION_MANAGE,
		);
	}

	private function scheduledFilter(?string $scheduled): ?bool {
		if ($scheduled === null || $scheduled === '') {
			return null;
		}
		return match (strtolower(trim($scheduled))) {
			'true', '1' => true,
			'false', '0' => false,
			default => throw new ValidationException('scheduled must be true or false'),
		};
	}

	private function run(callable $operation, ?string $workspace, string $capability): DataResponse {
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
