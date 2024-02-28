<?php

declare(strict_types=1);
namespace OCA\ContextChat\TextProcessing;

use OCA\ContextChat\Service\LangRopeService;
use OCA\ContextChat\Service\ScopeType;
use OCP\IL10N;
use OCP\TextProcessing\IProvider;
use OCP\TextProcessing\IProviderWithUserId;

/**
 * @template-implements IProviderWithUserId<ScopedContextChatTaskType>
 * @template-implements IProvider<ScopedContextChatTaskType>
 */
class ScopedContextChatProvider implements IProvider, IProviderWithUserId {

	private ?string $userId = null;

	public function __construct(
		private LangRopeService $langRopeService,
		private IL10N $l10n,
	) {
	}

	public function getName(): string {
		return $this->l10n->t('Nextcloud Assistant Scoped Context Chat Provider');
	}

	/**
	 * @param string $prompt JSON string with the following structure:
	 * {
	 *   "scopeType": string,
	 *   "scopeList": list[string],
	 *   "prompt": string,
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
				'Invalid JSON string, expected { "scopeType": string, "scopeList": list[string], "prompt": string }',
				intval($e->getCode()), $e,
			);
		}

		if (
			!is_array($parsedData)
			|| !isset($parsedData['scopeType'])
			|| !is_string($parsedData['scopeType'])
			|| !isset($parsedData['scopeList'])
			|| !is_array($parsedData['scopeList'])
			|| !isset($parsedData['prompt'])
			|| !is_string($parsedData['prompt'])
		) {
			throw new \RuntimeException('Invalid JSON string, expected { "scopeType": string, "scopeList": list[string], "prompt": string }');
		}

		$scopeTypeEnum = ScopeType::tryFrom($parsedData['scopeType']);
		if ($scopeTypeEnum === null) {
			throw new \RuntimeException('Invalid scope type: ' . $parsedData['scopeType']);
		}

		$response = $this->langRopeService->scopedQuery(
			$this->userId,
			$parsedData['prompt'],
			$scopeTypeEnum,
			$parsedData['scopeList'],
		);

		if (isset($response['error'])) {
			throw new \RuntimeException('No result in ContextChat response. ' . $response['error']);
		}

		return $response['message'] ?? '';
	}

	public function getTaskType(): string {
		return ScopedContextChatTaskType::class;
	}

	public function setUserId(?string $userId): void {
		$this->userId = $userId;
	}
}
