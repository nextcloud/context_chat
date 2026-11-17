<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\BackgroundJobs;

use OCA\ContextChat\Service\FsEventService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\IRootFolder;

/**
 * Runs after an allow or a deny tag was removed from a file or folder, once the
 * tag rows are consistent again.
 * - allow tag removed: removes from the index what is no longer eligible.
 * - deny tag removed: queues for indexing what is now eligible.
 */
class TagRemovedJob extends QueuedJob {
	public const KIND_ALLOW = 'allow';
	public const KIND_DENY = 'deny';

	public function __construct(
		ITimeFactory $timeFactory,
		private IRootFolder $rootFolder,
		private FsEventService $fsEventService,
	) {
		parent::__construct($timeFactory);
	}

	/**
	 * @param array{file_id:int, kind?:string} $argument
	 */
	protected function run($argument): void {
		$node = $this->rootFolder->getFirstNodeById($argument['file_id']);
		if ($node === null) {
			return;
		}
		if (($argument['kind'] ?? self::KIND_ALLOW) === self::KIND_DENY) {
			$this->fsEventService->onInsert($node);
			return;
		}
		$this->fsEventService->onTagRemoved($node);
	}
}
