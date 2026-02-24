<?php

namespace OCA\AppAPI {
    if (!class_exists('\OCA\AppAPI\PublicFunctions')) {
        class PublicFunctions {
            public function exAppRequest($appId, $route, $userId, $method, $params, $options) {}
        }
    }
}

namespace OCA\ContextChat\Tests\Unit\Service {

use OCA\ContextChat\AppInfo\Application;
use OCA\ContextChat\Logger;
use OCA\ContextChat\Service\LangRopeService;
use OCA\ContextChat\Service\ProviderConfigService;
use OCA\ContextChat\Type\Source;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use OCA\AppAPI\PublicFunctions;

class LangRopeServiceTest extends TestCase {
    private $logger;
    private $l10n;
    private $appConfig;
    private $appManager;
    private $urlGenerator;
    private $userManager;
    private $providerService;

    protected function setUp(): void {
        parent::setUp();

        $this->logger = $this->createMock(Logger::class);
        $this->l10n = $this->createMock(IL10N::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->providerService = $this->createMock(ProviderConfigService::class);
    }

    public function testIndexSourcesWithContentLength() {
        $source = new Source(
            ['user1'],
            'ref1',
            'title1',
            'content1',
            1234567890,
            'text/plain',
            'provider1',
            100 // size
        );

        $responseMock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getHeader', 'getBody', 'getStatusCode'])
            ->getMock();
        $responseMock->method('getHeader')->willReturn('application/json');
        $responseMock->method('getBody')->willReturn(json_encode(['loaded_sources' => [], 'sources_to_retry' => []]));
        $responseMock->method('getStatusCode')->willReturn(200);

        $appApiMock = $this->createMock(PublicFunctions::class);
        $appApiMock->expects($this->once())
            ->method('exAppRequest')
            ->with(
                'context_chat_backend',
                '/loadSources',
                null, // userId is null in constructor
                'PUT',
                $this->callback(function($params) {
                    // verify params structure
                    if (!is_array($params) || count($params) !== 1) return false;
                    $p = $params[0];
                    if ($p['name'] !== 'sources') return false;
                    if (!isset($p['headers']['Content-Length'])) return false;
                    if ($p['headers']['Content-Length'] !== 100) return false;
                    return true;
                }),
                $this->anything()
            )
            ->willReturn($responseMock);

        $this->appManager->method('isEnabledForUser')->willReturn(true);
        $this->appManager->method('getAppVersion')->willReturn(Application::MIN_APP_API_VERSION);
        $this->appConfig->method('getAppValueString')->willReturnCallback(function($key, $default, $lazy) {
            if ($key === 'backend_init') return 'true';
            if ($key === 'request_timeout') return '30';
            return $default;
        });

        $service = $this->getMockBuilder(LangRopeService::class)
            ->setConstructorArgs([
                $this->logger,
                $this->l10n,
                $this->appConfig,
                $this->appManager,
                $this->urlGenerator,
                $this->userManager,
                $this->providerService,
                null // userId
            ])
            ->onlyMethods(['getAppApiFunctions'])
            ->getMock();

        $service->method('getAppApiFunctions')->willReturn($appApiMock);

        $service->indexSources([$source]);
    }

    public function testIndexSourcesWithoutContentLength() {
        $source = new Source(
            ['user1'],
            'ref1',
            'title1',
            'content1',
            1234567890,
            'text/plain',
            'provider1',
            null // size
        );

        $responseMock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getHeader', 'getBody', 'getStatusCode'])
            ->getMock();
        $responseMock->method('getHeader')->willReturn('application/json');
        $responseMock->method('getBody')->willReturn(json_encode(['loaded_sources' => [], 'sources_to_retry' => []]));
        $responseMock->method('getStatusCode')->willReturn(200);

        $appApiMock = $this->createMock(PublicFunctions::class);
        $appApiMock->expects($this->once())
            ->method('exAppRequest')
            ->with(
                'context_chat_backend',
                '/loadSources',
                null,
                'PUT',
                $this->callback(function($params) {
                    if (!is_array($params) || count($params) !== 1) return false;
                    $p = $params[0];
                    if (isset($p['headers']['Content-Length'])) return false;
                    return true;
                }),
                $this->anything()
            )
            ->willReturn($responseMock);

        $this->appManager->method('isEnabledForUser')->willReturn(true);
        $this->appManager->method('getAppVersion')->willReturn(Application::MIN_APP_API_VERSION);
        $this->appConfig->method('getAppValueString')->willReturnCallback(function($key, $default, $lazy) {
            if ($key === 'backend_init') return 'true';
            if ($key === 'request_timeout') return '30';
            return $default;
        });

        $service = $this->getMockBuilder(LangRopeService::class)
            ->setConstructorArgs([
                $this->logger,
                $this->l10n,
                $this->appConfig,
                $this->appManager,
                $this->urlGenerator,
                $this->userManager,
                $this->providerService,
                null // userId
            ])
            ->onlyMethods(['getAppApiFunctions'])
            ->getMock();

        $service->method('getAppApiFunctions')->willReturn($appApiMock);

        $service->indexSources([$source]);
    }
}

}
