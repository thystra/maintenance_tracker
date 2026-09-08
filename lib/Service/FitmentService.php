<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use JsonException;
use OCA\MaintenanceTracker\Db\Asset;
use OCA\MaintenanceTracker\Exception\NotFoundException;
use OCA\MaintenanceTracker\Exception\RevisionConflictException;
use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Model\WorkspaceContext;
use OCP\AppFramework\Utility\ITimeFactory;

final class FitmentService {
	public function __construct(
		private FitmentPackValidator $validator,
		private FitmentRepository $repository,
		private AssetService $assets,
		private AssetFitmentDescriptorService $descriptors,
		private UuidGenerator $uuidGenerator,
		private ChangeJournal $journal,
		private ITimeFactory $timeFactory,
	) {
	}

	/** @param array<string,mixed> $pack @return array<string,mixed> */
	public function validatePack(array $pack): array {
		$v = $this->validator->validate($pack);
		return [
			'valid' => true,
			'pack' => $this->packMetadata($v),
		];
	}

	/** @param array<string,mixed> $pack @return array<string,mixed> */
	public function preview(WorkspaceContext $context, array $pack): array {
		$v = $this->validator->validate($pack);
		$workspaceId = $context->workspace()->getId();
		$conflicts = [];
		$warnings = [];

		$existingPack = $this->repository->findPack($workspaceId, $v['pack']['id']);
		if ($existingPack !== null) {
			$existingRevision = $this->repository->findRevision($workspaceId, (int)$existingPack['id'], $v['pack']['version']);
			if ($existingRevision !== null && !hash_equals((string)$existingRevision['content_hash'], $v['contentHash'])) {
				$conflicts[] = 'Fitment pack version already exists with different canonical content';
			}
			if ($existingRevision !== null && $this->repository->findImportByRevision($workspaceId, (int)$existingRevision['id']) !== null) {
				$warnings[] = 'This exact fitment pack revision is already imported into the workspace';
			}
		}

		foreach ($v['pack']['slots'] as $slot) {
			$existing = $this->repository->findSlotByKey($workspaceId, $slot['key']);
			if ($existing !== null && (string)$existing['kind'] !== $slot['kind']) {
				$conflicts[] = 'Existing standardized fitment slot has a different kind: ' . $slot['key'];
			}
		}

		$targetMatches = [];
		$assets = $this->allAssets($context);
		foreach ($v['pack']['equipment'] as $target) {
			$suggestions = [];
			foreach ($assets as $asset) {
				$match = $this->descriptors->match($asset, $target);
				if ($match['state'] !== 'conflict') {
					$suggestions[] = $this->assetSuggestion($asset, $match);
				}
			}
			$targetMatches[] = [
				'targetKey' => $target['key'],
				'descriptor' => $target,
				'matches' => $suggestions,
			];
		}

		return [
			'pack' => $this->packMetadata($v),
			'importable' => $conflicts === [],
			'conflicts' => array_values(array_unique($conflicts)),
			'warnings' => array_values(array_unique($warnings)),
			'targets' => $targetMatches,
		];
	}

