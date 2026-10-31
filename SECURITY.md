# Security policy

## Supported code

Security fixes are made on the default branch, `main`. Older commits and forks do
not receive separate maintenance. When reporting a problem, include the affected
commit or release and whether it also occurs on the current default branch.
Support and response times depend on maintainer availability.

## Report a vulnerability privately

Please do not publish suspected vulnerabilities, exploit details, or sensitive
data in issues, discussions, or pull requests.

Use the repository's **Security > Advisories > Report a vulnerability** option:

[Start a private vulnerability report](https://github.com/BullsEye72/organilog-api-gateway/security/advisories/new).

This option requires the maintainer to enable GitHub private vulnerability
reporting. If it is unavailable, check the
[maintainer's GitHub profile](https://github.com/BullsEye72) for a private contact
method. If none is listed, open an issue asking only for a private security contact
or for private reporting to be enabled. Do not include vulnerability details in
that public request.

In the private report, include:

- The affected commit or release, PHP version, and web-server/deployment details.
- The affected component and any authentication or permissions needed.
- Minimal reproduction steps using fabricated data, and expected versus actual
  behavior.
- The possible impact and any mitigation or fix you have identified.

Never send real API tokens, admin or integration keys, configuration files,
unredacted logs, or customer records. Test only installations and accounts you own
or have explicit permission to assess. Reports about Organilog's own service
should go to Organilog through its reporting channels.

The maintainer will assess the report, discuss reproduction and remediation
privately, and coordinate disclosure with the reporter where possible. Please
allow time for a fix and affected users to update before publishing details.

## Operate your installation securely

Serve only `public/` over HTTPS. Keep `config.php`, `var/`, and backups private,
use narrowly scoped integration keys, and keep PHP and your web server updated.
If a credential is exposed, revoke or rotate it promptly; deleting a public post
or commit does not invalidate it. See the
[administration guide](docs/administration.md) for deployment and recovery details.
