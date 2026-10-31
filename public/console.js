"use strict";

// Organilog API console. Talks only to our own /v1 API; the Organilog token never reaches
// the browser. The key lives in sessionStorage (this tab), history in localStorage (no key).

const $ = (sel) => document.querySelector(sel);
const KINDS = ["read", "create", "update", "delete"];
const HISTORY_MAX = 50;

const state = {
  key: null,
  info: null,        // GET /v1/catalog response
  endpoints: [],
  current: null,
  bodies: {},        // edited body per endpoint, kept while navigating
  editingKeyId: null,
  health: null,
  healthReceivedAt: 0,
  healthError: false,
};

// --- storage (may throw in private mode; the console still works without it) ----------
const store = {
  get(area, k, fallback) { try { const v = window[area].getItem(k); return v === null ? fallback : JSON.parse(v); } catch { return fallback; } },
  set(area, k, v) { try { window[area].setItem(k, JSON.stringify(v)); } catch { /* ignore */ } },
  del(area, k) { try { window[area].removeItem(k); } catch { /* ignore */ } },
};

// --- helpers -----------------------------------------------------------------------------
function el(tag, attrs = {}, ...children) {
  const node = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (k === "class") node.className = v;
    else if (k.startsWith("on")) node.addEventListener(k.slice(2), v);
    else if (v === true) node.setAttribute(k, "");
    else if (v !== false && v != null) node.setAttribute(k, v);
  }
  for (const c of children.flat()) if (c != null) node.append(c instanceof Node ? c : document.createTextNode(String(c)));
  return node;
}

function toast(msg) {
  const t = $("#toast");
  t.textContent = msg;
  t.hidden = false;
  clearTimeout(toast.timer);
  toast.timer = setTimeout(() => (t.hidden = true), 2200);
}

async function copy(text, what = "Copied") {
  try { await navigator.clipboard.writeText(text); toast(what); } catch { toast("Copy failed"); }
}

function escapeHtml(s) {
  return s.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
}

function highlight(json) {
  return escapeHtml(json).replace(
    /("(?:\\.|[^"\\])*")(\s*:)?|\b(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)\b|\b(true|false|null)\b/g,
    (m, str, colon, num, lit) => {
      if (str) return colon ? `<span class="k">${str}</span>${colon}` : `<span class="s">${str}</span>`;
      if (num) return `<span class="n">${num}</span>`;
      return `<span class="l">${lit}</span>`;
    },
  );
}

function ago(iso) {
  if (!iso) return "never";
  const s = Math.round((Date.now() - new Date(iso).getTime()) / 1000);
  if (s < 60) return `${s}s ago`;
  if (s < 3600) return `${Math.round(s / 60)} min ago`;
  if (s < 86400) return `${Math.round(s / 3600)} h ago`;
  return new Date(iso).toLocaleDateString();
}

async function api(method, path, body) {
  const opts = { method, headers: { Authorization: `Bearer ${state.key}` } };
  if (body !== undefined) {
    opts.headers["Content-Type"] = "application/json";
    opts.body = typeof body === "string" ? body : JSON.stringify(body);
  }
  const started = performance.now();
  const res = await fetch(path, opts);
  const text = await res.text();
  let json = null;
  try { json = JSON.parse(text); } catch { /* not JSON */ }
  return { status: res.status, ok: res.ok, json, text, ms: Math.round(performance.now() - started) };
}

// --- connect -----------------------------------------------------------------------------
async function connect(key) {
  state.health = null;
  state.healthError = false;
  state.key = key;
  const res = await api("GET", "/v1/catalog");
  if (!res.ok) {
    state.key = null;
    renderUpstreamStatus();
    store.del("sessionStorage", "olk");
    toast(res.json?.error?.message ?? `HTTP ${res.status}`);
    return;
  }
  store.set("sessionStorage", "olk", key);
  state.info = res.json;
  state.endpoints = res.json.catalog.endpoints;
  $("#key-input").value = "";
  $("#key-input").placeholder = "Connected — paste another key to switch";
  $("#key-forget").hidden = false;
  $("#welcome").hidden = true;
  $("#tabs").hidden = false;
  $("#tab-keys").hidden = !res.json.key.is_admin;
  renderWhoami();
  renderEndpointList();
  showTab(location.hash.slice(1) || "console");
  loadUpstreamStatus();
}

