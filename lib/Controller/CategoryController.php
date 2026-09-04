<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Controller;

use OCA\MaintenanceTracker\Exception\AccessDeniedException;
use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Service\CategoryService;
use OCA\MaintenanceTracker\Service\CurrentUser;
use OCA\MaintenanceTracker\Service\WorkspaceService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

final class CategoryController extends OCSController {
	public function __construct(string $appName,IRequest $request,private CurrentUser $currentUser,private WorkspaceService $workspaceService,private CategoryService $service){parent::__construct($appName,$request);}
	#[NoAdminRequired] #[ApiRoute(verb:'GET',url:'/api/v1/categories')]
	public function index(?string $workspace=null):DataResponse{try{return $this->workspaceService->runWithCapability($this->currentUser->uid(),$workspace,'inventory.read',fn($c)=>new DataResponse(['workspace'=>$c->workspace()->getUuid(),'items'=>$this->service->list($c)]));}catch(AccessDeniedException $e){throw new OCSForbiddenException($e->getMessage(),$e);}}
	#[NoAdminRequired] #[ApiRoute(verb:'POST',url:'/api/v1/categories')]
	public function create(array $category,?string $workspace=null):DataResponse{try{return $this->workspaceService->runWithCapability($this->currentUser->uid(),$workspace,'inventory.manage',fn($c)=>new DataResponse($this->service->create($c,$category)->toApi(),Http::STATUS_CREATED));}catch(AccessDeniedException $e){throw new OCSForbiddenException($e->getMessage(),$e);}catch(ValidationException $e){throw new OCSBadRequestException($e->getMessage(),$e);}}
}
