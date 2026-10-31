"use strict";

const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const nodes = new Map();
function node(selector) {
  if (!nodes.has(selector)) nodes.set(selector, {
    hidden: false, dataset: {}, value: "", checked: false, disabled: false,
    textContent: "", firstChild: { textContent: "" }, addEventListener() {},
  });
  return nodes.get(selector);
}
const storage = new Map();
const listeners = new Map();
const requests = [];
let confirmations = 0;
const context = vm.createContext({
  document: {
    querySelector: node, hidden: false,
    addEventListener: (type, callback) => listeners.set(type, callback),
  },
  window: {
    sessionStorage: { getItem: () => null },
    localStorage: {
      getItem: (key) => storage.get(key) ?? null,
      setItem: (key, value) => storage.set(key, value),
    },
  },
  setInterval() {}, setTimeout, clearTimeout, AbortController, Date, URLSearchParams, performance,
  confirm: () => { confirmations++; return true; },
  fetch: (url, options) => url === "/branding.php"
    ? Promise.resolve({ json: async () => ({ configured: true }) })
    : new Promise((resolve, reject) => requests.push({ url, options, resolve, reject })),
});
vm.runInContext(fs.readFileSync(path.join(__dirname, "../public/console.js"), "utf8") +
  "\nglobalThis.hooks = { state, send };", context);
const { state, send } = context.hooks;

(async () => {
  state.key = "fixture-key";
  state.current = { id: "response/create", entity: "response", kind: "create" };
  node("#method").value = "POST";
  const originalBody = '{"data":{"message":"original"}}';
  node("#body").value = originalBody;
  const pending = send();
  assert.equal(node("#send").disabled, true);
  listeners.get("keydown")({ key: "Enter", ctrlKey: true, preventDefault() {} });
  await send();
  assert.equal(requests.length, 1, "Repeated keyboard submissions must not start another write");
  assert.equal(confirmations, 1, "A pending request must not open another confirmation");

  state.current = { id: "issue/update", entity: "issue", kind: "update" };
  node("#body").value = '{"id":"456","data":{"status":"5"}}';
  node("#normalize").checked = true;
  requests[0].resolve({ status: 200, ok: true, text: async () => '{"ok":true}' });
  await pending;
  const history = JSON.parse(storage.get("olk-history"));
  assert.equal(requests[0].url, "/v1/response/create");
  assert.equal(requests[0].options.body, JSON.stringify(JSON.parse(originalBody)));
  assert.equal(history[0].id, "response/create");
  assert.equal(history[0].body, originalBody, "History must keep the submitted body after the editor changes");
  assert.equal(history[0].normalize, false, "History must keep the submitted normalization setting");
  assert.equal(node("#send").disabled, false);

  const failing = send();
  requests[1].reject(new Error("offline"));
  await failing;
  assert.equal(node("#send").disabled, false, "Network errors must release the submission guard");
  assert.equal(JSON.parse(storage.get("olk-history")).length, 1);
  const retry = send();
  assert.equal(requests.length, 3, "A later manual submission must still work");
  requests[2].resolve({ status: 200, ok: true, text: async () => '{"ok":true}' });
  await retry;
  console.log("Console request checks passed.");
})().catch((error) => { console.error(error); process.exitCode = 1; });
