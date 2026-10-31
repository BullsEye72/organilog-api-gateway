// Builds data/catalog.json from Organilog's published Postman collection.
//
//   node bin/build-catalog.mjs [collection.json] (from the project root)
//
// Without an argument it downloads the public collection (documentation host, not the API).
// The catalog is both the console's endpoint list and the proxy's allowlist: an entity/action
// pair that is not in it is refused, so the proxy can never be used to probe for endpoints.

import { readFile, writeFile } from "node:fs/promises";
import { dirname, join } from "node:path";
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
  if (action === "create" || action === "update" || action === "delete") return action;
  return "read";
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

async function loadCollection(path) {
  if (path) return JSON.parse(await readFile(path, "utf8"));
  const res = await fetch(COLLECTION_URL);
  if (!res.ok) throw new Error(`collection download failed: HTTP ${res.status}`);
  return res.json();
}

const collection = await loadCollection(process.argv[2]);
const endpoints = [];
const entities = {};

(function walk(items, group) {
  for (const item of items) {
    if (item.item) { walk(item.item, group ?? item.name.trim()); continue; }
    const req = item.request ?? {};
    const raw = typeof req.url === "string" ? req.url : req.url?.raw ?? "";
    let path = raw.replace(/^.*\/api\/v3\//, "");
    if (group === "Contrat" && path === "contact/read_multiple.php") path = "contrat/read_multiple.php";

    const [entity, file = ""] = path.split("/");
    const action = file.replace(/\.php$/, "");
    const id = action ? `${entity}/${action}` : entity;
    if (endpoints.some((e) => e.id === id)) continue;

    const sample = sampleOf(req.body?.raw);
    if (id === "issue/read_one") { delete sample.id; sample.code_id = "12345"; }

    entities[entity] ??= { group: group === "_ Init" ? "Account" : group.replace(/\s+/g, " "), note: ENTITY_NOTES[entity] ?? null };
    endpoints.push({
      id,
      entity,
      action,
      kind: kindOf(action),
      group: entities[entity].group,
      name: item.name.trim(),
      description: toText(req.description),
      sample,
      verified_live: VERIFIED_LIVE.has(id),
      note: NOTES[id] ?? (action === "delete" ? DELETE_NOTE : null),
    });
  }
})(collection.item, null);

const catalog = {
  source: DOCS_URL,
  generated_at: new Date().toISOString(),
  daily_limit_note: toText(collection.info?.description).match(/limite journalière de [\d.]+ requêtes/i)?.[0] ?? null,
  entities,
  endpoints,
};

await writeFile(OUT, JSON.stringify(catalog, null, 2) + "\n", "utf8");
console.log(`${endpoints.length} endpoints across ${Object.keys(entities).length} entities -> ${OUT}`);
