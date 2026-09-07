<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Service;

use OCA\MaintenanceTracker\Db\Asset;
use OCA\MaintenanceTracker\Exception\NotFoundException;
use OCA\MaintenanceTracker\Exception\RevisionConflictException;
use OCA\MaintenanceTracker\Exception\ValidationException;
use OCA\MaintenanceTracker\Model\WorkspaceContext;
use OCP\AppFramework\Utility\ITimeFactory;

final class ProfileInstallationService {
	public function __construct(
		private ProfileValidator $validator,
		private ProfileCatalog $catalog,
		private ProfileRepository $repository,
		private AssetService $assets,
		private ComponentService $components,
		private MeterService $meters,
		private WorkGroupService $groups,
		private WorkDefinitionService $definitions,
		private UuidGenerator $uuidGenerator,
		private ChangeJournal $journal,
		private ITimeFactory $timeFactory,
	) {
	}

	/** @return list<array<string,mixed>> */
	public function bundled(): array {
		return $this->catalog->listBundled();
	}

	/** @param array<string,mixed> $input @return array<string,mixed> */
	public function validateProfile(array $input): array {
		$v = $this->validator->validate($input);
		$source = $this->catalog->classify($v['profile'], $v['contentHash']);
		return [
			'valid' => true,
			'profile' => $this->metadata($v['profile'], $v['contentHash'], $v['summary'], $source),
		];
	}

	/** @param array<string,mixed> $input @return array<string,mixed> */
	public function preview(WorkspaceContext $context, string $assetUuid, array $input): array {
		$asset = $this->assets->find($context, $assetUuid);
		$v = $this->validator->validate($input);
		$source = $this->catalog->classify($v['profile'], $v['contentHash']);
		$conflicts = $this->conflicts($context, $asset, $v['profile']);
		$warnings = [];
		$applicable = $this->applicable($asset, $v['profile'], $warnings);
		if ($v['profile']['parts'] !== []) {
			$conflicts[] = 'Profile contains part definitions that cannot be materialized until the parts subsystem is implemented';
		}
		return [
			'assetUuid' => $asset->getUuid(),
			'profile' => $this->metadata($v['profile'], $v['contentHash'], $v['summary'], $source),
			'applicable' => $applicable,
			'installable' => $applicable && $conflicts === [],
			'conflicts' => $conflicts,
			'warnings' => $warnings,
			'materializes' => $v['summary'],
		];
	}

	/** @return array<string,mixed>|null */
	public function current(WorkspaceContext $context, string $assetUuid): ?array {
		$asset = $this->assets->find($context, $assetUuid);
		$row = $this->repository->findInstallationForAsset($context->workspace()->getId(), $asset->getId());
		return $row === null ? null : $this->installationToApi($context, $row, $asset->getUuid());
	}

