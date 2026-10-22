import { DatabaseSync } from "node:sqlite";
import { describe, expect, it, vi } from "vitest";
import { CapacitorSqlPort, jeepWasmDir } from "../src/db/capacitorPort";
import * as dbBarrel from "../src/db";
import { migrate } from "../src/db/migrations";

/**
 * The real @capacitor-community/sqlite plugin needs a device. These tests drive CapacitorSqlPort with a fake connection that has the
 * plugin's SQLiteDBConnection shape (backed by node:sqlite) to prove HOW we call it: transaction=false everywhere, the plugin's own
 * begin/commit/rollback, the iOS metadata row filtered out, and saveToStore only on web after writes.
 */
function fakePlugin(o: { iosColumns?: boolean } = {}) {
  const db = new DatabaseSync(":memory:");
  const log: string[] = [];
  const txFlags: unknown[] = [];
  const conn = {
    execute: vi.fn(async (sql: string, tx?: boolean) => { txFlags.push(tx); db.exec(sql); return { changes: { changes: 0 } }; }),
    run: vi.fn(async (sql: string, values?: unknown[], tx?: boolean) => { txFlags.push(tx); const r = db.prepare(sql).run(...((values ?? []) as any[])); return { changes: { changes: Number(r.changes) } }; }),
    query: vi.fn(async (sql: string, values?: unknown[]) => {
      const rows = db.prepare(sql).all(...((values ?? []) as any[]));
      return { values: o.iosColumns ? [{ ios_columns: Object.keys(rows[0] ?? {}) }, ...rows] : rows };
    }),
    beginTransaction: vi.fn(async () => { log.push("begin"); db.exec("BEGIN"); return {}; }),
    commitTransaction: vi.fn(async () => { log.push("commit"); db.exec("COMMIT"); return {}; }),
    rollbackTransaction: vi.fn(async () => { log.push("rollback"); db.exec("ROLLBACK"); return {}; }),
  };
  const sqlite = { saveToStore: vi.fn(async (_db: string) => { log.push("save"); }), closeConnection: vi.fn(async (_db: string, _ro: boolean) => { log.push("close"); }) };
  return { conn, sqlite, log, txFlags };
}
const make = (kind: "capacitor-native" | "capacitor-web", f = fakePlugin()) => ({ f, port: new CapacitorSqlPort(kind, kind === "capacitor-native", f.sqlite as any, f.conn as any, "hardwhere") });

