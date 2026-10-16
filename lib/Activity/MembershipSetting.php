<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Activity;

use OCA\MaintenanceTracker\Service\IntegrationEventMap;

final class MembershipSetting extends AbstractSetting {
	public function getIdentifier(): string {
		return IntegrationEventMap::TYPE_MEMBERSHIP;
	}

	public function getName(): string {
		return $this->l->t('Workspace membership changes');
	}

	public function getPriority(): int {
		return 53;
	}
}
