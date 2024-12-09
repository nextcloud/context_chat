<?php

/**
 * Nextcloud - ContextChat
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Anupam Kumar <kyteinsky@gmail.com>
 * @copyright Anupam Kumar 2024
 */

namespace OCA\ContextChat\Type;

class ActionType {
	// { sourceIds: array<string> }
	public const DELETE_SOURCE_IDS = 'delete_source_ids';
	// { providerId: string }
	public const DELETE_PROVIDER_ID = 'delete_provider_id';
	// { userId: string }
	public const DELETE_USER_ID = 'delete_user_id';
	// { op: string, userIds: array<string>, sourceId: string }
	public const UPDATE_ACCESS_SOURCE_ID = 'update_access_source_id';
	// { op: string, userIds: array<string>, providerId: string }
	public const UPDATE_ACCESS_PROVIDER_ID = 'update_access_provider_id';
	// { userIds: array<string>, sourceId: string }
	public const UPDATE_ACCESS_DECL_SOURCE_ID = 'update_access_decl_source_id';
}