// Reading status never contacts Organilog, even with several consoles open.
async function loadUpstreamStatus() {
  if (!state.info || !state.key || loadUpstreamStatus.pending) return;
  loadUpstreamStatus.pending = true;
  const key = state.key;
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 6000);
  try {
    const res = await fetch("/v1/status", {
      headers: { Authorization: `Bearer ${key}` }, signal: controller.signal, cache: "no-store",
    });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const body = await res.json();
    if (!body.upstream) throw new Error("Missing status");
    if (state.key !== key) return;
    state.health = body.upstream;
    state.healthReceivedAt = Date.now();
    state.healthError = false;
  } catch {
    if (state.key === key) state.healthError = true;
  } finally {
    clearTimeout(timeout);
    loadUpstreamStatus.pending = false;
    renderUpstreamStatus();
  }
}

function renderUpstreamStatus() {
  const bar = $("#upstream-health");
  bar.hidden = !state.info || !state.key;
  if (bar.hidden) return;
  const h = state.health;
  const labels = {
    unknown: "Status unknown", healthy: "Reachable", degraded: "Recent failure",
    unavailable: "Cooling down", recovering: "Checking recovery", retry_ready: "Ready to retry",
  };
  const status = state.healthError ? "unknown" : h?.state ?? "unknown";
  bar.dataset.state = status;
  $("#upstream-label").textContent = `Organilog: ${state.healthError ? "status unavailable" : labels[status] ?? "Status unknown"}`;
  const parts = [];
  if (state.healthError) parts.push("Could not refresh status; retrying automatically.");
  else if (h) {
    const remaining = Math.max(0, h.retry_after - Math.floor((Date.now() - state.healthReceivedAt) / 1000));
    if (status === "unavailable" || status === "recovering") {
      parts.push(remaining > 0 ? `Proxy calls are paused; retry in ${remaining}s.` : "Waiting for the next request to check recovery.");
    }
    if (status === "retry_ready") parts.push("The next API request will check recovery.");
    if (h.last_observed_at) parts.push(`Last observation ${ago(h.last_observed_at)}${h.last_http_status ? ` (HTTP ${h.last_http_status})` : ""}.`);
    else parts.push("Waiting for the first Organilog request.");
    if (h.last_success_at) parts.push(`Last reachable ${ago(h.last_success_at)}.`);
  }
  $("#upstream-detail").textContent = parts.join(" ");
}

function renderWhoami() {
  const { key, writes_enabled } = state.info;
  $("#whoami").replaceChildren(
    el("span", { class: "chip" }, key.is_admin ? "admin key" : key.name),
    el("span", { class: `chip ${writes_enabled ? "on" : "off"}`, title: "allow_writes in config.php" },
      writes_enabled ? "writes ON" : "writes off (server)"),
  );
}

function forget() {
  store.del("sessionStorage", "olk");
  location.hash = "";
  location.reload();
}

// --- tabs --------------------------------------------------------------------------------
function showTab(name) {
  if (name === "keys" && !state.info?.key.is_admin) name = "console";
  if (!["console", "history", "keys"].includes(name)) name = "console";
  for (const b of document.querySelectorAll("#tabs button")) b.classList.toggle("active", b.dataset.tab === name);
  $("#view-console").hidden = name !== "console";
  $("#view-history").hidden = name !== "history";
  $("#view-keys").hidden = name !== "keys";
  history.replaceState(null, "", `#${name}`);
  if (name === "history") renderHistory();
  if (name === "keys") loadKeys();
}

