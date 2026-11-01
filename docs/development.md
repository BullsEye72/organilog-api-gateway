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
node tests/catalog_test.mjs
node tests/catalog_sync_test.mjs
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
# Check for changes without overwriting the bundled catalog:
node bin/build-catalog.mjs --check
node bin/build-catalog.mjs --check collection.json
```

The builder reads Organilog's public Postman collection; it never calls the account
API. Review the generated diff before committing. Historical `verified_live` flags
describe development checks, not validation against every installation or future API
version. Endpoint notes document known documentation discrepancies and uncertainties.

### List functions without browsing documentation

Organilog publishes its v3 requests as a machine-readable [Postman collection](https://documenter.gw.postman.com/api/collections/2071554/UzJFxK4j).
The builder recursively reads this collection and extracts routes, samples, and
descriptions. No Organilog token is needed to download it. `--check` returns a
nonzero exit code when the bundled catalog differs, ignoring its generation time.
The maintainer workflow below runs this comparison periodically. The offline CI
suite does not depend on an external documentation service.

Each endpoint's `documentation_url` links to the corresponding official request
using Postman's published request ID. The console displays **Official documentation**
beside the route title and opens it in a new tab. Collections without request IDs
fall back to the main documentation. Local discrepancy notes remain visible.

The gateway's authenticated `GET /v1/catalog` lists the functions bundled with
that deployment, including each route's `kind` and the current key's `allowed`
flag. It is not an upstream discovery endpoint. A collection comparison verifies
coverage of the published API; it cannot establish whether undocumented functions
exist. The standard upstream OpenAPI/Swagger JSON locations checked during the
[route audit](api-route-audit.md) returned 404.

The action `custom_field/new` creates a field definition; `custom_field/create`
upserts field values. Both require create permission and the server write switch.
Unknown actions now stop generation until their permission kind is explicitly
classified, and duplicate routes stop generation rather than being discarded.

### Maintainer catalog sync

The [Catalog sync workflow](../.github/workflows/catalog-sync.yml) runs every
Monday at **07:23 UTC**. You can also start it from **Actions → Catalog sync →
Run workflow**. Scheduled runs start once the workflow is on the repository's
default branch and Actions is enabled. GitHub can delay scheduled runs; the
summary's actual check timestamp is the source of truth.

Each run compares the latest public Postman collection with `data/catalog.json`.
The Actions summary shows the check date, bundled/published endpoint counts, new
and removed routes, and changed endpoint or catalog fields. Documentation links,
descriptions, examples, and permission kinds are included in the comparison.
Generation timestamps and JSON property/route ordering are ignored.

The **catalog-sync** artifact is retained for 30 days and contains:

- `report.md`: the human-readable summary.
- `report.json`: detailed before/after values for changed fields.
- `catalog.candidate.json`: a proposed catalog, only when differences were found.

The run succeeds when current, fails with exit 1 when changes need review, and
fails with exit 2 when the check cannot determine sync status (download/format
failure, empty collection, or an action requiring permission classification).
Failures also retain the report. An unsupported action must be classified in the
builder before a candidate can be generated. Report states describe published
coverage, not live endpoint availability.

Run the same check locally without any Organilog credentials:

```sh
node bin/check-catalog.mjs
# Work offline from an already downloaded collection:
node bin/check-catalog.mjs --collection collection.json
# Choose a different private report directory:
node bin/check-catalog.mjs --report-dir backup/catalog-sync
```

Local reports default to gitignored `backup/catalog-sync/`. Review the report and
candidate, regenerate using `node bin/build-catalog.mjs`, inspect the diff, and
run the offline checks before opening a PR. Neither the command nor workflow
updates the bundled catalog, opens a PR, or deploys changes. The workflow has
read-only repository permissions and requires no account secrets. It is separate
from the required PR/release CI so an upstream documentation outage does not
block normal development.

### Audit a deployed gateway

Set `ORGANILOG_AUDIT_URL` to the gateway's HTTPS origin and
`ORGANILOG_AUDIT_KEY` through your environment or secret manager, then run:

```sh
node bin/audit-routes.mjs audit-report.json
# Check local write-method guards without calling Organilog:
node bin/audit-routes.mjs guard-report.json --guards-only
```

The script reads one page from list routes, uses IDs returned by that account for
record reads, and checks output types, field names, counts, and error codes. It
never sends an upstream write. Write routes are checked using GET rejection;
their actual write responses require a separate test account with disposable
data. Routes without a matching record use ID 0, so a response does not establish
that an existing record can be retrieved. It stops upstream requests on connection
failure or cooldown and continues local write-method checks. Rerun after recovery.
`ORGANILOG_AUDIT_SKIP` optionally excludes comma-separated route IDs.

Reports contain no response values or credentials, but may contain custom field
names. Store them privately (for example, under gitignored `backup/`) and do not
commit account-specific data. The script exits nonzero on failures or untested
routes, including the reads intentionally omitted by `--guards-only`.

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
