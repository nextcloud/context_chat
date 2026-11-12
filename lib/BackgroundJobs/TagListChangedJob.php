<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\BackgroundJobs;

use OCA\ContextChat\Logger;
use OCA\ContextChat\Service\FsEventService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\IRootFolder;
use OCP\SystemTag\ISystemTagObjectMapper;

/**
 * An admin added a tag to, or removed a tag from, the allow or deny list. Only the
 * files and folders that carry that tag can change status, so only those are
 * looked at (a tag on a folder covers everything inside it).
 *
 * ACTION_INDEX:  the tag no longer blocks, or now allows: queue what is eligible.
 * ACTION_REMOVE: the tag now blocks, or no longer allows: remove from the index
 *                what is no longer eligible.
 *
 * Eligibility is decided with the lists as they are when the job runs.
 */
class TagListChangedJob extends QueuedJob {
	public const ACTION_INDEX = 'index';
	public const ACTION_REMOVE = 'remove';

	public function __construct(
		ITimeFactory $timeFactory,
		private Logger $logger,
		private IRootFolder $rootFolder,
		private ISystemTagObjectMapper $tagMapper,
		private FsEventService $fsEventService,
	) {
		parent::__construct($timeFactory);
	}

	/**
	 * @param array{tag_id:int|string, action:string} $argument
	 */
	protected function run($argument): void {
		$tagId = (string)($argument['tag_id'] ?? '');
		$action = $argument['action'] ?? '';
		if ($tagId === '' || !in_array($action, [self::ACTION_INDEX, self::ACTION_REMOVE], true)) {
			$this->logger->warning('[TagListChangedJob] Invalid arguments, nothing done');
			return;
		}

		try {
			$objectIds = $this->tagMapper->getObjectIdsForTags([$tagId], 'files');
		} catch (\Throwable $e) {
			// for example the tag was deleted in the meantime
			$this->logger->warning('[TagListChangedJob] Cannot list files of tag ' . $tagId . ': ' . $e->getMessage(), ['exception' => $e]);
			return;
		}

		foreach ($objectIds as $objectId) {
			try {
				$node = $this->rootFolder->getFirstNodeById((int)$objectId);
				if ($node === null) {
					continue;
				}
				if ($action === self::ACTION_INDEX) {
					$this->fsEventService->onInsert($node);
				} else {
					$this->fsEventService->onTagRemoved($node);
				}
			} catch (\Throwable $e) {
				$this->logger->warning('[TagListChangedJob] Failed for file id ' . $objectId . ': ' . $e->getMessage(), ['exception' => $e]);
			}
		}
	}
}
