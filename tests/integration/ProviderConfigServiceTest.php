<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Tests;

use OCA\ContextChat\AppInfo\Application;
use OCA\ContextChat\Service\ProviderConfigService;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProviderConfigServiceTest extends TestCase {
	/** @var MockObject | IConfig */
	private IConfig $config;

	private ProviderConfigService $providerConfig;

	public function setUp(): void {
		$this->config = $this->createMock(IConfig::class);
		$this->providerConfig = new ProviderConfigService($this->config);
	}

	public function testGetConfigKey(): void {
		$appId = 'app';
		$providerId = 'provider';
		$expected = $appId . '__' . $providerId;

		$this->assertEquals($expected, ProviderConfigService::getConfigKey($appId, $providerId));
	}

	public static function dataBank(): array {
		$validData = [
			ProviderConfigService::getConfigKey('app1', 'provider1') => [
				'isInitiated' => true,
				'classString' => 'class1',
			],
			ProviderConfigService::getConfigKey('app1', 'provider2') => [
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

		$this->assertEquals($providers, $this->providerConfig->getProviders());
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
			->expects($returnVal === 'invalid' ? $this->exactly(2) : $this->once())
			->method('setAppValue')
			->with(Application::APP_ID, 'providers', $this->logicalOr($this->equalTo(''), $this->equalTo($setProvidersValue)));

		$this->providerConfig->updateProvider($appId, $providerId, $providerClass, $isInitiated);
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
			->expects($returnVal === 'invalid' ? $this->exactly(2) : $this->once())
			->method('setAppValue')
			->with(Application::APP_ID, 'providers', $this->logicalOr(
				$this->equalTo(''),
				$this->equalTo(json_encode($providers))
			));

		$this->providerConfig->removeProvider($appId, $providerId);
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

		$this->assertEquals($expected, $this->providerConfig->hasProvider($appId, $providerId));
	}
}