	/** @param array<string,mixed> $input @return array<string,mixed> */
	public function install(WorkspaceContext $context, string $assetUuid, string $installationUuid, array $input): array {
		$installationUuid = strtolower(trim($installationUuid));
		if (!UuidGenerator::isValid($installationUuid)) {
			throw new ValidationException('installationUuid must be an RFC 4122 version 4 UUID');
		}
		$asset = $this->assets->find($context, $assetUuid);
		$v = $this->validator->validate($input);
		$profile = $v['profile'];
		$source = $this->catalog->classify($profile, $v['contentHash']);

		$byUuid = $this->repository->findInstallationByUuid($context->workspace()->getId(), $installationUuid);
		if ($byUuid !== null) {
			if ((int)$byUuid['asset_id'] === $asset->getId()
				&& $byUuid['profile_key'] === $profile['id']
				&& $byUuid['profile_version'] === $profile['version']
				&& hash_equals((string)$byUuid['content_hash'], $v['contentHash'])) {
				return $this->installationToApi($context, $byUuid, $asset->getUuid());
			}
			throw new RevisionConflictException('The supplied installation UUID already exists with different data');
		}

		$preview = $this->preview($context, $assetUuid, $profile);
		if (!$preview['installable']) {
			$reason = $preview['conflicts'][0] ?? ($preview['warnings'][0] ?? 'Profile is not applicable to this asset');
			throw new ValidationException((string)$reason);
		}

		$workspaceId = $context->workspace()->getId();
		$now = $this->timeFactory->getTime();
		$profileId = $this->profileIdentity($workspaceId, $profile['id'], $source, $now);
		$revisionId = $this->profileRevision($workspaceId, $profileId, $profile, $v['contentHash'], $v['canonicalJson'], $now);
		$assetProfileId = $this->repository->insertInstallation($workspaceId, $asset->getId(), $revisionId, $installationUuid, $now);

		$componentUuids = $this->materializeComponents($context, $asset, $assetProfileId, $profile['components']);
		$meterUuids = $this->materializeMeters($context, $asset, $assetProfileId, $profile['meters']);
		$groupUuids = $this->materializeGroups($context, $asset, $assetProfileId, $profile['workGroups']);
		$this->materializeDefinitions($context, $asset, $assetProfileId, $profile['workDefinitions'], $componentUuids, $meterUuids, $groupUuids);

		$updated = $this->assets->update($context, $asset->getUuid(), $asset->getRevision(), [
			'profileKey' => $profile['id'],
			'profileVersion' => $profile['version'],
		]);
		$this->journal->record(
			$workspaceId,
			'profile_installation',
			$installationUuid,
			'upsert',
			1,
			$now,
			'profile.installed',
			['profileKey' => $profile['id'], 'profileVersion' => $profile['version'], 'contentHash' => $v['contentHash']],
		);

		$row = $this->repository->findInstallationForAsset($workspaceId, $updated->getId());
		if ($row === null) {
			throw new \LogicException('Profile installation disappeared inside its transaction');
		}
		return $this->installationToApi($context, $row, $updated->getUuid());
	}

	/** @param array{origin:string,trustState:string} $source */
	private function profileIdentity(int $workspaceId, string $profileKey, array $source, int $now): int {
		$existing = $this->repository->findProfile($workspaceId, $profileKey);
		if ($existing !== null) {
			if ($existing['origin'] !== $source['origin'] || $existing['trust_state'] !== $source['trustState']) {
				throw new RevisionConflictException('Profile identity already exists with a different origin or trust state');
			}
			return (int)$existing['id'];
		}
		return $this->repository->insertProfile($workspaceId, $profileKey, $source['origin'], $source['trustState'], $now);
	}

	/** @param array<string,mixed> $profile */
	private function profileRevision(int $workspaceId, int $profileId, array $profile, string $hash, string $json, int $now): int {
		$existing = $this->repository->findRevision($workspaceId, $profileId, $profile['version']);
		if ($existing !== null) {
			if (!hash_equals((string)$existing['content_hash'], $hash)) {
				throw new RevisionConflictException('Profile version already exists with different content');
			}
			return (int)$existing['id'];
		}
		return $this->repository->insertRevision($workspaceId, $profileId, $profile, $hash, $json, $now);
	}

	/** @param list<array<string,mixed>> $templates @return array<string,list<string>> */
	private function materializeComponents(WorkspaceContext $context, Asset $asset, int $assetProfileId, array $templates): array {
		$byKey = [];
		foreach ($templates as $template) $byKey[$template['key']] = $template;
		$created = [];
		$creating = [];
		$createKey = function (string $key) use (&$createKey, &$created, &$creating, $byKey, $context, $asset, $assetProfileId): array {
			if (isset($created[$key])) return $created[$key];
			if (isset($creating[$key])) throw new \LogicException('Validated component cycle reached materialization');
			$creating[$key] = true;
			$template = $byKey[$key] ?? throw new \LogicException('Validated component reference disappeared');
			$parentUuid = null;
			if (isset($template['parentKey'])) {
				$parents = $createKey($template['parentKey']);
				$parentUuid = $parents[0] ?? throw new \LogicException('Validated parent failed to materialize');
			}
			$uuids = [];
			for ($ordinal = 1; $ordinal <= $template['quantity']; ++$ordinal) {
				$name = $template['quantity'] > 1 ? $template['name'] . ' ' . $ordinal : $template['name'];
				$row = $this->components->create($context, $asset->getUuid(), [
					'uuid' => $this->uuidGenerator->generate(),
					'parentUuid' => $parentUuid,
					'type' => $template['key'],
					'name' => $name,
					'notes' => $template['notes'] ?? null,
					'status' => $template['enabledByDefault'] ? 'active' : 'suppressed',
				]);
				$uuids[] = $row->getUuid();
				$this->repository->insertBinding($context->workspace()->getId(), $assetProfileId, 'component', $template['key'], $ordinal, $row->getUuid());
			}
			unset($creating[$key]);
			return $created[$key] = $uuids;
		};
		foreach (array_keys($byKey) as $key) $createKey($key);
		return $created;
	}

