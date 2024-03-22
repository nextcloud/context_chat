<?php
/**
 * Nextcloud - ContextChat
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Anupam Kumar <kyteinsky@gmail.com>
 * @copyright Anupam Kumar 2024
 */

declare(strict_types=1);

namespace OCA\ContextChat\Tests;

use DateTime;
use OCA\ContextChat\AppInfo\Application;
use OCA\ContextChat\BackgroundJobs\InitialContentImportJob;
use OCA\ContextChat\BackgroundJobs\SubmitContentJob;
use OCA\ContextChat\Db\QueueContentItemMapper;
use OCA\ContextChat\Public\ContentItem;
use OCA\ContextChat\Public\ContentManager;
use OCA\ContextChat\Public\IContentProvider;
use OCA\ContextChat\Service\LangRopeService;
use OCA\ContextChat\Service\ProviderConfigService;
use OCP\BackgroundJob\IJobList;
use OCP\Server;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class ContentManagerTest extends TestCase {
	/** @var MockObject | QueueContentItemMapper */
	private QueueContentItemMapper $mapper;
	/** @var MockObject | ProviderConfigService */
	private ProviderConfigService $providerConfig;
	/** @var MockObject | LangRopeService */
	private LangRopeService $service;

	private ContentManager $contentManager;
	private LoggerInterface $logger;
	private IJobList $jobList;

	// private bool $initCalled = false;
	private static string $providerClass = 'OCA\ContextChat\Tests\ContentProvider';

	public function setUp(): void {
		$this->jobList = Server::get(IJobList::class);
		$this->logger = Server::get(LoggerInterface::class);
		$this->mapper = $this->createMock(QueueContentItemMapper::class);
		$this->providerConfig = $this->createMock(ProviderConfigService::class);
		$this->service = $this->createMock(LangRopeService::class);

		$this->providerConfig
			->method('getProviders')
			->willReturn([
				ProviderConfigService::getDefaultProviderKey() => [
					'isInitiated' => true,
					'classString' => '',
				],
				ProviderConfigService::getConfigKey(Application::APP_ID, 'test-provider') => [
					'isInitiated' => false,
					'classString' => static::$providerClass,
				],
			]);

		// $this->overwriteService(ProviderConfigService::class, $this->providerConfig);

		// using this app's app id to pass the check that the app is enabled for the user
		$providerObj = new ContentProvider(Application::APP_ID, 'test-provider', function () {
			// $this->initCalled = true;
		});
		$providerClass = get_class($providerObj);

		\OC::$server->registerService($providerClass, function () use ($providerObj) {
			return $providerObj;
		});

		$this->contentManager = new ContentManager(
			$this->jobList,
			$this->providerConfig,
			$this->service,
			$this->mapper,
			$this->logger,
		);
	}

	public static function dataBank(): array {
		return [
			/* [$classString, $appId, $providerId, $registrationSuccessful] */
			[ static::$providerClass, Application::APP_ID, 'test-provider', true ],
			[ 'invalid', Application::APP_ID, 'test-provider', false ],
		];
	}

	/**
	 * @param class-string<IContentProvider> $providerClass
	 * @param string $appId
	 * @param string $providerId
	 * @param bool $registrationSuccessful
	 * @dataProvider dataBank
	 */
	public function testRegisterContentProvider(
		string $providerClass,
		string $appId,
		string $providerId,
		bool $registrationSuccessful,
	): void {
		$this->providerConfig
			->expects($registrationSuccessful ? $this->once() : $this->never())
			->method('hasProvider')
			->with($appId, $providerId)
			->willReturn(false);

		$this->providerConfig
			->expects($registrationSuccessful ? $this->once() : $this->never())
			->method('updateProvider')
			->with($appId, $providerId, $providerClass);

		$this->contentManager->registerContentProvider($providerClass);

		$jobsIter = $this->jobList->getJobsIterator(InitialContentImportJob::class, 1, 0);
		if ($registrationSuccessful) {
			$this->assertNotNull($jobsIter);
			$this->jobList->remove(InitialContentImportJob::class, $providerClass);
		}
	}

	public function testSubmitContent(): void {
		$appId = 'test';
		$items = [
			new ContentItem(
				'item-id',
				'provider-id',
				'title',
				'content',
				'email-file',
				new DateTime(),
				['user1', 'user2'],
			),
		];

		$this->mapper
			->expects($this->once())
			->method('insert');

		$this->jobList->remove(SubmitContentJob::class, null);
		$this->assertFalse($this->jobList->has(SubmitContentJob::class, null));

		$this->contentManager->submitContent($appId, $items);

		$this->assertTrue($this->jobList->has(SubmitContentJob::class, null));
		$this->jobList->remove(SubmitContentJob::class, null);
	}
}

class ContentProvider implements IContentProvider {
	public function __construct(
		private string $appId,
		private string $providerId,
		private $callback,
	) {
	}

	public function getId(): string {
		return $this->providerId;
	}

	public function getAppId(): string {
		return $this->appId;
	}

	public function getItemUrl(string $id): string {
		return 'https://nextcloud.local/test-provider/' . $id;
	}

	public function triggerInitialImport(): void {
		($this->callback)();
	}
}
