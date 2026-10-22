import type { SqlExec, SqlPort, SqlValue } from "../contracts";

/**
 * Shared SqlPort behaviour on top of three raw operations (exec / run / all) that a concrete driver provides.
 *  - One async mutex: a transaction holds it from BEGIN to COMMIT/ROLLBACK, plain exec/run/all calls queue behind it.
 *  - transaction(fn): begin -> fn(tx) -> commit; any throw rolls back and rethrows the ORIGINAL error.
 *    The `tx` handle bypasses the lock (it is only valid while fn runs; using it later throws).
 *  - Do NOT call port.transaction()/port.run() from inside fn (it would wait for the lock fn itself holds: deadlock). Use `tx`.
 */
export abstract class BaseSqlPort implements SqlPort {
  abstract readonly kind: SqlPort["kind"];
  abstract readonly encrypted: boolean;

  protected abstract rawExec(sql: string): Promise<void>;
  protected abstract rawRun(sql: string, params: SqlValue[]): Promise<{ changes: number }>;
  protected abstract rawAll<T>(sql: string, params: SqlValue[]): Promise<T[]>;
  protected abstract rawClose(): Promise<void>;

  /** Transaction control. Override when a driver must use its own API instead of raw SQL. */
  protected begin(): Promise<void> { return this.rawExec("BEGIN IMMEDIATE"); }
  protected commit(): Promise<void> { return this.rawExec("COMMIT"); }
  protected rollback(): Promise<void> { return this.rawExec("ROLLBACK"); }
  /** Called after every committed transaction and every standalone write (web: persist the in-memory DB). */
  protected afterWrite(): Promise<void> { return Promise.resolve(); }

  private tail: Promise<void> = Promise.resolve();
  private closed = false;

  private async acquire(allowClosed = false): Promise<() => void> {
    const prev = this.tail;
    let release!: () => void;
    this.tail = new Promise<void>((r) => { release = r; });
    await prev;
    if (this.closed && !allowClosed) { release(); throw new Error("Database is closed"); }
    return release;
  }

  private async locked<T>(fn: () => Promise<T>): Promise<T> {
    const release = await this.acquire();
    try { return await fn(); } finally { release(); }
  }

  exec(sql: string): Promise<void> {
    return this.locked(async () => { await this.rawExec(sql); await this.afterWrite(); });
  }

  run(sql: string, params: SqlValue[] = []): Promise<{ changes: number }> {
    return this.locked(async () => { const r = await this.rawRun(sql, params); await this.afterWrite(); return r; });
  }

  all<T = Record<string, unknown>>(sql: string, params: SqlValue[] = []): Promise<T[]> {
    return this.locked(() => this.rawAll<T>(sql, params));
  }

  async transaction<T>(fn: (tx: SqlExec) => Promise<T>): Promise<T> {
    const release = await this.acquire();
    let live = true;
    const alive = () => { if (!live) throw new Error("Transaction handle used after the transaction ended"); };
    const tx: SqlExec = {
      exec: async (sql) => { alive(); await this.rawExec(sql); },
      run: async (sql, params = []) => { alive(); return this.rawRun(sql, params); },
      all: async <R = Record<string, unknown>>(sql: string, params: SqlValue[] = []) => { alive(); return this.rawAll<R>(sql, params); },
    };
    try {
      await this.begin();
      let result: T;
      try {
        result = await fn(tx);
      } catch (e) {
        live = false;
        try { await this.rollback(); } catch { /* the original error is the one that matters */ }
        throw e;
      }
      live = false;
      try {
        await this.commit();
      } catch (e) {
        try { await this.rollback(); } catch { /* commit failed: nothing was persisted */ }
        throw e;
      }
      await this.afterWrite();
      return result;
    } finally {
      live = false;
      release();
    }
  }

  async close(): Promise<void> {
    const release = await this.acquire(true);
    try {
      if (this.closed) return;
      this.closed = true;
      await this.rawClose();
    } finally {
      release();
    }
  }
}
