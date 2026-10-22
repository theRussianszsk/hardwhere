import { newId } from "../format";
import type { CacheQuery, ChangeBus, CountUnsent, CreateLocalStore, DraftsApi, LocalCache, LocalStore, OutboxApi, OutboxItem, OutboxState, PhotoMeta, PhotosApi, ScopeInfo, SqlPort, SqlValue } from "../contracts";
import { ALL_STATES, OUTBOX_COLS, rowToOutboxItem, safeParse, UNSENT_STATES, type OutboxRow } from "./outboxRows";

/**
 * Scoped view over the local database. EVERY statement below is parameterised with scope.key: a scope can never read or
 * write another scope's rows, even for the same entity+id. Writes emit on the ChangeBus after they commit.
 */
const likeEscape = (s: string) => s.replace(/[\\%_]/g, (c) => `\\${c}`);
const iso = (ms: number) => new Date(ms).toISOString();

function cacheWhere(scopeKey: string, entity: string, q: CacheQuery = {}): { sql: string; params: SqlValue[] } {
  const parts = ["scope_key = ?", "entity = ?"];
  const params: SqlValue[] = [scopeKey, entity];
  for (const tok of (q.search ?? "").toLowerCase().split(/\s+/).filter(Boolean)) {
    parts.push(`search LIKE ? ESCAPE '\\'`);
    params.push(`%${likeEscape(tok)}%`);
  }
  for (const [k, v] of Object.entries(q.filters ?? {})) {
    if (!/^\w+$/.test(k)) throw new Error(`Invalid cache filter name "${k}"`);
    // Compared as text so the result does not depend on whether the native plugin binds numbers as numbers or as strings.
    parts.push(`CAST(json_extract(filters, ?) AS TEXT) = ?`);
    params.push(`$.${k}`, String(v));
  }
  return { sql: parts.join(" AND "), params };
}

function createCache(port: SqlPort, scope: ScopeInfo): LocalCache {
  const sk = scope.key;
  return {
    async get<T>(entity: string, id: number) {
      const rows = await port.all<{ payload: string }>(`SELECT payload FROM cache_entities WHERE scope_key = ? AND entity = ? AND id = ?`, [sk, entity, id]);
      return rows[0] ? safeParse<T | null>(rows[0].payload, null) : null;
    },
    async list<T>(entity: string, q: CacheQuery = {}) {
      const w = cacheWhere(sk, entity, q);
      const params = [...w.params];
      let tail = "";
      if (q.limit !== undefined || q.offset !== undefined) { tail = ` LIMIT ? OFFSET ?`; params.push(q.limit ?? -1, q.offset ?? 0); }
      const order = q.orderBy === "search" ? "search, id" : "id";
      const rows = await port.all<{ payload: string }>(`SELECT payload FROM cache_entities WHERE ${w.sql} ORDER BY ${order}${tail}`, params);
      return rows.map((r) => safeParse<T | null>(r.payload, null)).filter((x): x is T => x !== null);
    },
    async count(entity, q = {}) {
      const w = cacheWhere(sk, entity, q);
      const rows = await port.all<{ n: number }>(`SELECT COUNT(*) AS n FROM cache_entities WHERE ${w.sql}`, w.params);
      return Number(rows[0]?.n ?? 0);
    },
    async findByKey<T>(entity: string, key: string) {
      const rows = await port.all<{ payload: string }>(
        `SELECT e.payload FROM cache_keys k
           JOIN cache_entities e ON e.scope_key = k.scope_key AND e.entity = k.entity AND e.id = k.id
          WHERE k.scope_key = ? AND k.entity = ? AND k.key = ? ORDER BY e.id LIMIT 1`,
        [sk, entity, key.trim().toLowerCase()]);
      return rows[0] ? safeParse<T | null>(rows[0].payload, null) : null;
    },
    async lastSyncedAt() {
      const rows = await port.all<{ t: string | null }>(`SELECT last_synced_at AS t FROM sync_state WHERE scope_key = ?`, [sk]);
      return rows[0]?.t ?? null;
    },
  };
}

