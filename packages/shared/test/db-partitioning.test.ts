import { describe, expect, it } from "vitest";
import { makeScope, type CacheRow, type ScopeInfo } from "../src/contracts";
import { upsertCacheRow } from "../src/db/cacheWrite";
import { countUnsent, createLocalStore } from "../src/db/localStore";
import { listPhotoRefs, wipeScope } from "../src/db/wipe";
import { openDb, recordBus, makeClock, T0 } from "./support/helpers";

const row = (id: number, payload: Record<string, unknown>, extra: Partial<CacheRow> = {}): CacheRow => ({ id, payload: { id, ...payload }, search: "", keys: [], rowVersion: 1, ...extra });
const ts = "2026-01-01T00:00:00.000Z";

const vendorA = makeScope(1, 5, "vendor"); // account 1, tenant 5
const vendorOtherAccount = makeScope(2, 5, "vendor"); // same tenant, different employee
const vendorOtherTenant = makeScope(1, 6, "vendor"); // same account, different business
const customer = makeScope(1, null, "customer"); // same account id, customer audience
const pub = makeScope(0, null, "public");

async function seedAll(port: Awaited<ReturnType<typeof openDb>>, scopes: ScopeInfo[]) {
  for (const s of scopes) {
    await upsertCacheRow(port, s.key, "listing", row(1, { owner: s.key }, { search: `hammer ${s.key}`, keys: ["SKU-1"] }), "snap", ts);
    await port.run(`INSERT INTO sync_state (scope_key, cursor, snapshot_complete, last_synced_at) VALUES (?, '1.1', 1, ?)`, [s.key, ts]);
  }
}
// upsertCacheRow takes a SqlExec; the port itself is one (auto-commit), which keeps the seeding terse.

describe("cache partitioning", () => {
  it("scope keys differ for account, tenant and audience", () => {
    const keys = [vendorA, vendorOtherAccount, vendorOtherTenant, customer, pub].map((s) => s.key);
    expect(new Set(keys).size).toBe(5);
    expect(keys).toEqual(["vendor:1:5", "vendor:2:5", "vendor:1:6", "customer:1:0", "public:0:0"]);
  });

  it("two scopes holding the same entity+id never see each other's rows (get / list / count / findByKey / lastSyncedAt)", async () => {
    const port = await openDb();
    const { bus } = recordBus();
    await seedAll(port, [vendorA, vendorOtherAccount, vendorOtherTenant, customer]);
    for (const s of [vendorA, vendorOtherAccount, vendorOtherTenant, customer]) {
      const store = createLocalStore(port, s, bus);
      expect(await store.cache.get<{ owner: string }>("listing", 1)).toEqual({ id: 1, owner: s.key });
      expect(await store.cache.list<{ owner: string }>("listing")).toEqual([{ id: 1, owner: s.key }]);
      expect(await store.cache.count("listing")).toBe(1);
      expect((await store.cache.findByKey<{ owner: string }>("listing", "sku-1"))?.owner).toBe(s.key);
      expect(await store.cache.list("listing", { search: vendorA.key === s.key ? "vendor:2:5" : "vendor:1:5" })).toEqual([]);
    }
    // a scope with no rows at all stays empty
    const empty = createLocalStore(port, pub, bus);
    expect(await empty.cache.get("listing", 1)).toBeNull();
    expect(await empty.cache.count("listing")).toBe(0);
    expect(await empty.cache.findByKey("listing", "SKU-1")).toBeNull();
    expect(await empty.cache.lastSyncedAt()).toBeNull();
  });

  it("outbox, drafts and photos are partitioned too (same draft id / kind in two scopes)", async () => {
    const port = await openDb();
    const { bus } = recordBus();
    const a = createLocalStore(port, vendorA, bus);
    const b = createLocalStore(port, vendorOtherTenant, bus);
    await a.drafts.put("sale", "d1", { who: "a" });
    await b.drafts.put("sale", "d1", { who: "b" });
    expect(await a.drafts.get("sale", "d1")).toEqual({ who: "a" });
    expect(await b.drafts.get("sale", "d1")).toEqual({ who: "b" });
    await a.outbox.enqueue("stock.adjust", { q: 1 }, { id: "cmd-1" });
    expect(await b.outbox.get("cmd-1")).toBeNull();
    expect(await b.outbox.list()).toEqual([]);
    // an id that belongs to another scope can neither be hijacked, retried nor discarded from here
    await expect(b.outbox.enqueue("stock.adjust", { q: 9 }, { id: "cmd-1" })).rejects.toThrow(/already in use/);
    await b.outbox.discard("cmd-1");
    await b.outbox.retryNow("cmd-1");
    expect((await a.outbox.get("cmd-1"))?.payload).toEqual({ q: 1 });
    await a.photos.add({ id: "p1", fileRef: "file-a", mime: "image/jpeg", size: 1, purpose: "ai", hint: null, outboxId: null });
    expect(await b.photos.list()).toEqual([]);
    await b.photos.remove("p1");
    expect(await a.photos.list()).toHaveLength(1);
  });

  it("account/tenant switching: a second scope starts empty even though the first one is fully synced", async () => {
    const port = await openDb();
    const { bus } = recordBus();
    await seedAll(port, [vendorA]);
    expect(await createLocalStore(port, vendorA, bus).cache.count("listing")).toBe(1);
    const next = createLocalStore(port, makeScope(2, 7, "vendor"), bus);
    expect(await next.cache.count("listing")).toBe(0);
    expect(await next.cache.lastSyncedAt()).toBeNull();
    expect(await next.outbox.unsentCount()).toBe(0);
  });
});

