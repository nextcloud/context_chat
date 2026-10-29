<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Tests;

use OCA\ContextChat\AppInfo\Application;
use OCA\ContextChat\BackgroundJobs\InitialContentImportJob;
use OCA\ContextChat\BackgroundJobs\SchedulerJob;
use OCA\ContextChat\BackgroundJobs\StorageCrawlJob;
use OCA\ContextChat\Db\FsEventMapper;
use OCA\ContextChat\Db\QueueAction;
use OCA\ContextChat\Db\QueueActionMapper;
use OCA\ContextChat\Db\QueueContentItem;
use OCA\ContextChat\Db\QueueContentItemMapper;
use OCA\ContextChat\Db\QueueFile;
use OCA\ContextChat\Db\QueueMapper;
use OCA\ContextChat\Logger;
use OCA\ContextChat\Service\FetchGuard;
use OCA\ContextChat\Service\LangRopeService;
use OCA\ContextChat\Service\ProviderConfigService;
use OCA\ContextChat\Service\ReindexService;
use OCA\ContextChat\Type\ActionType;
use OCA\ContextChat\Type\FsEventType;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[Group('DB')]
class ReindexServiceTest extends TestCase {
	private const PROVIDER_CLASS = 'OCA\\SomeApp\\ContentProvider';
	private const OTHER_PROVIDER_CLASS = 'OCA\\OtherApp\\ContentProvider';
	private const NOW = 1_800_000_000;

	private IDBConnection $db;
	private IJobList $jobList;
	private IAppConfig $appConfig;
	private ProviderConfigService $providerConfig;
	private LangRopeService&MockObject $langRope;
	private ReindexService $service;
	private FetchGuard $fetchGuard;
	/** @var list<array<string, mixed>> */
	private array $savedConfig;

	protected function setUp(): void {
		parent::setUp();
		$this->db = Server::get(IDBConnection::class);
		$this->jobList = Server::get(IJobList::class);
		// the app scoped config is only available from the app's container
		$container = (new Application())->getContainer();
		$this->appConfig = $container->get(IAppConfig::class);
		$this->providerConfig = $container->get(ProviderConfigService::class);
		$this->langRope = $this->createMock(LangRopeService::class);
		$this->fetchGuard = $this->newGuard(Server::get(ITimeFactory::class));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		$this->service = new ReindexService(
			$this->db,
			$this->jobList,
			$this->appConfig,
			$this->providerConfig,
			$this->langRope,
			Server::get(QueueContentItemMapper::class),
			$this->fetchGuard,
			$time,
			Server::get(Logger::class),
		);

		$this->savedConfig = $this->readConfig();
		$this->clearState();
		$this->appConfig->setAppValueString('providers', '', lazy: true);
	}

