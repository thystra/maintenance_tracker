<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Activity;

use OCA\MaintenanceTracker\AppInfo\Application;
use OCA\MaintenanceTracker\Service\IntegrationEventMap;
use OCP\Activity\IFilter;
use OCP\IL10N;
use OCP\IURLGenerator;

final class Filter implements IFilter {
	public function __construct(
		private IL10N $l,
		private IURLGenerator $urlGenerator,
		private IntegrationEventMap $map,
	) {
	}

	public function getIdentifier(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l->t('Maintenance');
	}

	public function getPriority(): int {
		return 60;
	}

	public function getIcon(): string {
		return $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg'));
	}

	/** @param string[] $types @return string[] */
	public function filterTypes(array $types): array {
		return array_values(array_intersect($types, $this->map->activityTypes()));
	}

	/** @return string[] */
	public function allowedApps(): array {
		return [Application::APP_ID];
	}
}
