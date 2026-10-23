<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Controller;

use OCA\MaintenanceTracker\Service\AuthorizationCatalog;
use OCA\MaintenanceTracker\Service\CurrentUser;
use OCA\MaintenanceTracker\Service\FuelService;
use OCA\MaintenanceTracker\Service\WorkspaceService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * Fuel log. Every member who can read activity history reads fuel tickets
 * and statistics; Contributors and above add tickets (activity.create);
 * Owners and Managers correct or delete tickets (activity.manage) and manage
 * regimes and per-item fuel settings (inventory.manage). Linking earlier
 * tickets to an odometer creates meters and readings (meter.manage).
 */
final class FuelController extends OCSController {
	use OcsErrorMapping;

	public function __construct(
		string $appName,
		IRequest $request,
		private CurrentUser $currentUser,
		private WorkspaceService $workspaces,
		private FuelService $service,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Fuel settings of an item: Fuel tab mode and visibility, default units
	 *
	 * @param string $assetUuid UUID of the item
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 *
	 * 200: Settings returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/assets/{assetUuid}/fuel-settings')]
	public function settings(string $assetUuid, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::ACTIVITY_READ, $workspace, fn ($c): DataResponse => new DataResponse($this->service->settings($c, $assetUuid)));
	}

	/**
	 * Change the fuel settings of an item
	 *
	 * @param string $assetUuid UUID of the item
	 * @param int $expectedRevision Revision the client last read (0 before the first change)
	 * @param array<string, mixed> $settings Fields to change: fuelTab (auto, on, off), distanceUnit, volumeUnit, economyUnit, fuelType
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 *
	 * 200: Settings updated
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/assets/{assetUuid}/fuel-settings')]
	public function updateSettings(string $assetUuid, int $expectedRevision, array $settings, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::INVENTORY_MANAGE, $workspace, fn ($c): DataResponse => new DataResponse($this->service->updateSettings($c, $assetUuid, $expectedRevision, $settings)));
	}

	/**
	 * List the operating regimes of an item
	 *
	 * @param string $assetUuid UUID of the item
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_OK, array{items: list<array<string, mixed>>}, array{}>
	 *
	 * 200: Regimes returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/assets/{assetUuid}/fuel-regimes')]
	public function regimes(string $assetUuid, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::ACTIVITY_READ, $workspace, fn ($c): DataResponse => new DataResponse(['items' => $this->service->regimes($c, $assetUuid)]));
	}

	/**
	 * Add an operating regime to an item
	 *
	 * @param string $assetUuid UUID of the item
	 * @param array<string, mixed> $regime Regime fields: name, optional isDefault, sort and client uuid
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_CREATED, array<string, mixed>, array{}>
	 *
	 * 201: Regime created
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/assets/{assetUuid}/fuel-regimes')]
	public function createRegime(string $assetUuid, array $regime, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::INVENTORY_MANAGE, $workspace, fn ($c): DataResponse => new DataResponse($this->service->createRegime($c, $assetUuid, $regime), Http::STATUS_CREATED));
	}

	/**
	 * Rename, re-sort or make a regime the default
	 *
	 * @param string $uuid UUID of the regime
	 * @param int $expectedRevision Revision the client last read
	 * @param array<string, mixed> $regime Fields to change: name, sort, isDefault
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 *
	 * 200: Regime updated
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PATCH', url: '/api/v1/fuel-regimes/{uuid}')]
	public function updateRegime(string $uuid, int $expectedRevision, array $regime, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::INVENTORY_MANAGE, $workspace, fn ($c): DataResponse => new DataResponse($this->service->updateRegime($c, $uuid, $expectedRevision, $regime)));
	}

	/**
	 * Delete a regime; tickets keep showing it as history
	 *
	 * @param string $uuid UUID of the regime
	 * @param int $expectedRevision Revision the client last read
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_OK, array{uuid: string, tickets: int}, array{}>
	 *
	 * 200: Regime deleted
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/fuel-regimes/{uuid}')]
	public function destroyRegime(string $uuid, int $expectedRevision, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::INVENTORY_MANAGE, $workspace, fn ($c): DataResponse => new DataResponse($this->service->archiveRegime($c, $uuid, $expectedRevision)));
	}

	/**
	 * List the fuel tickets of an item, newest first, with segment economy
	 *
	 * @param string $assetUuid UUID of the item
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 *
	 * 200: Tickets returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/assets/{assetUuid}/fuel')]
	public function index(string $assetUuid, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::ACTIVITY_READ, $workspace, fn ($c): DataResponse => new DataResponse($this->service->list($c, $assetUuid)));
	}

	/**
	 * Add a fuel ticket
	 *
	 * @param string $assetUuid UUID of the item
	 * @param array<string, mixed> $ticket Ticket fields: uuid, filledAt, odometer (+odometerUnit) or readingUuid, volume (+volumeUnit), fullFill, missedPrevious, fuelType, station, regimeUuid, totalCost, currency, notes
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_CREATED, array<string, mixed>, array{}>
	 *
	 * 201: Ticket created
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/assets/{assetUuid}/fuel')]
	public function create(string $assetUuid, array $ticket, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::ACTIVITY_CREATE, $workspace, fn ($c): DataResponse => new DataResponse($this->service->create($c, $assetUuid, $ticket), Http::STATUS_CREATED));
	}

	/**
	 * Show one fuel ticket
	 *
	 * @param string $uuid UUID of the ticket
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 *
	 * 200: Ticket returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/fuel/{uuid}')]
	public function show(string $uuid, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::ACTIVITY_READ, $workspace, fn ($c): DataResponse => new DataResponse($this->service->show($c, $uuid)));
	}

	/**
	 * Correct a fuel ticket; its reading and cost entry follow
	 *
	 * @param string $uuid UUID of the ticket
	 * @param int $expectedRevision Revision the client last read
	 * @param array<string, mixed> $ticket Fields to change
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 *
	 * 200: Ticket updated
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PATCH', url: '/api/v1/fuel/{uuid}')]
	public function update(string $uuid, int $expectedRevision, array $ticket, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::ACTIVITY_MANAGE, $workspace, fn ($c): DataResponse => new DataResponse($this->service->update($c, $uuid, $expectedRevision, $ticket)));
	}

	/**
	 * Delete a fuel ticket and its cost entry (the odometer reading stays)
	 *
	 * @param string $uuid UUID of the ticket
	 * @param int $expectedRevision Revision the client last read
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 *
	 * 200: Ticket deleted
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/fuel/{uuid}')]
	public function destroy(string $uuid, int $expectedRevision, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::ACTIVITY_MANAGE, $workspace, fn ($c): DataResponse => new DataResponse($this->service->archive($c, $uuid, $expectedRevision)));
	}

	/**
	 * Fuel economy statistics of an item for a period
	 *
	 * @param string $assetUuid UUID of the item
	 * @param string $period One of 30d, 90d, 365d, year, all, custom
	 * @param string|null $from Start date (YYYY-MM-DD) of a custom period
	 * @param string|null $to End date (YYYY-MM-DD) of a custom period
	 * @param string|null $unit Economy unit (mpg, km_l, l_100km); defaults to the item's setting
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 *
	 * 200: Statistics returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/assets/{assetUuid}/fuel-stats')]
	public function stats(string $assetUuid, string $period = '90d', ?string $from = null, ?string $to = null, ?string $unit = null, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::ACTIVITY_READ, $workspace, fn ($c): DataResponse => new DataResponse($this->service->stats($c, $assetUuid, $period, $from, $to, $unit)));
	}

	/**
	 * Items with the Fuel tab, for the quick "Log fuel" dialog: settings, regimes and the latest fill-up of each
	 *
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_OK, array{items: list<array<string, mixed>>}, array{}>
	 *
	 * 200: Items returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/fuel-vehicles')]
	public function vehicles(?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::ACTIVITY_READ, $workspace, fn ($c): DataResponse => new DataResponse($this->service->vehicles($c)));
	}

	/**
	 * Record the odometer of earlier fill-ups that have no reading, creating the item's Odometer meter if it has no distance meter
	 *
	 * Tickets are linked oldest first; a value that would decrease the meter is skipped and listed. Repeating the call does nothing new.
	 *
	 * @param string $assetUuid UUID of the item
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>
	 *
	 * 200: Tickets linked
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/assets/{assetUuid}/fuel-odometer')]
	public function linkOdometer(string $assetUuid, ?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::METER_MANAGE, $workspace, fn ($c): DataResponse => new DataResponse($this->service->linkOdometer($c, $assetUuid)));
	}

	/**
	 * Recent fuel economy of the workspace's items, for the dashboard
	 *
	 * @param string|null $workspace UUID of the workspace; defaults to the caller's personal workspace
	 * @return DataResponse<Http::STATUS_OK, array{items: list<array<string, mixed>>}, array{}>
	 *
	 * 200: Summary returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/fuel-summary')]
	public function summary(?string $workspace = null): DataResponse {
		return $this->run(AuthorizationCatalog::ACTIVITY_READ, $workspace, fn ($c): DataResponse => new DataResponse($this->service->summary($c)));
	}
}
