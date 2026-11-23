<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Listener;

use OCA\ContextChat\BackgroundJobs\TagRemovedJob;
use OCA\ContextChat\Service\FsEventService;
use OCA\ContextChat\Service\TagEligibilityService;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\IRootFolder;
use OCP\SystemTag\TagAssignedEvent;
use OCP\SystemTag\TagUnassignedEvent;

/**
 * @template-implements IEventListener<Event>
 */
class TagListener implements IEventListener {
	public function __construct(
		private TagEligibilityService $tagEligibility,
		private IRootFolder $rootFolder,
		private FsEventService $fsEventService,
		private IJobList $jobList,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof TagAssignedEvent) && !($event instanceof TagUnassignedEvent)) {
			return;
		}

		if ($event->getObjectType() !== 'files') {
			return;
		}

		// In the "index everything" mode the tags are ignored.
		if (!$this->tagEligibility->usesTags()) {
			return;
		}

		$eventTags = array_map('strval', $event->getTags());
		$allowHit = array_intersect($this->tagEligibility->getAllowTagIds(), $eventTags) !== [];
		$denyHit = array_intersect($this->tagEligibility->getDenyTagIds(), $eventTags) !== [];
		if (!$allowHit && !$denyHit) {
			return;
		}

		foreach ($event->getObjectIds() as $objectId) {
			if ($event instanceof TagUnassignedEvent) {
				// The tag mapper emits this event while it is still rewriting the tag
				// rows, so a tag on a parent folder cannot be seen yet. Decide later.
				if ($allowHit) {
					$this->jobList->add(TagRemovedJob::class, ['file_id' => (int)$objectId, 'kind' => TagRemovedJob::KIND_ALLOW]);
				}
				if ($denyHit) {
					$this->jobList->add(TagRemovedJob::class, ['file_id' => (int)$objectId, 'kind' => TagRemovedJob::KIND_DENY]);
				}
				continue;
			}

			$node = $this->rootFolder->getFirstNodeById((int)$objectId);
			if ($node === null) {
				continue;
			}
			if ($allowHit) {
				// An allow tag was added: index what is now eligible.
				$this->fsEventService->onInsert($node);
			}
			if ($denyHit) {
				// A deny tag was added: take out of the index what is no longer eligible.
				$this->fsEventService->onTagRemoved($node);
			}
		}
	}
}