// --- endpoint list -----------------------------------------------------------------------
function renderEndpointList() {
  const q = $("#filter").value.trim().toLowerCase();
  const onlyAllowed = $("#only-allowed").checked;
  const groups = new Map();
  for (const ep of state.endpoints) {
    if (onlyAllowed && !ep.allowed) continue;
    if (q && !`${ep.id} ${ep.name} ${ep.group}`.toLowerCase().includes(q)) continue;
    if (!groups.has(ep.group)) groups.set(ep.group, []);
    groups.get(ep.group).push(ep);
  }
  const open = store.get("localStorage", "olk-open-groups", ["Ticket"]);
  const list = $("#endpoint-list");
  list.replaceChildren();
  if (!groups.size) list.append(el("p", { class: "muted" }, "Nothing matches."));
  for (const [group, eps] of groups) {
    const det = el("details", { class: "group", open: q !== "" || open.includes(group) },
      el("summary", {}, group, el("span", { class: "count" }, eps.length)),
      eps.map((ep) => el("button", {
        class: `ep ${ep.allowed ? "" : "denied"} ${state.current?.id === ep.id ? "active" : ""}`,
        title: ep.allowed ? ep.id : `${ep.id} — this key may not call it`,
        onclick: () => selectEndpoint(ep.id),
      },
      el("span", { class: `badge ${ep.kind}` }, ep.kind),
      el("span", { class: "label" }, ep.action || "account"),
      ep.verified_live ? el("span", { class: "dot", title: "verified live" }) : null)),
    );
    det.addEventListener("toggle", () => {
      if (q) return;
      const set = new Set(store.get("localStorage", "olk-open-groups", ["Ticket"]));
      det.open ? set.add(group) : set.delete(group);
      store.set("localStorage", "olk-open-groups", [...set]);
    });
    list.append(det);
  }
}

function selectEndpoint(id, body) {
  const ep = state.endpoints.find((e) => e.id === id);
  if (!ep) return;
  saveCurrentBody();
  state.current = ep;
  $("#empty-state").hidden = true;
  $("#request").hidden = false;
  $("#response").hidden = true;

  $("#ep-kind").className = `badge ${ep.kind}`;
  $("#ep-kind").textContent = ep.kind;
  $("#ep-name").textContent = ep.name;
  $("#ep-verified").hidden = !ep.verified_live;
  $("#ep-unverified").hidden = ep.verified_live;
  $("#ep-note").hidden = !ep.note;
  $("#ep-note").textContent = ep.note ?? "";
  const entityNote = state.info.catalog.entities[ep.entity]?.note;
  $("#ep-entity-note").hidden = !entityNote;
  $("#ep-entity-note").textContent = entityNote ?? "";
  $("#ep-desc").textContent = ep.description || "No description in the docs.";
  $("#ep-doc").hidden = !ep.description;

  const method = $("#method");
  method.value = "POST";
  method.disabled = ep.kind !== "read";
  $("#body").value = body ?? state.bodies[id] ?? JSON.stringify(ep.sample, null, 2);
  $("#body-error").hidden = true;
  updateUrl();
  renderEndpointList();
}

function saveCurrentBody() {
  if (state.current) state.bodies[state.current.id] = $("#body").value;
}

function parseBody() {
  const text = $("#body").value.trim();
  if (text === "") return {};
  try {
    const body = JSON.parse(text);
    if (typeof body !== "object" || body === null || Array.isArray(body)) throw new Error("The body must be a JSON object.");
    $("#body-error").hidden = true;
    return body;
  } catch (e) {
    $("#body-error").textContent = e.message;
    $("#body-error").hidden = false;
    return null;
  }
}

function requestUrl(body) {
  const ep = state.current;
  const params = new URLSearchParams();
  if ($("#method").value === "GET" && body) {
    for (const [k, v] of Object.entries(body)) params.set(k, typeof v === "object" ? JSON.stringify(v) : v);
  }
  if ($("#normalize").checked) params.set("normalize", "1");
  const qs = params.toString();
  return `/v1/${ep.id}${qs ? `?${qs}` : ""}`;
}

