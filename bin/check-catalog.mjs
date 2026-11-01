// Maintainer-only comparison against the public collection. Never changes the
// bundled catalog, calls the account API, or needs Organilog credentials.
import { mkdir, readFile, rm, writeFile } from "node:fs/promises";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { buildCatalog, loadCollection } from "./build-catalog.mjs";

const ROOT = join(dirname(fileURLToPath(import.meta.url)), "..");
const SOURCE = "https://documenter.gw.postman.com/api/collections/2071554/UzJFxK4j";

function canonical(value) {
  if (Array.isArray(value)) return value.map(canonical);
  if (value && typeof value === "object") {
    return Object.fromEntries(Object.keys(value).sort().map(key => [key, canonical(value[key])]));
  }
  return value;
}

function equal(a, b) { return JSON.stringify(canonical(a)) === JSON.stringify(canonical(b)); }

function changes(before, after) {
  return [...new Set([...Object.keys(before), ...Object.keys(after)])].sort()
    .filter(field => !equal(before[field], after[field]))
    .map(field => ({ field, before: before[field] ?? null, after: after[field] ?? null }));
}

function endpointsById(catalog) {
  if (!Array.isArray(catalog.endpoints) || !catalog.endpoints.length || !catalog.entities
      || typeof catalog.entities !== "object" || Array.isArray(catalog.entities)) {
    throw new Error("Invalid catalog: expected endpoints and entities.");
  }
  const indexed = new Map();
  for (const endpoint of catalog.endpoints) {
    if (!/^[a-z_]+(?:\/[a-z_]+)?$/.test(endpoint.id) || indexed.has(endpoint.id)) {
      throw new Error("Invalid catalog: malformed or duplicate route ID.");
    }
    indexed.set(endpoint.id, endpoint);
  }
  return indexed;
}

export function compareCatalogs(current, candidate, checkedAt = new Date().toISOString()) {
  const oldRoutes = endpointsById(current);
  const newRoutes = endpointsById(candidate);
  const added = [...newRoutes.keys()].filter(id => !oldRoutes.has(id)).sort();
  const removed = [...oldRoutes.keys()].filter(id => !newRoutes.has(id)).sort();
  const changed = [...newRoutes.keys()].filter(id => oldRoutes.has(id)).sort()
    .map(id => ({ id, changes: changes(oldRoutes.get(id), newRoutes.get(id)) }))
    .filter(endpoint => endpoint.changes.length);
  const { generated_at: oldTime, endpoints: oldEndpoints, ...oldMetadata } = current;
  const { generated_at: newTime, endpoints: newEndpoints, ...newMetadata } = candidate;
  const metadata = changes(oldMetadata, newMetadata);
  return {
    status: added.length || removed.length || changed.length || metadata.length ? "drift" : "current",
    checked_at: checkedAt,
    source: SOURCE,
    bundled_generated_at: current.generated_at ?? null,
    bundled_endpoints: oldRoutes.size,
    published_endpoints: newRoutes.size,
    added, removed, changed, metadata,
  };
}

// Public documentation can contain Markdown/HTML; render names as plain text.
function plain(value) {
  return String(value).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
    .replace(/\|/g, "&#124;").replace(/`/g, "&#96;").replace(/[\r\n]/g, " ");
}

export function renderReport(report) {
  const lines = [
    "# Organilog catalog sync", "",
    `Checked at: ${report.checked_at} (UTC)`, "",
    `Source: [Organilog public Postman collection](${SOURCE})`, "",
  ];
  if (report.status === "error") {
    lines.push("**Check failed — sync status is unknown.**", "", plain(report.error), "",
      "Check the download or collection format. Unknown actions require an explicit permission classification before regeneration.");
  } else {
    lines.push(report.status === "current" ? "**Catalog is current.**" : "**Catalog changes need maintainer review.**", "",
      `Bundled: ${report.bundled_endpoints} endpoints. Published: ${report.published_endpoints} endpoints.`, "",
      `Bundled generation date: ${plain(report.bundled_generated_at ?? "unknown")}`, "",
      `Added: ${report.added.length}. Removed: ${report.removed.length}. Changed: ${report.changed.length}. Metadata changes: ${report.metadata.length}.`, "");
    if (report.status === "drift") {
      lines.push("| Change | Route or metadata | Changed fields |", "| --- | --- | --- |");
      for (const id of report.added) lines.push(`| Added | ${plain(id)} | New route |`);
      for (const id of report.removed) lines.push(`| Removed | ${plain(id)} | Removed from publication |`);
      for (const endpoint of report.changed) {
        lines.push(`| Changed | ${plain(endpoint.id)} | ${endpoint.changes.map(c => plain(c.field)).join(", ")} |`);
      }
      for (const change of report.metadata) lines.push(`| Metadata | ${plain(change.field)} | See report.json |`);
      lines.push("", "Download the catalog-sync artifact for report.json (before/after values) and catalog.candidate.json.", "",
        "Review changes, then regenerate with node bin/build-catalog.mjs and run the offline checks before submitting a PR.");
    }
  }
  lines.push("", "This checks published API coverage. Live endpoint health is checked separately. The bundled catalog was not modified.", "");
  return lines.join("\n");
}

async function main() {
  const options = { "--catalog": join(ROOT, "data", "catalog.json"), "--report-dir": join(ROOT, "backup", "catalog-sync") };
  const args = process.argv.slice(2);
  for (let i = 0; i < args.length; i += 2) {
    if (!["--catalog", "--collection", "--report-dir"].includes(args[i]) || !args[i + 1] || args[i + 1].startsWith("--")) {
      throw new Error("Usage: node bin/check-catalog.mjs [--collection collection.json] [--catalog catalog.json] [--report-dir directory]");
    }
    options[args[i]] = args[i + 1];
  }
  const directory = resolve(options["--report-dir"]);
  const catalogFile = resolve(options["--catalog"]);
  const reportFiles = ["report.json", "report.md", "catalog.candidate.json"].map(name => join(directory, name));
  if (reportFiles.includes(catalogFile) || (options["--collection"] && reportFiles.includes(resolve(options["--collection"])))) {
    throw new Error("Report files must not overwrite catalog or collection inputs.");
  }
  await mkdir(directory, { recursive: true });
  const checkedAt = new Date().toISOString();
  let report, candidate;
  try {
    const current = JSON.parse(await readFile(catalogFile, "utf8"));
    candidate = buildCatalog(await loadCollection(options["--collection"]), checkedAt);
    report = compareCatalogs(current, candidate, checkedAt);
  } catch (error) {
    candidate = null;
    report = { status: "error", checked_at: checkedAt, source: SOURCE, error: error.message };
  }
  // A reused report directory must never retain a candidate from an older check.
  if (candidate && report.status === "drift") {
    await writeFile(reportFiles[2], JSON.stringify(candidate, null, 2) + "\n");
  } else {
    await rm(reportFiles[2], { force: true });
  }
  await writeFile(reportFiles[0], JSON.stringify(report, null, 2) + "\n");
  await writeFile(reportFiles[1], renderReport(report));
  console.log(`Catalog sync: ${report.status}. Reports: ${directory}`);
  process.exitCode = { current: 0, drift: 1, error: 2 }[report.status];
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  main().catch(error => { console.error(error.message); process.exitCode = 2; });
}