function createOutboxApi(port: SqlPort, scope: ScopeInfo, bus: ChangeBus, now: () => number): OutboxApi {
  const sk = scope.key;
  const emit = () => bus.emit({ area: "outbox" });
  const fetchOne = async (id: string) => (await port.all<OutboxRow>(`SELECT ${OUTBOX_COLS} FROM outbox WHERE id = ? AND scope_key = ?`, [id, sk]))[0];
  return {
    async enqueue<P>(kind: string, payload: P, o: { id?: string; label?: string; state?: "draft" | "queued"; payloadVersion?: number } = {}) {
      const id = o.id ?? newId();
      const t = iso(now());
      await port.run(
        `INSERT INTO outbox (id, scope_key, kind, payload, payload_version, label, state, attempts, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?) ON CONFLICT (id) DO NOTHING`,
        [id, sk, kind, JSON.stringify(payload ?? null), o.payloadVersion ?? 1, o.label ?? "", o.state ?? "queued", t, t]);
      // Enqueuing the same id twice (double tap) returns the original; an id owned by another scope is an error, never a leak.
      const row = await fetchOne(id);
      if (!row) throw new Error(`Outbox id ${id} is already in use`);
      emit();
      return rowToOutboxItem(row) as OutboxItem<P>;
    },
    async list(filter = {}) {
      const parts = ["scope_key = ?"];
      const params: SqlValue[] = [sk];
      if (filter.kinds?.length) { parts.push(`kind IN (${filter.kinds.map(() => "?").join(",")})`); params.push(...filter.kinds); }
      if (filter.states?.length) { parts.push(`state IN (${filter.states.map(() => "?").join(",")})`); params.push(...filter.states); }
      let tail = "";
      if (filter.limit !== undefined) { tail = ` LIMIT ?`; params.push(filter.limit); }
      const rows = await port.all<OutboxRow>(`SELECT ${OUTBOX_COLS} FROM outbox WHERE ${parts.join(" AND ")} ORDER BY created_at, rowid${tail}`, params);
      return rows.map(rowToOutboxItem);
    },
    async get<P = unknown, R = unknown>(id: string) {
      const row = await fetchOne(id);
      return row ? (rowToOutboxItem(row) as OutboxItem<P, R>) : null;
    },
    async submitDraft(id) {
      const r = await port.run(`UPDATE outbox SET state = 'queued', next_attempt_at = NULL, updated_at = ? WHERE id = ? AND scope_key = ? AND state = 'draft'`, [iso(now()), id, sk]);
      if (r.changes) emit();
    },
    async retryNow(id) {
      const r = await port.run(
        `UPDATE outbox SET state = 'queued', next_attempt_at = NULL, attempts = 0, last_error = NULL, updated_at = ?
          WHERE id = ? AND scope_key = ? AND state IN ('queued','needs_review','rejected')`, [iso(now()), id, sk]);
      if (r.changes) emit();
    },
    async markConfirmed(id, response) {
      const r = await port.run(
        `UPDATE outbox SET state = 'confirmed', response = ?, last_error = NULL, next_attempt_at = NULL, updated_at = ?
          WHERE id = ? AND scope_key = ? AND state IN ('queued','needs_review','rejected')`, [JSON.stringify(response ?? null), iso(now()), id, sk]);
      if (r.changes) emit();
    },
    async discard(id) {
      // A row mid-send is never deleted: the handler may still reach the server. (Interrupted rows are recovered on app start.)
      const r = await port.run(`DELETE FROM outbox WHERE id = ? AND scope_key = ? AND state <> 'sending'`, [id, sk]);
      if (r.changes) emit();
    },
    async countsByState() {
      const counts = Object.fromEntries(ALL_STATES.map((s) => [s, 0])) as Record<OutboxState, number>;
      const rows = await port.all<{ state: OutboxState; n: number }>(`SELECT state, COUNT(*) AS n FROM outbox WHERE scope_key = ? GROUP BY state`, [sk]);
      for (const r of rows) counts[r.state] = Number(r.n);
      return counts;
    },
    async unsentCount() {
      const rows = await port.all<{ n: number }>(`SELECT COUNT(*) AS n FROM outbox WHERE scope_key = ? AND state IN (${UNSENT_STATES.map(() => "?").join(",")})`, [sk, ...UNSENT_STATES]);
      return Number(rows[0]?.n ?? 0);
    },
  };
}

