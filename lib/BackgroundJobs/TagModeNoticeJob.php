<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\BackgroundJobs;

use OCA\ContextChat\AppInfo\Application;
use OCA\ContextChat\Logger;
use OCA\ContextChat\Notification\Notifier;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager;

/**
 * Tells the users that the admin switched to indexing by tags, or takes that notice
 * away again when the admin switches back to indexing everything.
 *
 * It runs in the background because there can be many users. The job looks at the
 * mode as it is when it runs, so quick switches back and forth, and the order in which
 * the jobs run, cannot leave a notice that no longer fits the mode.
 */
class TagModeNoticeJob extends QueuedJob {
	public const ACTION_NOTIFY = 'notify';
	public const ACTION_DISMISS = 'dismiss';

	private const OBJECT_TYPE = 'tag_mode';
	private const OBJECT_ID = 'enabled';

	public function __construct(
		ITimeFactory $timeFactory,
		private Logger $logger,
		private IAppConfig $appConfig,
		private IUserManager $userManager,
		private IManager $notificationManager,
	) {
		parent::__construct($timeFactory);
	}

	/**
	 * @param array{action:string, skip_user?:string|null} $argument
	 */
	protected function run($argument): void {
		$action = $argument['action'] ?? '';
		if (!in_array($action, [self::ACTION_NOTIFY, self::ACTION_DISMISS], true)) {
			$this->logger->warning('[TagModeNoticeJob] Invalid action, nothing done');
			return;
		}

		$tagMode = $this->appConfig->getAppValueString('index_mode', 'all', lazy: true) === 'tag_only';
		if (($action === self::ACTION_NOTIFY) !== $tagMode) {
			// the mode changed again after this job was queued
			return;
		}

		$skipUser = $argument['skip_user'] ?? null;
		$count = 0;
		$this->userManager->callForSeenUsers(function (IUser $user) use ($action, $skipUser, &$count): void {
			if (!$user->isEnabled() || $user->getUID() === $skipUser) {
				return;
			}

			$notification = $this->notificationManager->createNotification();
			$notification->setApp(Application::APP_ID)
				->setUser($user->getUID())
				->setObject(self::OBJECT_TYPE, self::OBJECT_ID);
			// never leave two of them for one user
			$this->notificationManager->markProcessed($notification);

			if ($action === self::ACTION_NOTIFY) {
				$notification->setDateTime($this->time->getDateTime())
					->setSubject(Notifier::SUBJECT_TAG_MODE_ENABLED);
				$this->notificationManager->notify($notification);
			}
			$count++;
		});

		$this->logger->info('[TagModeNoticeJob] ' . $action . ' done for ' . $count . ' users');
	}
}
