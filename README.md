<!--
  - SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Nextcloud Assistant Context Chat

[![REUSE status](https://api.reuse.software/badge/github.com/nextcloud/context_chat)](https://api.reuse.software/info/github.com/nextcloud/context_chat)

![](https://raw.githubusercontent.com/nextcloud/context_chat/main/img/Logo.png)

## Install

See [the Admin docs](https://docs.nextcloud.com/server/latest/admin_manual/ai/app_context_chat.html) for installation steps and requirements.

After a successful install, start using Context Chat from the Assistant UI.

> [!NOTE]
> Refer to the [Context Chat Backend's readme](https://github.com/nextcloud/context_chat_backend/?tab=readme-ov-file) and the [AppAPI's documentation](https://cloud-py-api.github.io/app_api/) for help with setup of AppAPI's deploy daemon.  
> See the [NC Admin docs](https://docs.nextcloud.com/server/latest/admin_manual/ai/app_context_chat.html) for requirements and known limitations.
>
> The HTTP request timeout is 30 minutes for long running requests. It can be changed with the `request_timeout` app config. The same also needs to be done for docker socket proxy/HaRP. See [Slow responding ExApps](https://github.com/cloud-py-api/docker-socket-proxy?tab=readme-ov-file#slow-responding-exapps) and `HP_TIMEOUT_SERVER` in [Environment variables in HaRP](https://github.com/nextcloud/harp#environment-variables)
>
> Please open an issue if you need help :)
