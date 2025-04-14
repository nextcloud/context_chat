<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\TaskProcessing;

use OCA\ContextChat\AppInfo\Application;
use OCA\ContextChat\Exceptions\RetryIndexException;
use OCA\ContextChat\Logger;
use OCA\ContextChat\Service\LangRopeService;
use OCA\ContextChat\Service\MetadataService;
use OCA\ContextChat\Service\ProviderConfigService;
use OCA\ContextChat\Service\ScanService;
use OCA\ContextChat\Type\ScopeType;
use OCA\ContextChat\Type\Source;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\IL10N;
use OCP\TaskProcessing\ISynchronousProvider;
use RuntimeException;

class ContextChatProvider implements ISynchronousProvider {

	public function __construct(
		private LangRopeService $langRopeService,
		private IL10N $l10n,
		private IRootFolder $rootFolder,
		private Logger $logger,
		private ScanService $scanService,
		private MetadataService $metadataService,
		private IAppConfig $appConfig,
	) {
	}

	public function getId(): string {
		return Application::APP_ID . '-context_chat';
	}

	public function getName(): string {
		return $this->l10n->t('Nextcloud Assistant Context Chat Provider');
	}

	public function getTaskTypeId(): string {
		return ContextChatTaskType::ID;
	}

	public function getExpectedRuntime(): int {
		return 120;
	}

	public function getInputShapeEnumValues(): array {
		return [];
	}

	public function getInputShapeDefaults(): array {
		return [];
	}

	public function getOptionalInputShape(): array {
		return [];
	}

	public function getOptionalInputShapeEnumValues(): array {
		return [];
	}

	public function getOptionalInputShapeDefaults(): array {
		return [];
	}

	public function getOutputShapeEnumValues(): array {
		return [];
	}

	public function getOptionalOutputShape(): array {
		return [];
	}

	public function getOptionalOutputShapeEnumValues(): array {
		return [];
	}

	/**
	 * @inheritDoc
	 * @return array{output: string, sources: list<string>}
	 * @throws \RuntimeException
	 */
	public function process(?string $userId, array $input, callable $reportProgress): array {
		if ($userId === null) {
			throw new \RuntimeException('User ID is required to process the prompt.');
		}

		if (!isset($input['prompt']) || !is_string($input['prompt'])) {
			throw new \RuntimeException('Invalid input, expected "prompt" key with string value');
		}

		if (
			!isset($input['scopeType']) || !is_string($input['scopeType'])
			|| !isset($input['scopeList']) || !is_array($input['scopeList'])
			|| !isset($input['scopeListMeta']) || !is_string($input['scopeListMeta'])
		) {
			throw new \RuntimeException('Invalid input, expected "scopeType" key with string value, "scopeList" key with array value and "scopeListMeta" key with string value');
		}

		try {
			ScopeType::validate($input['scopeType']);
		} catch (\InvalidArgumentException $e) {
			throw new \RuntimeException($e->getMessage(), intval($e->getCode()), $e);
		}

		// unscoped query
		if ($input['scopeType'] === ScopeType::NONE) {
			$response = $this->langRopeService->query($userId, $input['prompt']);
			if (isset($response['error'])) {
				throw new \RuntimeException('No result in ContextChat response. ' . $response['error']);
			}
			return $this->processResponse($userId, $response);
		}

		// scoped query
		$scopeList = array_unique($input['scopeList']);
		if (count($scopeList) === 0) {
			throw new \RuntimeException('Empty scope list provided, use unscoped query instead');
		}

		// index sources before the query, not needed for providers
		if ($input['scopeType'] === ScopeType::SOURCE) {
			$processedScopes = $this->indexFiles($userId, ...$input['scopeList']);
			$this->logger->debug('All valid files indexed, querying ContextChat', ['scopeType' => $input['scopeType'], 'scopeList' => $processedScopes]);
		} elseif ($input['scopeType'] === ScopeType::PROVIDER) {
			/** @var array<string> $scopeList */
			$processedScopes = $scopeList;
			$this->logger->debug('No need to index sources, querying ContextChat', ['scopeType' => $input['scopeType'], 'scopeList' => $processedScopes]);
		} else {
			// this should never happen
			throw new \InvalidArgumentException('Invalid scope type');
		}

		if (count($processedScopes) === 0) {
			throw new \RuntimeException('No supported sources found in the scope list, extend the list or use unscoped query instead');
		}

		$response = $this->langRopeService->query(
			$userId,
			$input['prompt'],
			true,
			$input['scopeType'],
			$processedScopes,
		);

		return $this->processResponse($userId, $response);
	}