function createDrafts(port: SqlPort, scope: ScopeInfo, bus: ChangeBus, now: () => number): DraftsApi {
  const sk = scope.key;
  return {
    async put(kind, id, payload) {
      await port.run(
        `INSERT INTO drafts (scope_key, kind, id, payload, updated_at) VALUES (?, ?, ?, ?, ?)
         ON CONFLICT (scope_key, kind, id) DO UPDATE SET payload = excluded.payload, updated_at = excluded.updated_at`,
        [sk, kind, id, JSON.stringify(payload ?? null), iso(now())]);
      bus.emit({ area: "drafts" });
    },
    async get<T>(kind: string, id: string) {
      const rows = await port.all<{ payload: string }>(`SELECT payload FROM drafts WHERE scope_key = ? AND kind = ? AND id = ?`, [sk, kind, id]);
      return rows[0] ? safeParse<T | null>(rows[0].payload, null) : null;
    },
    async list<T>(kind: string) {
      const rows = await port.all<{ id: string; payload: string; updated_at: string }>(`SELECT id, payload, updated_at FROM drafts WHERE scope_key = ? AND kind = ? ORDER BY updated_at DESC, id`, [sk, kind]);
      return rows.map((r) => ({ id: r.id, payload: safeParse<T>(r.payload, null as T), updatedAt: r.updated_at }));
    },
    async remove(kind, id) {
      const r = await port.run(`DELETE FROM drafts WHERE scope_key = ? AND kind = ? AND id = ?`, [sk, kind, id]);
      if (r.changes) bus.emit({ area: "drafts" });
    },
  };
}

interface PhotoRow { id: string; file_ref: string; mime: string; size: number; purpose: string; hint: string | null; outbox_id: string | null; created_at: string }
function createPhotos(port: SqlPort, scope: ScopeInfo, bus: ChangeBus, now: () => number): PhotosApi {
  const sk = scope.key;
  return {
    async add(m) {
      await port.run(
        `INSERT INTO photo_queue (id, scope_key, file_ref, mime, size, purpose, hint, outbox_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)`,
        [m.id, sk, m.fileRef, m.mime, m.size, m.purpose, m.hint ?? null, m.outboxId ?? null, iso(now())]);
      bus.emit({ area: "photos" });
    },
    async list() {
      const rows = await port.all<PhotoRow>(`SELECT id, file_ref, mime, size, purpose, hint, outbox_id, created_at FROM photo_queue WHERE scope_key = ? ORDER BY created_at, rowid`, [sk]);
      return rows.map((r): PhotoMeta => ({ id: r.id, fileRef: r.file_ref, mime: r.mime, size: Number(r.size), purpose: r.purpose, hint: r.hint, outboxId: r.outbox_id, createdAt: r.created_at }));
    },
    async remove(id) {
      const r = await port.run(`DELETE FROM photo_queue WHERE id = ? AND scope_key = ?`, [id, sk]);
      if (r.changes) bus.emit({ area: "photos" });
    },
  };
}

/** `opts.now` is an optional extra (deterministic timestamps in tests); the contract signature still matches. */
export const createLocalStore = ((port: SqlPort, scope: ScopeInfo, bus: ChangeBus, opts: { now?: () => number } = {}): LocalStore => {
  const now = opts.now ?? Date.now;
  return {
    scope,
    cache: createCache(port, scope),
    drafts: createDrafts(port, scope, bus, now),
    outbox: createOutboxApi(port, scope, bus, now),
    photos: createPhotos(port, scope, bus, now),
    onChange: (l) => bus.subscribe(l),
  };
}) satisfies CreateLocalStore;

/** Count of things the user has not seen succeed: unsent outbox items + saved drafts. */
export const countUnsent: CountUnsent = async (port, scopeKey) => {
  const o = await port.all<{ n: number }>(`SELECT COUNT(*) AS n FROM outbox WHERE scope_key = ? AND state IN (${UNSENT_STATES.map(() => "?").join(",")})`, [scopeKey, ...UNSENT_STATES]);
  const d = await port.all<{ n: number }>(`SELECT COUNT(*) AS n FROM drafts WHERE scope_key = ?`, [scopeKey]);
  return Number(o[0]?.n ?? 0) + Number(d[0]?.n ?? 0);
};
