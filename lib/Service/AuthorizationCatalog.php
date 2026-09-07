<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

final class AuthorizationCatalog {
	public const WORKSPACE_READ = 'workspace.read';
	public const INVENTORY_READ = 'inventory.read';
	public const INVENTORY_MANAGE = 'inventory.manage';
	public const WORKSPACE_MEMBERS_READ = 'workspace.members.read';
	public const WORKSPACE_MEMBERS_MANAGE = 'workspace.members.manage';
	public const AUDIT_READ = 'audit.read';
	public const METER_READ = 'meter.read';
	public const METER_MANAGE = 'meter.manage';
	public const READING_CREATE = 'reading.create';
	public const READING_CORRECT = 'reading.correct';
	public const MAINTENANCE_DEFINITION_READ = 'maintenance_definition.read';
	public const MAINTENANCE_DEFINITION_MANAGE = 'maintenance_definition.manage';
	public const ACTIVITY_READ = 'activity.read';
	public const ACTIVITY_CREATE = 'activity.create';
	public const ACTIVITY_MANAGE = 'activity.manage';
	public const MAINTENANCE_FORECAST_READ = 'maintenance_forecast.read';
	public const MAINTENANCE_OCCURRENCE_READ = 'maintenance_occurrence.read';
	public const MAINTENANCE_OCCURRENCE_RECONCILE = 'maintenance_occurrence.reconcile';
	public const REMINDER_POLICY_READ = 'reminder_policy.read';
	public const REMINDER_POLICY_MANAGE = 'reminder_policy.manage';

	/**
	 * Reserved vocabulary is deliberately present before its subsystem exists.
	 * An unimplemented capability can never authorize an operation.
	 *
	 * @var array<string, array{implemented: bool, write: bool}>
	 */
	private const CAPABILITIES = [
		self::WORKSPACE_READ => ['implemented' => true, 'write' => false],
		self::INVENTORY_READ => ['implemented' => true, 'write' => false],
		self::INVENTORY_MANAGE => ['implemented' => true, 'write' => true],
		self::WORKSPACE_MEMBERS_READ => ['implemented' => true, 'write' => false],
		self::WORKSPACE_MEMBERS_MANAGE => ['implemented' => true, 'write' => true],
		self::AUDIT_READ => ['implemented' => true, 'write' => false],
		self::METER_READ => ['implemented' => true, 'write' => false],
		self::METER_MANAGE => ['implemented' => true, 'write' => true],
		self::READING_CREATE => ['implemented' => true, 'write' => true],
		self::READING_CORRECT => ['implemented' => true, 'write' => true],
		self::MAINTENANCE_DEFINITION_READ => ['implemented' => true, 'write' => false],
		self::MAINTENANCE_DEFINITION_MANAGE => ['implemented' => true, 'write' => true],
		self::ACTIVITY_READ => ['implemented' => true, 'write' => false],
		self::ACTIVITY_CREATE => ['implemented' => true, 'write' => true],
		self::ACTIVITY_MANAGE => ['implemented' => true, 'write' => true],
		self::MAINTENANCE_FORECAST_READ => ['implemented' => true, 'write' => false],
		self::MAINTENANCE_OCCURRENCE_READ => ['implemented' => true, 'write' => false],
		self::MAINTENANCE_OCCURRENCE_RECONCILE => ['implemented' => true, 'write' => true],
		self::REMINDER_POLICY_READ => ['implemented' => true, 'write' => false],
		self::REMINDER_POLICY_MANAGE => ['implemented' => true, 'write' => true],

		'maintenance_definition.*' => ['implemented' => false, 'write' => false],
		'activity.*' => ['implemented' => false, 'write' => false],
		'evidence.*' => ['implemented' => false, 'write' => false],
		'checkout.*' => ['implemented' => false, 'write' => false],
		'retention.manage' => ['implemented' => false, 'write' => true],
		'report.share.create' => ['implemented' => false, 'write' => true],
		'report.share.revoke' => ['implemented' => false, 'write' => true],
		'external_submission.read' => ['implemented' => false, 'write' => false],
		'external_submission.review' => ['implemented' => false, 'write' => true],
		'workspace.settings.manage' => ['implemented' => false, 'write' => true],
		'workspace.delete' => ['implemented' => false, 'write' => true],
	];