	/** @param array<string,mixed> $pack @return array<string,mixed> */
	public function import(WorkspaceContext $context, string $importUuid, array $pack): array {
		$importUuid = $this->uuid($importUuid, 'importUuid');
		$v = $this->validator->validate($pack);
		$workspaceId = $context->workspace()->getId();

		$byUuid = $this->repository->findImportByUuid($workspaceId, $importUuid);
		if ($byUuid !== null) {
			if ($byUuid['pack_key'] === $v['pack']['id']
				&& $byUuid['pack_version'] === $v['pack']['version']
				&& hash_equals((string)$byUuid['content_hash'], $v['contentHash'])) {
				return $this->importToApi($byUuid, $v['summary']);
			}
			throw new RevisionConflictException('The supplied fitment import UUID already exists with different data');
		}

		$preview = $this->preview($context, $v['pack']);
		if (!$preview['importable']) {
			throw new ValidationException((string)($preview['conflicts'][0] ?? 'Fitment pack cannot be imported'));
		}

		$now = $this->timeFactory->getTime();
		$packId = $this->packIdentity($workspaceId, $v['pack']['id'], $now);
		$revisionId = $this->packRevision($workspaceId, $packId, $v, $now);
		$existingImport = $this->repository->findImportByRevision($workspaceId, $revisionId);
		if ($existingImport !== null) {
			throw new RevisionConflictException('This fitment pack revision is already imported under a different import UUID');
		}
		$importId = $this->repository->insertImport($workspaceId, $revisionId, $importUuid, $now);

		$targetIds = [];
		foreach ($v['pack']['equipment'] as $target) {
			$uuid = $this->uuidGenerator->generate();
			$id = $this->repository->insertTarget(
				$workspaceId,
				$importId,
				$uuid,
				$target,
				$this->json($target['aliases']),
				$this->json($target['identifiers']),
				$this->json($target['qualifiers']),
				$now,
			);
			$targetIds[$target['key']] = $id;
			$this->repository->insertBinding($workspaceId, $importId, 'equipment', $target['key'], 1, $uuid);
		}

		$slotIds = [];
		foreach ($v['pack']['slots'] as $slot) {
			$existing = $this->repository->findSlotByKey($workspaceId, $slot['key']);
			if ($existing !== null) {
				if ((string)$existing['kind'] !== $slot['kind']) {
					throw new RevisionConflictException('Standardized fitment slot already exists with a different kind: ' . $slot['key']);
				}
				$id = (int)$existing['id'];
				$uuid = (string)$existing['uuid'];
			} else {
				$uuid = $this->uuidGenerator->generate();
				$id = $this->repository->insertSlot($workspaceId, $uuid, $slot['key'], $slot['label'], $slot['kind'], $now);
			}
			$slotIds[$slot['key']] = $id;
			$this->repository->insertBinding($workspaceId, $importId, 'slot', $slot['key'], 1, $uuid);
		}

		$partIds = [];
		foreach ($v['pack']['parts'] as $part) {
			$identityHash = $this->validator->partIdentityHash($part['manufacturer'], $part['partNumber']);
			$existing = $this->repository->findPartByIdentityHash($workspaceId, $identityHash);
			if ($existing !== null) {
				$id = (int)$existing['id'];
				$uuid = (string)$existing['uuid'];
			} else {
				$uuid = $this->uuidGenerator->generate();
				$id = $this->repository->insertPart($workspaceId, $uuid, $identityHash, $part['manufacturer'], $part['partNumber'], $part['description'], $now);
			}
			$partIds[$part['key']] = ['id' => $id, 'uuid' => $uuid];
			$this->repository->insertBinding($workspaceId, $importId, 'part', $part['key'], 1, $uuid);
		}

		foreach ($v['pack']['offers'] as $offer) {
			$uuid = $this->uuidGenerator->generate();
			$this->repository->insertOffer($workspaceId, $importId, $partIds[$offer['partKey']]['id'], $uuid, $offer, $now);
			$this->repository->insertBinding($workspaceId, $importId, 'offer', $offer['key'], 1, $uuid);
		}

		foreach ($v['pack']['fitments'] as $fitment) {
			$uuid = $this->uuidGenerator->generate();
			$this->repository->insertFitment(
				$workspaceId,
				$importId,
				$targetIds[$fitment['equipmentKey']],
				$slotIds[$fitment['slotKey']],
				$partIds[$fitment['partKey']]['id'],
				$uuid,
				$fitment,
				$this->json($fitment['evidence']),
				$now,
			);
			$sourceKey = hash('sha256', $fitment['equipmentKey'] . "\0" . $fitment['slotKey'] . "\0" . $fitment['partKey']);
			$this->repository->insertBinding($workspaceId, $importId, 'fitment', $sourceKey, 1, $uuid);
		}

		$this->journal->record(
			$workspaceId,
			'fitment_pack',
			$importUuid,
			'upsert',
			1,
			$now,
			'fitment_pack.imported',
			['packKey' => $v['pack']['id'], 'packVersion' => $v['pack']['version'], 'contentHash' => $v['contentHash']],
		);

		$row = $this->repository->findImportByUuid($workspaceId, $importUuid);
		if ($row === null) {
			throw new \LogicException('Fitment import disappeared inside its transaction');
		}
		return $this->importToApi($row, $v['summary']);
	}

