<!--
SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div id="context_chat">
		<NcSettingsSection :name="t('context_chat', 'Context Chat')">
			<NcSettingsSection :name="t('context_chat', 'Indexing Status')">
				<NcNoteCard v-if="stats.initial_indexing_complete" show-alert type="success">
					{{
						t('context_chat', 'The initial indexing run finished at: {date}.', {date: showDate(stats.intial_indexing_completed_at)})
					}}
				</NcNoteCard>
				<NcNoteCard v-else type="warning">
					{{ t('context_chat', 'The initial indexing is still running.') }}
				</NcNoteCard>
				<NcNoteCard v-if="stats.backend_available" show-alert type="success">
					{{ t('context_chat', 'The Context Chat Backend app is installed and responsive.') }}
				</NcNoteCard>
				<NcNoteCard v-else type="warning">
					{{ t('context_chat', 'The Context Chat Backend app is not installed or not responsing.') }}
				</NcNoteCard>
				<NcNoteCard
					v-if="stats.initial_indexing_complete && stats.eligible_files_count > stats.vectordb_document_counts['files__default'] * 1.2"
					type="warning">
					{{
						t('context_chat', 'Less files were indexed than expected. Only {percent}% files out of {eligibleCount} are in the VectorDB.', {
							percent: Math.round((stats.vectordb_document_counts['files__default'] / stats.eligible_files_count) * 100),
							eligibleCount: stats.eligible_files_count
						})
					}}
				</NcNoteCard>
				<table>
					<thead>
						<tr>
							<th>{{ t('context_chat', 'Content provider') }}</th>
							<th>{{ t('context_chat', 'Queued documents') }}</th>
							<th>{{ t('context_chat', 'Documents in vector database') }}</th>
						</tr>
					</thead>
					<tr v-for="(count, providerId) in stats.queued_documents_counts" :key="providerId">
						<td>{{ providerId }}</td>
						<td>{{ count }}</td>
						<td v-if="stats.vectordb_document_counts">
							{{ stats.vectordb_document_counts[providerId] }}
							<template v-if="providerId === 'files__default'">
								{{ t('context_chat', '(out of {count} sent)', {count: stats.indexed_files_count}) }}
							</template>
						</td>
						<td v-else>
							{{ t('context_chat', 'Not available') }}
						</td>
					</tr>
				</table>
				<p>&nbsp;</p>
				<p>{{ t('context_chat', 'Eligible files for indexing: {count}', {count: stats.eligible_files_count}) }}</p>
				<p>{{ t('context_chat', 'Queued content update actions: {count}', {count: stats.queued_actions_count}) }}</p>
				<p>{{ t('context_chat', 'Queued File System events: {count}', {count: stats.queued_fs_events_count}) }}</p>
			</NcSettingsSection>
			<NcSettingsSection :name="t('context_chat', 'Download Logs')">
				<div class="horizontal-flex">
					<NcButton :href="downloadURLNextcloudLogs">
						{{ t('context_chat', 'Download the PHP App logs') }}
					</NcButton>
					<NcButton :href="downloadURLDockerLogs">
						{{ t('context_chat', 'Download the Ex-App Backend logs') }}
					</NcButton>
				</div>
				<p>&nbsp;</p>
				<p>
					<a href="https://docs.nextcloud.com/server/latest/admin_manual/ai/app_context_chat.html">{{
						t('context_chat', 'Official documentation')
					}}</a>
				</p>
			</NcSettingsSection>
		</NcSettingsSection>
	</div>
</template>

<script>
import { NcNoteCard, NcSettingsSection, NcButton } from '@nextcloud/vue'
import { loadState } from '@nextcloud/initial-state'
import humanizeDuration from 'humanize-duration'
import { generateUrl } from '@nextcloud/router'

const MAX_RELATIVE_DATE = 1000 * 60 * 60 * 24 * 7 // one week

export default {
	name: 'ViewAdmin',
	components: { NcSettingsSection, NcNoteCard, NcButton },

	data() {
		return {
			stats: {},
			downloadURLNextcloudLogs: generateUrl('/apps/context_chat/download-logs-nextcloud'),
			downloadURLDockerLogs: generateUrl('/apps/app_api/proxy/context_chat_backend/downloadLogs'),
		}
	},

	watch: {
		error(error) {
			if (!error) return
			OC.Notification.showTemporary(error)
		},
	},
	async created() {
		this.stats = loadState('context_chat', 'stats')
	},

	methods: {
		showDate(timestamp) {
			if (!timestamp) {
				return this.t('context_chat', 'never')
			}
			const date = new Date(Number(timestamp) * 1000)
			const age = Date.now() - date
			if (age < MAX_RELATIVE_DATE) {
				const duration = humanizeDuration(age, {
					language: OC.getLanguage().split('-')[0],
					units: ['d', 'h', 'm', 's'],
					largest: 1,
					round: true,
				})
				return this.t('context_chat', '{time} ago', { time: duration })
			} else {
				return date.toLocaleDateString()
			}
		},
	},
}
</script>
<style>
figure[class^='icon-'] {
	display: inline-block;
}

#context_chat {
	position: relative;
}

#context_chat table {
	border-collapse: collapse;
	width: 100%;
	border: 1px solid #ccc;
}

#context_chat th,
#context_chat td {
	border: 1px solid #ccc;
	padding: 8px;
	text-align: left;
}

#context_chat th {
	background-color: var(--color-background-dark);
}

#context_chat p a:link, #context_chat p a:visited, #context_chat p a:hover {
	text-decoration: underline;
}

.horizontal-flex {
	display: flex;
	gap: 10px;
}
</style>