describe("wipeScope / countUnsent / listPhotoRefs", () => {
  async function populate() {
    const port = await openDb();
    const { bus } = recordBus();
    const clock = makeClock();
    const scopes = [vendorA, vendorOtherTenant];
    await seedAll(port, scopes);
    const stores: Record<string, ReturnType<typeof createLocalStore>> = {};
    for (const s of scopes) {
      const st = createLocalStore(port, s, bus, { now: clock.now });
      stores[s.key] = st;
      await st.outbox.enqueue("stock.adjust", { q: 1 }, { id: `q-${s.key}` });
      await st.outbox.enqueue("stock.adjust", { q: 2 }, { id: `d-${s.key}`, state: "draft" });
      await st.drafts.put("sale", "d1", { cart: [] });
      await st.photos.add({ id: `p-${s.key}`, fileRef: `file-${s.key}`, mime: "image/jpeg", size: 5, purpose: "ai", hint: null, outboxId: `q-${s.key}` });
    }
    await port.run(`INSERT INTO cache_keys (scope_key, entity, key, id) VALUES (?, 'listing', 'extra', 1)`, [vendorA.key]);
    return { port, stores };
  }
  const count = async (port: Awaited<ReturnType<typeof openDb>>, table: string, key: string) =>
    Number((await port.all<{ n: number }>(`SELECT COUNT(*) AS n FROM ${table} WHERE scope_key = ?`, [key]))[0]!.n);

  it("keepUnsent:true removes cache + sync state of ONLY the target scope and keeps its unsent work", async () => {
    const { port } = await populate();
    await wipeScope(port, vendorA.key, { keepUnsent: true });
    for (const t of ["cache_entities", "cache_keys", "sync_state"]) expect(await count(port, t, vendorA.key)).toBe(0);
    for (const t of ["outbox", "drafts", "photo_queue"]) expect(await count(port, t, vendorA.key)).toBeGreaterThan(0);
    // the other scope is completely untouched
    for (const t of ["cache_entities", "sync_state", "outbox", "drafts", "photo_queue"]) expect(await count(port, t, vendorOtherTenant.key)).toBeGreaterThan(0);
    expect(await count(port, "cache_keys", vendorOtherTenant.key)).toBe(1);
  });

  it("keepUnsent:false also removes outbox, drafts and photo rows of that scope only", async () => {
    const { port } = await populate();
    await wipeScope(port, vendorA.key, { keepUnsent: false });
    for (const t of ["cache_entities", "cache_keys", "sync_state", "outbox", "drafts", "photo_queue"]) expect(await count(port, t, vendorA.key)).toBe(0);
    for (const t of ["cache_entities", "sync_state", "outbox", "drafts", "photo_queue"]) expect(await count(port, t, vendorOtherTenant.key)).toBeGreaterThan(0);
  });

  it("wiping a scope that has nothing is a no-op", async () => {
    const { port } = await populate();
    await wipeScope(port, "vendor:99:99", { keepUnsent: false });
    expect(await count(port, "outbox", vendorA.key)).toBe(2);
  });

  it("countUnsent = unsent outbox items (draft/queued/sending/needs_review/rejected) + saved drafts, per scope", async () => {
    const { port, stores } = await populate();
    const a = stores[vendorA.key]!;
    expect(await countUnsent(port, vendorA.key)).toBe(3); // queued + outbox-draft + 1 drafts row
    await port.run(`INSERT INTO outbox (id, scope_key, kind, payload, state, created_at, updated_at) VALUES ('x1', ?, 'k', '{}', 'confirmed', ?, ?)`, [vendorA.key, ts, ts]);
    expect(await countUnsent(port, vendorA.key)).toBe(3); // confirmed does not count
    for (const [i, state] of (["sending", "needs_review", "rejected"] as const).entries())
      await port.run(`INSERT INTO outbox (id, scope_key, kind, payload, state, created_at, updated_at) VALUES (?, ?, 'k', '{}', ?, ?, ?)`, [`y${i}`, vendorA.key, state, ts, ts]);
    expect(await countUnsent(port, vendorA.key)).toBe(6);
    expect(await a.outbox.unsentCount()).toBe(5); // OutboxApi.unsentCount excludes the drafts table
    expect(await countUnsent(port, "vendor:99:99")).toBe(0);
  });

  it("listPhotoRefs returns only the scope's file refs so the caller can delete the files before wiping", async () => {
    const { port } = await populate();
    expect(await listPhotoRefs(port, vendorA.key)).toEqual([`file-${vendorA.key}`]);
    expect(await listPhotoRefs(port, "vendor:99:99")).toEqual([]);
  });

  it("wipeScope is atomic: a failure part-way leaves the scope intact", async () => {
    const { port } = await populate();
    await port.exec(`CREATE TRIGGER boom BEFORE DELETE ON drafts BEGIN SELECT RAISE(ABORT, 'nope'); END`);
    await expect(wipeScope(port, vendorA.key, { keepUnsent: false })).rejects.toThrow(/nope/);
    expect(await count(port, "cache_entities", vendorA.key)).toBe(1);
    expect(await count(port, "sync_state", vendorA.key)).toBe(1);
    expect(await count(port, "outbox", vendorA.key)).toBe(2);
  });
});

describe("clock sanity for stores", () => {
  it("uses the injected clock for created/updated timestamps", async () => {
    const port = await openDb();
    const { bus } = recordBus();
    const clock = makeClock();
    const store = createLocalStore(port, vendorA, bus, { now: clock.now });
    const item = await store.outbox.enqueue("k", {});
    expect(item.createdAt).toBe(new Date(T0).toISOString());
  });
});
