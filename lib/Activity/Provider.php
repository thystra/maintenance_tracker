<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alan Johnson
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MaintenanceTracker\Activity;

use OCA\MaintenanceTracker\AppInfo\Application;
use OCA\MaintenanceTracker\Service\IntegrationEventMap;
use OCA\MaintenanceTracker\Service\IntegrationLabels;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

/**
 * Renders Maintenance Tracker activities at read time. Stored events carry
 * only identifiers; the workspace name is shown only while the viewing user
 * is still a member, so a former member never learns a later rename.
 */
final class Provider implements IProvider {
	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $urlGenerator,
		private IntegrationEventMap $map,
		private IntegrationLabels $labels,
	) {
	}

	public function parse($language, IEvent $event, ?IEvent $previousEvent = null): IEvent {
		if ($event->getApp() !== Application::APP_ID || $this->map->activity($event->getSubject()) === null) {
			throw new UnknownActivityException();
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $language);
		$p = $event->getSubjectParameters();
		$viewer = $event->getAffectedUser();
		$actor = is_string($p['actor'] ?? null) ? $p['actor'] : 'system';
		$details = is_array($p['details'] ?? null) ? $p['details'] : [];
		$participants = is_array($p['participants'] ?? null) ? array_values($p['participants']) : [];
		$subjectId = is_string($p['subjectId'] ?? null) ? $p['subjectId'] : '';
		$workspaceId = is_int($p['workspaceId'] ?? null) ? $p['workspaceId'] : 0;

		$params = [
			'actor' => $this->labels->user($actor, $l->t('Maintenance Tracker')),
			'workspace' => $this->labels->workspace($workspaceId, $viewer, $l->t('a workspace you no longer belong to')),
		];
		$role = static fn (string $key): string => is_string($details[$key] ?? null) ? $details[$key] : '';
		$member = $subjectId !== '' ? $this->labels->user($subjectId, $subjectId) : null;
		[$from, $to] = [(string)($participants[0] ?? ''), (string)($participants[1] ?? '')];

		$subject = match ($event->getSubject()) {
			'activity.created' => $l->t('{actor} recorded maintenance in {workspace}'),
			'activity.updated' => $l->t('{actor} updated a maintenance record in {workspace}'),
			'activity.archived' => $l->t('{actor} archived a maintenance record in {workspace}'),
			'work_definition.created' => $l->t('{actor} added a maintenance task in {workspace}'),
			'work_definition.updated' => $l->t('{actor} changed a maintenance task in {workspace}'),
			'work_definition.archived' => $l->t('{actor} archived a maintenance task in {workspace}'),
			'evidence.created' => $l->t('{actor} added evidence in {workspace}'),
			'evidence.archived' => $l->t('{actor} archived evidence in {workspace}'),
			'evidence.linked' => $l->t('{actor} attached evidence to a record in {workspace}'),
			'evidence.unlinked' => $l->t('{actor} detached evidence from a record in {workspace}'),
			'workspace.member.added' => $subjectId === $viewer
				? $l->t('{actor} added you to {workspace} as %s', [$role('role')])
				: $l->t('{actor} added {member} to {workspace} as %s', [$role('role')]),
			'workspace.member.role_changed' => $subjectId === $viewer
				? $l->t('{actor} changed your role in {workspace} to %s', [$role('toRole')])
				: $l->t('{actor} changed the role of {member} in {workspace} to %s', [$role('toRole')]),
			'workspace.member.removed' => $subjectId === $viewer
				? $l->t('{actor} removed you from {workspace}')
				: $l->t('{actor} removed {member} from {workspace}'),
			'workspace.ownership.offered' => $viewer === $to
				? $l->t('{actor} offered you ownership of {workspace}')
				: $l->t('{actor} offered ownership of {workspace} to {to}'),
			'workspace.ownership.cancelled' => $l->t('{actor} withdrew the ownership offer for {workspace}'),
			'workspace.ownership.declined' => $l->t('{actor} declined ownership of {workspace}'),
			'workspace.ownership.expired' => $l->t('The ownership offer for {workspace} expired'),
			'workspace.ownership.aborted' => $l->t('{actor} aborted the ownership transfer of {workspace}'),
			'workspace.ownership.transferred' => $l->t('Ownership of {workspace} passed from {from} to {to}'),
			'workspace.owner.assigned' => $l->t('An administrator made {to} the owner of {workspace}'),
			'workspace.orphaned' => $l->t('{workspace} lost its owner and awaits an administrator'),
			default => throw new UnknownActivityException(),
		};

		if (in_array($event->getSubject(), ['workspace.member.added', 'workspace.member.role_changed', 'workspace.member.removed'], true) && $member !== null) {
			$params['member'] = $member;
		}
		if ($from !== '') {
			$params['from'] = $this->labels->user($from, $from);
		}
		if ($to !== '') {
			$params['to'] = $this->labels->user($to, $to);
		}
		$params = array_filter($params, static fn (string $key): bool => str_contains($subject, '{' . $key . '}'), ARRAY_FILTER_USE_KEY);

		$event->setRichSubject($subject, $params);
		$event->setParsedSubject(IntegrationLabels::plain($subject, $params));
		$event->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg')));
		$event->setLink($this->urlGenerator->linkToRouteAbsolute('maintenance_tracker.page.index'));
		return $event;
	}
}
