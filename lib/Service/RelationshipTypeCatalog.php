<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Exception\ValidationException;

final class RelationshipTypeCatalog {
	private const ALL_CLASSES = [
		'vehicle', 'trailer', 'building', 'equipment', 'appliance', 'system',
		'tool', 'medical_device', 'location', 'other',
	];

	/** @var array<string, array{label:string,inverseKey:string,inverseLabel:string,symmetric:bool,sourceClasses:list<string>,targetClasses:list<string>}> */
	private const TYPES = [
		'tows' => [
			'label' => 'Tows',
			'inverseKey' => 'towed_by',
			'inverseLabel' => 'Towed by',
			'symmetric' => false,
			'sourceClasses' => ['vehicle'],
			'targetClasses' => ['trailer'],
		],
		'carries' => [
			'label' => 'Carries',
			'inverseKey' => 'carried_by',
			'inverseLabel' => 'Carried by',
			'symmetric' => false,
			'sourceClasses' => ['vehicle', 'trailer', 'equipment'],
			'targetClasses' => ['vehicle', 'equipment', 'tool', 'medical_device', 'other'],
		],
		'powers' => [
			'label' => 'Powers',
			'inverseKey' => 'powered_by',
			'inverseLabel' => 'Powered by',
			'symmetric' => false,
			'sourceClasses' => ['vehicle', 'equipment', 'system'],
			'targetClasses' => ['equipment', 'appliance', 'system', 'tool', 'medical_device'],
		],
		'attached_to' => [
			'label' => 'Attached to',
			'inverseKey' => 'has_attached',
			'inverseLabel' => 'Has attached',
			'symmetric' => false,
			'sourceClasses' => self::ALL_CLASSES,
			'targetClasses' => self::ALL_CLASSES,
		],
		'installed_in' => [
			'label' => 'Installed in',
			'inverseKey' => 'contains_installed',
			'inverseLabel' => 'Contains installed',
			'symmetric' => false,
			'sourceClasses' => ['equipment', 'appliance', 'system', 'tool', 'medical_device', 'other'],
			'targetClasses' => ['vehicle', 'trailer', 'building', 'equipment', 'system'],
		],
		'stored_at' => [
			'label' => 'Stored at',
			'inverseKey' => 'stores',
			'inverseLabel' => 'Stores',
			'symmetric' => false,
			'sourceClasses' => self::ALL_CLASSES,
			'targetClasses' => ['building', 'location'],
		],
		'paired_with' => [
			'label' => 'Paired with',
			'inverseKey' => 'paired_with',
			'inverseLabel' => 'Paired with',
			'symmetric' => true,
			'sourceClasses' => self::ALL_CLASSES,
			'targetClasses' => self::ALL_CLASSES,
		],
		'services' => [
			'label' => 'Services',
			'inverseKey' => 'serviced_by',
			'inverseLabel' => 'Serviced by',
			'symmetric' => false,
			'sourceClasses' => ['equipment', 'system', 'tool'],
			'targetClasses' => self::ALL_CLASSES,
		],
	];

	/** @return list<array<string, mixed>> */
	public function list(): array {
		$items = [];
		foreach (self::TYPES as $key => $definition) {
			$items[] = ['key' => $key, ...$definition];
		}
		return $items;
	}

	/** @return array{label:string,inverseKey:string,inverseLabel:string,symmetric:bool,sourceClasses:list<string>,targetClasses:list<string>} */
	public function definition(string $key): array {
		if (!isset(self::TYPES[$key])) {
			throw new ValidationException('Unsupported relationship type');
		}
		return self::TYPES[$key];
	}

	public function assertCompatible(string $key, string $sourceClass, string $targetClass): void {
		$definition = $this->definition($key);
		if (!in_array($sourceClass, $definition['sourceClasses'], true)
			|| !in_array($targetClass, $definition['targetClasses'], true)) {
			throw new ValidationException(sprintf(
				'Relationship type %s is not compatible with %s -> %s',
				$key,
				$sourceClass,
				$targetClass,
			));
		}
	}
}
