<template>
	<div class="cwyd-picker-content-wrapper">
		<div class="cwyd-picker-content">
			<h2>
				{{ t('cwyd', 'Chat with your documents') }}
			</h2>
			<div class="input-wrapper">
				<NcTextField
					ref="cwyd-search-input"
					:value.sync="query"
					:label="inputPlaceholder"
					:disabled="loading"
					:show-trailing-button="!!query"
					@keydown.enter="generate"
					@trailing-button-click="query = ''" />
			</div>
			<div v-if="result === null || query === ''"
				class="prompts">
				<NcUserBubble v-for="p in prompts"
					:key="p.id + p.value"
					class="prompt-bubble"
					:title="p.value"
					:size="30"
					avatar-image="icon-history"
					:display-name="p.value"
					@click="query = p.value" />
			</div>
			<div v-if="result !== null"
				class="preview">
				<h3>{{ t('cwyd', 'Preview') }}</h3>
				<NcRichContenteditable :value.sync="result"
					class="editable-preview"
					:multiline="true"
					:disabled="loading"
					:placeholder="t('cwyd', 'Preview content')"
					:link-autocomplete="false" />
			</div>
			<div class="footer">
				<NcButton class="advanced-button"
					type="tertiary"
					:aria-label="t('cwyd', 'Show/hide advanced options')"
					@click="showAdvanced = !showAdvanced">
					<template #icon>
						<component :is="showAdvancedIcon" />
					</template>
					{{ t('cwyd', 'Advanced options') }}
				</NcButton>
				<NcButton
					type="secondary"
					:aria-label="t('cwyd', 'Preview')"
					:disabled="loading || !query"
					@click="generate">
					{{ previewButtonLabel }}
					<template #icon>
						<NcLoadingIcon v-if="loading" />
						<RefreshIcon v-else-if="result !== null" />
						<EyeIcon v-else />
					</template>
				</NcButton>
				<NcButton v-if="result !== null"
					type="primary"
					:aria-label="t('cwyd', 'Submit')"
					:disabled="loading || emptyResult"
					@click="submit">
					{{ t('cwyd', 'Send') }}
					<template #icon>
						<ArrowRightIcon />
					</template>
				</NcButton>
			</div>
			<div v-show="showAdvanced" class="advanced">
				<div class="line">
					<NcCheckboxRadioSwitch
						class="include-query"
						:checked.sync="includeQuery">
						{{ t('cwyd', 'Include the prompt in the result') }}
					</NcCheckboxRadioSwitch>
					<div class="spacer" />
				</div>
				<div class="line">
					<label for="nb-results">
						{{ t('cwyd', 'How many results to generate') }}
					</label>
					<div class="spacer" />
					<input
						id="nb-results"
						v-model="completionNumber"
						type="number"
						min="1"
						max="10"
						step="1">
				</div>
				<div class="line">
					<label for="max-tokens">
						{{ t('cwyd', 'Approximate maximum number of words to generate (tokens)') }}
					</label>
					<div class="spacer" />
					<input
						id="max-tokens"
						v-model="maxTokens"
						type="number"
						min="10"
						max="100000"
						step="1">
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import EyeIcon from 'vue-material-design-icons/Eye.vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import ArrowRightIcon from 'vue-material-design-icons/ArrowRight.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import HelpCircleIcon from 'vue-material-design-icons/HelpCircle.vue'

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'
import NcUserBubble from '@nextcloud/vue/dist/Components/NcUserBubble.js'
import NcRichContenteditable from '@nextcloud/vue/dist/Components/NcRichContenteditable.js'

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'