	protected function tearDown(): void {
		$this->clearState();
		// the wipe resets the stats and the providers, put back what the instance had before
		$qb = $this->db->getQueryBuilder();
		$qb->delete('appconfig')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter(Application::APP_ID)))
			->executeStatement();
		foreach ($this->savedConfig as $row) {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('appconfig');
			foreach ($row as $column => $value) {
				$qb->setValue($column, $qb->createNamedParameter($value));
			}
			$qb->executeStatement();
		}
		Server::get(\OCP\IAppConfig::class)->clearCache(true);
		parent::tearDown();
	}

	/** @return list<array<string, mixed>> */
	private function readConfig(): array {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('*')->from('appconfig')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter(Application::APP_ID)))
			->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}

	private function clearState(): void {
		foreach (ReindexService::QUEUE_TABLES as $table) {
			$this->db->getQueryBuilder()->delete($table)->executeStatement();
		}
		foreach ([SchedulerJob::class, StorageCrawlJob::class, InitialContentImportJob::class] as $jobClass) {
			$this->jobList->remove($jobClass);
		}
	}

	private function seedIndexedState(): void {
		$file = new QueueFile();
		$file->setFileId(42);
		$file->setStorageId(1);
		$file->setRootId(1);
		$file->setUpdate(false);
		Server::get(QueueMapper::class)->insertIntoQueue($file);

		foreach (['someapp', 'otherapp'] as $appId) {
			$item = new QueueContentItem();
			$item->setItemId('item1');
			$item->setAppId($appId);
			$item->setProviderId('prov');
			$item->setTitle('title');
			$item->setContent('content');
			$item->setDocumentType('text');
			$item->setLastModified(new \DateTime());
			$item->setUsers('admin');
			Server::get(QueueContentItemMapper::class)->insert($item);
		}

		$action = new QueueAction();
		$action->setType(ActionType::DELETE_SOURCE_IDS);
		$action->setPayload('{"sourceIds":["files__default: 42"]}');
		Server::get(QueueActionMapper::class)->insertIntoQueue($action);

		Server::get(FsEventMapper::class)->insertRow(FsEventType::CREATE->value, 'admin', 42);

		$this->jobList->add(StorageCrawlJob::class, ['storage_id' => 1, 'root_id' => 1, 'overridden_root' => 1, 'last_file_id' => 0]);
		$this->jobList->add(InitialContentImportJob::class, self::PROVIDER_CLASS);
		$this->jobList->add(InitialContentImportJob::class, self::OTHER_PROVIDER_CLASS);

		$this->appConfig->setAppValueInt('last_indexed_time', 123, lazy: true);
		$this->appConfig->setAppValueInt('last_enqueued_db_id', 7, lazy: true);
		$this->appConfig->setAppValueInt('eligible_files_count', 99, lazy: true);
		$this->appConfig->setAppValueInt('eligible_files_count_time', 456, lazy: true);

		$this->providerConfig->updateProvider('files', 'default', '', true);
		$this->providerConfig->updateProvider('someapp', 'prov', self::PROVIDER_CLASS, true);
		$this->providerConfig->updateProvider('otherapp', 'prov', self::OTHER_PROVIDER_CLASS, true);
	}

	/** @return list<string> */
	private function contentQueueAppIds(): array {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->selectDistinct('app_id')->from('context_chat_content_queue')->executeQuery();
		$appIds = [];
		while (($appId = $result->fetchOne()) !== false) {
			$appIds[] = $appId;
		}
		$result->closeCursor();
		return $appIds;
	}

	private function rowCount(string $table): int {
		$qb = $this->db->getQueryBuilder();
		return (int)$qb->select($qb->func()->count('*'))->from($table)->executeQuery()->fetchOne();
	}

	public function testWipeClearsAllLocalStateAndTheBackend(): void {
		$this->seedIndexedState();
		$this->langRope->expects($this->once())->method('deleteAll');
		$this->langRope->expects($this->never())->method('deleteProvider');

		$this->service->wipe();

		foreach (ReindexService::QUEUE_TABLES as $table) {
			$this->assertSame(0, $this->rowCount($table), $table . ' should be empty');
		}
		$this->assertFalse($this->jobList->has(StorageCrawlJob::class, ['storage_id' => 1, 'root_id' => 1, 'overridden_root' => 1, 'last_file_id' => 0]));
		$this->assertFalse($this->jobList->has(InitialContentImportJob::class, self::PROVIDER_CLASS));
		$this->assertFalse($this->jobList->has(InitialContentImportJob::class, self::OTHER_PROVIDER_CLASS));
		$this->assertSame(0, $this->appConfig->getAppValueInt('last_indexed_time', 0, lazy: true));
		$this->assertSame(-1, $this->appConfig->getAppValueInt('last_enqueued_db_id', -1, lazy: true));
		$this->assertSame(0, $this->appConfig->getAppValueInt('eligible_files_count_time', 0, lazy: true));
		$this->assertSame(self::NOW, $this->appConfig->getAppValueInt('installed_time', 0, lazy: true));

		$providers = $this->providerConfig->getProviders();
		$this->assertFalse($providers['someapp__prov']['isInitiated'], 'content providers must be imported again');
		$this->assertTrue($providers[ProviderConfigService::getDefaultProviderKey()]['isInitiated'], 'files are re-crawled by SchedulerJob instead');
	}

	public function testWipeClearsLocalStateBeforeTheBackendFails(): void {
		$this->seedIndexedState();
		$this->langRope->method('deleteAll')->willThrowException(new \RuntimeException('backend down'));

		try {
			$this->service->wipe();
			$this->fail('wipe() must report the backend failure');
		} catch (\RuntimeException $e) {
			$this->assertSame('backend down', $e->getMessage());
		}

		// nothing may be indexed or pruned against a half-wiped vector DB, and a re-run must work
		foreach (ReindexService::QUEUE_TABLES as $table) {
			$this->assertSame(0, $this->rowCount($table), $table . ' should be empty');
		}
	}

	public function testScheduleReindexAfterWipeQueuesCrawlAndProviderImportsOnce(): void {
		$this->seedIndexedState();
		$this->service->wipe();

		$this->assertTrue($this->service->scheduleReindex());
		$this->assertFalse($this->service->scheduleReindex(), 'an already scheduled crawl is not scheduled again');

		$this->assertTrue($this->jobList->has(SchedulerJob::class, null));
		$this->assertTrue($this->jobList->has(InitialContentImportJob::class, self::PROVIDER_CLASS));
		$importJobs = iterator_to_array($this->jobList->getJobsIterator(InitialContentImportJob::class, null, 0), false);
		$this->assertCount(2, $importJobs);
	}

	public function testScheduleReindexSkipsInitiatedProviders(): void {
		$this->providerConfig->updateProvider('someapp', 'prov', self::PROVIDER_CLASS, true);

		$this->service->scheduleReindex();

		$this->assertTrue($this->jobList->has(SchedulerJob::class, null));
		$this->assertFalse($this->jobList->has(InitialContentImportJob::class, self::PROVIDER_CLASS));
	}

	public function testWipeFilesOnlyLeavesContentProvidersAndActions(): void {
		$this->seedIndexedState();
		$this->langRope->expects($this->never())->method('deleteAll');
		$this->langRope->expects($this->once())->method('deleteProvider')->with('files__default');

		$this->service->wipe(['files__default']);

		$this->assertSame(0, $this->rowCount('context_chat_queue'));
		$this->assertSame(0, $this->rowCount('context_chat_fs_events'));
		$this->assertSame(2, $this->rowCount('context_chat_content_queue'), 'content providers must be kept');
		$this->assertSame(1, $this->rowCount('context_chat_action_queue'), 'actions of other providers and users must be kept');
		$this->assertFalse($this->jobList->has(StorageCrawlJob::class, ['storage_id' => 1, 'root_id' => 1, 'overridden_root' => 1, 'last_file_id' => 0]));
		$this->assertTrue($this->jobList->has(InitialContentImportJob::class, self::PROVIDER_CLASS));
		$this->assertSame(0, $this->appConfig->getAppValueInt('last_indexed_time', 0, lazy: true));
		$this->assertTrue($this->providerConfig->getProviders()['someapp__prov']['isInitiated']);
	}

	public function testWipeOneContentProviderLeavesTheRest(): void {
		$this->seedIndexedState();
		$this->langRope->expects($this->never())->method('deleteAll');
		$this->langRope->expects($this->once())->method('deleteProvider')->with('someapp__prov');

		$this->service->wipe(['someapp__prov']);

		$this->assertSame(['otherapp'], $this->contentQueueAppIds());
		$this->assertSame(1, $this->rowCount('context_chat_queue'), 'files must be kept');
		$this->assertSame(1, $this->rowCount('context_chat_fs_events'), 'files must be kept');
		$this->assertSame(1, $this->rowCount('context_chat_action_queue'));
		$this->assertFalse($this->jobList->has(InitialContentImportJob::class, self::PROVIDER_CLASS));
		$this->assertTrue($this->jobList->has(InitialContentImportJob::class, self::OTHER_PROVIDER_CLASS));
		$this->assertSame(123, $this->appConfig->getAppValueInt('last_indexed_time', 0, lazy: true), 'file stats must be kept');

		$providers = $this->providerConfig->getProviders();
		$this->assertFalse($providers['someapp__prov']['isInitiated']);
		$this->assertTrue($providers['otherapp__prov']['isInitiated']);
	}

	public function testWipeOfAnUnregisteredProviderOnlyDeletesInTheBackend(): void {
		$this->seedIndexedState();
		$this->langRope->expects($this->once())->method('deleteProvider')->with('removedapp__prov');

		$this->service->wipe(['removedapp__prov']);

		$this->assertSame(2, $this->rowCount('context_chat_content_queue'));
		$this->assertArrayNotHasKey('removedapp__prov', $this->providerConfig->getProviders());
	}

	public function testScheduleReindexOfANamedProviderReimportsItEvenIfImported(): void {
		$this->providerConfig->updateProvider('someapp', 'prov', self::PROVIDER_CLASS, true);
		$this->providerConfig->updateProvider('otherapp', 'prov', self::OTHER_PROVIDER_CLASS, true);

		$this->assertTrue($this->service->scheduleReindex(['someapp__prov']));

		$this->assertTrue($this->jobList->has(InitialContentImportJob::class, self::PROVIDER_CLASS));
		$this->assertFalse($this->jobList->has(InitialContentImportJob::class, self::OTHER_PROVIDER_CLASS));
		$this->assertFalse($this->jobList->has(SchedulerJob::class, null), 'files are not in the scope');
		// InitialContentImportJob skips initiated providers
		$this->assertFalse($this->providerConfig->getProviders()['someapp__prov']['isInitiated']);
	}

	public function testScheduleReindexOfFilesOnly(): void {
		$this->providerConfig->updateProvider('someapp', 'prov', self::PROVIDER_CLASS, false);

		$this->assertTrue($this->service->scheduleReindex(['files__default']));
		$this->assertFalse($this->service->scheduleReindex(['files__default']));

		$this->assertTrue($this->jobList->has(SchedulerJob::class, null));
		$this->assertFalse($this->jobList->has(InitialContentImportJob::class, self::PROVIDER_CLASS));
	}

	public function testWipeableProviderKeysIncludeProvidersOnlyKnownToTheBackend(): void {
		$this->providerConfig->updateProvider('someapp', 'prov', self::PROVIDER_CLASS, true);
		$this->langRope->method('getIndexedDocumentsCounts')->willReturn(['files__default' => 3, 'removedapp__prov' => 1]);

		$this->assertSame(['files__default', 'someapp__prov'], $this->service->getRegisteredProviderKeys());
		$this->assertSame(['files__default', 'removedapp__prov', 'someapp__prov'], $this->service->getWipeableProviderKeys());
	}

	private function newGuard(ITimeFactory $time): FetchGuard {
		return new FetchGuard($this->db, $this->appConfig, $time, Server::get(QueueMapper::class), Server::get(QueueContentItemMapper::class));
	}

	/** @return ITimeFactory&MockObject a clock that advances one second per sleep(1) of the guard */
	private function clock(int &$now): ITimeFactory {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(function () use (&$now) {
			return $now;
		});
		return $time;
	}

	public function testFetchingIsPausedOnlyWhileTheBackendIsWiped(): void {
		$this->seedIndexedState();
		$seen = null;
		$this->langRope->method('deleteAll')->willReturnCallback(function () use (&$seen) {
			$seen = $this->fetchGuard->isPaused();
		});

		$this->service->wipe();

		$this->assertTrue($seen, 'nothing may be handed out while the vector DB is wiped');
		$this->assertFalse($this->fetchGuard->isPaused());
	}

	public function testFetchingResumesWhenTheBackendFails(): void {
		$this->seedIndexedState();
		$this->langRope->method('deleteAll')->willThrowException(new \RuntimeException('backend down'));

		try {
			$this->service->wipe();
		} catch (\RuntimeException) {
		}

		$this->assertFalse($this->fetchGuard->isPaused());
	}

	public function testWipeIsRefusedWhileDocumentsAreStillBeingIndexed(): void {
		$this->seedIndexedState();
		$queueMapper = Server::get(QueueMapper::class);
		$queueMapper->lock($queueMapper->getMaxId());
		$this->appConfig->setAppValueInt('wipe_drain_timeout', 0, lazy: true);
		$this->langRope->expects($this->never())->method('deleteAll');

		try {
			$this->service->wipe();
			$this->fail('the wipe must not run while a document is being indexed');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('1 documents are still being indexed', $e->getMessage());
		} finally {
			$this->appConfig->deleteAppValue('wipe_drain_timeout');
		}

		$this->assertSame(1, $queueMapper->countLocked(), 'nothing may be cleared when the wipe is refused');
		$this->assertFalse($this->fetchGuard->isPaused());
	}

	public function testResumeDoesNotLiftANewerPause(): void {
		$now = self::NOW;
		$guard = $this->newGuard($this->clock($now));

		$first = $guard->pause();
		$now += 10;
		$second = $guard->pause();
		$guard->resume($first);

		$this->assertTrue($guard->isPaused(), 'the second wipe is still running');
		$guard->resume($second);
		$this->assertFalse($guard->isPaused());
	}

	public function testPauseExpiresOnItsOwn(): void {
		$now = self::NOW;
		$guard = $this->newGuard($this->clock($now));

		$until = $guard->pause();
		$this->assertTrue($guard->isPaused());
		$now = $until;

		$this->assertFalse($guard->isPaused(), 'a wipe that died must not stop the indexing forever');
		$guard->resume($until);
	}

	public function testWaitEndsOnceTheHandedOutBatchIsReportedBack(): void {
		$queueMapper = Server::get(QueueMapper::class);
		$file = new QueueFile();
		$file->setFileId(43);
		$file->setStorageId(1);
		$file->setRootId(1);
		$file->setUpdate(false);
		$id = $queueMapper->insertIntoQueue($file)->getId();
		$queueMapper->lock($id);

		$checks = 0;
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(function () use (&$checks, $queueMapper, $id) {
			// the backend reports the batch back while the wipe is waiting
			if (++$checks === 3) {
				$queueMapper->removeFromQueue([$id]);
			}
			return self::NOW;
		});

		$reported = [];
		$this->newGuard($time)->waitForHandedOutDocuments(function (int $locked) use (&$reported) {
			$reported[] = $locked;
		});

		$this->assertSame(0, $queueMapper->countLocked());
		$this->assertSame([1], $reported, 'the count is reported once, not on every poll');
	}

	/**
	 * The steps are reported in order, each one before its work: what the admin reads is what runs next.
	 */
	public function testWipeReportsEachStepBeforeDoingIt(): void {
		$this->seedIndexedState();
		$events = [];
		$this->langRope->method('deleteAll')->willReturnCallback(function () use (&$events) {
			$events[] = 'backend:deleteAll';
		});

		$this->service->wipe(null, false, function (string $message) use (&$events) {
			// mark the moment the queues are emptied, to check the order against the work
			$events[] = $message . ($this->rowCount('context_chat_queue') === 0 ? ' [queues empty]' : '');
		});

		$this->assertSame([
			'Pausing the hand-out of queued documents and actions to context_chat_backend',
			'Checking for documents context_chat_backend is still indexing',
			'Removing the scheduled crawl and content import jobs',
			'Clearing the indexing, actions and file system events queues',
			'Resetting the indexing statistics and the content providers\' import state [queues empty]',
			'Deleting all the documents from the vector DB [queues empty]',
			'backend:deleteAll',
			'Resuming the hand-out of queued documents and actions [queues empty]',
		], $events);
	}

	public function testWipeOfOneProviderReportsItsSteps(): void {
		$this->seedIndexedState();
		$events = [];
		$this->langRope->method('deleteProvider')->willReturnCallback(function (string $key) use (&$events) {
			$events[] = 'backend:deleteProvider ' . $key;
		});

		$this->service->wipe(['someapp__prov'], true, function (string $message) use (&$events) {
			$events[] = $message;
		});

		$this->assertSame([
			'Pausing the hand-out of queued documents and actions to context_chat_backend',
			'Not waiting for the documents context_chat_backend may still be indexing (--ignore-handed-out)',
			'Removing the import job and clearing the indexing queue of someapp__prov',
			'Deleting the documents of someapp__prov from the vector DB',
			'backend:deleteProvider someapp__prov',
			'Resuming the hand-out of queued documents and actions',
		], $events);
	}

	public function testResumeIsReportedWhenTheBackendFails(): void {
		$this->seedIndexedState();
		$this->langRope->method('deleteAll')->willThrowException(new \RuntimeException('backend down'));
		$events = [];

		try {
			$this->service->wipe(null, false, function (string $message) use (&$events) {
				$events[] = $message;
			});
			$this->fail('the backend failure must be reported');
		} catch (\RuntimeException) {
		}

		$this->assertSame('Deleting all the documents from the vector DB', $events[count($events) - 2]);
		$this->assertSame('Resuming the hand-out of queued documents and actions', end($events));
	}
}