describe("CapacitorSqlPort (driven by a fake plugin connection)", () => {
  it("reports its kind/encryption and works as a normal SqlPort (schema migrates, rows round-trip)", async () => {
    const { port } = make("capacitor-native");
    expect(port).toMatchObject({ kind: "capacitor-native", encrypted: true });
    expect(await migrate(port)).toEqual({ from: 0, to: 2 });
    await port.run(`INSERT INTO meta (key, value) VALUES (?, ?)`, ["k", "v"]);
    expect(await port.all(`SELECT value FROM meta WHERE key = ?`, ["k"])).toEqual([{ value: "v" }]);
    expect(make("capacitor-web").port).toMatchObject({ kind: "capacitor-web", encrypted: false });
  });

  it("never lets the plugin open its own per-statement transaction (transaction=false on every execute/run)", async () => {
    const { port, f } = make("capacitor-native");
    await port.exec(`CREATE TABLE t (a INTEGER)`);
    await port.run(`INSERT INTO t VALUES (?)`, [1]);
    await port.transaction(async (tx) => { await tx.exec(`CREATE TABLE u (a INTEGER)`); await tx.run(`INSERT INTO u VALUES (?)`, [2]); });
    expect(f.txFlags.length).toBe(4);
    expect(f.txFlags.every((x) => x === false)).toBe(true);
  });

  it("transactions use the plugin's begin/commit/rollback; a throw rolls back and rethrows the original error", async () => {
    const { port, f } = make("capacitor-native");
    await port.exec(`CREATE TABLE t (a INTEGER)`);
    await port.transaction(async (tx) => { await tx.run(`INSERT INTO t VALUES (1)`); });
    const boom = new Error("boom");
    await expect(port.transaction(async (tx) => { await tx.run(`INSERT INTO t VALUES (2)`); throw boom; })).rejects.toBe(boom);
    expect(f.log).toEqual(["begin", "commit", "begin", "rollback"]);
    expect((await port.all<{ a: number }>(`SELECT a FROM t`)).map((r) => r.a)).toEqual([1]);
  });

  it("filters the iOS `ios_columns` metadata row out of query results", async () => {
    const { port } = make("capacitor-native", fakePlugin({ iosColumns: true }));
    await port.exec(`CREATE TABLE t (a INTEGER, b TEXT); INSERT INTO t VALUES (1,'x'),(2,'y')`);
    expect(await port.all(`SELECT a, b FROM t ORDER BY a`)).toEqual([{ a: 1, b: "x" }, { a: 2, b: "y" }]);
    expect(await port.all(`SELECT a FROM t WHERE a > 5`)).toEqual([]);
  });

  it("web: saves the in-memory database to IndexedDB after every committed transaction and standalone write, not after reads or rollbacks", async () => {
    const { port, f } = make("capacitor-web");
    await port.exec(`CREATE TABLE t (a INTEGER)`);
    expect(f.sqlite.saveToStore).toHaveBeenCalledTimes(1);
    await port.run(`INSERT INTO t VALUES (1)`);
    expect(f.sqlite.saveToStore).toHaveBeenCalledTimes(2);
    await port.all(`SELECT * FROM t`);
    expect(f.sqlite.saveToStore).toHaveBeenCalledTimes(2);
    await port.transaction(async (tx) => { await tx.run(`INSERT INTO t VALUES (2)`); await tx.run(`INSERT INTO t VALUES (3)`); });
    expect(f.sqlite.saveToStore).toHaveBeenCalledTimes(3); // once per transaction, after COMMIT
    expect(f.log.slice(-3)).toEqual(["begin", "commit", "save"]);
    await expect(port.transaction(async () => { throw new Error("x"); })).rejects.toThrow("x");
    expect(f.sqlite.saveToStore).toHaveBeenCalledTimes(3);
    expect(f.sqlite.saveToStore).toHaveBeenCalledWith("hardwhere");
  });

  it("native never calls saveToStore; close() goes through sqlite.closeConnection(name, false)", async () => {
    const { port, f } = make("capacitor-native");
    await port.exec(`CREATE TABLE t (a INTEGER)`);
    await port.transaction(async (tx) => { await tx.run(`INSERT INTO t VALUES (1)`); });
    expect(f.sqlite.saveToStore).not.toHaveBeenCalled();
    await port.close();
    expect(f.sqlite.closeConnection).toHaveBeenCalledWith("hardwhere", false);
  });
});

describe("db barrel / jeep-sqlite helpers", () => {
  it("jeep-sqlite wants a directory: a path to sql-wasm.wasm is trimmed to its folder", () => {
    expect(jeepWasmDir("/assets/sql-wasm.wasm")).toBe("/assets");
    expect(jeepWasmDir("/assets")).toBe("/assets");
    expect(jeepWasmDir("/static/wasm/sql-wasm.wasm")).toBe("/static/wasm");
    expect(jeepWasmDir("sql-wasm.wasm")).toBe("");
  });

  it("importing the barrel is inert (the Capacitor plugins are only loaded inside openLocalDatabase) and exposes the contract factories", () => {
    for (const name of ["openLocalDatabase", "migrate", "createMetaStore", "createLocalStore", "wipeScope", "countUnsent", "listPhotoRefs", "resetCacheTables", "ensureJeepSqlite"] as const)
      expect(typeof dbBarrel[name]).toBe("function");
  });
});
