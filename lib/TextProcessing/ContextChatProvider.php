<?php

declare(strict_types=1);
namespace OCA\ContextChat\TextProcessing;

use OCA\ContextChat\Service\LangRopeService;
use OCP\IL10N;
use OCP\TextProcessing\IProvider;
use OCP\TextProcessing\IProviderWithUserId;

/**
 * @template-implements IProviderWithUserId<ContextChatTaskType>
 * @template-implements IProvider<ContextChatTaskType>
 */
class ContextChatProvider implements IProvider, IProviderWithUserId {

	private ?string $userId = null;

	public function __construct(
		private LangRopeService $langRopeService,
		private IL10N $l10n,
	) {
	}

	public function getName(): string {
		return $this->l10n->t('Nextcloud Assistant Context Chat provider');
	}

	public function process(string $prompt): string {
		$response = $this->langRopeService->query($this->userId, $prompt);
		if (isset($response['error'])) {
			throw new \RuntimeException('No result in ContextChat response. ' . $response['error']);
		}
		return $response['message'] ?? '';
	}

	public function getTaskType(): string {
		return ContextChatTaskType::class;
	}

	public function setUserId(?string $userId): void {
		$this->userId = $userId;
	}
}
