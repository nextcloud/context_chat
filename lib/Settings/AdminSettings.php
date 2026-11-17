<?php

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Settings;

use OCA\ContextChat\Service\StatisticsService;
use OCA\ContextChat\Service\TagEligibilityService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;
use OCP\SystemTag\ISystemTagManager;

class AdminSettings implements ISettings {
	public function __construct(
		private IInitialState $initialState,
		private StatisticsService $statisticsService,
		private IAppConfig $appConfig,
		private ISystemTagManager $tagManager,
		private TagEligibilityService $tagEligibility,
	) {
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		$stats = $this->statisticsService->getStatistics();
		$this->initialState->provideInitialState('stats', $stats);
		$indexMode = $this->appConfig->getAppValueString('index_mode', 'all', lazy: true);
		$this->initialState->provideInitialState('index_mode', $indexMode);
		$this->initialState->provideInitialState('tag_allow_ids', array_map('intval', $this->tagEligibility->getAllowTagIds()));
		$this->initialState->provideInitialState('tag_deny_ids', array_map('intval', $this->tagEligibility->getDenyTagIds()));
		$this->initialState->provideInitialState('system_tags', $this->getSystemTags());
		return new TemplateResponse('context_chat', 'admin');
	}

	/**
	 * All system tags, sorted by name, for the allow and deny tag selectors.
	 *
	 * @return list<array{id: int, label: string}>
	 */
	private function getSystemTags(): array {
		$tags = [];
		foreach ($this->tagManager->getAllTags() as $tag) {
			$tags[] = ['id' => (int)$tag->getId(), 'label' => $tag->getName()];
		}
		usort($tags, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));
		return $tags;
	}

	/**
	 * @return string the section ID, e.g. 'sharing'
	 */
	public function getSection(): string {
		return 'ai';
	}

	/**
	 * @return int whether the form should be rather on the top or bottom of the admin section. The forms are arranged in ascending order of the priority values. It is required to return a value between 0 and 100.
	 */
	public function getPriority(): int {
		return 50;
	}
}
