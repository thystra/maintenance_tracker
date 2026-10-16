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
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCS\OCSPreconditionFailedException;

/**
 * Shared capability-authorized execution and domain-exception mapping for the
 * v0.1.10 parts, vendor, link, and cost controllers. Requires `$currentUser`
 * and `$workspaces` properties on the using controller.
 */
trait OcsErrorMapping {
	private function run(string $capability, ?string $workspace, callable $operation): DataResponse {
		return $this->mapErrors(fn (): DataResponse => $this->workspaces->runWithCapability($this->currentUser->uid(), $workspace, $capability, $operation));
	}

	/**
	 * Authorize, then run $operation OUTSIDE any database transaction. Used by
	 * operations that call Nextcloud Files or sharing; their database writes use
	 * WorkspaceService::runLocked().
	 */
	private function runOutsideTransaction(string $capability, ?string $workspace, callable $operation): DataResponse {
		return $this->mapErrors(function () use ($capability, $workspace, $operation): DataResponse {
			$context = $this->workspaces->authorize($this->currentUser->uid(), $workspace, $capability);
			return $operation($context);
		});
	}

	private function mapErrors(callable $operation): DataResponse {
		try {
			return $operation();
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
