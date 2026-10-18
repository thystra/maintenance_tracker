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
use OCA\MaintenanceTracker\Service\ProfileLibraryService;
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

/**
 * Workspace profile library: profiles built in the profile editor, saved
 * from an item or imported from JSON. Reads need profile.read; changes need
 * profile.manage (Owner, Manager).
 */
final class ProfileLibraryController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private CurrentUser $currentUser,
		private WorkspaceService $workspaces,
		private ProfileLibraryService $library,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Show a workspace profile
	 *
	 * @param string $uuid UUID of the workspace profile
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 * @throws OCSBadRequestException Invalid input
	 * @throws OCSForbiddenException Not allowed
	 * @throws OCSNotFoundException Not found
	 *
	 * 200: Success
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/profile-library/{uuid}')]
	public function show(string $uuid, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::PROFILE_READ, $workspace, fn ($context): DataResponse => new DataResponse($this->library->show($context, $uuid)));
	}

	/**
	 * Create or import a workspace profile
	 *
	 * The document is validated like an installed profile (1 MiB, depth and
	 * list bounds, data only) and stored in canonical form. A client UUID
	 * makes retries idempotent.
	 *
	 * @param array<string, mixed> $profile Profile document (schemaVersion 2)
	 * @param string|null $uuid Client-generated UUID of the workspace profile
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_CREATED, array<string, mixed>, array{}>
	 * @throws OCSBadRequestException Invalid input
	 * @throws OCSForbiddenException Not allowed
	 * @throws OCSNotFoundException Not found
	 *
	 * 201: Profile stored
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/profile-library')]
	public function create(array $profile, ?string $uuid = null, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::PROFILE_MANAGE, $workspace, fn ($context): DataResponse => new DataResponse($this->library->create($context, $profile, $uuid), Http::STATUS_CREATED));
	}

	/**
	 * Replace a workspace profile
	 *
	 * @param string $uuid UUID of the workspace profile
	 * @param int $expectedRevision Revision last read by the client
	 * @param array<string, mixed> $profile Complete profile document
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 * @throws OCSBadRequestException Invalid input
	 * @throws OCSForbiddenException Not allowed
	 * @throws OCSNotFoundException Not found
	 *
	 * 200: Profile updated
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/profile-library/{uuid}')]
	public function update(string $uuid, int $expectedRevision, array $profile, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::PROFILE_MANAGE, $workspace, fn ($context): DataResponse => new DataResponse($this->library->update($context, $uuid, $expectedRevision, $profile)));
	}

	/**
	 * Delete a workspace profile
	 *
	 * Items it was installed on keep their immutable installation snapshot.
	 *
	 * @param string $uuid UUID of the workspace profile
	 * @param int $expectedRevision Revision last read by the client
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 * @throws OCSBadRequestException Invalid input
	 * @throws OCSForbiddenException Not allowed
	 * @throws OCSNotFoundException Not found
	 *
	 * 200: Profile deleted
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/profile-library/{uuid}')]
	public function destroy(string $uuid, int $expectedRevision, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::PROFILE_MANAGE, $workspace, fn ($context): DataResponse => new DataResponse($this->library->archive($context, $uuid, $expectedRevision)));
	}

	/**
	 * Save an item's setup as a workspace profile
	 *
	 * Meters, components, specifications, parts that fit, task groups, tasks
	 * with schedules, and fuel regimes and settings; never history.
	 *
	 * @param string $assetUuid UUID of the item
	 * @param string $name Name of the new profile
	 * @param string|null $id Profile id (lowercase, dotted); defaults to workspace.<name>
	 * @param string|null $version Semantic version; defaults to 1.0.0
	 * @param string|null $description Description
	 * @param string|null $uuid Client-generated UUID of the workspace profile
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_CREATED, array<string, mixed>, array{}>
	 * @throws OCSBadRequestException Invalid input
	 * @throws OCSForbiddenException Not allowed
	 * @throws OCSNotFoundException Not found
	 *
	 * 201: Profile stored
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/assets/{assetUuid}/save-as-profile')]
	public function saveFromAsset(string $assetUuid, string $name, ?string $id = null, ?string $version = null, ?string $description = null, ?string $uuid = null, ?string $workspace = null): DataResponse {
		$input = array_filter(['name' => $name, 'id' => $id, 'version' => $version, 'description' => $description, 'uuid' => $uuid], static fn ($v): bool => $v !== null);
		return $this->run(AuthorizationCatalog::PROFILE_MANAGE, $workspace, fn ($context): DataResponse => new DataResponse($this->library->saveFromAsset($context, $assetUuid, $input), Http::STATUS_CREATED));
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
