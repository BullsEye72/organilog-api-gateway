# Contributing

Contributions to the Organilog API proxy are welcome: bug reports, documentation,
tests, and focused fixes all help. Please follow the [code of conduct](CODE_OF_CONDUCT.md).

## Before you start

- Search existing issues and pull requests before opening a new one.
- For a substantial feature or behavior change, open a feature request first so
  the maintainer can discuss the approach with you.
- Report suspected vulnerabilities using the [security policy](SECURITY.md),
  rather than a public issue or pull request.
- This is an independent community integration, not an official Organilog product.
  Account and upstream service problems should go through Organilog support.

## Set up a development environment

Fork the repository, clone your fork, and create a branch from `main` for your
change. Follow the [development guide](docs/development.md) for Docker or local PHP
setup. PHP requires curl, mbstring, and json; browser setup also requires sessions.
The application does not need Composer or npm dependencies.

Use fabricated credentials and disposable data for tests. Running the application
with a real account can send requests to Organilog; keep writes disabled unless
you deliberately need them in an account you are authorized to use.

## Make and check your change

- Keep each pull request focused on one problem and follow the surrounding code's
  style. Avoid unrelated formatting or generated-file changes.
- Add or update tests when you change behavior, and update documentation when
  setup, configuration, or API behavior changes.
- Keep credentials, configuration, logs, backups, and customer data out of commits,
  screenshots, and test fixtures. Review your staged diff before committing;
  `.gitignore` does not protect files that are already tracked.
- Keep private files outside `public/`. Preserve authentication, permission checks,
  TLS verification, and the default restriction on upstream writes.
- For endpoint catalog changes, follow the regeneration instructions in the
  development guide and review the generated diff. Do not edit the catalog merely
  to include your account's responses.

Run the offline PHP suite with the required PHP extensions, `proc_open`, and an
available loopback port:

```sh
php tests/run.php
```

Run the browser console checks with Node.js, without installing packages:

```sh
node tests/console_status_test.js
node tests/console_requests_test.js
```

For Docker-based PHP checks, including real Apache routing and access protection:

```sh
docker compose build
docker compose --profile test run --rm test
docker compose --profile test run --rm apache-test
```

The Docker commands cover the PHP checks; run the two Node.js checks separately
for console changes. The offline suites use disposable fixtures and do not need
live Organilog credentials. If you cannot run a relevant check, say so in your PR
and explain what you verified instead.

## Submit a pull request

Target `main`. Explain the problem, the resulting behavior, and how you checked
it. Link related issues and include sanitized screenshots for visual changes.
Mention compatibility, configuration, or deployment changes a user needs to know
about. The maintainer may request changes before merging; review and release
timelines depend on availability.

## Licensing

Contributions are accepted under the repository's existing
[Apache License 2.0 with Commons Clause License Condition v1.0](LICENSE).
This is a source-available project. By submitting a contribution for inclusion,
you agree that it may be distributed under those terms. Submit only material you
have the right to contribute, preserve applicable notices, and identify any new
third-party material and its license. See [NOTICE](NOTICE) for attribution.
