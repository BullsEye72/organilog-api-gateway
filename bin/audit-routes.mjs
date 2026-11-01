// Read-only audit of a deployed gateway. Credentials stay in the environment and
// response values stay in memory; the report contains shapes/counts only.
import { writeFile } from "node:fs/promises";
import { resolve } from "node:path";

const base = process.env.ORGANILOG_AUDIT_URL;
const key = process.env.ORGANILOG_AUDIT_KEY;
if (!base || !key || !process.argv[2] || process.argv.length > 4
    || (process.argv[3] && process.argv[3] !== "--guards-only")) {
  console.error("Usage: ORGANILOG_AUDIT_URL=https://your-gateway ORGANILOG_AUDIT_KEY=... node bin/audit-routes.mjs report.json [--guards-only]");
  process.exit(1);
}
const url = new URL(base);
if (url.protocol !== "https:" || url.pathname !== "/" || url.username || url.password || url.search || url.hash) {
  throw new Error("Audit URL must be an HTTPS origin.");
}
const readActions = new Set(["", "read_all", "read_one", "read_multiple", "search"]);
const report = { checked_at: new Date().toISOString(), origin: url.origin, routes: [] };
const year = report.checked_at.slice(0, 4);
const records = new Map();
const skip = new Set((process.env.ORGANILOG_AUDIT_SKIP ?? "").split(",").filter(Boolean));
const guardsOnly = process.argv[3] === "--guards-only";

async function request(path, method = "GET", payload) {
  const response = await fetch(new URL(`/v1/${path}`, url), {
    method, redirect: "error", signal: AbortSignal.timeout(45000),
    headers: { Authorization: `Bearer ${key}`, ...(payload ? { "Content-Type": "application/json" } : {}) },
    ...(payload ? { body: JSON.stringify(payload) } : {}),
  });
  const raw = await response.text();
  let body;
  try { body = JSON.parse(raw); } catch { /* Report non-JSON without keeping its contents. */ }
  return { status: response.status, body, bytes: Buffer.byteLength(raw), retryAfter: response.headers.get("retry-after") };
}

function rowsOf(data) {
  if (Array.isArray(data)) return data;
  if (Array.isArray(data?.results)) return data.results;
  if (data && typeof data === "object" && data.id) return [data];
  return [];
}

function shape(data) {
  if (data === null) return "null";
  if (Array.isArray(data)) return "array";
  return typeof data;
}

const catalogResponse = await request("catalog");
if (catalogResponse.status !== 200 || !catalogResponse.body?.ok) throw new Error(`Catalog unavailable: HTTP ${catalogResponse.status}`);
const { catalog, writes_enabled: writesEnabled } = catalogResponse.body;
report.writes_enabled = writesEnabled;
report.catalog_generated_at = catalog.generated_at;
report.endpoint_count = catalog.endpoints.length;
report.entity_count = Object.keys(catalog.entities).length;

// Lists precede record reads, so IDs come from this account instead of docs.
const endpoints = [...catalog.endpoints].sort((a, b) => {
  const rank = e => e.action === "" ? 0 : e.action === "read_all" ? 1 : readActions.has(e.action) ? 2 : 3;
  return rank(a) - rank(b);
});
for (const endpoint of endpoints) {
  const { id, entity, action, kind } = endpoint;
  if (skip.has(id)) continue;
  if (!/^[a-z_]+(?:\/[a-z_]+)?$/.test(id)) throw new Error("Invalid catalog route.");
  const read = readActions.has(action) && kind === "read";
  if (guardsOnly && read) continue;
  if (report.stopped && read) continue;
  const payload = {};
  let record = records.get(entity)?.find(row => row?.id);
  if (read) {
    if (action === "read_all") {
      payload.page_nbr = "1";
      if (entity === "response") payload.item_type = "issue";
      if (entity === "custom_field_value") payload.last_datetime = `${year}-01-01 00:00`;
    } else if (action === "read_one" || action === "read_multiple") {
      // Custom-field values take the owning record's ID, not a field definition ID.
      if (entity === "custom_field") {
        record = records.get("intervention")?.find(row => row?.id);
        payload.module = "intervention";
      }
      if (entity === "invoice_link") record = records.get("invoicing_invoice")?.find(row => row?.id);
      payload[action === "read_multiple" ? "ids" : "id"] = String(record?.id ?? "0");
    } else if (action === "search") {
      if (entity === "client") payload.title = String(record?.title ?? "__organilog_route_audit_no_match__");
      if (entity === "adresse") payload.search = String(record?.ville || "__organilog_route_audit_no_match__");
      if (entity === "intervention") {
        payload.date_from = `${year}-01-01`;
        payload.date_to = `${year}-12-31`;
      }
      payload.page_nbr = "1";
    }
  }
  const result = { id, kind, method: read ? "POST" : "GET", test: read ? "read" : "write_method_guard" };
  try {
    const { status, body, bytes, retryAfter } = await request(id, result.method, read ? payload : undefined);
    Object.assign(result, {
      status, ok: body?.ok ?? null, error: body?.error?.code ?? null,
      bytes, upstream_status: body?.meta?.upstream_status ?? null,
      upstream_calls: body?.meta?.upstream_calls ?? null,
      curl_errno: body?.error?.curl_errno ?? null,
    });
    if (read) {
      const data = body?.data;
      const rows = rowsOf(data);
      result.output_type = shape(data);
      result.output_keys = data && typeof data === "object" && !Array.isArray(data) ? Object.keys(data) : [];
      result.rows = rows.length;
      result.row_keys = Object.keys(rows.find(row => row && typeof row === "object") ?? {});
      result.used_existing_record = record !== undefined;
      result.upstream_error_keys = data && typeof data === "object" ? Object.keys(data).filter(k => /error|message|success|status/i.test(k)) : [];
      if (body?.ok && action === "read_all") records.set(entity, rows);
    }
    result.passed = read
      ? status === 200 && body?.ok === true && body.endpoint === id && body.kind === kind
        && body.data !== null && typeof body.data === "object"
        && body.meta?.upstream_status >= 200 && body.meta.upstream_status < 300
        && body.meta.upstream_calls >= 1
      : status === 405 && body?.error?.code === "method_not_allowed";
    if (status === 429 || retryAfter || ["upstream_cooldown", "upstream_unreachable", "upstream_invalid_response"].includes(result.error)) {
      report.stopped = { route: id, reason: result.error ?? `HTTP ${status}`, retry_after: retryAfter };
    }
  } catch (error) {
    result.passed = false;
    result.error = error.name === "TimeoutError" ? "audit_timeout" : "audit_transport_error";
    report.stopped = { route: id, reason: result.error };
  }
  report.routes.push(result);
  console.log(`${result.passed ? "PASS" : "FAIL"} ${result.method} ${id}: ${result.status ?? result.error}${read ? ` (${result.rows ?? 0} rows)` : ""}`);
  // Keep a partial sanitized report if the process is interrupted.
  await writeFile(resolve(process.argv[2]), JSON.stringify(report, null, 2) + "\n");
  // Local method guards do not contact Organilog, so they remain testable during
  // an upstream outage. Stop upstream calls until a later, separate audit run.
}
report.summary = {
  tested: report.routes.length,
  passed: report.routes.filter(row => row.passed).length,
  failed: report.routes.filter(row => !row.passed).length,
  untested: catalog.endpoints.length - report.routes.length,
};
await writeFile(resolve(process.argv[2]), JSON.stringify(report, null, 2) + "\n");
console.log(JSON.stringify(report.summary));
if (report.summary.failed || report.summary.untested) process.exitCode = 1;
