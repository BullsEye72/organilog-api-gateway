# Organilog API proxy

**English** | [Français](README.fr.md)

A self-hosted PHP proxy and browser console for the Organilog v3 API. Connect your
Organilog account once, then give each app or automation its own API key, permissions,
and request limit. Your Organilog token stays on your server.

The console lets you browse endpoints, try requests, view request history, and
manage integration keys. No database or dependency installation is needed.

[Download a release](https://github.com/BullsEye72/organilog-api-gateway/releases)
· [Install](#install-on-your-webserver)
· [Hosting guide](docs/administration.md)
· [Development](docs/development.md)

![Organilog API console in dark mode, showing the searchable endpoint catalog and a sample ticket request](docs/screenshots/console-sanitized.png)

*Browse endpoints, edit JSON requests, and see Organilog availability in one console.*

- **Explore the API.** Start from request samples, read endpoint notes, and copy
  requests as curl commands.
- **Control each integration.** Give every tool its own key, permissions, and
  request limit.
- **Keep track of activity.** Check upstream availability and revisit requests
  in your browser's history.

## What you need

- A webserver with **PHP 8.1 or newer**, the **curl**, **mbstring**, and **json**
  extensions, and PHP sessions enabled.
- A domain or subdomain with **HTTPS**.
- Your Organilog account name and API token.
- Access to your hosting file manager or an FTP client.

Your hosting must let you point the domain's **document root** (the folder served
to visitors) at the project's **`public/`** folder. Use a dedicated domain or
subdomain, such as `organilog.example.com`; installation under a URL path such as
`example.com/organilog` is not supported.

## Install on your webserver

### 1. Upload the files

Download the installable **`organilog-vX.Y.Z.zip`** asset from a
[GitHub Release](https://github.com/BullsEye72/organilog-api-gateway/releases),
or download this repository. Extract it, then upload the project to your hosting,
including the hidden `.htaccess` files. Set your domain's document root to the
project's **`public/`** folder and enable HTTPS.

For example, if the project is in `/home/you/organilog`, the document root must be
`/home/you/organilog/public`. Keep the other project files outside the public folder.

On **Apache 2.4**, enable `mod_rewrite` and allow the supplied `.htaccess` rules
(`AllowOverride All`). On **Nginx**, adapt the included
[configuration example](deploy/nginx.conf). Ask your host if you need help with
these settings.

PHP needs permission to create `config.php` and the `var/` folder beside `public/`,
and to write to `var/` afterwards. It must also be able to make outbound HTTPS
requests to Organilog.

### 2. Run the setup wizard

1. Open **`https://your-domain/setup.php`** in your browser.
2. In your hosting file manager or FTP client, open **`var/setup-key.txt`**. The
   wizard creates this file when you first visit it. Paste its code into the wizard
   to confirm ownership.
3. Choose an application name and, optionally, a logo.
4. Enter your Organilog account name and API token. For `mycompany.organilog.com`,
   enter **`mycompany`** as the account name.
5. Click **Test Organilog connection**, then confirm and click **Install Organilog API**.
6. **Save the admin key in your password manager. It is shown only once.**

Setup saves your configuration and locks itself. If it asks you to remove
`public/setup.php` manually, do so using your file manager.

### 3. Create your first integration key

1. Open your domain, paste the admin key into the **API key** field, and click **Connect**.
2. Open **Keys**, then click **New key**.
3. Give the key a name, choose the permissions it needs, and set its request limit.
   Start with read access to the entities your integration uses.
4. Click **Save** and copy the new key into your integration's configuration.
   This key is also shown only once.

Use a separate key for each integration. The admin key lets you manage those keys;
keep it for administration.

![API key management showing per-integration permissions, request limits, usage, and key controls; key names and prefixes are blurred](docs/screenshots/api-keys.png)

*Manage permissions and request limits per integration, with usage and last-used
details at a glance. Names and key prefixes are blurred in this screenshot.*

## Use the console and API

In **Console**, pick an endpoint from the list, read its notes, and edit the sample
request with values from your account. Click **Send** to see the response, or
**Copy as curl** to use the request elsewhere. **History** shows recent requests
from that browser.
**Official documentation** opens the selected endpoint's Organilog documentation
in a new tab.

Your integrations call your own domain and send their key in an
`Authorization: Bearer ...` header. For example, this read request lists tickets:

```sh
curl 'https://organilog.example.com/v1/issue/read_all?page_nbr=1' \
  -H 'Authorization: Bearer YOUR_INTEGRATION_KEY'
```

Replace the domain and key with your own. The proxy adds the Organilog account and
token automatically. The response's `data` field contains Organilog's answer.
The console includes endpoint documentation and request samples.

For a machine-readable list of this installation's available functions, call
**`GET /v1/catalog`** with the same authorization header. Each endpoint includes
its operation kind and whether your key is allowed to use it. See the
[development guide](docs/development.md#list-functions-without-browsing-documentation)
for checking coverage against Organilog's public Postman collection.

### Enable changes to Organilog

New installations allow reads only. To enable creates, updates, and deletes, open
the private **`config.php`** file and change **`'allow_writes' => false`** to
**`'allow_writes' => true`**. Each integration key must also have permission for the
operation. Requests from the console use your real Organilog account.

## Maintain your installation

- Back up **`config.php` and all of `var/`** securely. They contain your credentials,
  key settings, usage records, and logs.
- When updating, preserve those files and replace the application source. See the
  [update instructions](docs/administration.md#updating-an-existing-installation),
  including the pause required when upgrading from older file locking code.
- Manage, disable, or rotate integration keys from **Keys**. For a lost admin key,
  follow the [admin key recovery instructions](docs/administration.md#configuration-and-administration).

For hosting issues, connection errors, or more settings, see the
[hosting and administration guide](docs/administration.md).
To work on the code locally, see the [development guide](docs/development.md).

## License and attribution

Copyright 2026 Anthony Jaspers. Licensed under **Apache License 2.0 with the Commons
Clause License Condition v1.0**. This is a source-available license with a restriction
on sales; see [LICENSE](LICENSE) for the complete terms and [NOTICE](NOTICE) for
attribution. The software is provided as is, without warranty.

This is an independent community integration developed with Organilog's permission,
not an official Organilog product. Organilog's services and API remain subject to
their own terms.