function updateUrl() {
  if (!state.current) return;
  const body = $("#method").value === "GET" ? (() => { try { return JSON.parse($("#body").value || "{}"); } catch { return null; } })() : null;
  $("#url").textContent = `${$("#method").value} ${location.origin}${requestUrl(body)}`;
}

// --- send --------------------------------------------------------------------------------
async function send() {
  const btn = $("#send");
  if (btn.disabled) return;
  const ep = state.current;
  if (!ep) return;
  const body = parseBody();
  if (body === null) return;
  if (ep.kind !== "read") {
    const target = body.id ? ` id ${body.id}` : "";
    if (!confirm(`${ep.kind.toUpperCase()} ${ep.entity}${target} on the LIVE Organilog account?\n\nThis is production data.`)) return;
  }
  const method = $("#method").value;
  const submittedBody = $("#body").value;
  const normalize = $("#normalize").checked;
  btn.disabled = true;
  btn.firstChild.textContent = "Sending… ";
  try {
    const res = await api(method, requestUrl(body), method === "POST" ? body : undefined);
    showResponse(res);
    pushHistory({ id: ep.id, method, body: submittedBody, normalize, status: res.status, ms: res.ms, at: new Date().toISOString() });
  } catch (e) {
    showResponse({ status: 0, ok: false, json: null, text: String(e), ms: 0 });
  } finally {
    btn.disabled = false;
    btn.firstChild.textContent = "Send ";
    loadUpstreamStatus();
  }
}

function showResponse(res) {
  $("#response").hidden = false;
  const st = $("#res-status");
  st.textContent = res.status ? `${res.status}${res.json?.ok === false && res.json?.error ? ` · ${res.json.error.code}` : ""}` : "network error";
  st.className = `status ${res.ok ? "ok" : "err"}`;
  const meta = res.json?.meta;
  const parts = [`${res.ms} ms total`];
  if (meta) {
    parts.push(`Organilog HTTP ${meta.upstream_status} in ${meta.upstream_ms} ms`, `${meta.upstream_calls} upstream call${meta.upstream_calls > 1 ? "s" : ""}`);
    if (meta.verification) parts.push(`update check: ${meta.verification.status}`);
  }
  const retryAfter = res.json?.error?.retry_after ?? meta?.retry_after;
  if (retryAfter) parts.push(`retry after ${retryAfter}s`);
  const count = res.json?.data?.count_results_total;
  if (count != null) {
    const pages = res.json.data.count_pages;
    parts.push(`${count} results · ${pages} page${pages == 1 ? "" : "s"} of ${res.json.data.defined_items_by_page}`);
  }
  $("#res-meta").textContent = parts.join(" · ");
  const pretty = res.json ? JSON.stringify(res.json, null, 2) : res.text;
  $("#res-body").innerHTML = res.json ? highlight(pretty) : escapeHtml(pretty);
  $("#res-body").dataset.raw = pretty;
}

function curl() {
  const body = parseBody();
  if (body === null || !state.current) return;
  const method = $("#method").value;
  const url = `${location.origin}${requestUrl(body)}`;
  const lines = [`curl -X ${method} '${url}'`, `  -H "Authorization: Bearer $ORGANILOG_API_KEY"`];
  if (method === "POST") {
    lines.push(`  -H 'Content-Type: application/json'`, `  -d '${JSON.stringify(body).replace(/'/g, "'\\''")}'`);
  }
  copy(lines.join(" \\\n"), "curl copied (key left as $ORGANILOG_API_KEY)");
}

// --- history -----------------------------------------------------------------------------
function pushHistory(entry) {
  const list = store.get("localStorage", "olk-history", []);
  list.unshift(entry);
  store.set("localStorage", "olk-history", list.slice(0, HISTORY_MAX));
}