	/** @return array<string,mixed> */
	public function sourceExport(WorkspaceContext $context, string $importUuid): array {
		$row = $this->importRow($context, $importUuid);
		$pack = $this->decodeObject((string)$row['content_json'], 'Stored fitment revision');
		return [
			'importUuid' => (string)$row['import_uuid'],
			'contentHash' => (string)$row['content_hash'],
			'pack' => $pack,
		];
	}

	/** @return array<string,mixed> */
	public function targets(WorkspaceContext $context, string $importUuid): array {
		$import = $this->importRow($context, $importUuid);
		$rows = $this->repository->listTargetsForImport($context->workspace()->getId(), (int)$import['id']);
		return [
			'importUuid' => (string)$import['import_uuid'],
			'items' => array_map(fn (array $row): array => $this->targetWithMatches($context, $row), $rows),
		];
	}

	/** @return array<string,mixed> */
	public function matchSuggestions(WorkspaceContext $context, string $targetUuid): array {
		$target = $this->targetRow($context, $targetUuid);
		return [
			'target' => $this->targetToApi($target),
			'items' => $this->suggestionsForTarget($context, $target),
		];
	}

	/** @return array<string,mixed> */
	public function map(WorkspaceContext $context, string $targetUuid, string $mappingUuid, string $assetUuid, bool $acceptConflict = false): array {
		$mappingUuid = $this->uuid($mappingUuid, 'mappingUuid');
		$target = $this->targetRow($context, $targetUuid);
		$asset = $this->assets->find($context, $this->uuid($assetUuid, 'assetUuid'));
		$workspaceId = $context->workspace()->getId();
		$match = $this->descriptors->match($asset, $this->targetDescriptor($target));

		$byUuid = $this->repository->findMappingByUuid($workspaceId, $mappingUuid);
		if ($byUuid !== null) {
			if ((int)$byUuid['target_id'] === (int)$target['id'] && (int)$byUuid['asset_id'] === $asset->getId() && (string)$byUuid['active_marker'] === 'active') {
				return $this->mappingToApi($mappingUuid, $target, $asset, (string)$byUuid['match_state'], (bool)$byUuid['conflict_override'], (int)$byUuid['mapped_at']);
			}
			throw new RevisionConflictException('The supplied fitment mapping UUID already exists with different data');
		}

		$existing = $this->repository->findActiveMappingForTarget($workspaceId, (int)$target['id']);
		if ($existing !== null) {
			throw new RevisionConflictException('This fitment target already has an active local-asset mapping under a different mapping UUID');
		}
		if (in_array($match['state'], ['conflict','insufficient'], true) && !$acceptConflict) {
			throw new ValidationException('Mapping a conflict or insufficient match requires explicit acceptConflict=true confirmation');
		}

		$now = $this->timeFactory->getTime();
		$override = in_array($match['state'], ['conflict','insufficient'], true);
		$this->repository->insertMapping($workspaceId, $mappingUuid, (int)$target['id'], $asset->getId(), $match['state'], $override, $now);
		$this->journal->record(
			$workspaceId,
			'fitment_mapping',
			$mappingUuid,
			'upsert',
			1,
			$now,
			'fitment_mapping.created',
			['targetKey' => (string)$target['source_key'], 'matchState' => $match['state']],
		);
		return $this->mappingToApi($mappingUuid, $target, $asset, $match['state'], $override, $now);
	}

	/** @return array<string,mixed> */
	public function assetFitments(WorkspaceContext $context, string $assetUuid): array {
		$asset = $this->assets->find($context, $assetUuid);
		$rows = $this->repository->findFitmentsForAsset($context->workspace()->getId(), $asset->getId());
		return [
			'assetUuid' => $asset->getUuid(),
			'items' => array_map(fn (array $row): array => $this->fitmentRowToApi($row), $rows),
		];
	}

