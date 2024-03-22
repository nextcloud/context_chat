<?php
/**
 * Nextcloud - ContextChat
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Anupam Kumar
 * @copyright Anupam Kumar 2024
 */

namespace OCA\ContextChat\Service;

use OCA\ContextChat\AppInfo\Application;
use OCA\ContextChat\Public\IContentProvider;
use OCP\IConfig;

/* array<[$appId__$providerId], array{ isInitiated: bool, classString: string }> */

class ProviderConfigService {
	public function __construct(
		private IConfig $config,
	) {
	}

	public static function getSourceId(int | string $nodeId, ?string $providerId = null): string {
		return ($providerId ?? self::getDefaultProviderKey()) . ': ' . $nodeId;
	}

	public static function getDefaultProviderKey(): string {
		return ProviderConfigService::getConfigKey('files', 'default');
	}

	public static function getConfigKey(string $appId, string $providerId): string {
		return $appId . '__' . $providerId;
	}

	/**
	 * @param array $providers
	 * @return bool
	 */
	private function validateProvidersArray(array $providers): bool {
		foreach ($providers as $providerId => $value) {
			if (!is_string($providerId) || $providerId === ''
				|| !isset($value['isInitiated']) || !is_bool($value['isInitiated'])
				|| !isset($value['classString']) || !is_string($value['classString'])
			) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @return array<string, array{ isInitiated: bool, classString: string }>
	 */
	public function getProviders(): array {
		$providers = [];
		$providersString = $this->config->getAppValue(Application::APP_ID, 'providers', '');

		if ($providersString !== '') {
			$providers = json_decode($providersString, true);

			if ($providers === null || !$this->validateProvidersArray($providers)) {
				$providers = [];
				$this->config->setAppValue(Application::APP_ID, 'providers', '');
			}
		}

		return $providers;
	}

	/**
	 * @param string $appId
	 * @param string $providerId
	 * @param class-string<IContentProvider> $providerClass
	 * @param bool $isInitiated
	 */
	public function updateProvider(
		string $appId,
		string $providerId,
		string $providerClass,
		bool $isInitiated = false,
	): void {
		$providers = $this->getProviders();
		$providers[self::getConfigKey($appId, $providerId)] = [
			'isInitiated' => $isInitiated,
			'classString' => $providerClass,
		];
		$this->config->setAppValue(Application::APP_ID, 'providers', json_encode($providers));
	}

	/**
	 * @param string $appId
	 * @param ?string $providerId
	 */
	public function removeProvider(string $appId, ?string $providerId = null): void {
		$providers = $this->getProviders();

		if ($providerId !== null && isset($providers[self::getConfigKey($appId, $providerId)])) {
			unset($providers[self::getConfigKey($appId, $providerId)]);
		} elseif ($providerId === null) {
			foreach ($providers as $k => $v) {
				if (str_starts_with($k, self::getConfigKey($appId, ''))) {
					unset($providers[$k]);
				}
			}
		}

		$this->config->setAppValue(Application::APP_ID, 'providers', json_encode($providers));
	}

	/**
	 * @param string $appId
	 * @param string $providerId
	 * @return bool
	 */
	public function hasProvider(string $appId, string $providerId): bool {
		$providers = $this->getProviders();
		return isset($providers[self::getConfigKey($appId, $providerId)]);
	}
}
