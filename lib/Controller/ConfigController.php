<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Controller;

use OCA\ContextChat\BackgroundJobs\SchedulerJob;
use OCA\ContextChat\BackgroundJobs\UntaggedCleanupSchedulerJob;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig as ICoreAppConfig;
use OCP\IRequest;
use OCP\PreConditionNotMetException;

class ConfigController extends Controller {

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

	/**
	 * Set admin config values
	 *
	 * @param array $values key/value pairs to store in app config
	 * @return DataResponse
	 */
	public function setAdminConfig(array $values): DataResponse {
		if (isset($values['index_mode'])) {
			$oldMode = $this->appConfig->getAppValueString('index_mode', 'all', lazy: true);
			$newMode = $values['index_mode'];
			if ($newMode !== $oldMode) {
				if ($newMode === 'tag_only') {
					$this->jobList->add(UntaggedCleanupSchedulerJob::class);
				} else {
					$this->jobList->add(SchedulerJob::class);
				}
			}
		}
		foreach ($values as $key => $value) {
			$this->appConfig->setAppValueString($key, $value, lazy: true);
		}
		$this->coreAppConfig->clearCache();
		return new DataResponse(1);
	}
}