function renderHistory() {
  const list = store.get("localStorage", "olk-history", []);
  const tbody = $("#history-table tbody");
  tbody.replaceChildren();
  if (!list.length) tbody.append(el("tr", {}, el("td", { colspan: 5, class: "muted" }, "No requests yet.")));
  for (const h of list) {
    const ep = state.endpoints.find((e) => e.id === h.id);
    tbody.append(el("tr", {},
      el("td", {}, ago(h.at)),
      el("td", {}, el("span", { class: `badge ${ep?.kind ?? "read"}` }, ep?.kind ?? "?"), " ", el("code", {}, `${h.method} ${h.id}`)),
      el("td", { class: h.status >= 200 && h.status < 300 ? "status ok" : "status err" }, h.status || "—"),
      el("td", {}, `${h.ms} ms`),
      el("td", { class: "actions-cell" }, el("button", {
        disabled: !ep,
        onclick: () => {
          showTab("console");
          selectEndpoint(h.id, h.body);
          if (ep.kind === "read") $("#method").value = h.method;
          $("#normalize").checked = !!h.normalize;
          updateUrl();
        },
      }, "Load")),
    ));
  }
}

// --- keys (admin) ------------------------------------------------------------------------
function hasAllPermissions(permissions) {
  return KINDS.every((kind) => (permissions["*"] ?? []).includes(kind));
}

function renderKeyPermissions(permissions) {
  if (hasAllPermissions(permissions)) {
    return el("span", { class: "chip", title: "Read, create, update and delete across all entities" }, "All permissions");
  }
  const perms = Object.entries(permissions);
  return perms.length
    ? perms.map(([entity, kinds]) => el("span", { class: "perm" }, el("code", {}, entity), " ", kinds.join(", ")))
    : el("span", { class: "muted" }, "none");
}

async function loadKeys() {
  const res = await api("GET", "/v1/admin/keys");
  const tbody = $("#keys-table tbody");
  tbody.replaceChildren();
  if (!res.ok) { toast(res.json?.error?.message ?? `HTTP ${res.status}`); return; }
  state.keys = res.json.keys;
  if (!state.keys.length) tbody.append(el("tr", {}, el("td", { colspan: 7, class: "muted" }, "No keys yet. Create one per tool.")));
  for (const k of state.keys) {
    tbody.append(el("tr", { class: k.enabled ? "" : "disabled" },
      el("td", {}, el("strong", {}, k.name), k.notes ? el("div", { class: "muted" }, k.notes) : null, k.enabled ? null : el("div", { class: "muted" }, "disabled")),
      el("td", {}, el("code", {}, `${k.prefix}…`)),
      el("td", {}, renderKeyPermissions(k.permissions)),
      el("td", {}, k.rate_limit_per_minute || "∞"),
      el("td", {}, k.usage.today),
      el("td", {}, ago(k.usage.last_used_at)),
      el("td", { class: "actions-cell" },
        el("button", { onclick: () => openKeyEditor(k) }, "Edit"), " ",
        el("button", { onclick: () => toggleKey(k) }, k.enabled ? "Disable" : "Enable"), " ",
        el("button", { onclick: () => rotateKey(k) }, "Rotate"), " ",
        el("button", { class: "danger", onclick: () => deleteKey(k) }, "Delete")),
    ));
  }
}

function entitiesForMatrix() {
  const kindsByEntity = {};
  for (const ep of state.endpoints) (kindsByEntity[ep.entity] ??= new Set()).add(ep.kind);
  return kindsByEntity;
}

function renderMatrix(permissions) {
  const kindsByEntity = entitiesForMatrix();
  const entities = state.info.catalog.entities;
  const rows = [["*", "All entities", new Set(KINDS)], ...Object.keys(kindsByEntity).sort().map((e) => [e, entities[e]?.group ?? e, kindsByEntity[e]])];
  const table = $("#matrix");
  table.replaceChildren(el("thead", {}, el("tr", {}, el("th", {}, "Entity"), KINDS.map((k) => el("th", {}, el("span", { class: `badge ${k}` }, k))))));
  const tbody = el("tbody");
  for (const [entity, label, available] of rows) {
    tbody.append(el("tr", { class: entity === "*" ? "star" : "", "data-entity": entity },
      el("td", {}, entity === "*" ? el("span", {}, el("code", {}, "*"), " all entities") : el("span", {}, el("code", {}, entity), " ", el("span", { class: "muted" }, label))),
      KINDS.map((kind) => available.has(kind)
        ? el("td", {}, el("input", { type: "checkbox", "data-kind": kind, checked: (permissions[entity] ?? []).includes(kind), "aria-label": `${entity} ${kind}` }))
        : el("td", { class: "na", title: "Organilog has no such endpoint" }, "—")),
    ));
  }
  table.append(tbody);
  $("#matrix-filter").value = "";
}

