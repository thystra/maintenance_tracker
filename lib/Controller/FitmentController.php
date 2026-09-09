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
use OCA\MaintenanceTracker\Service\FitmentService;
use OCA\MaintenanceTracker\Service\FitmentZipBundleService;
use OCA\MaintenanceTracker\Service\WorkspaceService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCS\OCSPreconditionFailedException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

final class FitmentController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private CurrentUser $currentUser,
		private WorkspaceService $workspaces,
		private FitmentService $service,
		private FitmentZipBundleService $bundles,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/fitment-packs/validate')]
	public function validate(array $pack, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::FITMENT_READ, $workspace, fn ($context): DataResponse => new DataResponse($this->service->validatePack($pack)));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/fitment-packs/preview')]
	public function preview(array $pack, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::FITMENT_READ, $workspace, fn ($context): DataResponse => new DataResponse($this->service->preview($context, $pack)));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/fitment-packs/import')]
	public function import(string $importUuid, array $pack, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::FITMENT_IMPORT, $workspace, fn ($context): DataResponse => new DataResponse($this->service->import($context, $importUuid, $pack), Http::STATUS_CREATED));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/fitment-packs/bundle/validate')]
	public function validateBundle(?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::FITMENT_READ, $workspace, function ($context): DataResponse {
			$decoded = $this->bundles->importPath($this->uploadedBundlePath());
			return new DataResponse($this->service->validatePack($decoded['pack']));
		});
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/fitment-packs/bundle/preview')]
	public function previewBundle(?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::FITMENT_READ, $workspace, function ($context): DataResponse {
			$decoded = $this->bundles->importPath($this->uploadedBundlePath());
			return new DataResponse($this->service->preview($context, $decoded['pack']));
		});
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/fitment-packs/bundle/import')]
	public function importBundle(string $importUuid, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::FITMENT_IMPORT, $workspace, function ($context) use ($importUuid): DataResponse {
			$decoded = $this->bundles->importPath($this->uploadedBundlePath());
			return new DataResponse($this->service->import($context, $importUuid, $decoded['pack']), Http::STATUS_CREATED);
		});
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/fitment-packs/{importUuid}/export')]
	public function export(string $importUuid, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::FITMENT_READ, $workspace, fn ($context): DataResponse => new DataResponse($this->service->sourceExport($context, $importUuid)));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/fitment-packs/{importUuid}/bundle')]
	public function exportBundle(string $importUuid, ?string $workspace = null): DataDownloadResponse {
		return $this->run(AuthorizationCatalog::FITMENT_READ, $workspace, function ($context) use ($importUuid): DataDownloadResponse {
			$source = $this->service->sourceExport($context, $importUuid);
			return $this->bundleDownload($source['pack']);
		});
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/fitment-packs/{importUuid}/targets')]
	public function targets(string $importUuid, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::FITMENT_READ, $workspace, fn ($context): DataResponse => new DataResponse($this->service->targets($context, $importUuid)));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/fitment-targets/{targetUuid}/matches')]
	public function matches(string $targetUuid, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::FITMENT_READ, $workspace, fn ($context): DataResponse => new DataResponse($this->service->matchSuggestions($context, $targetUuid)));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/fitment-targets/{targetUuid}/map')]
	public function map(string $targetUuid, string $mappingUuid, string $assetUuid, bool $acceptConflict = false, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::FITMENT_MAP, $workspace, fn ($context): DataResponse => new DataResponse($this->service->map($context, $targetUuid, $mappingUuid, $assetUuid, $acceptConflict), Http::STATUS_CREATED));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/assets/{assetUuid}/fitments')]
	public function assetFitments(string $assetUuid, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::FITMENT_READ, $workspace, fn ($context): DataResponse => new DataResponse($this->service->assetFitments($context, $assetUuid)));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/assets/{assetUuid}/fitment-export/community')]
	public function communityExport(string $assetUuid, array $export, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::FITMENT_READ, $workspace, fn ($context): DataResponse => new DataResponse($this->service->communityExport($context, $assetUuid, $export)));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/assets/{assetUuid}/fitment-export/community/bundle')]
	public function communityBundleExport(string $assetUuid, array $export, ?string $workspace = null): DataDownloadResponse {
		return $this->run(AuthorizationCatalog::FITMENT_READ, $workspace, function ($context) use ($assetUuid, $export): DataDownloadResponse {
			$community = $this->service->communityExport($context, $assetUuid, $export);
			return $this->bundleDownload($community['pack']);
		});
	}

	/** @param array<string,mixed> $pack */
	private function bundleDownload(array $pack): DataDownloadResponse {
		$response = new DataDownloadResponse(
			$this->bundles->exportBytes($pack),
			$this->bundles->fileName($pack),
			'application/zip',
		);
		$response->cacheFor(0);
		return $response;
	}

	private function uploadedBundlePath(): string {
		$file = $this->request->getUploadedFile('bundle');
		if (!is_array($file)) {
			throw new ValidationException('A multipart fitment ZIP upload named bundle is required');
		}
		$error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
		if (!is_int($error) || $error !== UPLOAD_ERR_OK) {
			throw new ValidationException('The fitment ZIP upload did not complete successfully');
		}
		$path = $file['tmp_name'] ?? null;
		if (!is_string($path) || $path === '' || !is_file($path) || !is_readable($path)) {
			throw new ValidationException('The uploaded fitment ZIP bundle is unavailable');
		}
		$reportedSize = $file['size'] ?? null;
		if (is_int($reportedSize) && $reportedSize > FitmentZipBundleService::MAX_COMPRESSED_BYTES) {
			throw new ValidationException('Fitment ZIP bundle exceeds the 8 MiB reviewed compressed-size bound');
		}
		return $path;
	}

	private function run(string $capability, ?string $workspace, callable $operation): Response {
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
