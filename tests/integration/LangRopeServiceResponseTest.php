<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI {
	if (!class_exists(PublicFunctions::class)) {
		/**
		 * Stand-in for AppAPI's public functions, which the tests do not install.
		 */
		class PublicFunctions {
			public static mixed $response = null;

			public function exAppRequest(string $appId, string $route, ?string $userId = null, string $method = 'POST', array $params = [], array $options = []): mixed {
				return self::$response;
			}
		}
	}
}

namespace OCA\ContextChat\Tests {
	use OCA\AppAPI\PublicFunctions;
	use OCA\ContextChat\AppInfo\Application;
	use OCA\ContextChat\Service\LangRopeService;
	use OCP\App\IAppManager;
	use OCP\AppFramework\Services\IAppConfig;
	use OCP\Http\Client\IResponse;
	use OCP\Server;
	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\Attributes\Group;
	use PHPUnit\Framework\TestCase;

	/**
	 * What reaches the callers when the backend answers something that is not a JSON object or array.
	 */
	#[Group('DB')]
	class LangRopeServiceResponseTest extends TestCase {
		private IAppConfig $appConfig;
		private string $savedBackendInit;

		protected function setUp(): void {
			parent::setUp();
			$this->appConfig = (new Application())->getContainer()->get(IAppConfig::class);
			$this->savedBackendInit = $this->appConfig->getAppValueString('backend_init', 'false', lazy: true);
			$this->appConfig->setAppValueString('backend_init', 'true', lazy: true);
			\OC::$server->registerService(PublicFunctions::class, static fn () => new PublicFunctions());
		}

		protected function tearDown(): void {
			$this->appConfig->setAppValueString('backend_init', $this->savedBackendInit, lazy: true);
			parent::tearDown();
		}

		private function service(): LangRopeService {
			$service = Server::get(LangRopeService::class);
			$appManager = $this->createMock(IAppManager::class);
			$appManager->method('isEnabledForUser')->willReturn(true);
			(new \ReflectionProperty($service, 'appManager'))->setValue($service, $appManager);
			return $service;
		}

		private function backendAnswers(string $body): void {
			$response = $this->createMock(IResponse::class);
			$response->method('getHeader')->willReturnCallback(static fn (string $name) => $name === 'Content-Type' ? 'application/json' : '');
			$response->method('getBody')->willReturn($body);
			$response->method('getStatusCode')->willReturn(200);
			PublicFunctions::$response = $response;
		}

		/** @return array<string, array{string}> */
		public static function malformedBodies(): array {
			return [
				'null' => ['null'],
				'a number' => ['3'],
				'a string' => ['"ok"'],
				'not JSON' => ['<html>proxy error</html>'],
			];
		}

		#[DataProvider('malformedBodies')]
		public function testMalformedResponseIsReportedAsABackendError(string $body): void {
			$this->backendAnswers($body);

			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessage('Malformed response from Context Chat Backend (ExApp) for /countIndexedDocuments');
			$this->service()->getIndexedDocumentsCounts();
		}

		public function testCountsAreReturned(): void {
			$this->backendAnswers('{"files__default": 3}');

			$this->assertSame(['files__default' => 3], $this->service()->getIndexedDocumentsCounts());
		}
	}
}
