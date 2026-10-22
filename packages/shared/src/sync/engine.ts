import { ApiError } from "../api/errors";
import type { SyncKind } from "../api/types";
import type { CreateSyncEngine, EntityMapper, SqlExec, SyncEngine, SyncEngineOptions, SyncPhase, SyncResult, SyncStatus } from "../contracts";
import { deleteCacheRow, deleteStaleRows, upsertCacheRow } from "../db/cacheWrite";
import { safeParse } from "../db/outboxRows";
import { newId, STALE_AFTER_MS } from "../format";
import { DEFAULT_MAPPERS } from "./mappers";

/** Mirrors SyncProjector::ENTITIES on the server. The first snapshot page's `entities` overrides this list. */
export const ENTITIES_BY_KIND: Readonly<Record<SyncKind, readonly string[]>> = {
  vendor: ["category", "product", "store", "listing", "inventory_item", "order"],
  customer: ["category", "product", "store", "listing", "order"],
  public: ["category", "product", "store", "listing"],
};

/** Extras beyond the contract (all optional, so a SyncEngineOptions object is still valid). */
export type SyncEngineOptionsX = SyncEngineOptions & { pageSize?: number };
/** SyncEngine plus ready(): resolves once the persisted sync state was read, so status() is accurate on a cold start. */
export type SyncEngineHandle = SyncEngine & { ready(): Promise<void> };

interface Progress { entity: string | null; after: number; entities: string[] }
interface State {
  cursor: string | null; snapshotComplete: boolean; pendingCursor: string | null; snapshotId: string | null; progress: Progress | null;
  lastSyncedAt: string | null; lastAttemptAt: string | null;
}
const blank = (): State => ({ cursor: null, snapshotComplete: false, pendingCursor: null, snapshotId: null, progress: null, lastSyncedAt: null, lastAttemptAt: null });

/** The scope's sync_state row disappeared (logout / revoked / cache reset) while a run was in flight: stop, never resurrect rows. */
class ScopeWipedError extends Error { constructor() { super("scope was wiped during sync"); this.name = "ScopeWipedError"; } }

const START_CURSOR = "0.0";