	/** @param list<array<string,mixed>> $templates @return array<string,string> */
	private function materializeMeters(WorkspaceContext $context, Asset $asset, int $assetProfileId, array $templates): array {
		$created=[];foreach($templates as $t){$row=$this->meters->create($context,$asset->getUuid(),['uuid'=>$this->uuidGenerator->generate(),'key'=>$t['key'],'name'=>$t['name'],'dimension'=>$t['dimension'],'displayUnit'=>$t['displayUnit'],'monotonic'=>$t['monotonic']]);$created[$t['key']]=$row['uuid'];$this->repository->insertBinding($context->workspace()->getId(),$assetProfileId,'meter',$t['key'],1,$row['uuid']);}return $created;
	}

	/** @param list<array<string,mixed>> $templates @return array<string,string> */
	private function materializeGroups(WorkspaceContext $context, Asset $asset, int $assetProfileId, array $templates): array {
		$created=[];foreach($templates as $t){$payload=['uuid'=>$this->uuidGenerator->generate(),'key'=>$t['key'],'name'=>$t['name'],'sortOrder'=>$t['sortOrder']];if(isset($t['description']))$payload['description']=$t['description'];$row=$this->groups->create($context,$asset->getUuid(),$payload);$created[$t['key']]=$row['uuid'];$this->repository->insertBinding($context->workspace()->getId(),$assetProfileId,'work_group',$t['key'],1,$row['uuid']);}return $created;
	}

	/** @param list<array<string,mixed>> $templates @param array<string,list<string>> $componentUuids @param array<string,string> $meterUuids @param array<string,string> $groupUuids */
	private function materializeDefinitions(WorkspaceContext $context, Asset $asset, int $assetProfileId, array $templates, array $componentUuids, array $meterUuids, array $groupUuids): void {
		foreach($templates as $t){$payload=['uuid'=>$this->uuidGenerator->generate(),'key'=>$t['key'],'title'=>$t['title'],'kind'=>$t['kind'],'status'=>$t['enabledByDefault']?'active':'suppressed','schedule'=>$this->runtimeSchedule($t['schedule'],$meterUuids)];if(isset($t['groupKey']))$payload['groupUuid']=$groupUuids[$t['groupKey']];if(isset($t['componentKey']))$payload['componentUuid']=$componentUuids[$t['componentKey']][0];if(isset($t['instructions']))$payload['instructions']=$t['instructions'];if(isset($t['notes']))$payload['notes']=$t['notes'];$row=$this->definitions->create($context,$asset->getUuid(),$payload);$this->repository->insertBinding($context->workspace()->getId(),$assetProfileId,'work_definition',$t['key'],1,$row['uuid']);}
	}

	/** @param array<string,string> $meterUuids */
	private function runtimeSchedule(string|array $schedule, array $meterUuids): string|array {
		if ($schedule === 'none') return 'none';
		$rules=[];foreach($schedule['rules'] as $r){if($r['type']==='meter'){$rules[]=['type'=>'meter','meterUuid'=>$meterUuids[$r['meterKey']],'interval'=>$r['interval']];}else{$rules[]=$r;}}
		return ['combination'=>'any','rules'=>$rules];
	}

