<!--
  - SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Nextcloud Assistant Context Chat

[![REUSE status](https://api.reuse.software/badge/github.com/nextcloud/context_chat)](https://api.reuse.software/info/github.com/nextcloud/context_chat)

![](https://raw.githubusercontent.com/nextcloud/context_chat/main/img/Logo.png)

## Install
1. Install two other mandatory apps for this app to work as desired in your Nextcloud install from the "Apps" page:
- AppAPI (>= v2.0.x): https://apps.nextcloud.com/apps/app_api
- Assistant: https://apps.nextcloud.com/apps/assistant (The OCS API or the `occ` commands can also be used to interact with this app but it recommended to do that through a Text Processing OCP API consumer like the Assistant app.)
2. Install this app (Nextcloud Assistant Context Chat): https://apps.nextcloud.com/apps/context_chat
3. Install the Context Chat Backend app (https://apps.nextcloud.com/apps/context_chat_backend) from the "External Apps" page. It is important to note here that the backend app should have the same major and minor version as this app (context_chat)
4. Start using Context Chat from the Assistant UI

> [!NOTE]
> Refer to the [Context Chat Backend's readme](https://github.com/nextcloud/context_chat_backend/?tab=readme-ov-file) and the [AppAPI's documentation](https://cloud-py-api.github.io/app_api/) for help with setup of AppAPI's deploy daemon.  
> See the [NC Admin docs](https://docs.nextcloud.com/server/latest/admin_manual/ai/app_context_chat.html) for requirements and known limitations.
>
> The HTTP request timeout is 50 minutes for all requests except deletion requests, which have 3 seconds timeout. The 50 minutes timeout can be changed with the `request_timeout` app config. The same also needs to be done for docker socket proxy (if you're using that). See [Slow responding ExApps](https://github.com/cloud-py-api/docker-socket-proxy?tab=readme-ov-file#slow-responding-exapps)  
>
> Please open an issue if you need help :)
