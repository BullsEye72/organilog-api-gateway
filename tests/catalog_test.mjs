import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { buildCatalog } from "../bin/build-catalog.mjs";

const request = (path, options = {}) => ({
  name: path,
  request: { method: "POST", url: `https://sync.organilog.com/api/v3/${path}`, ...options },
});
const collection = items => ({ item: items, info: {} });
const items = [
  request("account/"),
  request("custom_field/new.php", { body: { raw: '{"account":"dummy","token":"dummy","data":{"nom":"Test"}}' } }),
  request("custom_field/create.php"),
  request("custom_field/update.php"),
  request("custom_field/read_all.php"),
  request("custom_field/delete.php"),
];
const documentationId = "9100685d-f7c0-4be6-8595-d30ac92ad572";
items[1]._postman_id = documentationId;
items[1].id = "16d61711-1ec1-488b-8ec0-1cc6b6dccf5a";
const result = buildCatalog(collection(items), "fixed");
assert.deepEqual(result.endpoints.map(e => e.kind), ["read", "create", "create", "update", "read", "delete"]);
assert.deepEqual(result.endpoints[1].sample, { data: { nom: "Test" } });
assert.equal(result.endpoints[1].id, "custom_field/new");
assert.equal(result.endpoints[1].verified_live, false);
assert.equal(result.endpoints[1].documentation_url, `${result.source}#${documentationId}`);
assert.equal(result.endpoints[0].documentation_url, result.source, "Missing IDs fall back to the main documentation");
const exported = buildCatalog(collection([{
  ...request("user/read_one.php"), id: documentationId,
}]));
assert.equal(exported.endpoints[0].documentation_url, `${result.source}#${documentationId}`, "Standard collection exports can use id");
const malformedId = buildCatalog(collection([{
  ...request("user/read_one.php"), _postman_id: "not-a-request-id",
}]));
assert.equal(malformedId.endpoints[0].documentation_url, result.source);
assert.equal(result.generated_at, "fixed");
assert.throws(() => buildCatalog(collection([request("custom_field/execute.php")])), /Unknown action/);
assert.throws(() => buildCatalog(collection([request("send_email/")])), /Unknown action/);
assert.throws(() => buildCatalog(collection([items[0], items[0]])), /Duplicate endpoint/);
assert.throws(() => buildCatalog(collection([request("client/read_all.php", { method: "GET" })])), /Unsupported method/);
for (const url of ["https://example.com/api/v3/client/read_all.php", "https://sync.organilog.com/api/v3/client/read_all.php?extra=1"]) {
  assert.throws(() => buildCatalog(collection([request("", { url })])), /Unsupported endpoint URL/);
}
const nested = buildCatalog(collection([
  { name: "Contact", item: [request("contact/read_multiple.php")] },
  { name: "Contrat", item: [{ name: "Reads", item: [request("contact/read_multiple.php")] }] },
  request("user/read_one.php", { url: { raw: "https://sync.organilog.com/api/v3/user/read_one.php" } }),
]));
assert.deepEqual(nested.endpoints.map(e => e.id), ["contact/read_multiple", "contrat/read_multiple", "user/read_one"]);
const bundled = JSON.parse(await readFile(new URL("../data/catalog.json", import.meta.url)));
for (const [id, kind] of [["custom_field/new", "create"], ["custom_field/update", "update"]]) {
  assert.equal(bundled.endpoints.find(e => e.id === id)?.kind, kind, `Bundled catalog must include ${id} with write permissions`);
}
console.log("Passed catalog generation, coverage, and permission classification checks.");
