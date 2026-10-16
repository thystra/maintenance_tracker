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
use OCA\MaintenanceTracker\Service\ProfileInstallationService;
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

final class ProfileController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private CurrentUser $currentUser,
		private WorkspaceService $workspaces,
		private ProfileInstallationService $service,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * List profiles
	 *
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 * @throws OCSBadRequestException Invalid input
	 * @throws OCSForbiddenException Not allowed
	 * @throws OCSNotFoundException Not found
	 *
	 * 200: Success
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/profiles')]
	public function index(?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::PROFILE_READ, $workspace, fn ($context): DataResponse => new DataResponse([
			'workspace' => $context->workspace()->getUuid(),
			'items' => $this->service->bundled(),
		]));
	}

	/**
	 * Validate (profile)
	 *
	 * @param array<string, mixed> $profile Profile fields
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 * @throws OCSBadRequestException Invalid input
	 * @throws OCSForbiddenException Not allowed
	 * @throws OCSNotFoundException Not found
	 *
	 * 200: Success
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/profiles/validate')]
	public function validate(array $profile, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::PROFILE_READ, $workspace, fn ($context): DataResponse => new DataResponse($this->service->validateProfile($profile)));
	}

	/**
	 * Current (profile)
	 *
	 * @param string $assetUuid UUID of the asset
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 * @throws OCSBadRequestException Invalid input
	 * @throws OCSForbiddenException Not allowed
	 * @throws OCSNotFoundException Not found
	 *
	 * 200: Success
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/assets/{assetUuid}/profile-installation')]
	public function current(string $assetUuid, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::PROFILE_READ, $workspace, fn ($context): DataResponse => new DataResponse([
			'installation' => $this->service->current($context, $assetUuid),
		]));
	}

	/**
	 * Preview (profile)
	 *
	 * @param string $assetUuid UUID of the asset
	 * @param array<string, mixed> $profile Profile fields
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 * @throws OCSBadRequestException Invalid input
	 * @throws OCSForbiddenException Not allowed
	 * @throws OCSNotFoundException Not found
	 *
	 * 200: Success
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/assets/{assetUuid}/profiles/preview')]
	public function preview(string $assetUuid, array $profile, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::PROFILE_READ, $workspace, fn ($context): DataResponse => new DataResponse($this->service->preview($context, $assetUuid, $profile)));
	}

	/**
	 * Install (profile)
	 *
	 * @param string $assetUuid UUID of the asset
	 * @param string $installationUuid UUID of the installation
	 * @param array<string, mixed> $profile Profile fields
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_CREATED, array<string, mixed>, array{}>
	 * @throws OCSBadRequestException Invalid input
	 * @throws OCSForbiddenException Not allowed
	 * @throws OCSNotFoundException Not found
	 *
	 * 201: Profile created
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/assets/{assetUuid}/profiles/install')]
	public function install(string $assetUuid, string $installationUuid, array $profile, ?string $workspace = null): DataResponse {
		return $this->run(
			AuthorizationCatalog::PROFILE_INSTALL,
			$workspace,
			fn ($context): DataResponse => new DataResponse($this->service->install($context, $assetUuid, $installationUuid, $profile), Http::STATUS_CREATED),
		);
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