	/**
	 * Bundles are explicit. Do not derive Manager permissions from Owner or from
	 * a rank: a future sensitive capability must be consciously granted.
	 *
	 * @var array<string, list<string>>
	 */
	private const ROLE_CAPABILITIES = [
		'owner' => [
			self::WORKSPACE_READ,
			self::INVENTORY_READ,
			self::INVENTORY_MANAGE,
			self::WORKSPACE_MEMBERS_READ,
			self::WORKSPACE_MEMBERS_MANAGE,
			self::AUDIT_READ,
			self::METER_READ,
			self::METER_MANAGE,
			self::READING_CREATE,
			self::READING_CORRECT,
			self::MAINTENANCE_DEFINITION_READ,
			self::MAINTENANCE_DEFINITION_MANAGE,
			self::ACTIVITY_READ,
			self::ACTIVITY_CREATE,
			self::ACTIVITY_MANAGE,
			self::MAINTENANCE_FORECAST_READ,
			self::MAINTENANCE_OCCURRENCE_READ,
			self::MAINTENANCE_OCCURRENCE_RECONCILE,
			self::REMINDER_POLICY_READ,
			self::REMINDER_POLICY_MANAGE,
		],
		'manager' => [
			self::WORKSPACE_READ,
			self::INVENTORY_READ,
			self::INVENTORY_MANAGE,
			self::WORKSPACE_MEMBERS_READ,
			self::AUDIT_READ,
			self::METER_READ,
			self::METER_MANAGE,
			self::READING_CREATE,
			self::READING_CORRECT,
			self::MAINTENANCE_DEFINITION_READ,
			self::MAINTENANCE_DEFINITION_MANAGE,
			self::ACTIVITY_READ,
			self::ACTIVITY_CREATE,
			self::ACTIVITY_MANAGE,
			self::MAINTENANCE_FORECAST_READ,
			self::MAINTENANCE_OCCURRENCE_READ,
			self::MAINTENANCE_OCCURRENCE_RECONCILE,
			self::REMINDER_POLICY_READ,
			self::REMINDER_POLICY_MANAGE,
		],
		'contributor' => [
			self::WORKSPACE_READ,
			self::INVENTORY_READ,
			self::METER_READ,
			self::READING_CREATE,
			self::MAINTENANCE_DEFINITION_READ,
			self::ACTIVITY_READ,
			self::ACTIVITY_CREATE,
			self::MAINTENANCE_FORECAST_READ,
			self::MAINTENANCE_OCCURRENCE_READ,
			self::REMINDER_POLICY_READ,
		],
		'viewer' => [
			self::WORKSPACE_READ,
			self::INVENTORY_READ,
			self::METER_READ,
			self::MAINTENANCE_DEFINITION_READ,
			self::ACTIVITY_READ,
			self::MAINTENANCE_FORECAST_READ,
			self::MAINTENANCE_OCCURRENCE_READ,
			self::REMINDER_POLICY_READ,
		],
	];

	public function normalizeRole(string $role): string {
		$role = strtolower(trim($role));
		if ($role === 'editor') {
			return 'manager';
		}
		if (!isset(self::ROLE_CAPABILITIES[$role])) {
			throw new \InvalidArgumentException("Unknown workspace role: {$role}");
		}

		return $role;
	}

	public function allows(string $role, string $capability): bool {
		$role = $this->normalizeRole($role);
		$definition = self::CAPABILITIES[$capability] ?? null;
		if ($definition === null || !$definition['implemented']) {
			return false;
		}

		return in_array($capability, self::ROLE_CAPABILITIES[$role], true);
	}

	public function isWrite(string $capability): bool {
		$definition = self::CAPABILITIES[$capability] ?? null;
		if ($definition === null) {
			throw new \LogicException("Unknown capability: {$capability}");
		}
		if (!$definition['implemented']) {
			throw new \LogicException("Capability is reserved but not implemented: {$capability}");
		}

		return $definition['write'];
	}

	/**
	 * @return list<string>
	 */
	public function capabilitiesForRole(string $role): array {
		return self::ROLE_CAPABILITIES[$this->normalizeRole($role)];
	}

	/**
	 * @return array<string, array{implemented: bool, write: bool}>
	 */
	public function definitions(): array {
		return self::CAPABILITIES;
	}
}