	/**
	 * Build a privacy-minimized portable pack from the currently mapped fitment facts.
	 * Offers are intentionally omitted until a reviewed local/source offer-selection
	 * policy is implemented.
	 *
	 * @param array<string,mixed> $request
	 * @return array<string,mixed>
	 */
	public function communityExport(WorkspaceContext $context, string $assetUuid, array $request): array {
		$asset = $this->assets->find($context, $assetUuid);
		$this->known($request, ['pack','equipment'], 'community fitment export');
		if (!isset($request['pack'], $request['equipment']) || !is_array($request['pack']) || !is_array($request['equipment'])) {
			throw new ValidationException('Community fitment export requires reviewed pack and equipment objects');
		}
		$rows = $this->repository->findFitmentsForAsset($context->workspace()->getId(), $asset->getId());
		if ($rows === []) {
			throw new ValidationException('The asset has no mapped fitment facts to export');
		}

		$slots = [];
		$parts = [];
		$fitments = [];
		foreach ($rows as $row) {
			$slotKey = (string)$row['slot_key'];
			$slots[$slotKey] ??= [
				'key' => $slotKey,
				'label' => (string)$row['slot_label'],
				'kind' => (string)$row['slot_kind'],
				'aliases' => [],
			];
			$identityHash = $this->validator->partIdentityHash((string)$row['part_manufacturer'], (string)$row['part_number']);
			$partKey = 'part_' . substr($identityHash, 0, 12);
			$parts[$partKey] ??= [
				'key' => $partKey,
				'manufacturer' => (string)$row['part_manufacturer'],
				'partNumber' => (string)$row['part_number'],
				'description' => (string)$row['part_description'],
			];
			$tuple = $slotKey . "\0" . $partKey;
			$candidate = [
				'equipmentKey' => (string)($request['equipment']['key'] ?? ''),
				'slotKey' => $slotKey,
				'partKey' => $partKey,
				'relation' => (string)$row['relation'],
				'verification' => (string)$row['verification'],
				'evidence' => $this->decodeList((string)$row['evidence_json'], 'Stored fitment evidence'),
			];
			if ($row['notes'] !== null) {
				$candidate['notes'] = (string)$row['notes'];
			}
			if (!isset($fitments[$tuple]) || $this->fitmentStrength($candidate) > $this->fitmentStrength($fitments[$tuple])) {
				$fitments[$tuple] = $candidate;
			}
		}

		$meta = $request['pack'];
		$pack = [
			'schemaVersion' => 1,
			'vocabularyVersion' => 1,
			'id' => $meta['id'] ?? null,
			'version' => $meta['version'] ?? null,
			'name' => $meta['name'] ?? null,
			'description' => $meta['description'] ?? null,
			'dataLicense' => $meta['dataLicense'] ?? null,
			'provenance' => $meta['provenance'] ?? null,
			'equipment' => [$request['equipment']],
			'slots' => array_values($slots),
			'parts' => array_values($parts),
			'offers' => [],
			'fitments' => array_values($fitments),
		];
		$v = $this->validator->validate($pack);
		return [
			'contentHash' => $v['contentHash'],
			'pack' => $v['pack'],
			'offersPolicy' => 'omitted_pending_explicit_selection',
		];
	}

	private function packIdentity(int $workspaceId, string $packKey, int $now): int {
		$existing = $this->repository->findPack($workspaceId, $packKey);
		return $existing === null ? $this->repository->insertPack($workspaceId, $packKey, $now) : (int)$existing['id'];
	}

	/** @param array{pack:array<string,mixed>,canonicalJson:string,contentHash:string,summary:array<string,int>} $v */
	private function packRevision(int $workspaceId, int $packId, array $v, int $now): int {
		$existing = $this->repository->findRevision($workspaceId, $packId, $v['pack']['version']);
		if ($existing !== null) {
			if (!hash_equals((string)$existing['content_hash'], $v['contentHash'])) {
				throw new RevisionConflictException('Fitment pack version already exists with different canonical content');
			}
			return (int)$existing['id'];
		}
		return $this->repository->insertRevision($workspaceId, $packId, $v['pack'], $v['contentHash'], $v['canonicalJson'], $now);
	}

