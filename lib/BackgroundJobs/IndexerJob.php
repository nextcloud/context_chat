<?php
/*
 * Copyright (c) 2022 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);

namespace OCA\Cwyd\BackgroundJobs;

use OCA\Cwyd\Db\QueueFile;
use OCA\Cwyd\Service\LangRopeService;
use OCA\Cwyd\Service\QueueService;
use OCA\Cwyd\Service\StorageService;
use OCA\Cwyd\Type\Source;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\TimedJob;
use OCP\DB\Exception;
use OCP\Files\Config\ICachedMountInfo;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\File;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

class IndexerJob extends TimedJob
{

	public function __construct(
		ITimeFactory            $time,
		private LoggerInterface $logger,
		private QueueService    $queue,
		private IUserMountCache $userMountCache,
		private IJobList        $jobList,
		private LangRopeService $langRopeService,
		private StorageService  $storageService,
		private IRootFolder     $rootFolder,
	)
	{
		parent::__construct($time);
		$this->setInterval(60 * 5);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	/**
	 * @param array{storageId: int, rootId: int} $argument
	 * @return void
	 * @throws Exception
	 * @throws \ErrorException
	 * @throws \Throwable
	 */
	public function run($argument): void
	{
		/**
		 * @var int $storageId
		 */
		$storageId = $argument['storageId'];
		$rootId = $argument['rootId'];
		$this->logger->debug('Index files of storage ' . $storageId);
		try {
			$this->logger->debug('fetching ' . $this->getBatchSize() . ' files from queue');
			$files = $this->queue->getFromQueue($storageId, $rootId, $this->getBatchSize());
		} catch (Exception $e) {
			$this->logger->error('Cannot retrieve items from  queue', ['exception' => $e]);
			return;
		}

		// Setup Filesystem for a users that can access this mount
		$mounts = array_values(array_filter($this->userMountCache->getMountsForStorageId($storageId), function (ICachedMountInfo $mount) use ($rootId) {
			return $mount->getRootId() === $rootId;
		}));
		if (count($mounts) > 0) {
			\OC_Util::setupFS($mounts[0]->getUser()->getUID());
		}

		try {
			$this->logger->debug('Running indexing');
			$this->index($files);
		} catch (\RuntimeException $e) {
			$this->logger->warning('Temporary problem with indexing, trying again soon', ['exception' => $e]);
		} catch (\ErrorException $e) {
			$this->logger->warning('Problem with indexing', ['exception' => $e]);
			$this->logger->debug('Removing ' . static::class . ' with argument ' . var_export($argument, true) . 'from oc_jobs');
			$this->jobList->remove(static::class, $argument);
			throw $e;
		}

		try {
			// If there is at least one file left in the queue, reschedule this job
			$files = $this->queue->getFromQueue($storageId, $rootId, 1);
			if (count($files) === 0) {
				$this->logger->debug('Removing ' . static::class . ' with argument ' . var_export($argument, true) . 'from oc_jobs');
				$this->jobList->remove(static::class, $argument);
			}
		} catch (Exception $e) {
			$this->logger->error('Cannot retrieve items from queue', ['exception' => $e]);
			return;
		}
	}

	/**
	 * @return int
	 */
	protected function getBatchSize(): int
	{
		return 2000;
	}

	/**
	 * @param QueueFile[] $files
	 * @return void
	 * @throws \RuntimeException|\ErrorException
	 */
	protected function index(array $files): void {
		foreach ($files as $queueFile) {
			$file = current($this->rootFolder->getById($queueFile->getFileId()));
			if (!$file instanceof File) {
				continue;
			}
			try {
				$fileHandle = $file->fopen('r');
			} catch (LockedException|NotPermittedException $e) {
				$this->logger->error('Could not open file ' . $file->getPath() . ' for reading', ['exception' => $e]);
				continue;
			}
			$userIds = $this->storageService->getUsersForFileId($queueFile->getFileId());
			foreach ($userIds as $userId) {
				try {
					$source = new Source($userId, 'file: ' . $file->getId(), $fileHandle, $file->getMtime(), $file->getMimeType());
				} catch (InvalidPathException|NotFoundException $e) {
					$this->logger->error('Could not find file ' . $file->getPath(), ['exception' => $e]);
					continue 2;
				}
				$this->langRopeService->indexSources($userId, [$source]);
			}
			try {
				$this->queue->removeFromQueue($queueFile);
			} catch (Exception $e) {
				$this->logger->error('Could not remove file from queue', ['exception' => $e]);
			}
		}
	}
}
