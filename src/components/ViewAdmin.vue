<!--
SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div id="context_chat">
		<NcSettingsSection :name="t('context_chat', 'Status')">
			<NcNoteCard v-if="stats.initial_indexing_complete" show-alert type="success">
				{{ t('context_chat', 'The initial indexing run finished at: {date}.', {date: showDate(stats.intial_indexing_completed_at)}) }}
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
			<NcNoteCard v-if="stats.initial_indexing_complete && stats.eligible_files_count > stats.vectordb_document_counts['files__default'] * 1.2" type="warning">
				{{ t('context_chat', 'Less files were indexed than expected. Only {percent}% files out of {eligibleCount} are in the VectorDB.', {percent: Math.round((stats.vectordb_document_counts['files__default'] / stats.eligible_files_count) * 100), eligibleCount: stats.eligible_files_count}) }}
			</NcNoteCard>

			<ul>
				<li>
					{{ t('context_chat', 'Eligible files for indexing: {count}', {count: stats.eligible_files_count}) }}
				</li>
				<li>
					{{ t('context_chat', 'Queued files for indexing: {count}', {count: stats.queued_files_count}) }}
				</li>
				<li v-for="(count, providerId) in stats.queued_documents_counts" :key="providerId">
					{{ t('context_chat', 'Queued documents from provider {providerId} for indexing: {count}', {count, providerId}) }}
				</li>
				<template v-if="stats.vectordb_document_counts">
					<li v-for="(count, providerId) in stats.vectordb_document_counts" :key="providerId">
						{{ t('context_chat', 'Documents in VectorDB from provider {providerId}: {count}', {count, providerId}) }}
					</li>
				</template>
				<li>
					{{ t('context_chat', 'Queued content update actions: {count}', {count: stats.queued_actions_count}) }}
				</li>
			</ul>
			<p><a href="https://docs.nextcloud.com/server/latest/admin_manual/ai/app_context_chat.html">{{ t('context_chat', 'Official documentation') }}</a></p>
		</NcSettingsSection>
	</div>
</template>

<script>
import { NcNoteCard, NcSettingsSection } from '@nextcloud/vue'
import { loadState } from '@nextcloud/initial-state'
import humanizeDuration from 'humanize-duration'

const MAX_RELATIVE_DATE = 1000 * 60 * 60 * 24 * 7 // one week

export default {
	name: 'ViewAdmin',
	components: { NcSettingsSection, NcNoteCard },

	data() {
		return {
		  stats: {},
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

#context_chat .loading,
#context_chat .success {
	position: fixed;
	top: 70px;
	right: 20px;
}

#context_chat a:link, #context_chat a:visited, #context_chat a:hover {
	text-decoration: underline;
}
</style>
