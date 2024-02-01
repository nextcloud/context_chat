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

use OCA\ContextChat\AppInfo\Application;
use OCA\ContextChat\Service\ProviderConfigService;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProviderConfigServiceTest extends TestCase {
	/** @var MockObject | IConfig */
	private IConfig $config;

	private ProviderConfigService $configService;

	public function setUp(): void {
		$this->config = $this->createMock(IConfig::class);
		$this->configService = new ProviderConfigService($this->config);
	}

	public function testGetConfigKey(): void {
		$appId = 'app';
		$providerId = 'provider';
		$expected = $appId . '__' . $providerId;

		$this->assertEquals($expected, ProviderConfigService::getConfigKey($appId, $providerId));
	}

	public static function dataBank(): array {
		$validData = [
			'app1__provider1' => [
				'isInitiated' => true,
				'classString' => 'class1',
			],
			'app1__provider2' => [
				'isInitiated' => false,
				'classString' => 'class2',
			],
		];

		return [
			[ json_encode($validData), $validData ],
			[ '', [] ],
			[ 'invalid', [] ],
		];
	}

	/**
	 * @dataProvider dataBank
	 * @param string $returnVal
	 * @param array $providers
	 * @return void
	 */
	public function testGetProviders(string $returnVal, array $providers): void {
		$this->config
			->expects($this->once())
			->method('getAppValue')
			->with(Application::APP_ID, 'providers')
			->willReturn($returnVal);

		$this->assertEquals($providers, $this->configService->getProviders());
	}

	/**
	 * @dataProvider dataBank
	 * @param string $returnVal
	 * @param array $providers
	 * @return void
	 */
	public function testUpdateProvider(string $returnVal, array $providers): void {
		$appId = 'app';
		$providerId = 'provider';
		$providerClass = 'class';
		$isInitiated = true;

		$newProvider = [
			ProviderConfigService::getConfigKey($appId, $providerId) => [
				'isInitiated' => $isInitiated,
				'classString' => $providerClass,
			],
		];
		$extendedProviders = array_merge($providers, $newProvider);

		$setProvidersValue = match ($returnVal) {
			'', 'invalid' => json_encode($newProvider),
			default => json_encode($extendedProviders),
		};

		$this->config
			->expects($this->once())
			->method('getAppValue')
			->with(Application::APP_ID, 'providers')
			->willReturn($returnVal);

		$this->config
			->expects($this->once())
			->method('setAppValue')
			->with(Application::APP_ID, 'providers', $setProvidersValue);

		$this->configService->updateProvider($appId, $providerId, $providerClass, $isInitiated);
	}

	/**
	 * @dataProvider dataBank
	 * @param string $returnVal
	 * @param array $providers
	 * @return void
	 */
	public function testRemoveProvider(string $returnVal, array $providers): void {
		$appId = 'app1';
		$providerId = 'provider1';
		$identifier = ProviderConfigService::getConfigKey($appId, $providerId);

		$this->config
			->expects($this->once())
			->method('getAppValue')
			->with(Application::APP_ID, 'providers')
			->willReturn($returnVal);

		if (isset($providers[$identifier])) {
			unset($providers[$identifier]);
		}

		$this->config
			->expects($this->once())
			->method('setAppValue')
			->with(Application::APP_ID, 'providers', json_encode($providers));

		$this->configService->removeProvider($appId, $providerId);
	}

	/**
	 * @dataProvider dataBank
	 * @param string $returnVal
	 * @param array $providers
	 * @return void
	 */
	public function testHasProvider(string $returnVal, array $providers): void {
		$appId = 'app1';
		$providerId = 'provider1';

		$this->config
			->expects($this->once())
			->method('getAppValue')
			->with(Application::APP_ID, 'providers')
			->willReturn($returnVal);

		$expected = match ($returnVal) {
			'', 'invalid' => false,
			default => true,
		};

		$this->assertEquals($expected, $this->configService->hasProvider($appId, $providerId));
	}
}
