<?php

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
		private ActionService $actionService,
		private StorageService $storageService,
		private \OCP\Share\IManager $shareManager,
	) {

	}

	public function onAccessUpdate(Node $node, bool $recurse = true): void {
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
				// todo: Remove this once we no longer support Nextcloud 31
				$shareAccessList = $this->shareManager->getAccessList($file, true, true);
				/**
				 * @var string[] $shareUserIds
				 */
				$shareUserIds = array_keys($shareAccessList['users']);

				$fileRef = ProviderConfigService::getSourceId($file->getId());
				$fileUserIds = $this->storageService->getUsersForFileId($file->getId());
				$userIds = array_values(array_unique(array_merge($shareUserIds, $fileUserIds)));
				$this->actionService->updateAccessDeclSource($userIds, $fileRef);
			} catch (InvalidPathException|NotFoundException $e) {
				$this->logger->warning($e->getMessage(), ['exception' => $e]);
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

		foreach ($files as $file) {
			if (!$this->allowedMimeType($file)) {
				continue;
			}

			try {
				$fileRef = ProviderConfigService::getSourceId($node->getId());
				$this->actionService->deleteSources($fileRef);
			} catch (InvalidPathException|NotFoundException $e) {
				$this->logger->warning($e->getMessage(), ['exception' => $e]);
			}
		}
	}

	/**
	 * @throws \OCP\Files\InvalidPathException
	 */
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
			if (!$this->allowedPath($node)) {
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
