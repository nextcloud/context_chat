<?php

declare(strict_types=1);

namespace OCA\ContextChat\TaskProcessing;

use OCA\ContextChat\AppInfo\Application;
use OCA\ContextChat\Service\LangRopeService;
use OCA\ContextChat\Service\ProviderConfigService;
use OCA\ContextChat\Service\ScanService;
use OCA\ContextChat\Type\ScopeType;
use OCA\ContextChat\Type\Source;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\IL10N;
use OCP\TaskProcessing\ISynchronousProvider;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ContextChatProvider implements ISynchronousProvider {

	public function __construct(
		private LangRopeService $langRopeService,
		private IL10N $l10n,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
		private ScanService $scanService,
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
			return $response;
		}

		// scoped query
		$scopeList = array_unique($input['scopeList']);
		if (count($scopeList) === 0) {
			throw new \RuntimeException('No sources found');
		}

		// index sources before the query, not needed for providers
		if ($input['scopeType'] === ScopeType::SOURCE) {
			$processedScopes = $this->indexFiles($userId, ...$input['scopeList']);
			$this->logger->debug('All valid files indexed, querying ContextChat', ['scopeType' => $input['scopeType'], 'scopeList' => $processedScopes]);
		} elseif ($input['scopeType'] === ScopeType::PROVIDER) {
			$processedScopes = $scopeList;
			$this->logger->debug('No need to index sources, querying ContextChat', ['scopeType' => $input['scopeType'], 'scopeList' => $processedScopes]);
		}

		$response = $this->langRopeService->query(
			$userId,
			$input['prompt'],
			true,
			$input['scopeType'],
			$processedScopes,
		);

		if (isset($response['error'])) {
			throw new \RuntimeException('No result in ContextChat response: ' . $response['error']);
		}

		return $response;
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
		foreach ($filteredNodes as $node) {
			try {
				if ($node['node'] instanceof File) {
					$source = $this->scanService->getSourceFromFile($userId, Application::MIMETYPES, $node['node']);
					$this->scanService->indexSources([$source]);
					$indexedSources[] = $node['scope'];
				} elseif ($node['node'] instanceof Folder) {
					$fileSources = iterator_to_array($this->scanService->scanDirectory($userId, Application::MIMETYPES, $node['node']));
					$indexedSources = array_merge(
						$indexedSources,
						array_map(fn (Source $source) => $source->reference, $fileSources),
					);
				}
			} catch (RuntimeException $e) {
				$this->logger->warning('Could not index file/folder with ID ' . $node['node']->getId() . ': ' . $e->getMessage());
			}
		}

		return $indexedSources;
	}
}