	/** @param array<string,mixed> $profile @return list<string> */
	private function conflicts(WorkspaceContext $context, Asset $asset, array $profile): array {
		$out=[];$workspaceId=$context->workspace()->getId();
		if($this->repository->findInstallationForAsset($workspaceId,$asset->getId())!==null)$out[]='Asset already has a materialized profile installation; profile upgrades are a later explicit-diff workflow';
		if($asset->getProfileKey()!==null||$asset->getProfileVersion()!==null)$out[]='Asset already carries profile metadata and cannot be adopted silently';
		$meterKeys=array_fill_keys(array_map(static fn(array $i):string=>$i['key'],$this->meters->list($context,$asset->getUuid())),true);
		$groupKeys=array_fill_keys(array_map(static fn(array $i):string=>$i['key'],$this->groups->list($context,$asset->getUuid())),true);
		$defKeys=array_fill_keys(array_map(static fn(array $i):string=>$i['key'],$this->definitions->list($context,$asset->getUuid())),true);
		foreach($profile['meters'] as $i)if(isset($meterKeys[$i['key']]))$out[]='Meter key already exists on asset: '.$i['key'];
		foreach($profile['workGroups'] as $i)if(isset($groupKeys[$i['key']]))$out[]='Work-group key already exists on asset: '.$i['key'];
		foreach($profile['workDefinitions'] as $i)if(isset($defKeys[$i['key']]))$out[]='Work-definition key already exists on asset: '.$i['key'];
		return array_values(array_unique($out));
	}

	/** @param array<string,mixed> $profile @param list<string> $warnings */
	private function applicable(Asset $asset,array $profile,array &$warnings):bool{
		$app=$profile['applicability'];$ok=true;
		if($profile['category']!==$asset->getCategoryKey()&&$profile['category']!==$asset->getAssetClass()){$ok=false;$warnings[]='Profile category does not match the asset category/class';}
		if(isset($app['manufacturer'])&&strcasecmp((string)$asset->getManufacturer(),$app['manufacturer'])!==0){$ok=false;$warnings[]='Profile manufacturer does not match the asset';}
		if(isset($app['model'])&&strcasecmp((string)$asset->getModel(),$app['model'])!==0){$ok=false;$warnings[]='Profile model does not match the asset';}
		$year=$asset->getModelYear();if(isset($app['yearFrom'])&&($year===null||$year<$app['yearFrom'])){$ok=false;$warnings[]='Asset model year is before or unknown for the profile applicability range';}if(isset($app['yearTo'])&&($year===null||$year>$app['yearTo'])){$ok=false;$warnings[]='Asset model year is after or unknown for the profile applicability range';}
		return $ok;
	}

	/** @param array<string,mixed> $profile @param array<string,int> $summary @param array{origin:string,trustState:string} $source @return array<string,mixed> */
	private function metadata(array $profile,string $hash,array $summary,array $source):array{return ['id'=>$profile['id'],'version'=>$profile['version'],'name'=>$profile['name'],'category'=>$profile['category'],'description'=>$profile['description'],'dataLicense'=>$profile['dataLicense'],'provenance'=>$profile['provenance'],'applicability'=>$profile['applicability'],'contentHash'=>$hash,'summary'=>$summary,'origin'=>$source['origin'],'trustState'=>$source['trustState']];}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function installationToApi(WorkspaceContext $context,array $row,string $assetUuid):array{$bindings=array_map(static fn(array $b):array=>['sourceType'=>(string)$b['source_type'],'sourceKey'=>(string)$b['source_key'],'ordinal'=>(int)$b['source_ordinal'],'targetUuid'=>(string)$b['target_uuid']],$this->repository->findBindings($context->workspace()->getId(),(int)$row['id']));return ['installationUuid'=>(string)$row['installation_uuid'],'assetUuid'=>$assetUuid,'profile'=>['id'=>(string)$row['profile_key'],'version'=>(string)$row['profile_version'],'contentHash'=>(string)$row['content_hash'],'origin'=>(string)$row['origin'],'trustState'=>(string)$row['trust_state'],'dataLicense'=>(string)$row['data_license'],'provenance'=>['author'=>(string)$row['author_name'],'sourceUrl'=>(string)$row['source_url'],'sourceRevision'=>$row['source_revision']===null?null:(string)$row['source_revision']]],'installedAt'=>gmdate('Y-m-d\TH:i:s\Z',(int)$row['installed_at']),'bindings'=>$bindings];}
}
