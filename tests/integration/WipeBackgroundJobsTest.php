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
use OCA\ContextChat\Db\QueueContentItemMapper;
use OCA\ContextChat\Db\QueueMapper;
use OCA\ContextChat\Service\FetchGuard;
use OCA\ContextChat\Service\LangRopeService;
use OCA\ContextChat\Service\ProviderConfigService;
use OCA\ContextChat\Service\ReindexService;
use OCA\ContextChat\Service\StorageService;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\ContextChat\IContentProvider;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The crawl and import jobs must not queue anything for a provider while it is wiped: a job that runs when the
 * wipe starts is waited for, a job that starts during the wipe stops.
 */
#[Group('DB')]
class WipeBackgroundJobsTest extends TestCase {
	private const MOUNT = ['storage_id' => 1, 'root_id' => 1, 'overridden_root' => 1];
	private const PROVIDER_CLASS = 'OCA\\SomeApp\\ContentProvider';

	private IJobList $jobList;
	private IAppConfig $appConfig;
	private FetchGuard $fetchGuard;
	private QueueMapper $queueMapper;
	private ProviderConfigService $providerConfig;
	private string $savedProviders;

	protected function setUp(): void {
		parent::setUp();
		$this->jobList = Server::get(IJobList::class);
		$container = (new Application())->getContainer();
		$this->appConfig = $container->get(IAppConfig::class);
		$this->providerConfig = $container->get(ProviderConfigService::class);
		$this->fetchGuard = Server::get(FetchGuard::class);
		$this->queueMapper = Server::get(QueueMapper::class);
		$this->savedProviders = $this->appConfig->getAppValueString('providers', '', lazy: true);
		$this->clearState();
	}

	protected function tearDown(): void {
		$this->clearState();
		$this->appConfig->setAppValueString('providers', $this->savedProviders, lazy: true);
		parent::tearDown();
	}

