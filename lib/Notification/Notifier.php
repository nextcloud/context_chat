<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Notification;

use OCA\ContextChat\AppInfo\Application;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Turns the notifications created by this app into text in the language of the user.
 */
class Notifier implements INotifier {
	/** The admin switched to indexing by tags: tell the users. */
	public const SUBJECT_TAG_MODE_ENABLED = 'tag_mode_enabled';

	public function __construct(
		private IFactory $l10nFactory,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Context Chat');
	}

	/**
	 * @throws UnknownNotificationException when the notification is not from this app
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		switch ($notification->getSubject()) {
			case self::SUBJECT_TAG_MODE_ENABLED:
				$notification->setParsedSubject($l->t('Context Chat indexing changed'));
				$notification->setParsedMessage($l->t('Context Chat now indexes only documents selected with tags. Documents that do not match the tag rules are no longer used as context. Contact your administrator if you have questions.'));
				return $notification;

			default:
				throw new UnknownNotificationException();
		}
	}
}