function openKeyEditor(k) {
  state.editingKeyId = k?.id ?? null;
  const form = $("#key-editor");
  $("#key-editor-title").textContent = k ? `Edit ${k.name}` : "New key";
  form.name.value = k?.name ?? "";
  form.notes.value = k?.notes ?? "";
  form.rate_limit_per_minute.value = k?.rate_limit_per_minute ?? 60;
  form.enabled.checked = k?.enabled ?? true;
  renderMatrix(k?.permissions ?? { "*": ["read"] });
  syncAllPermissions();
  form.hidden = false;
  form.scrollIntoView({ behavior: "smooth" });
  form.name.focus();
}

function syncAllPermissions() {
  const kinds = [...$("#matrix").querySelectorAll('tr[data-entity="*"] input:checked')].map((input) => input.dataset.kind);
  const all = hasAllPermissions({ "*": kinds });
  $("#key-all-permissions").checked = all;
  $("#key-custom-permissions").hidden = all;
}

function readKeyEditor() {
  const form = $("#key-editor");
  const permissions = {};
  if ($("#key-all-permissions").checked) {
    permissions["*"] = [...KINDS];
  } else {
    for (const tr of $("#matrix").querySelectorAll("tbody tr")) {
      const kinds = [...tr.querySelectorAll("input:checked")].map((i) => i.dataset.kind);
      if (kinds.length) permissions[tr.dataset.entity] = kinds;
    }
  }
  return {
    name: form.name.value.trim(),
    notes: form.notes.value.trim(),
    rate_limit_per_minute: parseInt(form.rate_limit_per_minute.value || "0", 10),
    enabled: form.enabled.checked,
    permissions,
  };
}

async function saveKey(ev) {
  ev.preventDefault();
  const data = readKeyEditor();
  const writes = Object.values(data.permissions).flat().filter((k) => k !== "read");
  if (writes.length && !confirm(`This key will be able to ${[...new Set(writes)].join(", ")} on the live Organilog account. Continue?`)) return;
  const res = state.editingKeyId
    ? await api("PATCH", `/v1/admin/keys/${state.editingKeyId}`, data)
    : await api("POST", "/v1/admin/keys", data);
  if (!res.ok) { toast(res.json?.error?.message ?? `HTTP ${res.status}`); return; }
  $("#key-editor").hidden = true;
  if (res.json.key) showSecret(`Key for ${res.json.record.name}`, res.json.key);
  else toast("Saved");
  loadKeys();
}

async function toggleKey(k) {
  const res = await api("PATCH", `/v1/admin/keys/${k.id}`, { enabled: !k.enabled });
  if (!res.ok) toast(res.json?.error?.message ?? `HTTP ${res.status}`);
  loadKeys();
}

async function rotateKey(k) {
  if (!confirm(`Rotate "${k.name}"? Its current key stops working immediately.`)) return;
  const res = await api("POST", `/v1/admin/keys/${k.id}/rotate`);
  if (!res.ok) { toast(res.json?.error?.message ?? `HTTP ${res.status}`); return; }
  showSecret(`New key for ${k.name}`, res.json.key);
  loadKeys();
}

async function deleteKey(k) {
  if (!confirm(`Delete "${k.name}"? Anything using it stops working. Disabling is reversible; deleting is not.`)) return;
  const res = await api("DELETE", `/v1/admin/keys/${k.id}`);
  if (!res.ok) toast(res.json?.error?.message ?? `HTTP ${res.status}`);
  loadKeys();
}

