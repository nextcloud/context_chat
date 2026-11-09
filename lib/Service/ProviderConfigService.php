<?php

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Service;

use OCA\ContextChat\Public\IContentProvider as OCAIContentProvider;
use OCP\AppFramework\Services\IAppConfig;
use OCP\ContextChat\IContentProvider as OCPIContentProvider;

/* array<[$appId__$providerId], array{ isInitiated: bool, classString: string }> */

class ProviderConfigService {
	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * Get the item ID from a source ID.
	 *
	 * @param string $sourceId
	 * @param string|null $providerId
	 * @return ?string null returned if the sourceId is not in the expected format
	 */
	public static function getItemId(string $sourceId, ?string $providerId = null): ?string {
		if ($providerId === null || $providerId === '' || !str_starts_with($sourceId, $providerId . ': ')) {
			$parts = explode(': ', $sourceId, 2);
			if (count($parts) !== 2) {
				return null;
			}
			return $parts[1];
		}
		return substr($sourceId, strlen($providerId) + 2);
	}

	public static function getSourceId(int|string $nodeId, ?string $providerId = null): string {
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
	 * @return array{ isInitiated: bool, classString: string } | null
	 */
	public function getProvider(string $providerKey): ?array {
		$providers = $this->getProviders();
		return $providers[$providerKey] ?? null;
	}

	/**
	 * @return array<string, array{ isInitiated: bool, classString: string }>
	 */
	public function getProviders(): array {
		$providers = [];
		$providersString = $this->appConfig->getAppValueString('providers', '', lazy: true);

		if ($providersString !== '') {
			$providers = json_decode($providersString, true);

			if ($providers === null || !$this->validateProvidersArray($providers)) {
				$providers = [];
				$this->appConfig->setAppValueString('providers', '', lazy: true);
			}
		}

		return $providers;
	}

	/**
	 * @param string $appId
	 * @param string $providerId
	 * @param class-string<OCAIContentProvider|OCPIContentProvider> $providerClass
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
		$this->appConfig->setAppValueString('providers', json_encode($providers), lazy: true);
	}

	/**
	 * Mark all the content providers, except the files provider, as not initiated
	 * so their initial import runs again
	 */
	public function resetProvidersInitiation(): void {
		$providers = $this->getProviders();
		foreach ($providers as $key => $provider) {
			if ($key !== self::getDefaultProviderKey()) {
				$providers[$key]['isInitiated'] = false;
			}
		}
		$this->appConfig->setAppValueString('providers', json_encode($providers), lazy: true);
	}

	/**
	 * Get the parts of a provider key
	 *
	 * @param string $providerKey The provider key in the format "appId__providerId"
	 * @return array{0: string, 1: string} The appId and providerId
	 * @throws \RuntimeException when the provider key format is invalid
	 */
	public function getProviderParts(string $providerKey): array {
		$identifierValues = explode('__', $providerKey, 2);
		if (count($identifierValues) !== 2) {
			throw new \RuntimeException('Invalid provider key format: ' . $providerKey);
		}
		return $identifierValues;
	}

	/**
	 * Mark a specific provider as initiated or not initiated
	 *
	 * @param string $providerKey The provider key in the format "appId__providerId"
	 * @param bool $isInitiated
	 * @return void
	 * @throws \RuntimeException when the provider key format is invalid
	 */
	public function setProviderInitiated(string $providerKey, bool $isInitiated): void {
		$provider = $this->getProvider($providerKey);
		if ($provider === null) {
			return;
		}
		[$appId, $providerId] = $this->getProviderParts($providerKey);
		/** @psalm-suppress ArgumentTypeCoercion */
		$this->updateProvider($appId, $providerId, $provider['classString'], $isInitiated);
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

		$this->appConfig->setAppValueString('providers', json_encode($providers), lazy: true);
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
