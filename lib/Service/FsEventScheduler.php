<?php

namespace OCA\ContextChat\Service;

use OCA\ContextChat\AppInfo\Application;
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
	) {

	}

	/**
	 * @throws Exception
	 */
	private function scheduleEvent(FsEventType $type, int $nodeId): void {
		$item = new FsEvent();
		$item->setType($type);
		$item->setNodeId($nodeId);

		// do not catch DB exceptions
		$this->fsEventMapper->insert($item);

		if (!$this->jobList->has(FileSystemListenerJob::class, null)) {
			$this->jobList->add(FileSystemListenerJob::class, null);
		}
	}

	/**
	 * @throws InvalidPathException
	 * @throws NotFoundException
	 * @throws Exception
	 */
	public function onAccessUpdate(Node $node): void {
		$this->scheduleEvent(FsEventType::ACCESS_UPDATE, $node->getId());
	}

	/**
	 * @throws NotFoundException
	 * @throws InvalidPathException
	 * @throws Exception
	 */
	public function onDelete(Node $node): void {
		$this->scheduleEvent(FsEventType::DELETE, $node->getId());
	}

	/**
	 * @throws NotFoundException
	 * @throws InvalidPathException
	 * @throws Exception
	 */
	public function onInsert(Node $node): void {
		$this->scheduleEvent(FsEventType::CREATE, $node->getId());
	}
}
