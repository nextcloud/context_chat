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
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;

class FsEventService {

	public function __construct(
		private Logger $logger,
		private QueueService $queue,
		private ActionScheduler $actionService,
		private StorageService $storageService,
		private IRootFolder $rootFolder,
	) {

	}

	public function onAccessUpdateDecl(Node $node, bool $recurse = true): void {
		if ($node instanceof Folder) {
			if (!$recurse) {
				return;
			}

			$fileIds = $this->storageService->getAllFilesInFolder($node);
		} else {
			if (!$this->allowedMimeType($node)) {
				return;
			}
			try {
				$fileIds = [$node->getId()];
			} catch (InvalidPathException|NotFoundException $e) {
				return;
			}
		}
		foreach ($fileIds as $fileId) {
			try {
				$fileRef = ProviderConfigService::getSourceId($fileId);
				$userIds = $this->storageService->getUsersForFileId($fileId);

				$this->actionService->updateAccessDeclSource($userIds, $fileRef);
			} catch (InvalidPathException|NotFoundException $e) {
				$this->logger->warning('Cannot get file id for declarative access update:' . $e->getMessage(), [
					'exception' => $e
				]);
			} catch (Exception $e) {
				$this->logger->warning('Failed to insert declarative access update into DB:' . $e->getMessage(), [
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
			$fileIds = $this->storageService->getAllFilesInFolder($node);
		} else {
			if (!$this->allowedMimeType($node)) {
				return;
			}
			try {
				$fileIds = [$node->getId()];
			} catch (InvalidPathException|NotFoundException $e) {
				return;
			}
		}

		$fileRefs = [];
		foreach ($fileIds as $fileId) {
			try {
				$fileRefs[] = ProviderConfigService::getSourceId($fileId);
			} catch (InvalidPathException|NotFoundException|Exception $e) {
				$this->logger->warning($e->getMessage(), ['exception' => $e]);
			}
		}
		$batches = array_chunk($fileRefs, ActionScheduler::BATCH_SIZE);
		foreach ($batches as $batch) {
			$this->actionService->deleteSources($batch);
		}
	}

	public function onInsert(Node $node, bool $recurse = true, bool $update = false): void {
		if (!$this->allowedPath($node)) {
			return;
		}
		if ($node instanceof Folder) {
			if (!$recurse) {
				return;
			}
			$fileIds = $this->storageService->getAllFilesInFolder($node);
		} else {
			if (!$this->allowedMimeType($node)) {
				return;
			}
			try {
				$fileIds = [$node->getId()];
			} catch (InvalidPathException|NotFoundException $e) {
				return;
			}
		}

		foreach ($fileIds as $fileId) {
			$file = current($this->rootFolder->getById($fileId));
			if (!$file instanceof File) {
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
