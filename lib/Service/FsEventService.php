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
		private TagEligibilityService $tagEligibility,
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

	/**
	 * @param bool $onlyEligible only remove files that may be in the index under the current
	 *   tag rules. Only safe where the tags of the node are still in place (before the node
	 *   is deleted). Callers that clean up files that are no longer eligible must leave it off.
	 */
	public function onDelete(Node $node, bool $recurse = true, bool $onlyEligible = false): void {
		if ($node instanceof Folder) {
			if (!$recurse) {
				return;
			}
			$fileIds = $onlyEligible
				? $this->tagEligibility->eligibleFileIds($node)
				: $this->storageService->getAllFilesInFolder($node);
		} else {
			if (!$this->allowedMimeType($node)) {
				return;
			}
			if ($onlyEligible && !$this->tagEligibility->isEligible($node)) {
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
			// only the files inside that pass the allow/deny tag rules
			$fileIds = $this->tagEligibility->eligibleFileIds($node);
		} else {
			if (!$this->allowedMimeType($node)) {
				return;
			}
			if (!$this->tagEligibility->isEligible($node)) {
				return;
			}
			try {
				$fileIds = [$node->getId()];
			} catch (InvalidPathException|NotFoundException $e) {
				return;
			}
		}

		foreach ($fileIds as $fileId) {
			$queueFile = new QueueFile();
			if ($node->getMountPoint()->getNumericStorageId() === null) {
				return;
			}
			$queueFile->setStorageId($node->getMountPoint()->getNumericStorageId());
			$queueFile->setRootId($node->getMountPoint()->getStorageRootId());
			$queueFile->setFileId($fileId);
			$queueFile->setUpdate($update);
			try {
				$this->queue->insertIntoQueue($queueFile);
			} catch (Exception $e) {
				$this->logger->error('Failed to add file to queue', ['exception' => $e]);
			}
		}
	}

	/**
	 * Kept under its old name so other callers keep working: is this node
	 * eligible for indexing under the current mode and allow/deny tag rules?
	 */
	public function hasAiKnowledgeTag(Node $node): bool {
		return $this->tagEligibility->isEligible($node);
	}

	/**
	 * Called after a listed (allow) tag was removed from a node, or a deny tag was
	 * added: removes from the index what is no longer eligible.
	 */
	public function onTagRemoved(Node $node): void {
		if (!($node instanceof Folder)) {
			if (!$this->tagEligibility->isEligible($node)) {
				$this->deleteFileIds([$node->getId()]);
			}
			return;
		}

		$keep = array_flip($this->tagEligibility->eligibleFileIds($node));
		$remove = [];
		foreach ($this->storageService->getAllFilesInFolder($node) as $fileId) {
			if (!isset($keep[$fileId])) {
				$remove[] = $fileId;
			}
		}
		$this->deleteFileIds($remove);
	}

	/**
	 * @param int[] $fileIds
	 */
	private function deleteFileIds(array $fileIds): void {
		$fileRefs = [];
		foreach ($fileIds as $fileId) {
			try {
				$fileRefs[] = ProviderConfigService::getSourceId($fileId);
			} catch (InvalidPathException|NotFoundException|Exception $e) {
				$this->logger->warning($e->getMessage(), ['exception' => $e]);
			}
		}
		foreach (array_chunk($fileRefs, ActionScheduler::BATCH_SIZE) as $batch) {
			$this->actionService->deleteSources($batch);
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
