# Development

For installation on a webserver, follow the [setup and usage guide](../README.md).
The instructions below are for working on the project locally.

## Docker: local Apache and HTTPS

Install and start Docker Desktop (Linux containers), or Docker Engine with Compose.
No local PHP installation is needed. On Windows, the WSL2 backend requires hardware
virtualization enabled in BIOS/UEFI and the Virtual Machine Platform Windows feature.
If Docker reports `HCS_E_HYPERV_NOT_INSTALLED`, follow
[Microsoft's virtualization guide](https://support.microsoft.com/en-us/windows/experience/enable-virtualization-on-windows)
and restart the computer before running the containers.

From the project root:

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

## Local PHP server

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


## Unattended setup

Set `ORGANILOG_SETUP_ACCOUNT` and `ORGANILOG_SETUP_TOKEN` using your deployment
environment or secret manager, then run `php bin/setup.php`. Each variable replaces
the corresponding prompt. Unset them afterwards; the generated config is used at
runtime. Treat the command's output as a secret because it contains the admin key.

```sh
php bin/setup.php --help
```

## Project layout and checks

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

## CI and releases

The [CI workflow](../.github/workflows/ci.yml) runs on pull requests and pushes to
`main`, and can also be started from the repository's **Actions** tab. It uses
GitHub-hosted Ubuntu runners; no self-hosted runner or repository secrets are
required. Enable GitHub Actions in the repository if it is disabled.

The workflow checks PHP syntax on 8.1 and 8.4, builds an installable ZIP, and runs
the offline PHP suite against its extracted application files. Tests are copied
into that temporary installation solely for verification. Separate jobs run both
Node.js checks with Node 24 and build the Docker image to run the PHP and real
Apache/HTTPS suites. All suites use disposable data and dummy credentials.

After the first successful run, configure a branch ruleset for `main` requiring
the **CI passed** status check before merging. That check fails if any test job
fails, is cancelled, or is skipped. Workflow actions are pinned to commit hashes;
Dependabot opens weekly grouped update PRs for them.

To inspect a build, download **release-package** from its Actions run. It contains
an installable ZIP and a SHA-256 checksum, retained for seven days. You can also
build the last committed revision locally with Git, Bash, unzip, and sha256sum
(available on Linux or Git Bash on Windows):

```sh
bash .github/scripts/package-release.sh dev
cd dist
sha256sum --check organilog-dev.zip.sha256
```

The script uses an explicit list of tracked application files from `HEAD`.
Uncommitted changes, instance configuration, runtime data, tests, CI files, and
the local Docker environment are excluded. The ZIP includes `organilog/public/`
and both hidden `.htaccess` files, plus the CLI tools, catalog, hosting example,
documentation, and license notices. Development documentation refers to tools
available in the full source repository.

To publish a stable release, merge the intended changes to `main`, check that CI
passes, then tag that commit and push the tag. For example, from an up-to-date,
clean checkout of `main`, replacing the version with the next release:

```sh
git tag -a v1.0.0 -m "Release v1.0.0"
git push origin v1.0.0
```

Tags must use `vMAJOR.MINOR.PATCH`; other `v*` tags fail packaging. The
[release workflow](../.github/workflows/release.yml) reruns the complete CI workflow
on the tagged commit. Only after every check succeeds does it publish a GitHub
Release with generated notes, the tested ZIP, and its checksum. Publishing uses
the built-in `GITHUB_TOKEN`; only that job has `contents: write` permission. Create
a new version tag for subsequent releases rather than moving an existing tag.

Deployment stays manual: use the release ZIP with the
[installation guide](../README.md) or the
[update instructions](administration.md#updating-an-existing-installation).
Preserve the existing `config.php` and all of `var/` when updating.

## Publishing source code

Before your first Git push, review the files being committed. `.gitignore` excludes
the local config, its backups, runtime data, environment files, and private key
files. Commit `config.example.php` and `data/catalog.json`; each user supplies their
own credentials after cloning. An ignore rule does not remove secrets that were
already tracked or committed in another repository.
