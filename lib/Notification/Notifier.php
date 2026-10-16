<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Notification;

use OCA\MaintenanceTracker\AppInfo\Application;
use OCA\MaintenanceTracker\Db\AssetMapper;
use OCA\MaintenanceTracker\Db\WorkDefinitionMapper;
use OCA\MaintenanceTracker\Service\IntegrationLabels;
use OCA\MaintenanceTracker\Service\OwnershipTransferPolicy;
use OCA\MaintenanceTracker\Service\TransferRepository;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Renders ownership-transfer and maintenance-reminder notifications at read
 * time. A notification whose subject no longer applies (offer closed, member
 * removed, task archived) is withdrawn instead of rendered, and nothing is
 * shown to a user who is no longer a workspace member.
 */
final class Notifier implements INotifier {
	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $urlGenerator,
		private IntegrationLabels $labels,
		private TransferRepository $transfers,
		private WorkDefinitionMapper $definitions,
		private AssetMapper $assets,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Maintenance Tracker');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$uid = $notification->getUser();
		$p = $notification->getSubjectParameters();
		$workspaceId = is_int($p['workspaceId'] ?? null) ? $p['workspaceId'] : 0;
		if (!$this->labels->isMember($workspaceId, $uid)) {
			throw new AlreadyProcessedException();
		}
		$link = $this->urlGenerator->linkToRouteAbsolute('maintenance_tracker.page.index');
		$workspace = $this->labels->workspace($workspaceId, $uid, '');

		switch ($notification->getSubject()) {
			case 'transfer_offered':
				$transfer = $this->transfers->findByUuid($notification->getObjectId());
				if ($transfer === null || $transfer['status'] !== OwnershipTransferPolicy::STATUS_PENDING || $transfer['to_uid'] !== $uid) {
					throw new AlreadyProcessedException();
				}
				$subject = $l->t('{user} offered you ownership of {workspace}');
				$params = ['user' => $this->labels->user((string)$transfer['from_uid'], (string)$transfer['from_uid']), 'workspace' => $workspace];
				$action = $notification->createAction();
				$action->setLabel('review')->setParsedLabel($l->t('Review offer'))->setLink($link, 'WEB')->setPrimary(true);
				$notification->addParsedAction($action);
				break;
			case 'transfer_declined':
			case 'transfer_expired':
				$to = is_string($p['toUid'] ?? null) ? $p['toUid'] : '';
				$subject = $notification->getSubject() === 'transfer_declined'
					? $l->t('{user} declined ownership of {workspace}')
					: $l->t('Your ownership offer to {user} for {workspace} expired');
				$params = ['user' => $this->labels->user($to, $to), 'workspace' => $workspace];
				break;
			case 'maintenance_due_soon':
			case 'maintenance_due':
			case 'maintenance_overdue':
				try {
					$definition = $this->definitions->findByUuid($workspaceId, $notification->getObjectId());
					$asset = $this->assets->findById($workspaceId, $definition->getAssetId());
				} catch (DoesNotExistException) {
					throw new AlreadyProcessedException();
				}
				if ($definition->getStatus() !== 'active') {
					throw new AlreadyProcessedException();
				}
				$subject = match ($notification->getSubject()) {
					'maintenance_due_soon' => $l->t('Maintenance due soon: {task} on {asset}'),
					'maintenance_due' => $l->t('Maintenance due: {task} on {asset}'),
					default => $l->t('Maintenance overdue: {task} on {asset}'),
				};
				$params = [
					'task' => ['type' => 'highlight', 'id' => $definition->getUuid(), 'name' => $definition->getTitle()],
					'asset' => ['type' => 'highlight', 'id' => $asset->getUuid(), 'name' => $asset->getName()],
				];
				$notification->setParsedMessage($l->t('Workspace: %s', [$workspace['name']]));
				break;
			default:
				throw new UnknownNotificationException();
		}

		$notification->setRichSubject($subject, $params);
		$notification->setParsedSubject(IntegrationLabels::plain($subject, $params));
		$notification->setLink($link);
		$notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg')));
		return $notification;
	}
}
