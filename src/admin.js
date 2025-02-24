import Vue from 'vue'
import App from './components/ViewAdmin.vue'

global.ContextChat = new Vue({
	el: '#context_chat',
	render: h => h(App),
})
