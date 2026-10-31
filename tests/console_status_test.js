"use strict";

const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");
const path = require("node:path");

const nodes = new Map();
const node = (selector) => {
  if (!nodes.has(selector)) nodes.set(selector, { hidden: true, dataset: {}, textContent: "", addEventListener() {} });
  return nodes.get(selector);
};
const context = vm.createContext({
  document: { querySelector: node, addEventListener() {}, hidden: false },
  window: { sessionStorage: { getItem: () => null } },
  setInterval() {}, setTimeout, clearTimeout, AbortController, Date,
  fetch: async () => ({ json: async () => ({ configured: true }) }),
});
vm.runInContext(fs.readFileSync(path.join(__dirname, "../public/console.js"), "utf8") +
  "\nglobalThis.hooks = { state, renderUpstreamStatus, loadUpstreamStatus };", context);
const { state, renderUpstreamStatus, loadUpstreamStatus } = context.hooks;

(async () => {
  renderUpstreamStatus();
  assert.equal(node("#upstream-health").hidden, true);
  state.info = {};
  state.key = "test-only";
  renderUpstreamStatus();
  assert.match(node("#upstream-label").textContent, /unknown/);
  let requests = 0;
  context.fetch = async (url, options) => {
    requests++;
    assert.equal(url, "/v1/status");
    assert.equal(options.headers.Authorization, "Bearer test-only");
    return { ok: true, json: async () => ({ upstream: { state: "unavailable", retry_after: 60 } }) };
  };
  await loadUpstreamStatus();
  assert.equal(requests, 1);
  assert.equal(node("#upstream-health").dataset.state, "unavailable");
  assert.match(node("#upstream-label").textContent, /Cooling down/);
  assert.match(node("#upstream-detail").textContent, /retry in 60s/);
  state.healthReceivedAt -= 61000;
  renderUpstreamStatus();
  assert.match(node("#upstream-detail").textContent, /next request/);
  assert.doesNotMatch(node("#upstream-label").textContent, /Reachable/);
  state.health = { state: "retry_ready", retry_after: 0 };
  renderUpstreamStatus();
  assert.match(node("#upstream-label").textContent, /Ready to retry/);
  state.health = { state: "healthy", retry_after: 0, last_observed_at: new Date().toISOString() };
  renderUpstreamStatus();
  assert.match(node("#upstream-label").textContent, /Reachable/);
  context.fetch = async () => { throw new Error("offline"); };
  await loadUpstreamStatus();
  assert.equal(node("#upstream-health").dataset.state, "unknown");
  assert.match(node("#upstream-label").textContent, /status unavailable/);
  assert.match(node("#upstream-detail").textContent, /retrying automatically/);

  let resolve;
  context.fetch = () => new Promise((done) => { resolve = done; });
  const pending = loadUpstreamStatus();
  state.key = "new-key";
  resolve({ ok: true, json: async () => ({ upstream: { state: "degraded" } }) });
  await pending;
  assert.equal(state.health.state, "healthy", "Old credentials' pending status must be ignored");
  console.log("Console status checks passed.");
})().catch((error) => { console.error(error); process.exitCode = 1; });
