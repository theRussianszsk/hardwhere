import type { SqlExec, SqlPort, Migrate } from "../contracts";

/**
 * Local SQLite schema (independent from Laravel's migrations). Ordered, append-only list.
 * Each migration runs inside ONE transaction together with its schema_migrations row, so a failure leaves the
 * previous version fully intact. Timestamps are ISO-8601 TEXT, ids INTEGER, JSON documents TEXT.
 */
export interface Migration { version: number; name: string; up: string[] }

export class MigrationError extends Error {
  constructor(public readonly version: number, public readonly migrationName: string, public override readonly cause: unknown) {
    super(`Local migration ${version} (${migrationName}) failed: ${cause instanceof Error ? cause.message : String(cause)}`);
    this.name = "MigrationError";
  }
}
/** The database was written by a newer app build. Do not touch it; recover with resetCacheTables() or reinstall. */
export class NewerSchemaError extends Error {
  constructor(public readonly dbVersion: number, public readonly appVersion: number) {
    super(`Local database schema v${dbVersion} is newer than this app understands (v${appVersion}).`);
    this.name = "NewerSchemaError";
  }
}

/** Disposable server-derived tables: dropped/recreated by resetCacheTables(), never user work. */
const CACHE_TABLES = ["cache_entities", "cache_keys", "sync_state"] as const;
const CACHE_DDL_V1 = [
  `CREATE TABLE cache_entities (
     scope_key TEXT NOT NULL, entity TEXT NOT NULL, id INTEGER NOT NULL, payload TEXT NOT NULL, search TEXT NOT NULL DEFAULT '',
     row_version INTEGER, filters TEXT, snapshot_id TEXT, synced_at TEXT NOT NULL,
     PRIMARY KEY (scope_key, entity, id))`,
  `CREATE INDEX idx_cache_entities_search ON cache_entities (scope_key, entity, search)`,
  `CREATE TABLE cache_keys (
     scope_key TEXT NOT NULL, entity TEXT NOT NULL, key TEXT NOT NULL, id INTEGER NOT NULL,
     PRIMARY KEY (scope_key, entity, key, id))`,
  `CREATE TABLE sync_state (
     scope_key TEXT PRIMARY KEY, cursor TEXT, snapshot_complete INTEGER NOT NULL DEFAULT 0, pending_cursor TEXT, snapshot_id TEXT,
     snapshot_progress TEXT, last_synced_at TEXT, last_attempt_at TEXT, last_error TEXT)`,
];
const CACHE_INDEX_V2 = `CREATE INDEX idx_cache_entities_synced ON cache_entities (scope_key, entity, synced_at)`;

export const MIGRATIONS: readonly Migration[] = [
  {
    version: 1,
    name: "initial",
    up: [
      `CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)`,
      ...CACHE_DDL_V1,
      `CREATE TABLE outbox (
         id TEXT PRIMARY KEY, scope_key TEXT NOT NULL, kind TEXT NOT NULL, payload TEXT NOT NULL, payload_version INTEGER NOT NULL DEFAULT 1,
         label TEXT NOT NULL DEFAULT '',
         state TEXT NOT NULL CHECK (state IN ('draft','queued','sending','confirmed','rejected','needs_review')),
         attempts INTEGER NOT NULL DEFAULT 0, next_attempt_at TEXT, last_error TEXT, response TEXT,
         created_at TEXT NOT NULL, updated_at TEXT NOT NULL)`,
      `CREATE INDEX idx_outbox_due ON outbox (scope_key, state, next_attempt_at)`,
      `CREATE TABLE drafts (
         scope_key TEXT NOT NULL, kind TEXT NOT NULL, id TEXT NOT NULL, payload TEXT NOT NULL, updated_at TEXT NOT NULL,
         PRIMARY KEY (scope_key, kind, id))`,
      `CREATE TABLE photo_queue (
         id TEXT PRIMARY KEY, scope_key TEXT NOT NULL, file_ref TEXT NOT NULL, mime TEXT NOT NULL, size INTEGER NOT NULL,
         purpose TEXT NOT NULL, hint TEXT, outbox_id TEXT, created_at TEXT NOT NULL)`,
    ],
  },
  {
    version: 2,
    name: "outbox_priority_photo_attempts_synced_index",
    up: [
      `ALTER TABLE outbox ADD COLUMN priority INTEGER NOT NULL DEFAULT 0`,
      `ALTER TABLE photo_queue ADD COLUMN attempts INTEGER NOT NULL DEFAULT 0`,
      CACHE_INDEX_V2,
    ],
  },
];

export const CURRENT_SCHEMA_VERSION = MIGRATIONS[MIGRATIONS.length - 1]!.version;

const MIGRATIONS_TABLE = `CREATE TABLE IF NOT EXISTS schema_migrations (version INTEGER PRIMARY KEY, name TEXT, applied_at TEXT)`;

async function dbVersion(q: SqlExec): Promise<number> {
  const rows = await q.all<{ v: number | null }>(`SELECT MAX(version) AS v FROM schema_migrations`);
  return Number(rows[0]?.v ?? 0);
}

/** Apply `list` (ordered) to the database. Exported so tests can inject a failing/newer migration. */
export async function runMigrations(port: SqlPort, list: readonly Migration[], now: () => number = Date.now): Promise<{ from: number; to: number }> {
  await port.exec(MIGRATIONS_TABLE);
  const from = await dbVersion(port);
  const known = list.length ? list[list.length - 1]!.version : 0;
  if (from > known) throw new NewerSchemaError(from, known);

  let to = from;
  for (const m of list) {
    if (m.version <= from) continue;
    try {
      await port.transaction(async (tx) => {
        for (const sql of m.up) await tx.exec(sql);
        await tx.run(`INSERT INTO schema_migrations (version, name, applied_at) VALUES (?, ?, ?)`, [m.version, m.name, new Date(now()).toISOString()]);
      });
    } catch (e) {
      throw new MigrationError(m.version, m.name, e);
    }
    to = m.version;
  }
  return { from, to };
}

export const migrate: Migrate = (port) => runMigrations(port, MIGRATIONS);

/**
 * Recovery path (corrupt/unreadable cache, or a downgrade): drop and recreate ONLY the server-derived tables at the
 * CURRENT schema. outbox, drafts, photo_queue, meta and schema_migrations are never touched. The next sync re-snapshots.
 */
export async function resetCacheTables(port: SqlPort): Promise<void> {
  await port.transaction(async (tx) => {
    for (const t of CACHE_TABLES) await tx.exec(`DROP TABLE IF EXISTS ${t}`);
    for (const sql of CACHE_DDL_V1) await tx.exec(sql);
    await tx.exec(CACHE_INDEX_V2);
  });
}