	private function clearState(): void {
		$this->queueMapper->clearQueue();
		foreach ([SchedulerJob::class, StorageCrawlJob::class, InitialContentImportJob::class] as $jobClass) {
			$this->jobList->remove($jobClass);
		}
		$this->appConfig->deleteAppValue('last_enqueued_db_id');
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->delete('appconfig')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter(Application::APP_ID)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->like('configkey', $qb->createNamedParameter('running\_%')),
				$qb->expr()->like('configkey', $qb->createNamedParameter('wipe\_%')),
				$qb->expr()->in('configkey', $qb->createNamedParameter(['queue_fetch_paused_until', 'files_fetch_paused_until'], IQueryBuilder::PARAM_STR_ARRAY)),
			))
			->executeStatement();
	}

	/** @param ?callable(): void $meanwhile called between the two files of the batch */
	private function runCrawl(?callable $meanwhile = null): void {
		$storage = $this->createMock(StorageService::class);
		$storage->method('getFilesInMount')->willReturnCallback(static function () use ($meanwhile): \Generator {
			yield 101;
			if ($meanwhile !== null) {
				$meanwhile();
			}
			yield 102;
		});
		$job = Server::get(StorageCrawlJob::class);
		$job->setId('7');
		$this->replaceDependency($job, 'storageService', $storage);
		$this->invokeRun($job, self::MOUNT + ['last_file_id' => 0]);
	}

	private function runScheduler(): void {
		$storage = $this->createMock(StorageService::class);
		$storage->method('getMounts')->willReturnCallback(static function (): \Generator {
			yield self::MOUNT;
		});
		$job = Server::get(SchedulerJob::class);
		$this->replaceDependency($job, 'storageService', $storage);
		$this->invokeRun($job, null);
	}

	/** @param ?callable(): void $meanwhile called during the provider's import */
	private function runImport(?callable $meanwhile = null): void {
		$provider = $this->createMock(IContentProvider::class);
		$provider->method('getAppId')->willReturn('someapp');
		$provider->method('getId')->willReturn('prov');
		$provider->method('triggerInitialImport')->willReturnCallback(static function () use ($meanwhile): void {
			if ($meanwhile !== null) {
				$meanwhile();
			}
		});
		\OC::$server->registerService(self::PROVIDER_CLASS, static fn () => $provider);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn(true);

		$job = Server::get(InitialContentImportJob::class);
		$this->replaceDependency($job, 'appManager', $appManager);
		$this->invokeRun($job, self::PROVIDER_CLASS);
	}

	private function replaceDependency(object $object, string $property, object $value): void {
		(new \ReflectionProperty($object, $property))->setValue($object, $value);
	}

	private function invokeRun(object $job, mixed $argument): void {
		(new \ReflectionMethod($job, 'run'))->invoke($job, $argument);
	}

	private function countCrawlJobs(): int {
		return count(iterator_to_array($this->jobList->getJobsIterator(StorageCrawlJob::class, null, 0), false));
	}

	private function isImported(): bool {
		return $this->providerConfig->getProviders()['someapp__prov']['isInitiated'];
	}

	/** @return ITimeFactory a clock that starts at $now and moves when $now is changed */
	private function clock(int &$now): ITimeFactory {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(static function () use (&$now): int {
			return $now;
		});
		return $time;
	}

	private function guardWith(ITimeFactory $time): FetchGuard {
		return new FetchGuard(
			Server::get(IDBConnection::class), $this->appConfig, $time,
			$this->queueMapper, Server::get(QueueContentItemMapper::class),
		);
	}

	/**
	 * @param list<string>|null $providerKeys
	 * @return int the running jobs a wipe of these providers would wait for
	 */
	private function countRunningJobs(?array $providerKeys, ?FetchGuard $guard = null): int {
		$counted = null;
		$this->appConfig->setAppValueInt('wipe_drain_timeout', 0, lazy: true);
		try {
			($guard ?? $this->fetchGuard)->waitForRunningJobs($providerKeys, static function (int $running) use (&$counted): void {
				$counted = $running;
			});
		} catch (\RuntimeException) {
		} finally {
			$this->appConfig->deleteAppValue('wipe_drain_timeout');
		}
		return $counted ?? 0;
	}

	public function testCrawlQueuesAndContinuesWithoutAWipe(): void {
		$this->runCrawl();

		$this->assertSame(2, $this->queueMapper->count());
		$this->assertSame(1, $this->countCrawlJobs(), 'the next batch is scheduled');
		$this->assertSame(0, $this->countRunningJobs(null), 'the crawl is not recorded as running once it ended');
	}

	public function testCrawlStopsDuringAFilesWipe(): void {
		$pause = $this->fetchGuard->pause(['files__default']);
		try {
			$this->runCrawl();
		} finally {
			$this->fetchGuard->resume($pause);
		}

		$this->assertSame(0, $this->queueMapper->count());
		$this->assertSame(0, $this->countCrawlJobs());
	}

	public function testCrawlGoesOnDuringAWipeOfAContentProvider(): void {
		$wipe = $this->fetchGuard->pause(['someapp__prov']);
		try {
			$this->runCrawl();
		} finally {
			$this->fetchGuard->resume($wipe);
		}

		$this->assertSame(2, $this->queueMapper->count());
	}

	public function testCrawlIsRecordedAsRunningWhileItQueues(): void {
		$running = null;
		$this->runCrawl(function () use (&$running) {
			$running = [
				$this->countRunningJobs([ProviderConfigService::getDefaultProviderKey()]),
				$this->countRunningJobs(['someapp__prov']),
			];
		});

		$this->assertSame([1, 0], $running, 'a wipe of the files waits for the crawl, a wipe of another provider does not');
		$this->assertSame(0, $this->countRunningJobs(null));
	}

	public function testCrawlRecordIsRemovedWhenTheCrawlFails(): void {
		try {
			$this->runCrawl(static function (): void {
				throw new \RuntimeException('storage gone');
			});
			$this->fail('the failure must not be swallowed');
		} catch (\RuntimeException $e) {
			$this->assertSame('storage gone', $e->getMessage());
		}

		$this->assertSame(0, $this->countRunningJobs(null));
	}

	public function testSchedulerDoesNothingDuringAFullWipe(): void {
		$pause = $this->fetchGuard->pause();
		try {
			$this->runScheduler();
		} finally {
			$this->fetchGuard->resume($pause);
		}

		$this->assertSame(0, $this->countCrawlJobs());
	}

	public function testSchedulerAddsTheCrawlJobsWithoutAWipe(): void {
		$this->runScheduler();

		$this->assertSame(1, $this->countCrawlJobs());
		$this->assertSame(0, $this->countRunningJobs(null));
	}

	public function testImportMarksTheProviderImportedWithoutAWipe(): void {
		$this->providerConfig->updateProvider('someapp', 'prov', self::PROVIDER_CLASS, false);

		$this->runImport();

		$this->assertTrue($this->isImported());
		$this->assertSame(0, $this->countRunningJobs(null));
	}

	public function testImportStopsDuringAWipeOfItsProvider(): void {
		$this->providerConfig->updateProvider('someapp', 'prov', self::PROVIDER_CLASS, false);
		$imported = false;
		$wipe = $this->fetchGuard->pause(['someapp__prov']);
		try {
			$this->runImport(static function () use (&$imported): void {
				$imported = true;
			});
		} finally {
			$this->fetchGuard->resume($wipe);
		}

		$this->assertFalse($imported, 'nothing may be submitted while the provider is wiped');
		$this->assertFalse($this->isImported());
	}

	public function testImportStopsDuringAFullWipe(): void {
		$this->providerConfig->updateProvider('someapp', 'prov', self::PROVIDER_CLASS, false);
		$pause = $this->fetchGuard->pause();
		try {
			$this->runImport();
		} finally {
			$this->fetchGuard->resume($pause);
		}

		$this->assertFalse($this->isImported());
	}

	public function testImportIsRecordedAsRunningForItsProviderOnly(): void {
		$this->providerConfig->updateProvider('someapp', 'prov', self::PROVIDER_CLASS, false);
		$running = null;
		$this->runImport(function () use (&$running) {
			$running = [
				$this->countRunningJobs(['someapp__prov']),
				$this->countRunningJobs([ProviderConfigService::getDefaultProviderKey()]),
				$this->countRunningJobs(null),
			];
		});

		$this->assertSame([1, 0, 1], $running);
	}

	public function testWipeWaitsForARunningImportBeforeClearingTheQueue(): void {
		$this->providerConfig->updateProvider('someapp', 'prov', self::PROVIDER_CLASS, true);
		$record = $this->fetchGuard->jobStarted('someapp__prov', '42', FetchGuard::IMPORT_RUNNING_TTL);
		$this->appConfig->setAppValueInt('wipe_drain_timeout', 0, lazy: true);
		$langRope = $this->createMock(LangRopeService::class);
		$langRope->expects($this->never())->method('deleteProvider');
		$service = Server::get(ReindexService::class);
		$this->replaceDependency($service, 'langRopeService', $langRope);

		try {
			$service->wipe(['someapp__prov']);
			$this->fail('the wipe must wait for the running import');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('1 background jobs are still queueing documents', $e->getMessage());
		} finally {
			$this->appConfig->deleteAppValue('wipe_drain_timeout');
			$this->fetchGuard->jobEnded($record);
		}

		$this->assertTrue($this->isImported(), 'nothing may be reset when the wipe gives up');
		$this->assertFalse($this->fetchGuard->isPaused('someapp__prov'), 'the import may run again');
	}

	public function testWipeWithIgnoreHandedOutDoesNotWaitForRunningJobs(): void {
		$record = $this->fetchGuard->jobStarted(ProviderConfigService::getDefaultProviderKey(), '42');
		$langRope = $this->createMock(LangRopeService::class);
		$langRope->expects($this->once())->method('deleteProvider');
		$service = Server::get(ReindexService::class);
		$this->replaceDependency($service, 'langRopeService', $langRope);

		try {
			$service->wipe([ProviderConfigService::getDefaultProviderKey()], ignoreHandedOut: true);
		} finally {
			$this->fetchGuard->jobEnded($record);
		}
	}

	public function testAKilledJobBlocksWipesUntilItsRecordExpires(): void {
		$now = 1_800_000_000;
		$guard = $this->guardWith($this->clock($now));
		$guard->jobStarted(ProviderConfigService::getDefaultProviderKey(), '42');

		$now += FetchGuard::RUNNING_TTL - 1;
		$this->assertSame(1, $this->countRunningJobs(null, $guard));

		$now += 1;
		$this->assertSame(0, $this->countRunningJobs(null, $guard), 'a job that never ended must not block wipes forever');
	}

	public function testARunningCrawlKeepsItsRecordAlive(): void {
		$now = 1_800_000_000;
		$guard = $this->guardWith($this->clock($now));
		$record = $guard->jobStarted(ProviderConfigService::getDefaultProviderKey(), '42');

		for ($i = 0; $i < 4; $i++) {
			$now += FetchGuard::RUNNING_TTL / 2;
			$guard->jobAlive($record);
		}

		$this->assertSame(1, $this->countRunningJobs(null, $guard), 'a crawl still working must not be taken as killed');
		$guard->jobEnded($record);
	}
}
