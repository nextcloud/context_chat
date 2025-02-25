<?php

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\ContextChat\Settings;

use OCA\ContextChat\Db\QueueContentItemMapper;
use OCA\ContextChat\Service\ActionService;
use OCA\ContextChat\Service\LangRopeService;
use OCA\ContextChat\Service\QueueService;
use OCA\ContextChat\Service\StorageService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings {
	public function __construct(
		private IInitialState $initialState,
		private IAppConfig $appConfig,
		private ActionService $actionService,
		private QueueService $queueService,
		private StorageService $storageService,
		private LangRopeService $langRopeService,
		private QueueContentItemMapper $contentQueue,
	) {
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		$stats = [];

		$stats['installed_at'] = $this->appConfig->getAppValueInt('installed_time', 0);
		if ($this->appConfig->getAppValueInt('last_indexed_time', 0) === 0) {
			$stats['initial_indexing_complete'] = false;
		} else {
			$stats['initial_indexing_complete'] = true;
			$stats['intial_indexing_completed_at'] = $this->appConfig->getAppValueInt('last_indexed_time', 0);
		}

		$stats['eligible_files_count'] = $this->storageService->countFiles();
		$stats['queued_files_count'] = $this->queueService->count();
		$stats['indexed_files_count'] = Util::numericToNumber($this->appConfig->getAppValueString('indexed_files_count', '0'));
		$stats['queued_actions_count'] = $this->actionService->count();
		try {
			$stats['vectordb_document_counts'] = $this->langRopeService->getIndexedDocumentsCounts();
			$stats['backend_available'] = true;
		} catch (\RuntimeException $e) {
			$stats['backend_available'] = false;
		}
		$stats['queued_documents_counts'] = $this->contentQueue->count();

		$this->initialState->provideInitialState('stats', $stats);

		return new TemplateResponse('context_chat', 'admin');
	}

	/**
	 * @return string the section ID, e.g. 'sharing'
	 */
	public function getSection(): string {
		return 'context_chat';
	}

	/**
	 * @return int whether the form should be rather on the top or bottom of the admin section. The forms are arranged in ascending order of the priority values. It is required to return a value between 0 and 100.
	 */
	public function getPriority(): int {
		return 50;
	}
}
