<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\ContextChat\Exceptions;

/**
 * For 4xx responses from the context_chat_backend
 */
class FatalRequestException extends \RuntimeException {
}
