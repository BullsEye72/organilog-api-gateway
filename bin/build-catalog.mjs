// Builds data/catalog.json from Organilog's published Postman collection.
//
//   node bin/build-catalog.mjs [collection.json] (from the project root)
//
// Without an argument it downloads the public collection (documentation host, not the API).
// The catalog is both the console's endpoint list and the proxy's allowlist: an entity/action
// pair that is not in it is refused, so the proxy can never be used to probe for endpoints.

import { readFile, writeFile } from "node:fs/promises";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const COLLECTION_URL = "https://documenter.gw.postman.com/api/collections/2071554/UzJFxK4j";
const DOCS_URL = "https://documenter.getpostman.com/view/2071554/UzJFxK4j";
const OUT = join(dirname(fileURLToPath(import.meta.url)), "..", "data", "catalog.json");

// Exercised against the live API during development (2026-09-10 / 2026-09-11).
// Everything else comes from the docs alone and is marked unverified in the console.
const VERIFIED_LIVE = new Set([
  "account", "issue/read_all", "issue/read_one", "issue/read_multiple", "issue/update",
  "client/read_all", "client/read_one", "user/read_all", "user/read_one", "contact/read_all",
  "filiale/read_all", "adresse/read_all", "adresse/read_one", "intervention/read_all",
  "response/read_all", "response/create",
]);

// Where live behaviour differs from the docs, or a trap is worth flagging.
const NOTES = {
  "issue/read_one":
    "Takes the internal `id` or `code_id` (the ticket number people quote); code_id is the one used live. " +
    "Omits date_start / date_end; use issue/read_multiple when you need them.",
  "issue/read_multiple": "`ids` are internal ids. Unlike read_one, returns date_start / date_end.",
  "issue/update":
    "`id` is the internal id, not the code_id. Fields in `data` are merged into the ticket (verified " +
    "2026-09-11); the proxy still snapshots, re-reads and restores if a field is lost.",
  "response/read_all":
    "Ticket comments. Filter with item_type=issue and fk_item_id=<ticket internal id>.",
  "contrat/read_multiple":
    "The docs point this at contact/read_multiple.php, which looks like a copy-paste slip; corrected to contrat/. Unverified.",
  "custom_field/new":
    "Creates a custom-field definition. This is a write, despite the action being named new. " +
    "custom_field/create instead upserts values on existing records.",
  "custom_field/update": "Updates a custom-field definition, not its values on a record.",
};
const DELETE_NOTE =
  "Probably archives rather than erases (read_all describes archived items as is_actif=0, " +
  "\"cf. supprimés\"). Inferred from the docs, unverified.";

const ENTITY_NOTES = {
  issue:
    "Organilog says issue, the UI says ticket. status: 1 Nouveau, 2 En cours, 3 Résolu, " +
    "4 Attente de réponse, 5 Fermé, 6 Rejeté (read off the UI 2026-09-11). Priority codes are unconfirmed. " +
    "Empty values come back as sentinels: 0000-00-00, \"0\" for no relation, \"None\" for estimated_hours.",
  filiale: "Not a subsidiary: these rows are intervention / contract types (Curatif, Préventif …).",
  response: "Comments on tickets (item_type=issue) and on chantiers (item_type=intervention_group).",
};

function kindOf(action) {
  if (action === "new") return "create";
  if (action === "create" || action === "update" || action === "delete") return action;
  if (["", "read_one", "read_all", "read_multiple", "search"].includes(action)) return "read";
  throw new Error(`Unknown action ${action}: classify its permissions before adding it to the allowlist`);
}

