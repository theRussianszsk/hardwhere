import { describe, expect, it } from "vitest";
import { createNodeSqlitePort } from "./support/nodePort";
import { useTmpDir } from "./support/helpers";

const tmp = useTmpDir();

describe("sql port semantics (BaseSqlPort via node:sqlite)", () => {
  it("commits a transaction and rolls back (rethrowing the ORIGINAL error) when fn throws", async () => {
    const port = createNodeSqlitePort();
    await port.exec(`CREATE TABLE t (a INTEGER)`);
    await port.transaction(async (tx) => { await tx.run(`INSERT INTO t VALUES (1)`); });
    const boom = new Error("boom");
    await expect(port.transaction(async (tx) => { await tx.run(`INSERT INTO t VALUES (2)`); throw boom; })).rejects.toBe(boom);
    expect((await port.all<{ a: number }>(`SELECT a FROM t`)).map((r) => r.a)).toEqual([1]);
    // the port is still usable after a rollback
    await port.run(`INSERT INTO t VALUES (3)`);
    expect(await port.all(`SELECT a FROM t`)).toHaveLength(2);
  });

  it("serialises: plain calls wait for a running transaction, and concurrent transactions never interleave", async () => {
    const port = createNodeSqlitePort();
    await port.exec(`CREATE TABLE c (n INTEGER); INSERT INTO c VALUES (0)`);
    const order: string[] = [];
    let releaseTx!: () => void;
    const hold = new Promise<void>((r) => { releaseTx = r; });

    const t1 = port.transaction(async (tx) => {
      order.push("t1:start");
      await tx.run(`UPDATE c SET n = n + 1`);
      await hold;
      order.push("t1:end");
    });
    const read = port.all<{ n: number }>(`SELECT n FROM c`).then((r) => { order.push("read"); return r[0]!.n; });
    const t2 = port.transaction(async (tx) => { order.push("t2"); await tx.run(`UPDATE c SET n = n + 10`); });
    await new Promise((r) => setTimeout(r, 20));
    expect(order).toEqual(["t1:start"]); // read + t2 are parked behind t1
    releaseTx();
    await Promise.all([t1, t2]);
    expect(await read).toBe(1); // saw t1's committed value, not a half-done state, and not t2's
    expect(order).toEqual(["t1:start", "t1:end", "read", "t2"]);
    expect((await port.all<{ n: number }>(`SELECT n FROM c`))[0]!.n).toBe(11);
  });

  it("a failed transaction does not poison the queue for later callers", async () => {
    const port = createNodeSqlitePort();
    await port.exec(`CREATE TABLE t (a INTEGER PRIMARY KEY)`);
    const results = await Promise.allSettled([
      port.transaction(async (tx) => { await tx.run(`INSERT INTO t VALUES (1)`); await tx.run(`INSERT INTO t VALUES (1)`); }),
      port.transaction(async (tx) => { await tx.run(`INSERT INTO t VALUES (2)`); }),
    ]);
    expect(results.map((r) => r.status)).toEqual(["rejected", "fulfilled"]);
    expect((await port.all<{ a: number }>(`SELECT a FROM t`)).map((r) => r.a)).toEqual([2]);
  });

  it("the tx handle is dead once the transaction ended", async () => {
    const port = createNodeSqlitePort();
    await port.exec(`CREATE TABLE t (a INTEGER)`);
    let leaked!: Parameters<Parameters<typeof port.transaction>[0]>[0];
    await port.transaction(async (tx) => { leaked = tx; });
    await expect(leaked.run(`INSERT INTO t VALUES (1)`)).rejects.toThrow(/after the transaction ended/);
    expect(await port.all(`SELECT * FROM t`)).toHaveLength(0);
  });

  it("close() is idempotent and later calls fail loudly", async () => {
    const port = createNodeSqlitePort();
    await port.close();
    await port.close();
    await expect(port.all(`SELECT 1`)).rejects.toThrow(/closed/);
  });

  it("file database: committed data survives an abrupt handle drop, uncommitted data does not", async () => {
    const file = tmp("port.sqlite");
    const a = createNodeSqlitePort(file);
    await a.exec(`CREATE TABLE t (a INTEGER)`);
    await a.run(`INSERT INTO t VALUES (1)`);
    let reached!: () => void;
    const midTx = new Promise<void>((r) => { reached = r; });
    void a.transaction(async (tx) => { await tx.run(`INSERT INTO t VALUES (2)`); reached(); await new Promise(() => {}); }).catch(() => {});
    await midTx;
    a.kill(); // no COMMIT, no cleanup: what a force-quit looks like to SQLite
    const b = createNodeSqlitePort(file);
    expect((await b.all<{ a: number }>(`SELECT a FROM t`)).map((r) => r.a)).toEqual([1]);
    await b.close();
  });
});
