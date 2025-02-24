/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import Vue from 'vue'
import App from './components/ViewAdmin.vue'
import AppGlobal from './mixins/AppGlobal.js'

Vue.mixin(AppGlobal)

global.ContextChat = new Vue({
	el: '#context_chat',
	render: h => h(App),
})
