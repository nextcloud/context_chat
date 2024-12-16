<?php

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Controller;

use OCA\ContextChat\Service\MetadataService;
use OCA\ContextChat\Service\ProviderConfigService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class ProviderController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private MetadataService $metadataService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	public function getDefaultProviderKey(): DataResponse {
		$providerKey = ProviderConfigService::getDefaultProviderKey();
		return new DataResponse($providerKey);
	}

	/**
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	public function getProviders(): DataResponse {
		$providers = $this->metadataService->getEnrichedProviders();
		return new DataResponse(array_values($providers));
	}

	/**
	 * @param array<string> $sources
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	public function getMetadataFor(array $sources): DataResponse {
		$enrichedSources = $this->metadataService->getEnrichedSources(...$sources);
		return new DataResponse($enrichedSources);
	}
}