	/** @return array<string,mixed> */
	private function importRow(WorkspaceContext $context, string $importUuid): array {
		$row = $this->repository->findImportByUuid($context->workspace()->getId(), $this->uuid($importUuid, 'importUuid'));
		if ($row === null) {
			throw new NotFoundException('Fitment pack import not found');
		}
		return $row;
	}

	/** @return array<string,mixed> */
	private function targetRow(WorkspaceContext $context, string $targetUuid): array {
		$row = $this->repository->findTargetByUuid($context->workspace()->getId(), $this->uuid($targetUuid, 'targetUuid'));
		if ($row === null) {
			throw new NotFoundException('Fitment target not found');
		}
		return $row;
	}

	/** @return array<string,mixed> */
	private function targetWithMatches(WorkspaceContext $context, array $row): array {
		return [
			'target' => $this->targetToApi($row),
			'matches' => $this->suggestionsForTarget($context, $row),
		];
	}

	/** @return list<array<string,mixed>> */
	private function suggestionsForTarget(WorkspaceContext $context, array $row): array {
		$target = $this->targetDescriptor($row);
		$out = [];
		foreach ($this->allAssets($context) as $asset) {
			$out[] = $this->assetSuggestion($asset, $this->descriptors->match($asset, $target));
		}
		usort($out, static function (array $a, array $b): int {
			$rank = ['exact' => 0, 'candidate' => 1, 'insufficient' => 2, 'conflict' => 3];
			return ($rank[$a['state']] <=> $rank[$b['state']]) ?: strcmp($a['asset']['name'], $b['asset']['name']);
		});
		return $out;
	}

	/** @return list<Asset> */
	private function allAssets(WorkspaceContext $context): array {
		$out = [];
		$cursor = null;
		do {
			$page = $this->assets->findPage($context, $cursor, 100);
			foreach ($page['items'] as $asset) {
				$out[] = $asset;
			}
			$cursor = $page['nextCursor'];
		} while ($cursor !== null);
		return $out;
	}

	/** @param array{state:string,reasons:list<array<string,string>>} $match @return array<string,mixed> */
	private function assetSuggestion(Asset $asset, array $match): array {
		return [
			'asset' => [
				'uuid' => $asset->getUuid(),
				'name' => $asset->getName(),
				'descriptor' => $this->descriptors->describe($asset),
			],
			'state' => $match['state'],
			'reasons' => $match['reasons'],
		];
	}

	/** @return array<string,mixed> */
	private function targetDescriptor(array $row): array {
		return [
			'key' => (string)$row['source_key'],
			'assetClass' => (string)$row['asset_class'],
			'manufacturer' => (string)$row['manufacturer'],
			'model' => (string)$row['model'],
			'yearFrom' => $row['year_from'] === null ? null : (int)$row['year_from'],
			'yearTo' => $row['year_to'] === null ? null : (int)$row['year_to'],
			'aliases' => $this->decodeObject((string)$row['aliases_json'], 'Stored fitment target aliases'),
			'identifiers' => $this->decodeList((string)$row['identifiers_json'], 'Stored fitment target identifiers'),
			'qualifiers' => $this->decodeList((string)$row['qualifiers_json'], 'Stored fitment target qualifiers'),
		];
	}

	/** @return array<string,mixed> */
	private function targetToApi(array $row): array {
		return [
			'uuid' => (string)$row['uuid'],
			'importUuid' => isset($row['import_uuid']) ? (string)$row['import_uuid'] : null,
			'descriptor' => $this->targetDescriptor($row),
		];
	}

	/** @param array<string,int> $summary @return array<string,mixed> */
	private function importToApi(array $row, array $summary): array {
		return [
			'importUuid' => (string)$row['import_uuid'],
			'pack' => [
				'id' => (string)$row['pack_key'],
				'version' => (string)$row['pack_version'],
				'contentHash' => (string)$row['content_hash'],
				'dataLicense' => (string)$row['data_license'],
				'provenance' => [
					'author' => (string)$row['author_name'],
					'sourceUrl' => (string)$row['source_url'],
					'sourceRevision' => $row['source_revision'] === null ? null : (string)$row['source_revision'],
				],
				'summary' => $summary,
			],
			'importedAt' => gmdate('Y-m-d\TH:i:s\Z', (int)$row['imported_at']),
		];
	}

