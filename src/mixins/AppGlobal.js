/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
export default {
	methods: {
		t,
		n,
	},
	computed: {
		colorPrimary() {
			return getComputedStyle(document.documentElement).getPropertyValue('--color-primary')
		},
		colorPrimaryLight() {
			return getComputedStyle(document.documentElement).getPropertyValue('--color-primary-light')
		},
		colorPrimaryElement() {
			return getComputedStyle(document.documentElement).getPropertyValue('--color-primary-element')
		},
		colorPrimaryElementLight() {
			return getComputedStyle(document.documentElement).getPropertyValue('--color-primary-element-light')
		},
		colorPrimaryText() {
			return getComputedStyle(document.documentElement).getPropertyValue('--color-primary-text')
		},
		colorMainText() {
			return getComputedStyle(document.documentElement).getPropertyValue('--color-main-text')
		},
		colorMainBackground() {
			return getComputedStyle(document.documentElement).getPropertyValue('--color-main-background')
		},
		colorPlaceholderDark() {
			return getComputedStyle(document.documentElement).getPropertyValue('--color-placeholder-dark')
		},
	},
}
