# Organilog API proxy

A self-hosted PHP proxy and browser console for the Organilog v3 API. Run your own
instance with your own Organilog account. The account token stays on the server;
each integration gets a separate API key with entity permissions and rate limits.

- Browser console with endpoint documentation, request samples, and request history.
- Admin console for creating, editing, disabling, rotating, and deleting tool keys.
- Read/create/update/delete permissions per entity, plus a server-wide write switch.
- File-based key storage, usage counters, and audit logs. No database or Composer install.

## License

Copyright 2026 Anthony Jaspers. The project's original code is distributed under
the **Apache License 2.0 with Commons Clause License Condition v1.0**. The complete
combined terms are in [LICENSE](LICENSE); attribution information is in
[NOTICE](NOTICE). This is a source-available license with a restriction on sales.

You may use and modify the project, including for your company's internal
operations, and redistribute it subject to the license and notice requirements.
The Commons Clause prohibits selling a product or service whose value derives
entirely or substantially from this software. That includes reselling the proxy
and charging for hosted access, and can include paid consulting or support built
around it. It is not a blanket ban on commercial products that add substantial
independent value. See the [Commons Clause text and FAQ](https://commonsclause.com/)
for the scope of the restriction; the complete LICENSE governs.

When creating the GitHub repository, leave the license picker empty and commit
the included LICENSE and NOTICE files. Do not replace them with the plain Apache
2.0 template: that would omit the Commons Clause restriction. GitHub's automatic
license label may not identify this combined license accurately.

The software is provided **as is**, without a warranty of security, fitness, or
uninterrupted operation. The license's warranty disclaimer and limitation of
liability apply subject to applicable law and their stated exceptions; they do
not guarantee immunity from every data-leak claim. If you self-host, you manage
the deployment's credentials, access permissions, HTTPS, backups, and data
handling. No hosting or security service is provided by publishing this code.

This is an independent community integration developed with Organilog's permission,
not an official Organilog product or a security certification. Organilog's API,
documentation, trademarks, and services remain subject to their own rights and
terms; the project license does not grant rights in third-party materials.

## Requirements

- PHP **8.1 or newer** with **curl**, **mbstring**, and **json** enabled.
- Your own Organilog API account name (subdomain) and API token.
- PHP must be able to write to `var/` and reach the configured Organilog HTTPS API.
- Production: HTTPS hosting with Apache 2.4 + `mod_rewrite`, or Nginx + PHP-FPM.
  The document root must point to **`public/`**. Install at the root of a dedicated
  domain/subdomain; URL subdirectory installations are not supported.

Node.js is optional, used only to rebuild the bundled endpoint catalog. The regular
application needs neither Node.js nor npm.

## Browser setup (recommended)

1. Upload the project, including hidden `.htaccess` files. Point your domain's
   document root at **`public/`** and enable HTTPS in your hosting panel.
2. Open **`https://your-domain/setup.php`**. The console also takes you there when
   no configuration exists. `bin/setup.php` is the optional terminal installer;
   it cannot be opened in a browser.
3. Using your hosting file manager or FTP app, open **`var/setup-key.txt`** beside
   the `public/` folder. Paste its code into the wizard to confirm ownership.
4. Choose an application name and an optional PNG, JPEG or WebP logo (up to 2 MB).
5. Enter your Organilog account name (the subdomain, without `.organilog.com`)
   and master API token. Click **Test Organilog connection**.
6. Confirm and click **Install Organilog API**. Copy the admin key shown on the
   success page into your password manager, then open the console.

The wizard tests Organilog with a read-only account request, checks file writes
and locks, and saves your configuration and logo. Keys, permissions, usage, and
audit logs stay in private files under `var/`. **No SQL server, database
credentials, or schema migrations are needed.** Browser setup additionally needs
PHP sessions enabled.

On success the wizard removes its installation code, locks setup permanently, and
attempts to delete `public/setup.php`. If hosting permissions prevent deletion,
it tells you to remove that file manually; configuration and the persistent lock
still prevent reinstallation. Uploading `setup.php` again does not reset an instance.
Do not remove `var/installed.lock` or `config.php` to recover an admin key; follow
the recovery instructions below.

### If Organilog connection testing fails

The wizard shows the HTTP status and Organilog's error message, with your token
redacted. An account/token rejection means you should check the values in your
Organilog API settings. HTTP 429 means its request limit was reached; 5xx means
an upstream server problem. An HTML response can indicate an upstream or hosting
filter. The wizard does not display entire response bodies or account records.

Transport failures include a cURL error number and a next step: DNS lookup,
outbound port 443 access, timeout, or TLS certificate verification. For certificate
errors, ask your host to update PHP/cURL's trusted CA certificates and `curl.cainfo`.
Certificate verification remains enabled. See the
[PHP cURL configuration reference](https://www.php.net/manual/en/curl.configuration.php).

## Docker: local Apache and HTTPS

Install and start Docker Desktop (Linux containers), or Docker Engine with Compose.
No local PHP installation is needed. On Windows, the WSL2 backend requires hardware
virtualization enabled in BIOS/UEFI and the Virtual Machine Platform Windows feature.
If Docker reports `HCS_E_HYPERV_NOT_INSTALLED`, follow
[Microsoft's virtualization guide](https://support.microsoft.com/en-us/windows/experience/enable-virtualization-on-windows)
and restart the computer before running the containers.

From this directory:

```sh
docker compose build
docker compose --profile test run --rm test
docker compose --profile test run --rm apache-test
docker compose up -d
```

Open **https://localhost:8443/setup.php** and accept the local self-signed certificate.
Read its ownership code with `docker compose exec app cat var/setup-key.txt`,
then follow the browser wizard. The certificate is generated when the web
container starts. This Docker configuration is for local testing; use a trusted
certificate and appropriate hosting configuration for public deployment.

The `test` service runs the offline installation/API suite as the web user. The
`apache-test` service starts real Apache and checks HTTPS, redirects, private-file
access, setup as the web user, authentication, and key creation. Both services have
external networking disabled and use disposable fixtures, without mounting your
instance data or requiring live credentials. The app service runs Apache with the real
`public/.htaccess` rules, PHP 8.4, and the required extensions. Its HTTPS port is
bound to this computer's loopback interface only. The container health check tests
that the console loads; use setup's `--check` to check the instance configuration.

Browser setup requires a successful Organilog connection check. For an offline
disposable demo, the optional CLI installer accepts dummy credentials:
`docker compose exec --user www-data app php bin/setup.php`. The catalog and key
administration then work offline. Actual API calls require valid credentials.
Writes default to disabled.

Docker stores the config and runtime state in the **`organilog-state` named volume**:
`/var/www/organilog/var/config.php`, `keys.json`, `usage/`, and `logs/`. Setup runs as
`www-data`, so Apache can read and update those files. The local project's
`config.php` and `var/` are not mounted or copied into the image. Back up the volume
when it contains real configuration or keys. `docker compose down` stops/removes
the containers and preserves the volume; adding `--volumes` deletes that state.

Source files are copied into the image. After changing source code, rebuild with
`docker compose up -d --build`; the instance data remains in its volume. Inspect
startup errors with `docker compose logs app`.

## Quick start with local PHP

From the project directory:

```sh
php bin/setup.php
php bin/setup.php --check
php -S 127.0.0.1:8080 -t public bin/router.php
```

Setup asks for your Organilog account and token, creates `config.php` and `var/`, and
prints a new **admin key once**. Save that key in your password manager. Only its
SHA-256 hash is saved. Interactive token input is visible in the terminal; use the
environment variables below if you need to avoid echoing it.

Open **http://127.0.0.1:8080**, connect with the admin key, and open **Keys** to create
a key for each integration. Give each integration only the permissions it needs.
Writes to Organilog start disabled; set `allow_writes` to `true` in `config.php`
when you are ready. Admin key management works while upstream writes are disabled.

The built-in PHP server is for local development, bound to loopback. Requests sent
through it still reach your configured Organilog account. Use HTTPS hosting for
production.

Setup never overwrites an existing `config.php` or resets tool keys. `--check`
checks PHP extensions, config values, the catalog, and runtime file writes/locks.
It does **not** contact Organilog, verify the token, test TLS, or verify web-server
configuration. Connect to the console and call the `account` endpoint to test
the upstream credentials with a read request.

### Unattended setup

Set `ORGANILOG_SETUP_ACCOUNT` and `ORGANILOG_SETUP_TOKEN` using your deployment
environment or secret manager, then run `php bin/setup.php`. Each variable replaces
the corresponding prompt. Unset them afterwards; the generated config is used at
runtime. Treat the command's output as a secret because it contains the admin key.

```sh
php bin/setup.php --help
```

### Shared hosting / no terminal on the server

1. Copy `config.example.php` to `config.php` and fill in `organilog.account` and
   `organilog.token`.
2. Run `php bin/keygen.php` on a machine with PHP. Alternatively, with Node.js:
   ```sh
   node -e "const c=require('crypto');const k='olk_'+c.randomBytes(24).toString('hex');console.log(k);console.log(c.createHash('sha256').update(k).digest('hex'))"
   ```
3. Save the raw key in your password manager and put only its SHA-256 hash in
   `admin_key_sha256` in `config.php`.
4. Upload the project, including hidden `.htaccess` files. Set the domain's document
   root to `organilog/public` and enable HTTPS. Keep `config.php` private, readable
   by PHP, and outside that document root.
5. Create a writable `var/` directory if PHP cannot create it. Its `usage/` and
   `logs/` subdirectories are created automatically.
6. Open the console over HTTPS, connect, and create your integration keys.

## Production hosting

Upload or clone the application outside the public document directory, configure
your web root to its `public/` folder, and run setup as the PHP service user (or
grant that user access to the generated files). On POSIX systems setup creates
`config.php` with owner-only permissions and runtime directories with mode `0750`.
If your deployment and PHP users differ, adjust ownership/group permissions so PHP
can read the config and write the runtime files. Do not use world-writable permissions.
Use local persistent storage supporting `flock`; multiple instances with separate
`var/` directories do not share keys or rate limits.

**Apache:** enable `mod_rewrite` and allow the directives in `public/.htaccess`
(`AllowOverride All` for this directory). The included rules route `/v1/*` to
`api.php`, preserve Authorization headers with PHP-FPM, and redirect HTTP to HTTPS.
The root `.htaccess` denies access to non-public project files as a safety net.

**Nginx:** `.htaccess` files have no effect. Adapt [deploy/nginx.conf](deploy/nginx.conf)
with your domain, certificate paths, application path, and PHP-FPM socket.

If HTTPS terminates at a reverse proxy, configure that trusted proxy to overwrite
`X-Forwarded-Proto` with the actual client scheme. Prevent direct access to the
backend. The Apache redirect rules recognize that header for TLS termination.

After deployment, check that `/` loads, `/v1/catalog` without a key returns 401,
and `/config.php`, `/var/keys.json`, `/src/`, and `/.git/config` are inaccessible.
Run `php bin/setup.php --check` using the same PHP version, extensions, and file
permissions as the web application. CLI and web PHP configurations can differ.

## Configuration and administration

All server settings live in the gitignored `config.php`. The optional
`ORGANILOG_CONFIG_FILE` environment variable selects a different private file path;
Docker uses it to persist config in its data volume. Setup, diagnostics, and the
API all use that same path. A custom parent directory must already exist and be
writable by the setup user.

| Setting | Purpose | Fresh-install default |
| --- | --- | --- |
| `organilog.account`, `organilog.token` | Your upstream account credentials | Required during setup |
| `organilog.base_url` | Upstream HTTPS API URL | `https://sync.organilog.com/api/v3` |
| `organilog.timeout` | Per-attempt upstream timeout in seconds (1–300) | `30` |
| `admin_key_sha256` | Hash of the key allowed to manage integrations | Generated by setup |
| `allow_writes` | Allow upstream creates, updates, and deletes | `false` |
| `verify_updates` | Snapshot and re-read updated records to detect fields lost | `true` |
| `log_write_bodies` | Include write request bodies in audit logs | `false` |

Use the console's **Keys** tab for normal administration. To recover a lost admin
key or rotate it, run `php bin/keygen.php` and replace `admin_key_sha256` in
`config.php` with the new hash. The old admin key stops working; integration keys
and their usage remain intact. Do not delete the configuration or rerun setup to
rotate a key.

When creating or editing an integration key, **All permissions** grants read,
create, update, and delete access across every entity. These keys display a compact
**All permissions** label in the list, including existing keys with all four `*`
permissions. Clear the checkbox to edit individual permissions. Full API access
still follows the server's `allow_writes` setting; key management requires the
separate admin key.

Audit entries are stored in `var/logs/audit-YYYY-MM.jsonl`. Enabling write-body
logging records customer data in those files. Console request history also stores
request bodies in the browser's localStorage; clear **History** when appropriate.
Back up `config.php` and `var/` securely. They contain credentials, key hashes,
usage records, and logs. Restore them together to preserve your instance.

## Calling the API

```text
GET  /v1/issue/read_all?page_nbr=1
POST /v1/issue/read_one   {"code_id":"12345"}
POST /v1/issue/update     {"id":"123","data":{"status":"5"}}
GET  /v1/catalog
```

IDs above and samples in the catalog are illustrative; replace them with records
from your own account. Send the integration key as `Authorization: Bearer olk_…`
or `X-Api-Key`. Never put keys in query strings.

```sh
curl 'https://organilog.example.com/v1/catalog' \
  -H "Authorization: Bearer $ORGANILOG_API_KEY"
```

The request body follows Organilog's API, without `account` or `token`; the proxy
supplies those server-side. GET query parameters become the upstream body for reads.
Upstream writes require POST and both the global switch and the caller's permissions.
Add `?normalize=1` to convert known empty sentinels (`0000-00-00`, `"0"` foreign
keys, `"None"`) into `null` and add `status_label` on tickets.

Successful proxy responses contain `ok`, `endpoint`, `kind`, `data`, and `meta`.
`data` contains the upstream answer, with optional normalization applied. Errors
contain `ok: false` and `error` with a `code` and `message`.

Updates can cost two extra upstream reads for verification. If a previously filled
field becomes empty unexpectedly, the proxy attempts to restore the previous
record and reports `fields_lost`. This is a best-effort safeguard, not a database
transaction. Automatic read retries are disabled by default; `retry_reads` can
enable one retry on a connection failure when no cooldown is active. Writes are
never retried because a timed-out write may have succeeded. Delete behavior may archive records;
consult the bundled endpoint notes and your account's behavior before using it.

## Organilog availability and cooldowns

The console shows shared Organilog availability and refreshes every 10 seconds
while visible. `GET /v1/status` requires an API key, reads local state only, and
does not spend that key's request budget or call Organilog. Status describes this
instance's observations; it is not a global service monitor or proof of account
access. After five minutes without a request, old healthy/degraded observations
become unknown.

Two availability failures within 60 seconds pause further upstream requests for
60 seconds by default. Connection failures, HTTP 5xx replies, and successful HTTP
responses containing invalid JSON, HTML, or scalars count as failures. HTTP 429
and 503 responses with `Retry-After` pause traffic immediately; longer retry delays
are honoured. Ordinary client errors do not trigger an availability cooldown.

During a pause, proxy requests return HTTP 503 with `error.code: upstream_cooldown`,
`error.retry_after`, and a `Retry-After` header. Key administration and local status
remain available. Once the pause expires, one incoming request checks recovery;
other callers wait until that request succeeds or the pause restarts. Polling
status never sends a recovery request. Writes are never automatically retried.

Settings under `organilog` in `config.php` are optional:

| Setting | Default | Purpose |
| --- | --- | --- |
| `connect_timeout` | `5` | Connection timeout in seconds |
| `timeout` | `30` | Total timeout for each upstream attempt |
| `retry_reads` | `false` | Allow one retry of a failed read connection |
| `failure_threshold` | `2` | Failures within 60 seconds before pausing |
| `cooldown_seconds` | `60` | Minimum pause before one recovery request |

State lives in **`var/upstream-health.json`** under file locks and is shared by PHP
workers using that folder. Files from the older version remain compatible. Deploy
the updated `src/` and `public/` folders while preserving `config.php` and `var/`;
existing configs automatically receive the defaults, and setup does not need to
run again. Keep PHP write access to the imported health file.

Malformed successful responses return HTTP 502 `upstream_invalid_response`; they
are never retried. For writes, `write_outcome: unknown` means the request may have
succeeded and should be reconciled before retrying. If an acknowledged update's
verification read fails, the proxy keeps the update result and reports
`meta.write_outcome: acknowledged` with verification `unknown`. A failed or
unconfirmed restoration still reports field loss.

## Updating and publishing

Deploy new source files while preserving the existing `config.php` and **all of
`var/`**. Never overwrite runtime state with a local copy. Back up the instance
before upgrading, then run `php bin/setup.php --check` and test console access.

This version uses persistent sibling `.lock` files and atomic replacement for
JSON state. When upgrading from the older code that locked the JSON file itself,
briefly pause incoming requests and let existing PHP requests finish before
deploying the updated source. The two locking methods must not run together.
Keep the `.lock` files in `var/`; do not delete them while the app is running.

Before your first Git push, review the files being committed. `.gitignore` excludes
the local config, its backups, runtime data, environment files, and private key
files. Commit `config.example.php` and `data/catalog.json`; each user supplies their
own credentials after cloning. An ignore rule does not remove secrets that were
already tracked or committed in another repository.

## Project layout and development

```text
bin/setup.php          First-run installer and --check diagnostics
bin/keygen.php         Generate an admin key/hash for manual setup or recovery
bin/router.php         Router for the local PHP development server
bin/build-catalog.mjs  Optional catalog regeneration from public docs
Dockerfile            PHP/Apache image for local testing
compose.yaml          Local HTTPS app and isolated offline test service
config.example.php    Portable defaults; no account credentials
data/catalog.json     Bundled endpoints, samples, and API allowlist
deploy/nginx.conf     Nginx + PHP-FPM example
deploy/docker/        Local Apache configuration and certificate entrypoint
public/               The only public web directory
public/setup.php      Browser installer; attempts to delete itself after setup
public/branding.php   Public application name and validated logo
src/                  Proxy, authentication, configuration, and storage
tests/                Offline installation and API integration checks
var/                  Private runtime state (gitignored)
```

Run the offline checks with PHP and its required extensions:

```sh
php tests/run.php
node tests/console_status_test.js
node tests/console_requests_test.js
```

The checks use an isolated temporary installation and fabricated local upstream
responses, never your local config or live Organilog account. They cover the
installer, imported health state, cooldown/recovery, response validation, update
verification failures, and passive status polling. PHP checks require `proc_open`
and a loopback port; the console status check uses Node.js without extra packages.

To regenerate the catalog with Node.js 18+:

```sh
node bin/build-catalog.mjs
# Or read an already downloaded Postman collection:
node bin/build-catalog.mjs collection.json
```

The builder reads Organilog's public Postman collection; it never calls the account
API. Review the generated diff before committing. Historical `verified_live` flags
describe development checks, not validation against every installation or future API
version. Endpoint notes document known documentation discrepancies and uncertainties.
