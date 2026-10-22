/**
 * Capacitor SQLite driver (NOT executable in unit tests: needs a device/WebView). Keep it small and re-verify on a real device.
 *
 * Plugin surface used (all checked against @capacitor-community/sqlite 8.1.1 dist/esm/definitions.d.ts):
 *   SQLiteConnection(CapacitorSQLite):
 *     checkConnectionsConsistency() -> {result?}      isConnection(db, readonly) -> {result?}     retrieveConnection(db, readonly)
 *     createConnection(db, encrypted, mode, version, readonly)      closeConnection(db, readonly)
 *     isSecretStored() -> {result?}      setEncryptionSecret(passphrase)      [web only] initWebStore()      saveToStore(db)
 *   SQLiteDBConnection:
 *     open()   execute(sql, transaction?, isSQL92?)   query(sql, values?) -> {values?}   run(sql, values?, transaction?, returnMode?) -> {changes?:{changes?}}
 *     beginTransaction()   commitTransaction()   rollbackTransaction()
 *   We own transaction boundaries (see BaseSqlPort), so EVERY execute/run passes transaction=false, otherwise the plugin opens its own
 *   nested transaction per statement. Transactions use the plugin's begin/commit/rollbackTransaction (not raw "BEGIN" strings), so the
 *   native layer's transaction bookkeeping stays correct. query() rows are filtered for an iOS `ios_columns` metadata row (the plugin
 *   normally strips it already).
 *
 * Native (iOS/Android): SQLCipher database. The passphrase is generated here once and handed to the plugin via setEncryptionSecret();
 *   the plugin keeps it in Keychain / Android-Keystore-backed storage. We never persist or log it (not in SQLite, Preferences or
 *   localStorage). The plugin keeps ONE secret per app, so `keychainPrefix` cannot select between secrets and is accepted but unused.
 * Web (dev only): jeep-sqlite (sql.js) in the page, NOT encrypted. Persisted to IndexedDB with saveToStore() after each write.
 *   jeep-sqlite's `wasmpath` attribute is a DIRECTORY (it loads `${wasmpath}/sql-wasm.wasm`); a path ending in .wasm is accepted and trimmed.
 *
 * The plugin modules are imported lazily so merely importing the db barrel (tests, SSR) never registers Capacitor plugins.
 */
import type { SQLiteConnection, SQLiteDBConnection } from "@capacitor-community/sqlite";
import type { OpenLocalDatabase, SqlValue } from "../contracts";
import { BaseSqlPort } from "./port";

const stripDb = (n: string) => (n.endsWith(".db") ? n.slice(0, -3) : n);

/** Exported only so tests can drive it with a fake plugin connection. Use openLocalDatabase(). */
export class CapacitorSqlPort extends BaseSqlPort {
  constructor(
    readonly kind: "capacitor-native" | "capacitor-web",
    readonly encrypted: boolean,
    private readonly sqlite: SQLiteConnection,
    private readonly conn: SQLiteDBConnection,
    private readonly dbName: string,
  ) { super(); }

  protected async rawExec(sql: string): Promise<void> { await this.conn.execute(sql, false); }

  protected async rawRun(sql: string, params: SqlValue[]): Promise<{ changes: number }> {
    const r = await this.conn.run(sql, params, false);
    return { changes: Number(r.changes?.changes ?? 0) };
  }

  protected async rawAll<T>(sql: string, params: SqlValue[]): Promise<T[]> {
    const r = await this.conn.query(sql, params);
    return (r.values ?? []).filter((row) => !(row && typeof row === "object" && "ios_columns" in row)) as T[];
  }

  protected override async begin(): Promise<void> { await this.conn.beginTransaction(); }
  protected override async commit(): Promise<void> { await this.conn.commitTransaction(); }
  protected override async rollback(): Promise<void> { await this.conn.rollbackTransaction(); }

  protected override async afterWrite(): Promise<void> {
    if (this.kind === "capacitor-web") await this.sqlite.saveToStore(this.dbName);
  }

  protected async rawClose(): Promise<void> { await this.sqlite.closeConnection(this.dbName, false); }
}

/** jeep-sqlite wants the DIRECTORY holding sql-wasm.wasm; accept a path to the file too. "/assets/sql-wasm.wasm" -> "/assets". */
export const jeepWasmDir = (wasmPath: string): string => wasmPath.replace(/\/?[^/]*\.wasm$/, "");

let jeepReady: Promise<void> | null = null;

/** Web/dev only: register <jeep-sqlite>, mount it, and open the IndexedDB-backed store. Idempotent. */
export function ensureJeepSqlite(wasmPath = "/assets/sql-wasm.wasm"): Promise<void> {
  jeepReady ??= (async () => {
    const [{ defineCustomElements }, { CapacitorSQLite, SQLiteConnection: Conn }] = await Promise.all([import("jeep-sqlite/loader"), import("@capacitor-community/sqlite")]);
    defineCustomElements(window);
    if (!document.querySelector("jeep-sqlite")) {
      const el = document.createElement("jeep-sqlite");
      el.setAttribute("wasmpath", jeepWasmDir(wasmPath));
      document.body.appendChild(el);
    }
    await customElements.whenDefined("jeep-sqlite");
    await new Conn(CapacitorSQLite).initWebStore();
  })().catch((e) => { jeepReady = null; throw e; });
  return jeepReady;
}

export const openLocalDatabase: OpenLocalDatabase = async ({ name, allowUnencryptedWeb }) => {
  const [{ Capacitor }, { CapacitorSQLite, SQLiteConnection: Conn }] = await Promise.all([import("@capacitor/core"), import("@capacitor-community/sqlite")]);
  const native = Capacitor.getPlatform() === "android" || Capacitor.getPlatform() === "ios";
  const dbName = stripDb(name);

  if (!native) {
    if (allowUnencryptedWeb === false) throw new Error("An unencrypted web database is not allowed here.");
    await ensureJeepSqlite();
  }
  const sqlite = new Conn(CapacitorSQLite);

  if (native && !(await sqlite.isSecretStored()).result) {
    const bytes = globalThis.crypto.getRandomValues(new Uint8Array(32));
    await sqlite.setEncryptionSecret(Array.from(bytes, (b) => b.toString(16).padStart(2, "0")).join(""));
  }

  // Recipe from the plugin docs: reuse a live native connection after a WebView reload, otherwise create one.
  const consistent = await sqlite.checkConnectionsConsistency().then((r) => !!r.result, () => false);
  const exists = (await sqlite.isConnection(dbName, false)).result;
  const conn = consistent && exists
    ? await sqlite.retrieveConnection(dbName, false)
    : await sqlite.createConnection(dbName, native, native ? "secret" : "no-encryption", 1, false);
  await conn.open();
  // No foreign keys are declared in our schema; this is belt-and-braces and must not block opening the database.
  try { await conn.execute("PRAGMA foreign_keys = ON;", false); } catch { /* ignore */ }

  return new CapacitorSqlPort(native ? "capacitor-native" : "capacitor-web", native, sqlite, conn, dbName);
};
