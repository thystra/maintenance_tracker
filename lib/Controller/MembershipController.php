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
use OCA\MaintenanceTracker\Service\CurrentUser;
use OCA\MaintenanceTracker\Service\MembershipService;
use OCA\MaintenanceTracker\Service\WorkspaceService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

final class MembershipController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private CurrentUser $currentUser,
		private WorkspaceService $workspaces,
		private MembershipService $members,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/workspaces/{workspace}/members')]
	public function index(string $workspace): DataResponse {
		try {
			return $this->workspaces->runWithCapability(
				$this->currentUser->uid(),
				$workspace,
				'workspace.members.read',
				fn ($context): DataResponse => new DataResponse([
					'workspace' => $context->workspace()->getUuid(),
					'items' => $this->members->list($context),
				]),
			);
		} catch (AccessDeniedException $exception) {
			throw new OCSForbiddenException($exception->getMessage(), $exception);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/workspaces/{workspace}/members')]
	public function create(string $workspace, array $member): DataResponse {
		$actorUid = $this->currentUser->uid();
		try {
			$targetUid = $this->members->targetUidFromInput($member);

			return $this->workspaces->runWithCapabilityForUsers(
				$actorUid,
				[$targetUid],
				$workspace,
				'workspace.members.manage',
				fn ($context): DataResponse => new DataResponse(
					$this->members->add($context, $actorUid, $member),
					Http::STATUS_CREATED,
				),
			);
		} catch (AccessDeniedException $exception) {
			throw new OCSForbiddenException($exception->getMessage(), $exception);
		} catch (ValidationException $exception) {
			throw new OCSBadRequestException($exception->getMessage(), $exception);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PATCH', url: '/api/v1/workspaces/{workspace}/members/{userUid}')]
	public function update(string $workspace, string $userUid, array $member): DataResponse {
		$actorUid = $this->currentUser->uid();
		try {
			return $this->workspaces->runWithCapabilityForUsers(
				$actorUid,
				[$userUid],
				$workspace,
				'workspace.members.manage',
				fn ($context): DataResponse => new DataResponse(
					$this->members->changeRole($context, $actorUid, $userUid, $member),
				),
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
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/workspaces/{workspace}/members/{userUid}')]
	public function destroy(string $workspace, string $userUid): DataResponse {
		$actorUid = $this->currentUser->uid();
		try {
			return $this->workspaces->runWithCapabilityForUsers(
				$actorUid,
				[$userUid],
				$workspace,
				'workspace.members.manage',
				function ($context) use ($actorUid, $userUid): DataResponse {
					$this->members->remove($context, $actorUid, $userUid);

					return new DataResponse(['removed' => true]);
				},
			);
		} catch (AccessDeniedException $exception) {
			throw new OCSForbiddenException($exception->getMessage(), $exception);
		} catch (NotFoundException $exception) {
			throw new OCSNotFoundException($exception->getMessage(), $exception);
		} catch (ValidationException $exception) {
			throw new OCSBadRequestException($exception->getMessage(), $exception);
		}
	}
}
