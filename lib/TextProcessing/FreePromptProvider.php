<?php

declare(strict_types=1);
namespace OCA\ContextChat\TextProcessing;

use OCA\ContextChat\Service\LangRopeService;
use OCP\IL10N;
use OCP\TextProcessing\FreePromptTaskType;
use OCP\TextProcessing\IProvider;
use OCP\TextProcessing\IProviderWithUserId;

/**
 * @template-implements IProviderWithUserId<FreePromptTaskType>
 * @template-implements IProvider<FreePromptTaskType>
 */
class FreePromptProvider implements IProvider, IProviderWithUserId {

	private ?string $userId = null;

	public function __construct(
		private LangRopeService $langRopeService,
		private IL10N $l10n,
	) {
	}

	public function getName(): string {
		return $this->l10n->t('Nextcloud Assistant Context Chat Provider');
	}

	public function process(string $prompt): string {
		if ($this->userId === null) {
			throw new \RuntimeException('User ID is required to process the prompt.');
		}

		$response = $this->langRopeService->query($this->userId, $prompt, false);
		if (isset($response['error'])) {
			throw new \RuntimeException('No result in ContextChat response. ' . $response['error']);
		}
		return $response['message'] ?? '';
	}

	public function getTaskType(): string {
		return FreePromptTaskType::class;
	}

	public function setUserId(?string $userId): void {
		$this->userId = $userId;
	}
}
