<?php

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Type;

enum FsEventType: string {
	case CREATE = 'create';
	case ACCESS_UPDATE_DECL = 'access_update_decl';
}
