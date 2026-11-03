# Moving a tool from the Organilog API to the gateway

A tool that calls Organilog directly needs a few small changes to use this gateway.
Changing the URL and the token alone is not enough: authentication, the response
shape, and error handling also differ.

## Before and after

Directly against Organilog:

```sh
curl -X POST 'https://sync.organilog.com/api/v3/issue/read_all.php' \
  -H 'Content-Type: application/json' \
  -d '{"account":"mycompany","token":"ORGANILOG_TOKEN","page_nbr":"1"}'
```

```json
{ "results": [{ "id": "123", "...": "..." }], "count_pages": 4, "...": "..." }
```

Through the gateway:

```sh
curl -X POST 'https://organilog.example.com/v1/issue/read_all' \
  -H 'Authorization: Bearer YOUR_INTEGRATION_KEY' \
  -H 'Content-Type: application/json' \
  -d '{"page_nbr":"1"}'
```

```json
{
  "ok": true,
  "endpoint": "issue/read_all",
  "kind": "read",
  "data": { "results": [{ "id": "123", "...": "..." }], "count_pages": 4, "...": "..." },
  "meta": { "upstream_status": 200, "upstream_ms": 140, "upstream_calls": 1, "key": "my-tool" }
}
```

## Checklist

1. **URL.** Replace `https://sync.organilog.com/api/v3/` with
   `https://your-gateway/v1/` and drop the `.php` suffix:
   `issue/read_all.php` becomes `issue/read_all`. A path ending in `.php` returns 404.
   The account check `account/` becomes `/v1/account`.
2. **Credentials.** Remove `account` and `token` from the body. Send the integration
   key in an `Authorization: Bearer …` header (or `X-Api-Key`). The gateway discards
   `account` and `token` if they are still in the body, but a request without the
   header is refused with 401.
3. **Body.** POST bodies must be a JSON object with `Content-Type: application/json`.
   Form-encoded bodies are refused with 400 `invalid_json`. Reads may also use GET
   with the same fields in the query string.
4. **Response.** Organilog's answer is now under `data`. Code that reads `results`
   must read `data.results`; pagination fields move the same way.
5. **Success check.** Test `ok`, not the shape of the answer. On failure, `error.code`
   says why. Organilog can report errors inside an HTTP 200; the gateway turns
   those into `ok: false` with HTTP 422 `upstream_rejected`.
6. **Retries.** Retry only on 429, 502, 503, and 500, and only for reads. Many HTTP
   libraries retry every 5xx automatically: switch that off for writes. A failed write
   with `error.write_outcome: unknown` may have been applied, so check the record
   before sending it again. See the [status table](administration.md#calling-the-api).
7. **Key.** Create one key per tool in **Keys**, with only the permissions it needs.
   The default limit is 60 requests per minute; raise it for tools that send bursts.
8. **Writes.** Writes are off until `allow_writes` is enabled in `config.php`, and the
   key also needs create, update, or delete permission for the entity.
   Updates and deletes need the internal `id`; `code_id` alone is refused with 422
   `missing_id`.
9. **Endpoints.** Only endpoints in the catalog are forwarded; others return 404
   `unknown_endpoint`. `GET /v1/catalog` lists them and shows what your key may call.

## Behaviour that changes on updates

With `verify_updates` enabled (the default), each update reads the record before and
after writing, so it costs up to three upstream calls. If a field you did not send
was emptied, the gateway writes the previous values back and answers HTTP 409
`update_fields_lost`, with details in `meta.verification`. The update itself was
applied: do not repeat it.

## Optional clean-up

Add `?normalize=1` to the URL to turn Organilog's empty markers (`0000-00-00`,
`"None"`, `"0"` foreign keys) into `null`, and to add `status_label` to tickets.
Leave it off if the tool already handles those values.
