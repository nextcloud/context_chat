<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\TaskProcessing;

use OCA\ContextChat\AppInfo\Application;
use OCA\ContextChat\Logger;
use OCA\ContextChat\Service\LangRopeService;
use OCA\ContextChat\Service\MetadataService;
use OCA\ContextChat\Type\ScopeType;
use OCP\IL10N;
use OCP\TaskProcessing\ISynchronousProvider;

class ContextChatSearchProvider implements ISynchronousProvider {

	public function __construct(
		private LangRopeService $langRopeService,
		private IL10N $l10n,
		private Logger $logger,
		private MetadataService $metadataService,
	) {
	}

	public function getId(): string {
		return Application::APP_ID . '-context_chat_search';
	}

	public function getName(): string {
		return $this->l10n->t('Nextcloud Assistant Context Chat Search Provider');
	}

	public function getTaskTypeId(): string {
		return ContextChatSearchTaskType::ID;
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
	 * @return array{sources: list<string>}
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

		if ($input['scopeType'] === ScopeType::PROVIDER) {
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

		// TODO use a different query to only ask for sources to the backend app
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
	 * @return array{sources: list<string>}
	 * @throws \RuntimeException
	 */
	private function processResponse(string $userId, array $response): array {
		if (isset($response['error'])) {
			throw new \RuntimeException('No result in ContextChat response: ' . $response['error']);
		}
		if (!isset($response['sources']) || !is_array($response['sources'])) {
			throw new \RuntimeException('Invalid response from ContextChat, expected "sources" keys: ' . json_encode($response));
		}

		if (count($response['sources']) === 0) {
			$this->logger->info('No sources found in the response', ['response' => $response]);
			return [
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
			'sources' => $jsonSources,
		];
	}
}
