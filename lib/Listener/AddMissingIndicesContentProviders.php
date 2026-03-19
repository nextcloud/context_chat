<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Listener;

use OCP\DB\Events\AddMissingIndicesEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * @template-implements IEventListener<AddMissingIndicesEvent>
 */
class AddMissingIndicesListenerProviders implements IEventListener {
	#[\Override]
	public function handle(Event $event): void {
		if (!($event instanceof AddMissingIndicesEvent)) {
			// Unrelated
			return;
		}

		$event->addMissingIndex(
			'context_chat_content_queue',
			'ccc_q_provider_idx',
			['app_id', 'provider_id', 'item_id'],
		);
	}
}
