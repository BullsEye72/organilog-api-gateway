# API route audit — 1 November 2026

## Coverage and fixes

The current [public Postman JSON collection](https://documenter.gw.postman.com/api/collections/2071554/UzJFxK4j)
contains 140 requests. The checkout and deployed gateway each initially exposed
138 endpoints across 34 entities. Two published routes were missing:

- `custom_field/new`: create a custom-field definition.
- `custom_field/update`: update a custom-field definition.

The regenerated catalog contains 140 endpoints: 77 reads, 23 creates, 20 updates,
and 20 deletes. It preserves the existing correction of the collection's
`Contrat READ MULTIPLE` URL, which incorrectly points to `contact/read_multiple.php`.
Actual contract retrieval remains unverified live during this audit.

The builder previously treated every action except `create`, `update`, and
`delete` as a read. Simply regenerating would therefore have exposed
`custom_field/new` through read permission and bypassed the write switch. The
builder now explicitly classifies `new` as create and rejects unknown actions.
It also rejects duplicate routes and unsupported request URLs/methods.

Updated documentation and samples also include quote/invoice status updates and
product `type` values. These come from the public collection, not account data.
The changes are local; the audited deployment still has its 138-route catalog.

## Live output checks

Using the supplied temporary integration key, 23 read routes initially returned
HTTP 200 with valid JSON. The account output was an object; list outputs contained
`results` and pagination metadata (`count_results_total`,
`count_results_this_page`, `defined_items_by_page`, `count_pages`). Empty lists
were accepted as valid responses. Only one list page was sampled.

`media/read_all` and then `filiale/read_all` returned HTTP 502
`upstream_unreachable`. After cooldown, two separate recovery calls to `account`
also failed with cURL 28. The deployed server's upstream connection timed out;
these failures do not establish that those Organilog routes are absent or broken.
The remaining 52 read routes were not reached. Live output verification is
therefore incomplete.

All 61 deployed write routes rejected GET with HTTP 405 `method_not_allowed`.
The server reports `writes_enabled=false`. No upstream creates, updates, deletes,
or key-management changes were sent. Actual write responses remain unverified
live. No new historical `verified_live` flags were added to the catalog.

## Offline checks

- PHP 8.4: 379 installation/API checks, 208 upstream/response checks, and 23
  JSON-storage checks passed.
- Every one of the 140 catalog endpoints preserved a fabricated upstream JSON
  response. Every write route rejected GET and POST with writes disabled.
- Read-only principals were refused both new custom-field definition writes
  before contacting the fixture upstream.
- Node catalog generation/classification tests and both console suites passed.
- `node bin/build-catalog.mjs --check` against the downloaded public collection
  confirmed no catalog differences. Syntax and whitespace checks passed.

These fixture checks validate gateway behavior, not Organilog's business rules
or the existence of unpublished functions. Docker/Apache checks were not run:
the local Docker daemon was unavailable.

## Function discovery

The public Postman JSON collection is the confirmed machine-readable inventory
of published API functions. It requires no account token and is already the
builder's source. `node bin/build-catalog.mjs --check` now detects changes without
rewriting the bundled catalog. The authenticated gateway `GET /v1/catalog`
lists routes installed on that gateway, with permission kinds and `allowed` flags.

The following public upstream schema locations returned HTTP 404:

- `https://sync.organilog.com/api/v3/openapi.json`
- `https://sync.organilog.com/api/v3/swagger.json`
- `https://sync.organilog.com/openapi.json`
- `https://sync.organilog.com/swagger.json`

No runtime enumeration endpoint was confirmed. This does not prove one does not
exist; only Organilog can confirm an inventory of undocumented/account-specific
functions. Do not infer new routes from website/module names.

## Remaining verification

Deploy the reviewed catalog and builder changes, resolve or wait out the deployed
server's upstream connection failures, and rerun the private audit described in
the [development guide](development.md#audit-a-deployed-gateway). Validate actual
write responses separately using disposable data in an authorized test account.
Credentials and detailed live reports remain outside tracked files.

## Per-route results

Every row below passed the offline response-preservation check. “GET guard” only
checks that the deployed gateway refuses a write over GET; it does not validate
the Organilog write response. “Not reached” refers to live testing, not catalog
coverage.

| Route | Kind | Live result |
| --- | --- | --- |
| `account` | read | Valid JSON initially; later cURL 28 |
| `client/create` | create | GET guard: 405 |
| `client/read_one` | read | Not reached (upstream outage) |
| `client/read_all` | read | Valid JSON: 200 |
| `client/read_multiple` | read | Not reached (upstream outage) |
| `client/update` | update | GET guard: 405 |
| `client/delete` | delete | GET guard: 405 |
| `client/search` | read | Not reached (upstream outage) |
| `adresse/create` | create | GET guard: 405 |
| `adresse/read_one` | read | Not reached (upstream outage) |
| `adresse/read_all` | read | Valid JSON: 200 |
| `adresse/read_multiple` | read | Not reached (upstream outage) |
| `adresse/update` | update | GET guard: 405 |
| `adresse/delete` | delete | GET guard: 405 |
| `adresse/search` | read | Not reached (upstream outage) |
| `contact/create` | create | GET guard: 405 |
| `contact/read_one` | read | Not reached (upstream outage) |
| `contact/read_all` | read | Valid JSON: 200 |
| `contact/read_multiple` | read | Not reached (upstream outage) |
| `contact/update` | update | GET guard: 405 |
| `contact/delete` | delete | GET guard: 405 |
| `contrat/create` | create | GET guard: 405 |
| `contrat/read_one` | read | Not reached (upstream outage) |
| `contrat/read_all` | read | Valid JSON: 200 |
| `contrat/read_multiple` | read | Not reached (upstream outage) |
| `contrat/update` | update | GET guard: 405 |
| `contrat/delete` | delete | GET guard: 405 |
| `equipement/create` | create | GET guard: 405 |
| `equipement/read_one` | read | Not reached (upstream outage) |
| `equipement/read_all` | read | Valid JSON: 200 |
| `equipement/read_multiple` | read | Not reached (upstream outage) |
| `equipement/update` | update | GET guard: 405 |
| `equipement/delete` | delete | GET guard: 405 |
| `equipement_category/create` | create | GET guard: 405 |
| `equipement_category/read_one` | read | Not reached (upstream outage) |
| `equipement_category/read_all` | read | Valid JSON: 200 |
| `equipement_category/read_multiple` | read | Not reached (upstream outage) |
| `equipement_category/update` | update | GET guard: 405 |
| `equipement_category/delete` | delete | GET guard: 405 |
| `intervention/create` | create | GET guard: 405 |
| `intervention/read_one` | read | Not reached (upstream outage) |
| `intervention/read_all` | read | Valid JSON: 200 |
| `intervention/read_multiple` | read | Not reached (upstream outage) |
| `intervention/search` | read | Not reached (upstream outage) |
| `intervention/update` | update | GET guard: 405 |
| `intervention/delete` | delete | GET guard: 405 |
| `intervention_product/create` | create | GET guard: 405 |
| `intervention_product/read_all` | read | Valid JSON: 200 |
| `intervention_product/update` | update | GET guard: 405 |
| `intervention_product/delete` | delete | GET guard: 405 |
| `intervention_equipement/create` | create | GET guard: 405 |
| `intervention_equipement/read_all` | read | Valid JSON: 200 |
| `intervention_equipement/delete` | delete | GET guard: 405 |
| `intervention_group/create` | create | GET guard: 405 |
| `intervention_group/read_one` | read | Not reached (upstream outage) |
| `intervention_group/read_all` | read | Valid JSON: 200 |
| `intervention_group/read_multiple` | read | Not reached (upstream outage) |
| `intervention_group/update` | update | GET guard: 405 |
| `intervention_group/delete` | delete | GET guard: 405 |
| `intervention_issue/create` | create | GET guard: 405 |
| `intervention_issue/read_all` | read | Valid JSON: 200 |
| `intervention_issue/delete` | delete | GET guard: 405 |
| `invoicing_quote/create` | create | GET guard: 405 |
| `invoicing_quote/read_one` | read | Not reached (upstream outage) |
| `invoicing_quote/read_all` | read | Valid JSON: 200 |
| `invoicing_quote/read_multiple` | read | Not reached (upstream outage) |
| `invoicing_quote/update` | update | GET guard: 405 |
| `invoicing_quote/delete` | delete | GET guard: 405 |
| `invoicing_invoice/read_one` | read | Not reached (upstream outage) |
| `invoicing_invoice/read_all` | read | Valid JSON: 200 |
| `invoicing_invoice/read_multiple` | read | Not reached (upstream outage) |
| `invoicing_invoice/update` | update | GET guard: 405 |
| `invoicing_credit_note/read_one` | read | Not reached (upstream outage) |
| `invoicing_credit_note/read_all` | read | Valid JSON: 200 |
| `invoicing_credit_note/read_multiple` | read | Not reached (upstream outage) |
| `invoicing_credit_note/update` | update | GET guard: 405 |
| `invoice_link/read_one` | read | Not reached (upstream outage) |
| `product/create` | create | GET guard: 405 |
| `product/read_one` | read | Not reached (upstream outage) |
| `product/read_all` | read | Valid JSON: 200 |
| `product/read_multiple` | read | Not reached (upstream outage) |
| `product/update` | update | GET guard: 405 |
| `product/delete` | delete | GET guard: 405 |
| `issue/read_one` | read | Not reached (upstream outage) |
| `issue/read_all` | read | Valid JSON: 200 |
| `issue/read_multiple` | read | Not reached (upstream outage) |
| `issue/create` | create | GET guard: 405 |
| `issue/update` | update | GET guard: 405 |
| `issue/delete` | delete | GET guard: 405 |
| `issue_category/read_all` | read | Valid JSON: 200 |
| `issue_origin/read_all` | read | Valid JSON: 200 |
| `tracking/create` | create | GET guard: 405 |
| `tracking/read_one` | read | Not reached (upstream outage) |
| `tracking/read_all` | read | Valid JSON: 200 |
| `tracking_category/read_all` | read | Valid JSON: 200 |
| `universal_module/create` | create | GET guard: 405 |
| `universal_module/read_one` | read | Not reached (upstream outage) |
| `universal_module/read_all` | read | Valid JSON: 200 |
| `universal_module/read_multiple` | read | Not reached (upstream outage) |
| `universal_module/update` | update | GET guard: 405 |
| `universal_module/delete` | delete | GET guard: 405 |
| `absence/create` | create | GET guard: 405 |
| `absence/read_one` | read | Not reached (upstream outage) |
| `absence/read_all` | read | Valid JSON: 200 |
| `absence/read_multiple` | read | Not reached (upstream outage) |
| `absence/update` | update | GET guard: 405 |
| `absence/delete` | delete | GET guard: 405 |
| `media/create` | create | GET guard: 405 |
| `media/read_one` | read | Not reached (upstream outage) |
| `media/read_all` | read | 502 upstream_unreachable |
| `media/read_multiple` | read | Not reached (upstream outage) |
| `media/delete` | delete | GET guard: 405 |
| `filiale/read_all` | read | 502 upstream_unreachable |
| `user/read_one` | read | Not reached (upstream outage) |
| `user/read_all` | read | Not reached (upstream outage) |
| `user/read_multiple` | read | Not reached (upstream outage) |
| `response/create` | create | GET guard: 405 |
| `response/read_all` | read | Not reached (upstream outage) |
| `response/update` | update | GET guard: 405 |
| `response/delete` | delete | GET guard: 405 |
| `report_sent/read_one` | read | Not reached (upstream outage) |
| `report_sent/read_all` | read | Not reached (upstream outage) |
| `report_sent/read_multiple` | read | Not reached (upstream outage) |
| `custom_field/new` | create | Missing on deployment; added locally |
| `custom_field/read_all` | read | Not reached (upstream outage) |
| `custom_field/update` | update | Missing on deployment; added locally |
| `custom_field/create` | create | GET guard: 405 |
| `custom_field/read_one` | read | Not reached (upstream outage) |
| `custom_field_value/read_all` | read | Not reached (upstream outage) |
| `warehouse/create` | create | GET guard: 405 |
| `warehouse/read_one` | read | Not reached (upstream outage) |
| `warehouse/read_all` | read | Not reached (upstream outage) |
| `warehouse/read_multiple` | read | Not reached (upstream outage) |
| `warehouse/update` | update | GET guard: 405 |
| `warehouse/delete` | delete | GET guard: 405 |
| `warehouse_product/create` | create | GET guard: 405 |
| `warehouse_product/read_all` | read | Not reached (upstream outage) |
| `warehouse_product/update` | update | GET guard: 405 |
| `warehouse_product/delete` | delete | GET guard: 405 |
| `tax/read_all` | read | Not reached (upstream outage) |
