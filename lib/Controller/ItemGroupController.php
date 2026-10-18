<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Controller;

use OCA\MaintenanceTracker\Service\AuthorizationCatalog;
use OCA\MaintenanceTracker\Service\CurrentUser;
use OCA\MaintenanceTracker\Service\ItemGroupService;
use OCA\MaintenanceTracker\Service\WorkspaceService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * Item groups organise items into a tree. Every role that can read inventory
 * can read groups; Owners and Managers (inventory.manage) change them.
 */
final class ItemGroupController extends OCSController {
	use OcsErrorMapping;

	public function __construct(
		string $appName,
		IRequest $request,
		private CurrentUser $currentUser,
		private WorkspaceService $workspaces,
		private ItemGroupService $service,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * List item groups and which group each item is in
	 *
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_OK, array{workspace: string, items: list<array<string, mixed>>, assignments: list<array{assetUuid: string, groupUuid: string}>}, array{}>
	 *
	 * 200: Groups returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/item-groups')]
	public function index(?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::INVENTORY_READ, $workspace, fn ($c): DataResponse => new DataResponse(['workspace' => $c->workspace()->getUuid()] + $this->service->list($c)));
	}

	/**
	 * Create an item group
	 *
	 * @param array<string, mixed> $group Group fields: name, optional parentUuid, sort and client uuid
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_CREATED, array<string, mixed>, array{}>
	 *
	 * 201: Group created
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/item-groups')]
	public function create(array $group, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::INVENTORY_MANAGE, $workspace, fn ($c): DataResponse => new DataResponse($this->service->create($c, $group), Http::STATUS_CREATED));
	}

	/**
	 * Rename or re-sort an item group
	 *
	 * @param string $uuid UUID of the group
	 * @param int $expectedRevision Revision the client last read, for optimistic concurrency
	 * @param array<string, mixed> $group Fields to change: name, sort
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 *
	 * 200: Group updated
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PATCH', url: '/api/v1/item-groups/{uuid}')]
	public function update(string $uuid, int $expectedRevision, array $group, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::INVENTORY_MANAGE, $workspace, fn ($c): DataResponse => new DataResponse($this->service->update($c, $uuid, $expectedRevision, $group)));
	}

	/**
	 * Move an item group under another group or to the top level
	 *
	 * @param string $uuid UUID of the group
	 * @param int $expectedRevision Revision the client last read, for optimistic concurrency
	 * @param string|null $parentUuid New parent group; null moves the group to the top level
	 * @param int|null $sort Optional new sort position among its siblings
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 *
	 * 200: Group moved
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/item-groups/{uuid}/move')]
	public function move(string $uuid, int $expectedRevision, ?string $parentUuid = null, ?int $sort = null, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::INVENTORY_MANAGE, $workspace, fn ($c): DataResponse => new DataResponse($this->service->move($c, $uuid, $expectedRevision, $parentUuid, $sort)));
	}

	/**
	 * Delete an item group; its subgroups and items move up to its parent
	 *
	 * @param string $uuid UUID of the group
	 * @param int $expectedRevision Revision the client last read, for optimistic concurrency
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_OK, array{uuid: string, movedGroups: int, movedItems: int, ungroupedItems: int}, array{}>
	 *
	 * 200: Group deleted
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/item-groups/{uuid}')]
	public function destroy(string $uuid, int $expectedRevision, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::INVENTORY_MANAGE, $workspace, fn ($c): DataResponse => new DataResponse($this->service->archive($c, $uuid, $expectedRevision)));
	}

	/**
	 * Put an item into a group, or take it out of its group
	 *
	 * @param string $assetUuid UUID of the item
	 * @param string|null $groupUuid Group to put the item in; null leaves the item ungrouped
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_OK, array{assetUuid: string, groupUuid: string|null}, array{}>
	 *
	 * 200: Membership updated
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/assets/{assetUuid}/group')]
	public function setAssetGroup(string $assetUuid, ?string $groupUuid = null, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::INVENTORY_MANAGE, $workspace, fn ($c): DataResponse => new DataResponse($this->service->setAssetGroup($c, $assetUuid, $groupUuid)));
	}
}