function showSecret(title, secret) {
  $("#secret-title").textContent = title;
  $("#secret-value").textContent = secret;
  $("#secret-dialog").showModal();
}

// --- wiring ------------------------------------------------------------------------------
$("#key-form").addEventListener("submit", (e) => {
  e.preventDefault();
  const key = $("#key-input").value.trim();
  if (key) connect(key);
});
$("#key-forget").addEventListener("click", forget);
$("#tabs").addEventListener("click", (e) => { if (e.target.dataset.tab) showTab(e.target.dataset.tab); });
$("#filter").addEventListener("input", renderEndpointList);
$("#only-allowed").addEventListener("change", renderEndpointList);
$("#method").addEventListener("change", updateUrl);
$("#normalize").addEventListener("change", updateUrl);
$("#body").addEventListener("input", updateUrl);
$("#send").addEventListener("click", send);
$("#format").addEventListener("click", () => { const b = parseBody(); if (b) $("#body").value = JSON.stringify(b, null, 2); });
$("#reset").addEventListener("click", () => { if (state.current) { $("#body").value = JSON.stringify(state.current.sample, null, 2); updateUrl(); } });
$("#curl").addEventListener("click", curl);
$("#res-copy").addEventListener("click", () => copy($("#res-body").dataset.raw ?? ""));
$("#history-clear").addEventListener("click", () => { store.del("localStorage", "olk-history"); renderHistory(); });
$("#key-new").addEventListener("click", () => openKeyEditor(null));
$("#key-editor").addEventListener("submit", saveKey);
$("#key-editor-cancel").addEventListener("click", () => ($("#key-editor").hidden = true));
$("#key-all-permissions").addEventListener("change", (e) => {
  for (const input of $("#matrix").querySelectorAll('tr[data-entity="*"] input')) input.checked = e.target.checked;
  syncAllPermissions();
});
$("#matrix").addEventListener("change", syncAllPermissions);
$("#matrix-filter").addEventListener("input", (e) => {
  const q = e.target.value.trim().toLowerCase();
  for (const tr of $("#matrix").querySelectorAll("tbody tr")) {
    tr.hidden = q !== "" && tr.dataset.entity !== "*" && !tr.textContent.toLowerCase().includes(q);
  }
});
$("#secret-copy").addEventListener("click", () => copy($("#secret-value").textContent, "Key copied"));
$("#body").addEventListener("keydown", (e) => {
  if (e.key === "Tab") {
    e.preventDefault();
    const t = e.target;
    t.setRangeText("  ", t.selectionStart, t.selectionEnd, "end");
  }
});
document.addEventListener("keydown", (e) => {
  if (e.key === "Enter" && (e.ctrlKey || e.metaKey) && !$("#view-console").hidden && state.current) {
    e.preventDefault();
    send();
  }
});

const saved = store.get("sessionStorage", "olk", null);
if (saved) connect(saved);

setInterval(() => { if (!document.hidden) loadUpstreamStatus(); }, 10000);
setInterval(() => { if (!document.hidden) renderUpstreamStatus(); }, 1000);
document.addEventListener("visibilitychange", () => { if (!document.hidden) loadUpstreamStatus(); });

fetch('/branding.php', { cache: 'no-store' }).then((res) => res.json()).then((brand) => {
  if (brand.configured === false && brand.setup_available) {
    location.replace('/setup.php');
    return;
  }
  if (brand.configured) $('#setup-hint').hidden = true;
  if (brand.name) {
    $('#brand-name').textContent = brand.name;
    document.title = brand.name;
    $('#welcome h1').textContent = brand.name;
  }
  if (brand.logo_url) {
    $('#brand-logo').src = brand.logo_url;
    $('#brand-logo').hidden = false;
  }
}).catch(() => { /* Keep the default branding when configuration is unavailable. */ });
