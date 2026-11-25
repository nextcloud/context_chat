<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Listener;

use OCA\ContextChat\Logger;
use OCA\ContextChat\Service\FsEventScheduler;
use OCA\ContextChat\Service\FsEventService;
use OCP\DB\Exception;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IUserSession;
use OCP\Server;

/**
 * Re-indexes items restored from the user and the groupfolder trash. A restore moves the children
 * of a folder without cache events, so they would not be indexed again otherwise.
 */
class TrashRestoreListener {

	public function __construct(
		private Logger $logger,
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private FsEventService $fsEventService,
		private FsEventScheduler $fsEventScheduler,
	) {
	}

	/**
	 * Slot of the post_restore hook, OC_Hook::emit() calls it statically.
	 */
	public static function postRestoreHook(array $params): void {
		Server::get(self::class)->onRestore($params);
	}

	/**
	 * @param array $params filePath: path of the restored item in the files folder of the restoring user
	 */
	public function onRestore(array $params): void {
		$filePath = $params['filePath'] ?? null;
		if (!is_string($filePath) || $filePath === '') {
			return;
		}
		$user = $this->userSession->getUser();
		if ($user === null) {
			return;
		}

		try {
			$node = $this->rootFolder->getUserFolder($user->getUID())->get($filePath);
			if (!$this->fsEventService->isIndexableNode($node)) {
				return;
			}
			// Asynchronous, because we potentially recurse
			$this->fsEventScheduler->onInsert($node);
		} catch (InvalidPathException|NotFoundException|NotPermittedException|Exception $e) {
			$this->logger->warning('Error in trash restore listener: ' . $e->getMessage(), ['exception' => $e]);
		}
	}
}
