<?php

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Service;

use OCA\ContextChat\AppInfo\Application;
use OCA\ContextChat\Db\QueueFile;
use OCA\ContextChat\Logger;
use OCP\DB\Exception;
use OCP\Files\Folder;
use OCP\Files\InvalidPathException;
use OCP\Files\Node;
use OCP\Files\NotFoundException;

class FsEventService {

	public function __construct(
		private Logger $logger,
		private QueueService $queue,
		private ActionScheduler $actionService,
		private StorageService $storageService,
		private \OCP\Share\IManager $shareManager,
	) {

	}

	public function onAccessUpdateDecl(Node $node, bool $recurse = true): void {
		if ($node instanceof Folder) {
			if (!$recurse) {
				return;
			}

			$files = $this->storageService->getAllFilesInFolder($node);
		} else {
			$files = [$node];
		}

		foreach ($files as $file) {
			if (!$this->allowedMimeType($file)) {
				continue;
			}
			try {
				$fileRef = ProviderConfigService::getSourceId($file->getId());
				$fileUserIds = $this->storageService->getUsersForFileId($file->getId());

				if (class_exists('OCP\Files\Config\Event\UserMountAddedEvent')) {
					$userIds = $fileUserIds;
				} else {
					// todo: Remove this once we no longer support Nextcloud 31
					$shareAccessList = $this->shareManager->getAccessList($file, true, true);
					/** @var string[] $shareUserIds */
					$shareUserIds = array_keys($shareAccessList['users']);
					$userIds = array_values(array_unique(array_merge($shareUserIds, $fileUserIds)));
				}

				$this->actionService->updateAccessDeclSource($userIds, $fileRef);
			} catch (InvalidPathException|NotFoundException $e) {
				$this->logger->warning('Cannot get file id for declarative access update:' . $e->getMessage(), [
					'exception' => $e
				]);
			}
		}
	}

	public function onDelete(Node $node, bool $recurse = true): void {
		if ($node instanceof Folder) {
			if (!$recurse) {
				return;
			}
			$files = $this->storageService->getAllFilesInFolder($node);
		} else {
			$files = [$node];
		}

		$fileRefs = [];
		foreach ($files as $file) {
			if (!$this->allowedMimeType($file)) {
				continue;
			}

			try {
				$fileRefs[] = ProviderConfigService::getSourceId($file->getId());
			} catch (InvalidPathException|NotFoundException $e) {
				$this->logger->warning($e->getMessage(), ['exception' => $e]);
			}
		}
		$this->actionService->deleteSources(...$fileRefs);
	}

	public function onInsert(Node $node, bool $recurse = true, bool $update = false): void {
		if (!$this->allowedPath($node)) {
			return;
		}
		if ($node instanceof Folder) {
			if (!$recurse) {
				return;
			}
			$files = $this->storageService->getAllFilesInFolder($node);
		} else {
			$files = [$node];
		}

		foreach ($files as $file) {
			if (!$this->allowedMimeType($file)) {
				continue;
			}
			if (!$this->allowedPath($file)) {
				continue;
			}

			$queueFile = new QueueFile();
			if ($file->getMountPoint()->getNumericStorageId() === null) {
				return;
			}
			$queueFile->setStorageId($file->getMountPoint()->getNumericStorageId());
			$queueFile->setRootId($file->getMountPoint()->getStorageRootId());

			try {
				$queueFile->setFileId($file->getId());
			} catch (InvalidPathException|NotFoundException $e) {
				$this->logger->warning($e->getMessage(), ['exception' => $e]);
				return;
			}

			$queueFile->setUpdate($update);
			try {
				$this->queue->insertIntoQueue($queueFile);
			} catch (Exception $e) {
				$this->logger->error('Failed to add file to queue', ['exception' => $e]);
			}
		}
	}

	private function allowedMimeType(Node $file): bool {
		$mimeType = $file->getMimeType();
		return in_array($mimeType, Application::MIMETYPES, true);
	}

	private function allowedPath(Node $file): bool {
		$path = $file->getPath();
		return !preg_match('/^\/.+\/(files_versions|files_trashbin)\/.+/', $path, $matches);
	}
}
