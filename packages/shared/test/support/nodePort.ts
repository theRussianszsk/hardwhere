import { DatabaseSync } from "node:sqlite";
import { BaseSqlPort } from "../../src/db/port";
import type { SqlValue } from "../../src/contracts";

/** Every port still holding a file handle. Windows refuses to delete an open database file, so temp-dir cleanup closes these first. */
export const openFilePorts = new Set<NodeSqlitePort>();

/** TESTS ONLY. node:sqlite (built into Node 22) behind the same BaseSqlPort the Capacitor driver uses. */
export class NodeSqlitePort extends BaseSqlPort {
  readonly kind = "node-test" as const;
  readonly encrypted = false;
  private db: DatabaseSync | null;

  constructor(readonly path: string) { super(); this.db = new DatabaseSync(path); if (path !== ":memory:") openFilePorts.add(this); }

  private h(): DatabaseSync { if (!this.db) throw new Error("Database is closed"); return this.db; }
  protected async rawExec(sql: string) { this.h().exec(sql); }
  protected async rawRun(sql: string, params: SqlValue[]) { return { changes: Number(this.h().prepare(sql).run(...params).changes) }; }
  protected async rawAll<T>(sql: string, params: SqlValue[]) { return this.h().prepare(sql).all(...params) as T[]; }
  protected async rawClose() { this.kill(); }

  /**
   * Simulates the OS killing the app: the file handle is dropped immediately, with no mutex, no COMMIT and no cleanup.
   * Anything not committed is rolled back by SQLite itself, exactly as after a real process death.
   */
  kill(): void { try { this.db?.close(); } finally { this.db = null; openFilePorts.delete(this); } }
}

export const createNodeSqlitePort = (pathOrMemory = ":memory:"): NodeSqlitePort => new NodeSqlitePort(pathOrMemory);