function toText(html) {
  return (html || "")
    .replace(/<\/(p|li|tr|h\d)>/gi, "\n")
    .replace(/<br\s*\/?>/gi, "\n")
    .replace(/<[^>]+>/g, " ")
    .replace(/&nbsp;/g, " ").replace(/&amp;/g, "&").replace(/&lt;/g, "<").replace(/&gt;/g, ">")
    .replace(/&quot;/g, "\"").replace(/&#39;/g, "'")
    .replace(/[ \t]+/g, " ")
    .replace(/ *\n */g, "\n")
    .replace(/\n{2,}/g, "\n")
    .trim();
}

function sampleOf(raw) {
  if (!raw) return {};
  try {
    const body = JSON.parse(raw);
    delete body.account;
    delete body.token;
    return body;
  } catch {
    return {}; // a malformed sample is not worth guessing at
  }
}

export async function loadCollection(path) {
  if (path) return JSON.parse(await readFile(path, "utf8"));
  const res = await fetch(COLLECTION_URL, { signal: AbortSignal.timeout(30000) });
  if (!res.ok) throw new Error(`collection download failed: HTTP ${res.status}`);
  return res.json();
}

export function buildCatalog(collection, generatedAt = new Date().toISOString()) {
  const endpoints = [];
  const entities = {};

  (function walk(items, group) {
    for (const item of items) {
      if (item.item) { walk(item.item, group ?? item.name.trim()); continue; }
      const req = item.request ?? {};
      const raw = typeof req.url === "string" ? req.url : req.url?.raw ?? "";
      const url = new URL(raw);
      if (url.origin !== "https://sync.organilog.com" || url.search || url.hash
          || !/^\/api\/v3\/[a-z_]+\/(?:[a-z_]+\.php)?$/.test(url.pathname)) {
        throw new Error(`Unsupported endpoint URL in ${item.name}`);
      }
      if (req.method !== "POST") throw new Error(`Unsupported method in ${item.name}`);
      let path = url.pathname.slice("/api/v3/".length);
      if (group === "Contrat" && path === "contact/read_multiple.php") path = "contrat/read_multiple.php";

      const [entity, file = ""] = path.split("/");
      const action = file.replace(/\.php$/, "");
      const id = action ? `${entity}/${action}` : entity;
      if (action === "" && entity !== "account") throw new Error(`Unknown action for ${id}`);
      if (endpoints.some((e) => e.id === id)) throw new Error(`Duplicate endpoint ${id}`);

      const sample = sampleOf(req.body?.raw);
      if (id === "issue/read_one") { delete sample.id; sample.code_id = "12345"; }
      // Postman's published navigation uses the request's _postman_id as its hash.
      const documentationId = item._postman_id ?? item.id;
      const documentationUrl = typeof documentationId === "string"
        && /^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i.test(documentationId)
        ? `${DOCS_URL}#${documentationId}` : DOCS_URL;

      entities[entity] ??= { group: group === "_ Init" ? "Account" : (group ?? entity).replace(/\s+/g, " "), note: ENTITY_NOTES[entity] ?? null };
      endpoints.push({
        id,
        entity,
        action,
        kind: kindOf(action),
        group: entities[entity].group,
        name: item.name.trim(),
        documentation_url: documentationUrl,
        description: toText(req.description),
        sample,
        verified_live: VERIFIED_LIVE.has(id),
        note: NOTES[id] ?? (action === "delete" ? DELETE_NOTE : null),
      });
    }
  })(collection.item, null);

  if (endpoints.length === 0) throw new Error("The published collection contains no endpoints; refusing an empty catalog.");

  return {
    source: DOCS_URL,
    generated_at: generatedAt,
    daily_limit_note: toText(collection.info?.description).match(/limite journalière de [\d.]+ requêtes/i)?.[0] ?? null,
    entities,
    endpoints,
  };
}

async function main() {
  const args = process.argv.slice(2);
  const check = args[0] === "--check";
  if (check) args.shift();
  if (args.length > 1 || args[0]?.startsWith("--")) throw new Error("Usage: node bin/build-catalog.mjs [--check] [collection.json]");
  const catalog = buildCatalog(await loadCollection(args[0]));
  if (check) {
    const current = JSON.parse(await readFile(OUT, "utf8"));
    // Generation time is deliberately ignored; compare documentation, samples,
    // permission kinds, and routes as well as the endpoint count.
    const { generated_at: oldTime, ...oldData } = current;
    const { generated_at: newTime, ...newData } = catalog;
    if (JSON.stringify(oldData) !== JSON.stringify(newData)) {
      const oldIds = new Set(current.endpoints.map(e => e.id));
      const newIds = new Set(catalog.endpoints.map(e => e.id));
      console.error(JSON.stringify({
        added: [...newIds].filter(id => !oldIds.has(id)),
        removed: [...oldIds].filter(id => !newIds.has(id)),
        message: "Catalog differs from the published collection. Regenerate and review the diff.",
      }, null, 2));
      process.exitCode = 1;
      return;
    }
    console.log(`Catalog matches: ${catalog.endpoints.length} endpoints across ${Object.keys(catalog.entities).length} entities`);
    return;
  }
  await writeFile(OUT, JSON.stringify(catalog, null, 2) + "\n", "utf8");
  console.log(`${catalog.endpoints.length} endpoints across ${Object.keys(catalog.entities).length} entities -> ${OUT}`);
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  await main();
}