export const createSyncEngine = ((o: SyncEngineOptionsX): SyncEngineHandle => {
  const { api, port, scope, bus } = o;
  const sk = scope.key;
  const now = o.now ?? Date.now;
  const staleAfterMs = o.staleAfterMs ?? STALE_AFTER_MS;
  const pageSize = o.pageSize ?? 200;
  const mappers: Record<string, EntityMapper | undefined> = { ...DEFAULT_MAPPERS };
  for (const [k, m] of Object.entries(o.mappers ?? {})) if (m) mappers[k] = m;
  const iso = () => new Date(now()).toISOString();

  let st = blank();
  let loaded = false;
  let phase: SyncPhase = "idle";
  let error: SyncStatus["error"] = null;
  const listeners = new Set<() => void>();
  const notify = () => {
    for (const l of [...listeners]) { try { l(); } catch { /* a bad listener must not break sync */ } }
    bus?.emit({ area: "sync" });
  };
  const emitCache = (entities: Iterable<string>) => { for (const entity of entities) bus?.emit({ area: "cache", entity }); };

  // ---- persisted state -------------------------------------------------------------------------------------------------
  async function load(): Promise<void> {
    const r = (await port.all<Record<string, any>>(`SELECT * FROM sync_state WHERE scope_key = ?`, [sk]))[0];
    st = !r ? blank() : {
      cursor: r.cursor ?? null, snapshotComplete: !!r.snapshot_complete, pendingCursor: r.pending_cursor ?? null, snapshotId: r.snapshot_id ?? null,
      progress: safeParse<Progress | null>(r.snapshot_progress, null), lastSyncedAt: r.last_synced_at ?? null, lastAttemptAt: r.last_attempt_at ?? null,
    };
    loaded = true;
  }
  let loading: Promise<void> | null = null;
  const ensureLoaded = () => (loaded ? Promise.resolve() : (loading ??= load().finally(() => { loading = null; })));

  /** Every write transaction first proves the scope still has its sync_state row (see ScopeWipedError). */
  async function guard(tx: SqlExec): Promise<void> {
    if (!(await tx.all(`SELECT 1 AS x FROM sync_state WHERE scope_key = ?`, [sk])).length) throw new ScopeWipedError();
  }

  const progressJson = (p: Progress | null) => (p ? JSON.stringify(p) : null);

  // ---- snapshot --------------------------------------------------------------------------------------------------------
  async function beginSnapshot(): Promise<void> {
    const snapshotId = newId();
    const progress: Progress = { entity: ENTITIES_BY_KIND[scope.kind][0]!, after: 0, entities: [...ENTITIES_BY_KIND[scope.kind]] };
    await port.transaction(async (tx) => {
      await guard(tx);
      await tx.run(`UPDATE sync_state SET snapshot_id = ?, pending_cursor = NULL, snapshot_progress = ? WHERE scope_key = ?`, [snapshotId, progressJson(progress), sk]);
    });
    st = { ...st, snapshotId, pendingCursor: null, progress };
  }

  async function runSnapshot(res: SyncResult): Promise<void> {
    if (!st.progress || !st.snapshotId) await beginSnapshot();
    for (;;) {
      const { entity, after } = st.progress!;
      if (entity === null) break;
      const page = await api.syncSnapshot(scope.kind, entity, after, pageSize);
      res.pages++;

      // The cursor of the FIRST page of the snapshot is the delta starting point; resumed runs keep it.
      const first = st.pendingCursor === null;
      let entities = st.progress!.entities;
      if (first) {
        const server = Array.isArray(page.entities) && page.entities.length ? page.entities.map(String) : [...ENTITIES_BY_KIND[scope.kind]];
        entities = [entity, ...server.filter((e) => e !== entity)];
      }
      const pendingCursor = first ? page.cursor : st.pendingCursor;
      const advanced = page.next_after !== null && page.next_after !== undefined && page.next_after > after; // loop guard
      const next: Progress = advanced
        ? { entity, after: page.next_after as number, entities }
        : { entity: entities[entities.indexOf(entity) + 1] ?? null, after: 0, entities };

      const mapper = mappers[entity];
      const ts = iso();
      await port.transaction(async (tx) => {
        await guard(tx);
        if (mapper) for (const dto of page.items) await upsertCacheRow(tx, sk, entity, mapper(dto), st.snapshotId, ts);
        await tx.run(`UPDATE sync_state SET snapshot_progress = ?, pending_cursor = ? WHERE scope_key = ?`, [progressJson(next), pendingCursor, sk]);
      });
      st = { ...st, progress: next, pendingCursor };
      res.applied += mapper ? page.items.length : 0;
      emitCache([entity]);
    }

    // All entities paged: swap in the new snapshot atomically (stale rows vanish only now).
    const ts = iso();
    const cursor = st.pendingCursor ?? START_CURSOR;
    let stale: string[] = [];
    await port.transaction(async (tx) => {
      await guard(tx);
      stale = await deleteStaleRows(tx, sk, st.snapshotId!);
      await tx.run(
        `UPDATE sync_state SET cursor = ?, snapshot_complete = 1, pending_cursor = NULL, snapshot_progress = NULL, last_synced_at = ? WHERE scope_key = ?`,
        [cursor, ts, sk]);
    });
    st = { ...st, cursor, snapshotComplete: true, pendingCursor: null, progress: null, lastSyncedAt: ts };
    emitCache(stale);
  }

  // ---- delta -----------------------------------------------------------------------------------------------------------
  async function runDelta(res: SyncResult): Promise<void> {
    for (;;) {
      const cursor = st.cursor ?? START_CURSOR;
      const page = await api.syncChanges(scope.kind, cursor, pageSize);
      res.pages++;
      if (page.has_more && page.cursor === cursor) throw new Error("Sync cursor did not advance");

      const ts = iso();
      const touched = new Set<string>();
      let applied = 0;
      let deleted = 0;
      await port.transaction(async (tx) => {
        await guard(tx);
        applied = 0; deleted = 0; touched.clear();
        for (const op of page.changes) {
          const mapper = mappers[op.entity];
          if (!mapper) continue; // entity this build does not cache
          if (op.op === "delete") { await deleteCacheRow(tx, sk, op.entity, op.id); deleted++; }
          else if (op.data !== null && op.data !== undefined) { await upsertCacheRow(tx, sk, op.entity, { ...mapper(op.data), id: op.id }, st.snapshotId, ts); applied++; }
          else continue;
          touched.add(op.entity);
        }
        // The cursor moves in the SAME transaction as the rows it covers: a failed batch leaves both untouched.
        await tx.run(`UPDATE sync_state SET cursor = ?, last_synced_at = ? WHERE scope_key = ?`, [page.cursor, page.has_more ? st.lastSyncedAt : ts, sk]);
      });
      st = { ...st, cursor: page.cursor, lastSyncedAt: page.has_more ? st.lastSyncedAt : ts };
      res.applied += applied;
      res.deleted += deleted;
      emitCache(touched);
      if (!page.has_more) return;
    }
  }

  // ---- run / coalescing -----------------------------------------------------------------------------------------------
  const errorInfo = (e: unknown): NonNullable<SyncStatus["error"]> =>
    e instanceof ApiError ? { kind: e.kind, code: e.code, message: e.message }
      : { kind: "local", code: "apply_failed", message: e instanceof Error ? e.message : String(e) };

  async function run(force: boolean): Promise<SyncResult> {
    const res: SyncResult = { mode: "none", applied: 0, deleted: 0, cursor: null, pages: 0 };
    phase = "syncing";
    notify();
    try {
      await ensureLoaded();
      const attempt = iso();
      await port.run(`INSERT INTO sync_state (scope_key, last_attempt_at) VALUES (?, ?) ON CONFLICT (scope_key) DO UPDATE SET last_attempt_at = excluded.last_attempt_at`, [sk, attempt]);
      st = { ...st, lastAttemptAt: attempt };
      if (force) await beginSnapshot();
      if (!st.snapshotComplete || st.progress) { res.mode = "snapshot"; await runSnapshot(res); }
      else { res.mode = "delta"; await runDelta(res); }
      error = null;
      phase = "idle";
      await port.run(`UPDATE sync_state SET last_error = NULL WHERE scope_key = ?`, [sk]);
    } catch (e) {
      if (e instanceof ScopeWipedError) {
        loaded = false; error = null; phase = "idle"; // next run re-reads whatever the DB holds now
      } else {
        error = errorInfo(e);
        phase = "error";
        try {
          if (e instanceof ApiError) {
            if (e.kind === "unauthenticated") o.onUnauthenticated?.();
            else if (e.kind === "forbidden" && (e.code === "membership_revoked" || e.code === "no_business_access")) o.onAccessRevoked?.();
          }
        } catch { /* session callbacks must not break the engine */ }
        try { await port.run(`UPDATE sync_state SET last_error = ? WHERE scope_key = ?`, [JSON.stringify(error), sk]); } catch { /* diagnostics only */ }
      }
    }
    res.cursor = st.cursor;
    notify();
    return res;
  }

  let inflight: { p: Promise<SyncResult>; forced: boolean } | null = null;
  function syncNow(opts: { forceSnapshot?: boolean } = {}): Promise<SyncResult> {
    const force = !!opts.forceSnapshot;
    if (inflight) {
      // A forced re-snapshot requested while a normal run is going waits for it, then runs once more.
      if (force && !inflight.forced) return inflight.p.then(() => syncNow(opts));
      return inflight.p;
    }
    const entry: { p: Promise<SyncResult>; forced: boolean } = { forced: force, p: undefined as unknown as Promise<SyncResult> };
    entry.p = run(force).finally(() => { if (inflight === entry) inflight = null; });
    inflight = entry;
    return entry.p;
  }

  const ready = () => ensureLoaded().catch(() => undefined);
  void ready();

  return {
    syncNow,
    ready,
    status: (): SyncStatus => ({
      phase, snapshotComplete: st.snapshotComplete, lastSyncedAt: st.lastSyncedAt, lastAttemptAt: st.lastAttemptAt, error,
      stale: !st.lastSyncedAt || now() - Date.parse(st.lastSyncedAt) > staleAfterMs,
    }),
    subscribe: (l) => { listeners.add(l); return () => { listeners.delete(l); }; },
  };
}) satisfies CreateSyncEngine;
