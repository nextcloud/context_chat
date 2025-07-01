<?php

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Service;

use OCA\ContextChat\BackgroundJobs\FileSystemListenerJob;
use OCA\ContextChat\Db\FsEvent;
use OCA\ContextChat\Db\FsEventMapper;
use OCA\ContextChat\Type\FsEventType;
use OCP\BackgroundJob\IJobList;
use OCP\DB\Exception;
use OCP\Files\InvalidPathException;
use OCP\Files\Node;
use OCP\Files\NotFoundException;

class FsEventScheduler {

	public function __construct(
		private FsEventMapper $fsEventMapper,
		private IJobList $jobList,
		private StorageService $storageService,
	) {

	}

	/**
	 * @throws Exception
	 */
	private function getOwnerIdForNode(Node $node): string {
		if ($node->getOwner()) {
			return $node->getOwner()->getUID();
		}
		$ownerId = $this->storageService->getOwnerForFileId($node->getId());
		if ($ownerId !== false) {
			return $ownerId;
		}
		throw new NotFoundException('Cannot get owner for node ID ' . $node->getId());
	}

	/**
	 * @throws Exception
	 */
	private function scheduleEvent(FsEventType $type, string $userId, int $nodeId): void {
		$item = new FsEvent();
		$item->setUserId($userId);
		$item->setType($type);
		$item->setNodeId($nodeId);

		// do not catch DB exceptions
		$this->fsEventMapper->insert($item);

		if (!$this->jobList->has(FileSystemListenerJob::class, null)) {
			$this->jobList->add(FileSystemListenerJob::class, null);
		}
	}

	/**
	 * @throws Exception
	 */
	private function retractEvent(FsEventType $type, string $ownerId, int $nodeId) {
		$this->fsEventMapper->deleteByContent($type, $ownerId, $nodeId);
	}

	/**
	 * @throws InvalidPathException
	 * @throws NotFoundException
	 * @throws Exception
	 */
	public function onAccessUpdateDecl(int $nodeId): void {
		$ownerId = $this->storageService->getOwnerForFileId($nodeId);
		if ($ownerId === false) {
			throw new NotFoundException('Cannot get owner for file ID ' . $nodeId);
		}
		$this->scheduleEvent(FsEventType::ACCESS_UPDATE_DECL, $ownerId, $nodeId);
	}

	/**
	 * @throws NotFoundException
	 * @throws InvalidPathException
	 * @throws Exception
	 */
	public function onInsert(Node $node): void {
		$this->scheduleEvent(FsEventType::CREATE, $this->getOwnerIdForNode($node), $node->getId());
	}

	/**
	 * @throws NotFoundException
	 * @throws Exception
	 */
	public function retractAccessUpdateDecl(int $nodeId): void {
		$ownerId = $this->storageService->getOwnerForFileId($nodeId);
		if ($ownerId === false) {
			throw new NotFoundException('Cannot get owner for file ID ' . $nodeId);
		}
		$this->retractEvent(FsEventType::ACCESS_UPDATE_DECL, $ownerId, $nodeId);
	}
}
