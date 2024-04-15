<?php

declare(strict_types=1);
namespace OCA\ContextChat\TextProcessing;

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
use OCP\TextProcessing\IProvider;
use OCP\TextProcessing\IProviderWithUserId;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @template-implements IProviderWithUserId<ContextChatTaskType>
 * @template-implements IProvider<ContextChatTaskType>
 */
class ContextChatProvider implements IProvider, IProviderWithUserId {

	private ?string $userId = null;

	public function __construct(
		private IL10N $l10n,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
		private LangRopeService $langRopeService,
		private ScanService $scanService,
	) {
	}

	public function getName(): string {
		return $this->l10n->t('Nextcloud Assistant Context Chat Provider');
	}

	/**
	 * Accepted scopeList formats:
	 * - "files__default: $fileId"
	 * - "$appId__$providerId"
	 *
	 * @param string $prompt JSON string with the following structure:
	 * {
	 *   "scopeType": string, (optional key)
	 *   "scopeList": list[string], (optional key)
	 *   "prompt": string
	 * }
	 *
	 * @return string
	 */
	public function process(string $prompt): string {
		if ($this->userId === null) {
			throw new \RuntimeException('User ID is required to process the prompt.');
		}

		try {
			$parsedData = json_decode($prompt, true, flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE);
		} catch (\JsonException $e) {
			throw new \RuntimeException(
				'Invalid JSON string, expected { "prompt": string } or { "scopeType": string, "scopeList": list[string], "prompt": string }',
				intval($e->getCode()), $e,
			);
		}

		if (!isset($parsedData['prompt']) || !is_string($parsedData['prompt'])) {
			throw new \RuntimeException('Invalid JSON string, expected "prompt" key with string value');
		}

		if (!isset($parsedData['scopeType']) || !isset($parsedData['scopeList'])) {
			$response = $this->langRopeService->query($this->userId, $prompt);
			if (isset($response['error'])) {
				throw new \RuntimeException('No result in ContextChat response. ' . $response['error']);
			}
			return $response['message'] ?? '';
		}

		if (!is_string($parsedData['scopeType']) || !is_array($parsedData['scopeList'])) {
			throw new \RuntimeException('Invalid JSON string, expected "scopeType" key with string value and "scopeList" key with array value');
		}

		try {
			ScopeType::validate($parsedData['scopeType']);
		} catch (\InvalidArgumentException $e) {
			throw new \RuntimeException($e->getMessage(), intval($e->getCode()), $e);
		}

		$scopeList = array_unique($parsedData['scopeList']);
		if (count($scopeList) === 0) {
			throw new \RuntimeException('No sources found');
		}

		// index sources before the query, not needed for providers
		if ($parsedData['scopeType'] === ScopeType::SOURCE) {
			$processedScopes = $this->indexFiles(...$parsedData['scopeList']);
			$this->logger->debug('All valid files indexed, querying ContextChat', ['scopeType' => $parsedData['scopeType'], 'scopeList' => $processedScopes]);
		} else {
			$processedScopes = $scopeList;
			$this->logger->debug('No need to index sources, querying ContextChat', ['scopeType' => $parsedData['scopeType'], 'scopeList' => $processedScopes]);
		}

		$response = $this->langRopeService->query(
			$this->userId,
			$parsedData['prompt'],
			true,
			$parsedData['scopeType'],
			$processedScopes,
		);

		if (isset($response['error'])) {
			throw new \RuntimeException('No result in ContextChat response: ' . $response['error']);
		}

		return $response['message'] ?? '';
	}

	/**
	 * @param array scopeList
	 * @return array<string> List of scopes that were successfully indexed
	 */
	private function indexFiles(string ...$scopeList): array {
		$nodes = [];

		foreach ($scopeList as $scope) {
			if (!str_contains($scope, ProviderConfigService::getSourceId(''))) {
				$this->logger->warning('Invalid source format, expected "sourceId: itemId"');
				continue;
			}

			$nodeId = substr($scope, strlen(ProviderConfigService::getSourceId('')));

			try {
				$userFolder = $this->rootFolder->getUserFolder($this->userId);
			} catch (NotPermittedException $e) {
				$this->logger->warning('Could not get user folder for user ' . $this->userId . ': ' . $e->getMessage());
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
					$source = $this->scanService->getSourceFromFile($this->userId, Application::MIMETYPES, $node['node']);
					$this->scanService->indexSources([$source]);
					$indexedSources[] = $node['scope'];
				} elseif ($node['node'] instanceof Folder) {
					$fileSources = iterator_to_array($this->scanService->scanDirectory($this->userId, Application::MIMETYPES, $node['node']));
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

	public function getTaskType(): string {
		return ContextChatTaskType::class;
	}

	public function setUserId(?string $userId): void {
		$this->userId = $userId;
	}
}