	/** @param array{pack:array<string,mixed>,contentHash:string,summary:array<string,int>} $v */
	private function packMetadata(array $v): array {
		return [
			'id' => $v['pack']['id'],
			'version' => $v['pack']['version'],
			'name' => $v['pack']['name'],
			'description' => $v['pack']['description'],
			'dataLicense' => $v['pack']['dataLicense'],
			'provenance' => $v['pack']['provenance'],
			'contentHash' => $v['contentHash'],
			'summary' => $v['summary'],
		];
	}

	/** @return array<string,mixed> */
	private function mappingToApi(string $mappingUuid, array $target, Asset $asset, string $state, bool $override, int $mappedAt): array {
		return [
			'mappingUuid' => $mappingUuid,
			'targetUuid' => (string)$target['uuid'],
			'targetKey' => (string)$target['source_key'],
			'assetUuid' => $asset->getUuid(),
			'matchState' => $state,
			'conflictOverride' => $override,
			'mappedAt' => gmdate('Y-m-d\TH:i:s\Z', $mappedAt),
		];
	}

	/** @return array<string,mixed> */
	private function fitmentRowToApi(array $row): array {
		return [
			'uuid' => (string)$row['fitment_uuid'],
			'slot' => ['uuid' => (string)$row['slot_uuid'], 'key' => (string)$row['slot_key'], 'label' => (string)$row['slot_label'], 'kind' => (string)$row['slot_kind']],
			'part' => ['uuid' => (string)$row['part_uuid'], 'manufacturer' => (string)$row['part_manufacturer'], 'partNumber' => (string)$row['part_number'], 'description' => (string)$row['part_description']],
			'relation' => (string)$row['relation'],
			'verification' => (string)$row['verification'],
			'evidence' => $this->decodeList((string)$row['evidence_json'], 'Stored fitment evidence'),
			'notes' => $row['notes'] === null ? null : (string)$row['notes'],
			'source' => ['importUuid' => (string)$row['import_uuid'], 'packKey' => (string)$row['pack_key'], 'packVersion' => (string)$row['pack_version'], 'contentHash' => (string)$row['content_hash'], 'equipmentKey' => (string)$row['equipment_key']],
		];
	}

	/** @param array<string,mixed> $fitment */
	private function fitmentStrength(array $fitment): int {
		return ($fitment['verification'] === 'verified' ? 2 : 0) + ($fitment['relation'] === 'oem' ? 1 : 0);
	}

	private function uuid(string $value, string $field): string {
		$value = strtolower(trim($value));
		if (!UuidGenerator::isValid($value)) {
			throw new ValidationException("{$field} must be an RFC 4122 version 4 UUID");
		}
		return $value;
	}

	/** @param mixed $value */
	private function json(mixed $value): string {
		try {
			return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		} catch (JsonException $exception) {
			throw new ValidationException('Fitment data must be JSON encodable', 0, $exception);
		}
	}

	/** @return array<string,mixed> */
	private function decodeObject(string $json, string $label): array {
		try {
			$value = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
		} catch (JsonException $exception) {
			throw new \LogicException($label . ' is invalid JSON', 0, $exception);
		}
		if (!is_array($value) || array_is_list($value)) {
			throw new \LogicException($label . ' is not an object');
		}
		return $value;
	}

	/** @return list<mixed> */
	private function decodeList(string $json, string $label): array {
		try {
			$value = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
		} catch (JsonException $exception) {
			throw new \LogicException($label . ' is invalid JSON', 0, $exception);
		}
		if (!is_array($value) || !array_is_list($value)) {
			throw new \LogicException($label . ' is not a list');
		}
		return $value;
	}

	/** @param array<string,mixed> $input @param list<string> $allowed */
	private function known(array $input, array $allowed, string $label): void {
		$unknown = array_diff(array_keys($input), $allowed);
		if ($unknown !== []) {
			throw new ValidationException('Unknown ' . $label . ' fields: ' . implode(', ', $unknown));
		}
	}
}