	/**
	 * Validate and enrich sources JSON strings of the response
	 *
	 * @param string $userId
	 * @param array $response
	 * @return array{output: string, sources: list<string>}
	 * @throws \RuntimeException
	 */
	private function processResponse(string $userId, array $response): array {
		if (isset($response['error'])) {
			throw new \RuntimeException('No result in ContextChat response: ' . $response['error']);
		}
		if (!isset($response['output']) || !is_string($response['output'])
			|| !isset($response['sources']) || !is_array($response['sources'])) {
			throw new \RuntimeException('Invalid response from ContextChat, expected "output" and "sources" keys: ' . json_encode($response));
		}

		if (count($response['sources']) === 0) {
			$this->logger->info('No sources found in the response', ['response' => $response]);
			return [
				'output' => $response['output'] ?? '',
				'sources' => [],
			];
		}

		$jsonSources = array_filter(array_map(
			fn ($source) => json_encode($source),
			$this->metadataService->getEnrichedSources($userId, ...$response['sources'] ?? []),
		), fn ($json) => is_string($json));

		if (count($jsonSources) === 0) {
			$this->logger->warning('No sources could be enriched', ['sources' => $response['sources']]);
		} elseif (count($jsonSources) !== count($response['sources'] ?? [])) {
			$this->logger->warning('Some sources could not be enriched', ['sources' => $response['sources'], 'jsonSources' => $jsonSources]);
		}

		return [
			'output' => $response['output'] ?? '',
			'sources' => $jsonSources,
		];
	}

	/**
	 * @param array scopeList
	 * @return array<string> List of scopes that were successfully indexed
	 */
	private function indexFiles(string $userId, string ...$scopeList): array {
		$nodes = [];

		foreach ($scopeList as $scope) {
			if (!str_contains($scope, ProviderConfigService::getSourceId(''))) {
				$this->logger->warning('Invalid source format, expected "sourceId: itemId"');
				continue;
			}

			$nodeId = substr($scope, strlen(ProviderConfigService::getSourceId('')));

			try {
				$userFolder = $this->rootFolder->getUserFolder($userId);
			} catch (NotPermittedException $e) {
				$this->logger->warning('Could not get user folder for user ' . $userId . ': ' . $e->getMessage());
				continue;
			}
			$node = $userFolder->getById(intval($nodeId));
			if (count($node) === 0) {
				$this->logger->warning('Could not find file/folder with ID ' . $nodeId . ', skipping');
				continue;
			}
			$node = $node[0];

			if (!$node instanceof File && !$node instanceof Folder) {
				$this->logger->warning('Invalid source type, expected file/folder');
				continue;
			}

			$nodes[] = [
				'scope' => $scope,
				'node' => $node,
				'path' => $node->getPath(),
			];
		}

		// remove subfolders/files if parent folder is already indexed
		$filteredNodes = $nodes;
		foreach ($nodes as $node) {
			if ($node['node'] instanceof Folder) {
				$filteredNodes = array_filter($filteredNodes, function ($n) use ($node) {
					return !str_starts_with($n['path'], $node['path'] . DIRECTORY_SEPARATOR);
				});
			}
		}

		$indexedSources = [];
		$maxSize = $this->appConfig->getAppValueInt('indexing_max_size', Application::CC_MAX_SIZE);
		foreach ($filteredNodes as $node) {
			try {
				if ($node['node'] instanceof File) {
					if ($node['node']->getSize() > $maxSize) {
						$this->logger->warning('[ContextChatProvider] File too large to index', [
							'nodeSize' => $node['node']->getSize(),
							'maxSize' => $maxSize,
							'nodeId' => $node['node']->getId(),
							'path' => $node['node']->getPath(),
						]);
						continue;
					}
					$source = $this->scanService->getSourceFromFile(Application::MIMETYPES, $node['node']);
					if ($source === null) {
						continue;
					}
					$this->langRopeService->indexSources([$source]);
					$indexedSources[] = $node['scope'];
				} elseif ($node['node'] instanceof Folder) {
					$fileSources = iterator_to_array($this->scanService->scanDirectory(Application::MIMETYPES, $node['node']));
					$indexedSources = array_merge(
						$indexedSources,
						array_map(fn (Source $source) => $source->reference, $fileSources),
					);
				}
			} catch (RuntimeException $e) {
				$this->logger->warning('Could not index file/folder with ID ' . $node['node']->getId() . ': ' . $e->getMessage());
			} catch (RetryIndexException $e) {
				$this->logger->debug('This source is already being processed from another request, skipping', ['exception' => $e]);
			}
		}

		return $indexedSources;
	}
}