export default {
	name: 'CwydCustomPickerElement',

	components: {
		NcButton,
		NcLoadingIcon,
		NcTextField,
		NcSelect,
		NcCheckboxRadioSwitch,
		ChevronRightIcon,
		ChevronDownIcon,
		HelpCircleIcon,
		ArrowRightIcon,
		NcUserBubble,
		RefreshIcon,
		EyeIcon,
		NcRichContenteditable,
	},

	props: {
		providerId: {
			type: String,
			required: true,
		},
		accessible: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			query: '',
			result: null,
			loading: false,
			models: [],
			inputPlaceholder: t('cwyd', 'What is the matter with putting pineapple on pizza?'),
			showAdvanced: false,
			includeQuery: false,
			completionNumber: 1,
			maxTokens: 1000,
			prompts: null,
		}
	},

	computed: {
		showAdvancedIcon() {
			return this.showAdvanced
				? ChevronDownIcon
				: ChevronRightIcon
		},
		previewButtonLabel() {
			return this.result !== null
				? t('cwyd', 'Regenerate')
				: t('cwyd', 'Preview')
		},
		emptyResult() {
			return this.result.trim() === ''
		},
	},

	watch: {
	},

	mounted() {
		this.focusOnInput()
		this.getPromptHistory()
	},

	methods: {
		focusOnInput() {
			setTimeout(() => {
				this.$refs['cwyd-search-input'].$el.getElementsByTagName('input')[0]?.focus()
			}, 300)
		},
		getPromptHistory() {
			const params = {
				params: {
					type: 1,
				},
			}
			const url = generateUrl('/apps/cwyd/prompts')
			return axios.get(url, params)
				.then((response) => {
					this.prompts = response.data
				})
				.catch((error) => {
					console.error(error)
				})
		},
		submit() {
			this.$emit('submit', this.result.trim())
		},
		insertPrompt(prompt) {
			if (this.prompts.find(p => p.value === prompt) === undefined) {
				this.prompts.unshift({
					id: 0,
					value: prompt,
				})
			}
		},
		generate() {
			if (this.query === '') {
				return
			}
			this.loading = true
			const params = {
				prompt: this.query,
				n: this.completionNumber,
				maxTokens: this.maxTokens,
			}
			const url = generateUrl('/apps/cwyd/query')
			return axios.post(url, params)
				.then((response) => {
					const data = response.data
					if (data.choices && data.choices.length && data.choices.length > 0) {
						this.processCompletion(data.choices)
						this.insertPrompt(this.query)
					} else {
						this.error = response.data.error
					}
				})
				.catch((error) => {
					console.error('Cwyd completions request error', error)
					showError(
						t('cwyd', 'Cwyd error') + ': '
							+ (error.response?.data?.body?.error?.message
								|| error.response?.data?.body?.error?.code
								|| error.response?.data?.error
								|| t('cwyd', 'Unknown Cwyd API error')
							)
					)
				})
				.then(() => {
					this.loading = false
				})
		},
		processCompletion(choices) {
			const answers = this.selectedModel.id.startsWith('gpt-')
				? choices.filter(c => !!c.message?.content).map(c => c.message?.content.replace(/^\s+|\s+$/g, ''))
				: choices.filter(c => !!c.text).map(c => c.text.replace(/^\s+|\s+$/g, ''))
			if (answers.length > 0) {
				if (answers.length === 1) {
					this.result = this.includeQuery
						? t('cwyd', 'Prompt') + '\n' + this.query + '\n\n' + t('cwyd', 'Result') + '\n' + answers[0]
						: answers[0]
				} else {
					const multiAnswers = answers.map((a, i) => {
						return t('cwyd', 'Result {index}', { index: i + 1 }) + '\n' + a
					})
					this.result = this.includeQuery
						? t('cwyd', 'Prompt') + '\n' + this.query + '\n\n' + multiAnswers.join('\n\n')
						: multiAnswers.join('\n\n')
				}
			}
		},
	},
}
</script>

<style scoped lang="scss">
.cwyd-picker-content-wrapper {
	width: 100%;
}

.cwyd-picker-content {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 12px 16px 16px 16px;

	h2 {
		display: flex;
		align-items: center;
	}

	.prompts {
		margin-top: 8px;
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		> * {
			margin-right: 8px;
		}
	}

	.prompt-bubble {
		max-width: 250px;
	}

	.preview {
		width: 100%;
		h3 {
			font-weight: bold;
		}
		.editable-preview {
			width: 100% !important;
			max-height: 300px !important;
		}
	}

	.spacer {
		flex-grow: 1;
	}

	.attribution {
		color: var(--color-text-maxcontrast);
		padding-bottom: 8px;
	}

	.input-wrapper {
		display: flex;
		align-items: center;
		width: 100%;
	}

	.prompt-select {
		width: 100%;
		margin-top: 4px;
	}

	.footer {
		width: 100%;
		display: flex;
		align-items: center;
		justify-content: end;
		margin-top: 12px;
		> * {
			margin-left: 4px;
		}
	}

	.advanced {
		width: 100%;
		padding: 12px 0;
		.line {
			display: flex;
			align-items: center;
			margin-top: 8px;

			input {
				width: 200px;
			}
			.model-select {
				width: 300px;
			}
		}

		input[type=number] {
			width: 120px;
			appearance: initial !important;
			-moz-appearance: initial !important;
			-webkit-appearance: initial !important;
		}

		.include-query {
			margin-right: 16px;
		}
	}
}
</style>
