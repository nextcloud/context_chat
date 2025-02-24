<!--
  - Copyright (c) 2021. The Recognize contributors.
  -
  - This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
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
      <NcNoteCard type="warning" v-if="stats.initial_indexing_complete && stats.eligible_files_count > stats.vectordb_document_counts['files__default'] * 1.2">
        {{ t('context_chat', 'Less files were indexed than expected. Only {percent}% files out of {eligibleCount} are in the VectorDB.', {percent: Math.round((stats.vectordb_document_counts['files__default'] / stats.eligible_files_count) * 100), eligibleCount: stats.eligible_files_count}) }}
      </NcNoteCard>
      <NcNoteCard type="info">
        {{ t('context_chat', 'Eligible files for indexing: {count}', {count: stats.eligible_files_count}) }}
      </NcNoteCard>
      <NcNoteCard type="info">
        {{ t('context_chat', 'Queued files for indexing: {count}', {count: stats.queued_files_count}) }}
      </NcNoteCard>
      <NcNoteCard type="info" v-for="(count, providerId) in stats.queued_documents_counts">
        {{ t('context_chat', 'Queued documents from provider {providerId} for indexing: {count}', {count, providerId}) }}
      </NcNoteCard>
      <NcNoteCard type="info" v-for="(count, providerId) in stats.vectordb_document_counts">
        {{ t('context_chat', 'Documents in VectorDB from provider {providerId} for indexing: {count}', {count, providerId}) }}
      </NcNoteCard>
      <NcNoteCard type="info">
        {{ t('context_chat', 'Queued content update actions: {count}', {count: stats.queued_actions_count}) }}
      </NcNoteCard>
		</NcSettingsSection>
	</div>
</template>

<script>
import { NcNoteCard, NcSettingsSection, NcCheckboxRadioSwitch, NcTextField } from '@nextcloud/vue'
import { loadState } from '@nextcloud/initial-state'
import humanizeDuration from 'humanize-duration'

const MAX_RELATIVE_DATE = 1000 * 60 * 60 * 24 * 7 // one week

export default {
	name: 'ViewAdmin',
	components: { NcSettingsSection, NcNoteCard, NcCheckboxRadioSwitch, NcTextField },

	data() {
		return {
		  stats: {}
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
