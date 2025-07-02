/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { createApp } from 'vue'
import App from './components/ViewAdmin.vue'
import AppGlobal from './mixins/AppGlobal.js'

const app = createApp(App)
app.mixin(AppGlobal)

app.mount('#context_chat')
