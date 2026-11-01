import assert from "node:assert/strict";
import { mkdtemp, readFile, writeFile, rm, access } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { fileURLToPath } from "node:url";
import { spawnSync } from "node:child_process";
import { buildCatalog } from "../bin/build-catalog.mjs";
import { compareCatalogs, renderReport } from "../bin/check-catalog.mjs";

const request = action => ({ name: action, request: {
  method: "POST", url: `https://sync.organilog.com/api/v3/client/${action}.php`,
  body: { raw: '{"account":"fixture","token":"dummy","data":{"title":"Example"}}' },
} });
const collection = { info: {}, item: [request("read_all"), request("update")] };
const current = buildCatalog(collection, "old-date");
const reordered = JSON.parse(JSON.stringify(current));
reordered.generated_at = "new-date";
reordered.endpoints.reverse();
reordered.endpoints[0].sample = { data: { title: "Example" } };
assert.equal(compareCatalogs(current, reordered).status, "current", "Time and route order do not indicate API changes");
reordered.endpoints[0] = Object.fromEntries(Object.entries(reordered.endpoints[0]).reverse());
assert.equal(compareCatalogs(current, reordered).status, "current", "JSON property order is irrelevant");

const updatedCollection = { info: {}, item: [request("update"), request("search")] };
updatedCollection.item[0].request.description = "Updated documentation";
updatedCollection.item[0].request.body.raw = '{"data":{"title":"Changed example"}}';
const candidate = buildCatalog(updatedCollection, "new-date");
const drift = compareCatalogs(current, candidate, "check-date");
assert.equal(drift.status, "drift");
assert.deepEqual(drift.added, ["client/search"]);
assert.deepEqual(drift.removed, ["client/read_all"]);
assert.deepEqual(drift.changed[0].changes.map(c => c.field), ["description", "sample"]);
assert.equal(drift.changed[0].changes[1].before.data.title, "Example");
assert.equal(drift.changed[0].changes[1].after.data.title, "Changed example");
const permissions = structuredClone(current);
permissions.endpoints[1].kind = "read";
assert.equal(compareCatalogs(permissions, current).changed[0].changes[0].field, "kind");
const metadata = structuredClone(current);
metadata.entities.client.note = "Updated entity note";
assert.equal(compareCatalogs(current, metadata).metadata[0].field, "entities");
assert.throws(() => compareCatalogs(current, { endpoints: [], entities: {} }), /Invalid catalog/);
assert.throws(() => compareCatalogs(current, { ...current, endpoints: [current.endpoints[0], current.endpoints[0]] }), /duplicate/);
assert.match(renderReport(drift), /client\/search/);
assert.match(renderReport({ status: "error", checked_at: "fixed", error: "<script>\n|`" }), /&lt;script&gt;/);
assert.doesNotMatch(renderReport({ status: "error", checked_at: "fixed", error: "Unavailable" }), /Catalog is current/);

const directory = await mkdtemp(join(tmpdir(), "organilog-catalog-sync-"));
try {
  const catalogFile = join(directory, "catalog.json");
  const collectionFile = join(directory, "collection.json");
  const reportDirectory = join(directory, "reports");
  const original = JSON.stringify(current);
  await writeFile(catalogFile, original);
  const run = () => spawnSync(process.execPath, [
    fileURLToPath(new URL("../bin/check-catalog.mjs", import.meta.url)),
    "--catalog", catalogFile, "--collection", collectionFile, "--report-dir", reportDirectory,
  ], { encoding: "utf8" });
  const report = async () => JSON.parse(await readFile(join(reportDirectory, "report.json"), "utf8"));

  await writeFile(collectionFile, JSON.stringify(updatedCollection));
  assert.equal(run().status, 1, "Drift must fail the maintainer workflow");
  assert.equal((await report()).status, "drift");
  const artifact = JSON.parse(await readFile(join(reportDirectory, "catalog.candidate.json"), "utf8"));
  assert.equal(artifact.endpoints.length, 2);
  assert.ok(!JSON.stringify(artifact).includes('"token"'), "Candidates contain no collection authentication fields");

  await writeFile(collectionFile, JSON.stringify({ info: {}, item: [request("execute")] }));
  assert.equal(run().status, 2, "Unknown actions must be reported as unclassified, not published");
  assert.match((await report()).error, /Unknown action/);
  await assert.rejects(access(join(reportDirectory, "catalog.candidate.json")), /ENOENT/);

  await writeFile(collectionFile, '{"item":[]}');
  assert.equal(run().status, 2, "An empty collection must not report all routes removed");
  await writeFile(collectionFile, "{broken");
  assert.equal(run().status, 2, "A broken download must leave status unknown");
  assert.equal((await report()).status, "error");
  assert.match(await readFile(join(reportDirectory, "report.md"), "utf8"), /sync status is unknown/);

  await writeFile(collectionFile, JSON.stringify(collection));
  assert.equal(run().status, 0, "A matching catalog succeeds");
  assert.equal((await report()).status, "current");
  assert.ok((await report()).checked_at);
  assert.equal(await readFile(catalogFile, "utf8"), original, "All checks must leave the bundled catalog unchanged");
  console.log("Passed catalog sync comparisons, reports, and CLI checks (no live requests).");
} finally {
  // mkdtemp created this directory under the OS temp root; never use input paths.
  if (directory.startsWith(join(tmpdir(), "organilog-catalog-sync-"))) await rm(directory, { recursive: true, force: true });
}
