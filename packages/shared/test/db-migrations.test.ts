import { describe, expect, it } from "vitest";
import { CURRENT_SCHEMA_VERSION, MIGRATIONS, MigrationError, NewerSchemaError, migrate, resetCacheTables, runMigrations, type Migration } from "../src/db/migrations";
import { createMetaStore } from "../src/db/metaStore";
import { createNodeSqlitePort } from "./support/nodePort";

const tables = async (port: ReturnType<typeof createNodeSqlitePort>) =>
  (await port.all<{ name: string }>(`SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name`)).map((r) => r.name);
const version = async (port: ReturnType<typeof createNodeSqlitePort>) => Number((await port.all<{ v: number }>(`SELECT MAX(version) AS v FROM schema_migrations`))[0]!.v);
const cols = async (port: ReturnType<typeof createNodeSqlitePort>, table: string) => (await port.all<{ name: string }>(`PRAGMA table_info(${table})`)).map((c) => c.name);

const v3Boom: Migration = { version: 3, name: "boom", up: [`CREATE TABLE half_done (a INTEGER)`, `INSERT INTO table_that_does_not_exist VALUES (1)`] };
const v3Ok: Migration = { version: 3, name: "future", up: [`CREATE TABLE future_stuff (a INTEGER)`] };

describe("local schema migrations", () => {
  it("fresh install migrates 0 -> latest and creates every table", async () => {
    const port = createNodeSqlitePort();
    expect(await migrate(port)).toEqual({ from: 0, to: CURRENT_SCHEMA_VERSION });
    expect(CURRENT_SCHEMA_VERSION).toBe(2);
    expect(await tables(port)).toEqual(["cache_entities", "cache_keys", "drafts", "meta", "outbox", "photo_queue", "schema_migrations", "sync_state"]);
    expect(await cols(port, "outbox")).toContain("priority");
    expect(await cols(port, "photo_queue")).toContain("attempts");
    const idx = (await port.all<{ name: string }>(`SELECT name FROM sqlite_master WHERE type = 'index'`)).map((i) => i.name);
    expect(idx).toContain("idx_cache_entities_synced");
    expect(idx).toContain("idx_cache_entities_search");
  });

  it("upgrades a v1 database that already holds data to v2, preserving rows and applying defaults", async () => {
    const port = createNodeSqlitePort();
    expect(await runMigrations(port, MIGRATIONS.slice(0, 1))).toEqual({ from: 0, to: 1 });
    expect(await cols(port, "outbox")).not.toContain("priority");

    await port.run(`INSERT INTO outbox (id, scope_key, kind, payload, state, created_at, updated_at) VALUES ('o1','vendor:1:5','stock.adjust','{"q":1}','queued','2026-01-01T00:00:00.000Z','2026-01-01T00:00:00.000Z')`);
    await port.run(`INSERT INTO photo_queue (id, scope_key, file_ref, mime, size, purpose, created_at) VALUES ('p1','vendor:1:5','f1','image/jpeg',10,'ai','2026-01-01T00:00:00.000Z')`);
    await port.run(`INSERT INTO drafts (scope_key, kind, id, payload, updated_at) VALUES ('vendor:1:5','sale','d1','{}','2026-01-01T00:00:00.000Z')`);
    await port.run(`INSERT INTO meta (key, value) VALUES ('install_id','abc')`);
    await port.run(`INSERT INTO cache_entities (scope_key, entity, id, payload, synced_at) VALUES ('vendor:1:5','listing',1,'{}','2026-01-01T00:00:00.000Z')`);

    expect(await migrate(port)).toEqual({ from: 1, to: 2 });
    expect(await version(port)).toBe(2);
    const o = (await port.all<Record<string, unknown>>(`SELECT * FROM outbox WHERE id = 'o1'`))[0]!;
    expect(o).toMatchObject({ id: "o1", payload: '{"q":1}', state: "queued", attempts: 0, priority: 0, payload_version: 1, label: "" });
    expect((await port.all<{ attempts: number }>(`SELECT attempts FROM photo_queue WHERE id = 'p1'`))[0]!.attempts).toBe(0);
    expect(await port.all(`SELECT 1 FROM drafts`)).toHaveLength(1);
    expect(await createMetaStore(port).get("install_id")).toBe("abc");
    expect(await port.all(`SELECT 1 FROM cache_entities`)).toHaveLength(1);
  });

  it("is idempotent: re-running changes nothing", async () => {
    const port = createNodeSqlitePort();
    await migrate(port);
    await port.run(`INSERT INTO meta (key, value) VALUES ('k','v')`);
    const applied = await port.all(`SELECT * FROM schema_migrations ORDER BY version`);
    expect(await migrate(port)).toEqual({ from: 2, to: 2 });
    expect(await migrate(port)).toEqual({ from: 2, to: 2 });
    expect(await port.all(`SELECT * FROM schema_migrations ORDER BY version`)).toEqual(applied);
    expect(await createMetaStore(port).get("k")).toBe("v");
  });

  it("a failing migration rolls back everything it did, leaves the version unchanged and throws MigrationError", async () => {
    const port = createNodeSqlitePort();
    await migrate(port);
    const before = await tables(port);
    const err = await runMigrations(port, [...MIGRATIONS, v3Boom]).catch((e) => e);
    expect(err).toBeInstanceOf(MigrationError);
    expect(err.version).toBe(3);
    expect(err.cause).toBeInstanceOf(Error);
    expect(await version(port)).toBe(2);
    expect(await tables(port)).toEqual(before); // CREATE TABLE half_done was rolled back with the failed INSERT
    // the app keeps working at v2 and a later good v3 still applies
    expect(await runMigrations(port, [...MIGRATIONS, v3Ok])).toEqual({ from: 2, to: 3 });
  });

  it("a failure in v2 leaves a clean v1 database (partial ALTERs are undone)", async () => {
    const port = createNodeSqlitePort();
    const brokenV2: Migration = { version: 2, name: "broken", up: [`ALTER TABLE outbox ADD COLUMN priority INTEGER NOT NULL DEFAULT 0`, `ALTER TABLE no_such_table ADD COLUMN x INTEGER`] };
    await expect(runMigrations(port, [MIGRATIONS[0]!, brokenV2])).rejects.toBeInstanceOf(MigrationError);
    expect(await version(port)).toBe(1);
    expect(await cols(port, "outbox")).not.toContain("priority");
  });

  it("a database written by a NEWER app throws NewerSchemaError; resetCacheTables recovers the cache but keeps outbox, drafts and meta", async () => {
    const port = createNodeSqlitePort();
    await runMigrations(port, [...MIGRATIONS, v3Ok]);
    await port.run(`INSERT INTO outbox (id, scope_key, kind, payload, state, created_at, updated_at) VALUES ('o1','vendor:1:5','k','{}','queued','t','t')`);
    await port.run(`INSERT INTO drafts (scope_key, kind, id, payload, updated_at) VALUES ('vendor:1:5','sale','d1','{}','t')`);
    await port.run(`INSERT INTO photo_queue (id, scope_key, file_ref, mime, size, purpose, created_at) VALUES ('p1','vendor:1:5','f','image/jpeg',1,'x','t')`);
    await port.run(`INSERT INTO meta (key, value) VALUES ('install_id','abc')`);
    await port.run(`INSERT INTO cache_entities (scope_key, entity, id, payload, synced_at) VALUES ('vendor:1:5','listing',1,'{}','t')`);
    await port.run(`INSERT INTO cache_keys (scope_key, entity, key, id) VALUES ('vendor:1:5','listing','abc',1)`);
    await port.run(`INSERT INTO sync_state (scope_key, cursor, snapshot_complete) VALUES ('vendor:1:5','9.9',1)`);

    const err = await migrate(port).catch((e) => e);
    expect(err).toBeInstanceOf(NewerSchemaError);
    expect(err).toMatchObject({ dbVersion: 3, appVersion: 2 });
    expect(await version(port)).toBe(3); // untouched

    await resetCacheTables(port);
    for (const t of ["cache_entities", "cache_keys", "sync_state"]) expect(await port.all(`SELECT 1 FROM ${t}`)).toHaveLength(0);
    expect(await port.all(`SELECT 1 FROM outbox`)).toHaveLength(1);
    expect(await port.all(`SELECT 1 FROM drafts`)).toHaveLength(1);
    expect(await port.all(`SELECT 1 FROM photo_queue`)).toHaveLength(1);
    expect(await createMetaStore(port).get("install_id")).toBe("abc");
    // recreated at the CURRENT schema, including the v2 index, and usable
    const idx = (await port.all<{ name: string }>(`SELECT name FROM sqlite_master WHERE type = 'index'`)).map((i) => i.name);
    expect(idx).toEqual(expect.arrayContaining(["idx_cache_entities_synced", "idx_cache_entities_search"]));
    await port.run(`INSERT INTO cache_entities (scope_key, entity, id, payload, synced_at) VALUES ('s','e',1,'{}','t')`);
  });

  it("resetCacheTables on a healthy database also leaves user work alone and is repeatable", async () => {
    const port = createNodeSqlitePort();
    await migrate(port);
    await port.run(`INSERT INTO outbox (id, scope_key, kind, payload, state, created_at, updated_at) VALUES ('o1','s','k','{}','needs_review','t','t')`);
    await resetCacheTables(port);
    await resetCacheTables(port);
    expect((await port.all<{ state: string }>(`SELECT state FROM outbox`))[0]!.state).toBe("needs_review");
    expect(await version(port)).toBe(2);
  });

  it("the outbox state CHECK constraint rejects unknown states", async () => {
    const port = createNodeSqlitePort();
    await migrate(port);
    await expect(port.run(`INSERT INTO outbox (id, scope_key, kind, payload, state, created_at, updated_at) VALUES ('o','s','k','{}','bogus','t','t')`)).rejects.toThrow();
  });
});
