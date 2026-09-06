<!--
SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcSettingsSection id="context_chat" :name="t('context_chat', 'Context Chat')">
		<h3>{{ t('context_chat', 'Indexing Status') }}</h3>
		<NcNoteCard v-if="stats.initial_indexing_complete"
			:title="(new Date(Number(stats.intial_indexing_completed_at) * 1000)).toLocaleString()"
			show-alert
			type="success">
			{{ t('context_chat', 'The initial indexing run finished {date}.', {date: showDate(stats.intial_indexing_completed_at)}) }}
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
					<th>{{ t('context_chat', 'Queued documents including updates') }}</th>
					<th>{{ t('context_chat', 'Locked documents in queue') }}</th>
					<th>{{ t('context_chat', 'Documents in vector database') }}</th>
				</tr>
			</thead>
			<tr v-for="(count, providerId) in stats.queued_documents_counts" :key="providerId">
				<td>{{ providerId }}</td>
				<td>
					{{ count }}
					<template v-if="providerId === 'files__default'">
						{{ n('context_chat', '({count} new file)', '({count} new files)', stats.queued_new_files_count,
							{count: stats.queued_new_files_count}) }}
					</template>
				</td>
				<td>
					{{ stats.queued_documents_locked_counts[providerId] }}
				</td>
				<td>
					{{
						stats.backend_available
							? stats.vectordb_document_counts[providerId]
							: t('context_chat', 'CC Backend unavailable')
					}}
				</td>
			</tr>
		</table>
		<p>&nbsp;</p>
		<p>{{ t('context_chat', 'Eligible files for indexing: {count}', {count: stats.eligible_files_count}) }}</p>
		<p>{{ t('context_chat', 'Queued content update actions: {count}', {count: stats.queued_actions_count}) }}</p>
		<p>{{ t('context_chat', 'Locked queue content update actions: {count}', {count: stats.queued_actions_locked_count}) }}</p>
		<p>{{ t('context_chat', 'Queued file system events: {count}', {count: stats.queued_fs_events_count}) }}</p>
		<h3>{{ t('context_chat', 'Download Logs') }}</h3>
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
		<h3>{{ t('context_chat', 'Multimodal Indexing') }}</h3>
		<NcNoteCard v-if="multimodalEnabled" show-alert type="success">
			{{ t('context_chat', 'Multimodal indexing (images and audio) is enabled.') }}
		</NcNoteCard>
		<NcNoteCard v-else type="info">
			{{ t('context_chat', 'Multimodal indexing (images and audio) is not yet enabled.') }}
		</NcNoteCard>
		<NcNoteCard v-if="!ocrAvailable && !sttAvailable" type="warning">
			{{ t('context_chat', 'No multimodal task providers are available. Install an OCR provider (for images) or a Speech-to-text provider (for audio) to enable multimodal indexing.') }}
		</NcNoteCard>
		<template v-else>
			<NcNoteCard v-if="ocrAvailable" show-alert type="success">
				{{ t('context_chat', 'OCR provider available, image files will be indexed.') }}
			</NcNoteCard>
			<NcNoteCard v-else type="warning">
				{{ t('context_chat', 'No OCR provider available, image files will not be indexed.') }}
			</NcNoteCard>
			<NcNoteCard v-if="sttAvailable" show-alert type="success">
				{{ t('context_chat', 'Speech-to-text provider available, audio files will be indexed.') }}
			</NcNoteCard>
			<NcNoteCard v-else type="warning">
				{{ t('context_chat', 'No Speech-to-text provider available, audio files will not be indexed.') }}
			</NcNoteCard>
		</template>
		<div class="horizontal-flex">
			<NcButton :disabled="queueMultimodalLoading || (!ocrAvailable && !sttAvailable)"
				type="primary"
				@click="queueMultimodalFiles(multimodalEnabled)">
				{{
					multimodalEnabled
						? t('context_chat', 'Re-queue multimodal files')
						: t('context_chat', 'Enable multimodal indexing and queue all image and audio files')
				}}
			</NcButton>
			<NcLoadingIcon v-if="queueMultimodalLoading" :size="20" />
		</div>
	</NcSettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard, NcSettingsSection } from '@nextcloud/vue'
import humanizeDuration from 'humanize-duration'

const MAX_RELATIVE_DATE = 1000 * 60 * 60 * 24 * 7 // one week

export default {
	name: 'ViewAdmin',
	components: { NcSettingsSection, NcNoteCard, NcButton, NcLoadingIcon },

	data() {
		return {
			stats: {},
			multimodalEnabled: false,
			ocrAvailable: false,
			sttAvailable: false,
			queueMultimodalLoading: false,
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
		this.multimodalEnabled = loadState('context_chat', 'multimodal_enabled')
		this.ocrAvailable = loadState('context_chat', 'ocr_available')
		this.sttAvailable = loadState('context_chat', 'stt_available')
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
		async queueMultimodalFiles(force = false) {
			this.queueMultimodalLoading = true
			try {
				const response = await axios.post(generateUrl('/apps/context_chat/queue-multimodal-files'), { force })
				if (response.data?.errors?.length) {
					showError(response.data.errors.join('\n'), { timeout: 10 })
				} else {
					this.multimodalEnabled = true
					showSuccess(force
						? this.t('context_chat', 'Multimodal files sccessfully re-enqueued.')
						: this.t('context_chat', 'Multimodal files have been scheduled to be queued for indexation. They will be queued in the subsequent cron runs automatically.',
						),
					)
				}
			} catch (e) {
				console.error(e)
				showError(e.response?.data?.error ?? e.message)
			} finally {
				this.queueMultimodalLoading = false
			}
		},
	},
}
</script>

<style scoped>
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
