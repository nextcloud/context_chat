<?php


declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Controller;

use OCA\ContextChat\Service\MetadataService;
use OCA\ContextChat\Service\ProviderConfigService;
use OCA\ContextChat\Service\StorageService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\ExAppRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\OCSController;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class UtilController extends OCSController {
	public function __construct(
		$appName,
		IRequest $request,
		$corsMethods = 'PUT, POST, GET, DELETE, PATCH',
		$corsAllowedHeaders = 'Authorization, Content-Type, Accept, OCS-APIRequest',
		$corsMaxAge = 1728000,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request, $corsMethods, $corsAllowedHeaders, $corsMaxAge);
	}

	/**
	 * ExApp-only endpoint to resolve a scopeList
	 * @param IRootFolder $rootFolder
	 * @param StorageService $storageService
	 * @param ProviderConfigService $providerConfigService
	 * @param list<string> $source_ids
	 * @param string $userId
	 * @return DataResponse
	 */
	#[ExAppRequired]
	#[ApiRoute(verb: 'POST', url: '/resolve_scope_list')]
	public function resolveScopeList(IRootFolder $rootFolder, StorageService $storageService, ProviderConfigService $providerConfigService, array $source_ids, string $userId) : DataResponse {
		try {
			$userFolder = $rootFolder->getUserFolder($userId);
			$newScopeList = [];
			foreach ($source_ids as $source_id) {
				// Check whether this is a files source, if not just pass it through
				if (!str_starts_with($providerConfigService::getDefaultProviderKey(), $source_id)) {
					$newScopeList[] = $source_id;
					continue;
				}
				$nodeId = $providerConfigService->getItemId($source_id);
				if ($nodeId === null) {
					$this->logger->warning("Could not find node '$source_id'");
					continue;
				}
				$node = $userFolder->getFirstNodeById((int)$nodeId);
				if ($node instanceof Folder) {
					foreach ($storageService->getAllFilesInFolder($node) as $nodeId) {
						$newScopeList[] = $providerConfigService::getSourceId($nodeId);
					}
				} else {
					$newScopeList[] = $source_id;
				}
			}
			return new DataResponse(['source_ids' => $newScopeList]);
		} catch (\Throwable $e) {
			// Avoid leaking filesystem details; keep behavior consistent with other failure paths.
			$this->logger->warning($e);
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * ExApp-only endpoint to enrich sources with icon, label and url
	 * @param MetadataService $metadataService
	 * @param list<array{ source_id: string, title?: string }> $sources
	 * @param string $userId
	 * @return DataResponse
	 */
	#[ExAppRequired]
	#[ApiRoute(verb: 'POST', url: '/enrich_sources')]
	public function enrichSources(MetadataService $metadataService, array $sources, string $userId) : DataResponse {
		try {
			$sources = $metadataService->getEnrichedSources($userId, $sources);
			return new DataResponse(['sources' => $sources]);
		} catch (\Throwable $e) {
			// Avoid leaking filesystem details; keep behavior consistent with other failure paths.
			$this->logger->warning($e);
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
	}
}
