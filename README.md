# Nextcloud Assistant Context Chat

## Install
1. Install two other mandatory apps for this app to work as desired in your Nextcloud install from the "Apps" page:
- AppAPI (>= v2.0.x): https://apps.nextcloud.com/apps/app_api
- Assistant: https://apps.nextcloud.com/apps/assistant (The OCS API or the `occ` commands can also be used to interact with this app but it recommended to do that through a Text Processing OCP API consumer like the Assistant app.)
2. Install this app (Nextcloud Assistant Context Chat): https://apps.nextcloud.com/apps/context_chat
3. Install the Context Chat Backend app (https://apps.nextcloud.com/apps/context_chat_backend) from the "External Apps" page. It is important to note here that the backend app should have the same major and minor version as this app (context_chat)
4. Start using Context Chat from the Assistant UI

> [!NOTE]
> Refer to the [Context Chat Backend's readme](https://github.com/nextcloud/context_chat_backend/?tab=readme-ov-file) and the [AppAPI's documentation](https://cloud-py-api.github.io/app_api/) for help with setup of AppAPI's deploy daemon.
> Please open an issue if you need help :)
