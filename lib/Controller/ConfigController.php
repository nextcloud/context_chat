<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Controller;

use OCA\ContextChat\BackgroundJobs\SchedulerJob;
use OCA\ContextChat\BackgroundJobs\TagListChangedJob;
use OCA\ContextChat\BackgroundJobs\TagModeNoticeJob;
use OCA\ContextChat\BackgroundJobs\UntaggedCleanupSchedulerJob;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig as ICoreAppConfig;
use OCP\IRequest;
use OCP\PreConditionNotMetException;

class ConfigController extends Controller {

	private const TAG_LIST_KEYS = ['tag_allow_ids', 'tag_deny_ids'];

	public function __construct(
		string $appName,
		IRequest $request,
		private IAppConfig $appConfig,
		private IJobList $jobList,
		private ICoreAppConfig $coreAppConfig,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Set config values
	 *
	 * @param array $values key/value pairs to store in config
	 * @return DataResponse
	 * @throws PreConditionNotMetException
	 */
	#[NoAdminRequired]
	public function setConfig(array $values): DataResponse {
		if ($this->userId === null) {
			throw new PreConditionNotMetException('User must be logged in to set user config values');
		}
		foreach ($values as $key => $value) {
			$this->appConfig->setUserValue($this->userId, $key, $value);
		}
		return new DataResponse(1);
	}

	private function handleIndexModeChange(array $values): void {
		if (!isset($values['index_mode'])) {
			return;
		}
		$oldMode = $this->appConfig->getAppValueString('index_mode', 'all', lazy: true);
		$newMode = $values['index_mode'];
		if ($newMode === $oldMode) {
			return;
		}
		if ($newMode === 'tag_only') {
			$this->jobList->add(UntaggedCleanupSchedulerJob::class);
			$this->jobList->add(TagModeNoticeJob::class, [
				'action' => TagModeNoticeJob::ACTION_NOTIFY,
				'skip_user' => $this->userId,
			]);
		} else {
			$this->jobList->add(SchedulerJob::class);
			$this->jobList->add(TagModeNoticeJob::class, ['action' => TagModeNoticeJob::ACTION_DISMISS]);
		}
	}

	/**
	 * Keeps the index in line with a change of the allow/deny lists, without reading every
	 * file when that is not needed:
	 *  - the allow list goes from empty to filled: only files without an allow tag can
	 *    change (they must leave), so a full cleanup runs;
	 *  - the allow list goes from filled to empty: only files without tags can change
	 *    (they must enter), so a full indexing run is started;
	 *  - any other change: only files carrying the tag that was added or removed can change,
	 *    so one small job per tag looks at those files.
	 * Does nothing in mode "all", and when the mode itself changes in the same request
	 * (handleIndexModeChange already starts a full run then).
	 * Expects $values after normalizeTagLists and before the new values are stored.
	 */
	private function handleTagListChange(array $values): void {
		$oldMode = $this->appConfig->getAppValueString('index_mode', 'all', lazy: true);
		$newMode = $values['index_mode'] ?? $oldMode;
		if ($oldMode !== 'tag_only' || $newMode !== 'tag_only') {
			return;
		}

		$oldAllow = $this->sortedTagIds([], 'tag_allow_ids');
		$newAllow = $this->sortedTagIds($values, 'tag_allow_ids');
		$oldDeny = $this->sortedTagIds([], 'tag_deny_ids');
		$newDeny = $this->sortedTagIds($values, 'tag_deny_ids');

		$allowStarted = $oldAllow === [] && $newAllow !== [];
		$allowEmptied = $oldAllow !== [] && $newAllow === [];
		if ($allowStarted) {
			$this->jobList->add(UntaggedCleanupSchedulerJob::class);
		}
		if ($allowEmptied) {
			$this->jobList->add(SchedulerJob::class);
		}

		if (!$allowStarted) {
			foreach (array_diff($newAllow, $oldAllow) as $tagId) {
				$this->addTagListChangedJob($tagId, TagListChangedJob::ACTION_INDEX);
			}
		}
		if (!$allowEmptied) {
			foreach (array_diff($oldAllow, $newAllow) as $tagId) {
				$this->addTagListChangedJob($tagId, TagListChangedJob::ACTION_REMOVE);
			}
		}
		foreach (array_diff($newDeny, $oldDeny) as $tagId) {
			$this->addTagListChangedJob($tagId, TagListChangedJob::ACTION_REMOVE);
		}
		foreach (array_diff($oldDeny, $newDeny) as $tagId) {
			$this->addTagListChangedJob($tagId, TagListChangedJob::ACTION_INDEX);
		}
	}

	private function addTagListChangedJob(int $tagId, string $action): void {
		$this->jobList->add(TagListChangedJob::class, ['tag_id' => $tagId, 'action' => $action]);
	}

	/**
	 * @return int[] unique, sorted
	 */
	private function sortedTagIds(array $values, string $key): array {
		$ids = array_unique($this->tagIds($values, $key));
		sort($ids);
		return array_values($ids);
	}

	/**
	 * Turns the allow/deny tag lists of a request into a JSON list of unique, sorted
	 * tag ids, which is how they are stored. Accepts an array or a JSON string.
	 *
	 * @throws \InvalidArgumentException if a list is not a list of tag ids
	 */
	private function normalizeTagLists(array $values): array {
		foreach (self::TAG_LIST_KEYS as $key) {
			if (!array_key_exists($key, $values)) {
				continue;
			}
			$raw = $values[$key];
			if (is_string($raw)) {
				$raw = $raw === '' ? [] : json_decode($raw, true);
			}
			if (!is_array($raw)) {
				throw new \InvalidArgumentException("The value of $key must be a list of tag ids.");
			}
			$ids = [];
			foreach ($raw as $id) {
				if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
					throw new \InvalidArgumentException("The value of $key must be a list of tag ids.");
				}
				$ids[(int)$id] = (int)$id;
			}
			sort($ids);
			$values[$key] = json_encode(array_values($ids));
		}
		return $values;
	}

	/**
	 * Checks the settings as they will be after saving: what is already stored plus
	 * what arrived in the request.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function validateTagConfig(array $values): void {
		$allow = $this->tagIds($values, 'tag_allow_ids');
		$deny = $this->tagIds($values, 'tag_deny_ids');
		$mode = $values['index_mode'] ?? $this->appConfig->getAppValueString('index_mode', 'all', lazy: true);

		if (array_intersect($allow, $deny) !== []) {
			throw new \InvalidArgumentException('The same tag cannot be in both the allow list and the deny list.');
		}
		if ($mode === 'tag_only' && $allow === [] && $deny === []) {
			throw new \InvalidArgumentException('Select at least one allow or deny tag before indexing by tags.');
		}
	}

	/**
	 * @return int[]
	 */
	private function tagIds(array $values, string $key): array {
		$raw = $values[$key] ?? $this->appConfig->getAppValueString($key, '[]', lazy: true);
		$decoded = json_decode((string)$raw, true);
		return is_array($decoded) ? array_map('intval', $decoded) : [];
	}

	/**
	 * Set admin config values
	 *
	 * @param array $values key/value pairs to store in app config
	 * @return DataResponse
	 */
	public function setAdminConfig(array $values): DataResponse {
		try {
			$values = $this->normalizeTagLists($values);
			$this->validateTagConfig($values);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		$this->handleIndexModeChange($values);
		$this->handleTagListChange($values);
		foreach ($values as $key => $value) {
			$this->appConfig->setAppValueString($key, $value, lazy: true);
		}
		$this->coreAppConfig->clearCache();
		return new DataResponse(1);
	}
}
