<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Files_Trashbin\Events;

use OCP\Files\Events\Node\AbstractNodesEvent;
use OCP\Files\Node;

/**
 * Shipped by the files_trashbin app, not part of the OCP package used for static analysis.
 */
class NodeRestoredEvent extends AbstractNodesEvent {
	public function __construct(Node $source, Node $target) {
		parent::__construct($source, $target);
	}
}
