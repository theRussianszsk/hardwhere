import { describe, expect, it } from "vitest";
import { makeScope, type CacheRow } from "../src/contracts";
import { upsertCacheRow, deleteCacheRow } from "../src/db/cacheWrite";
import { createLocalStore } from "../src/db/localStore";
import { createMetaStore } from "../src/db/metaStore";
import { makeClock, openDb, recordBus } from "./support/helpers";

const scope = makeScope(1, 5, "vendor");
const ts = "2026-01-01T00:00:00.000Z";
const r = (id: number, name: string, extra: Partial<CacheRow> = {}): CacheRow => ({ id, payload: { id, name }, search: name.toLowerCase(), keys: [], rowVersion: 1, ...extra });

async function setup() {
  const port = await openDb();
  const { bus, events } = recordBus();
  const clock = makeClock();
  const store = createLocalStore(port, scope, bus, { now: clock.now });
  return { port, store, bus, events, clock };
}

describe("LocalCache queries", () => {
  it("search: ALL whitespace tokens must be substrings, case-insensitive", async () => {
    const { port, store } = await setup();
    await upsertCacheRow(port, scope.key, "product", r(1, "Bosch Impact Drill 600W"), "s", ts);
    await upsertCacheRow(port, scope.key, "product", r(2, "Makita Hammer Drill"), "s", ts);
    await upsertCacheRow(port, scope.key, "product", r(3, "Stanley Hammer"), "s", ts);
    const names = async (search: string) => (await store.cache.list<{ name: string }>("product", { search })).map((p) => p.name);
    expect(await names("drill")).toEqual(["Bosch Impact Drill 600W", "Makita Hammer Drill"]);
    expect(await names("  HAMMER   drill ")).toEqual(["Makita Hammer Drill"]);
    expect(await names("hammer bosch")).toEqual([]);
    expect(await names("")).toHaveLength(3);
    expect(await store.cache.count("product", { search: "hammer" })).toBe(2);
  });

  it("search treats % _ and \\ literally (no accidental wildcards)", async () => {
    const { port, store } = await setup();
    await upsertCacheRow(port, scope.key, "product", r(1, "Bolt 100% steel"), "s", ts);
    await upsertCacheRow(port, scope.key, "product", r(2, "Bolt 1000 steel"), "s", ts);
    await upsertCacheRow(port, scope.key, "product", r(3, "pipe a_b joint"), "s", ts);
    await upsertCacheRow(port, scope.key, "product", r(4, "pipe aXb joint"), "s", ts);
    await upsertCacheRow(port, scope.key, "product", r(5, "path c:\\temp"), "s", ts);
    const ids = async (search: string) => (await store.cache.list<{ id: number }>("product", { search })).map((p) => p.id);
    expect(await ids("100%")).toEqual([1]);
    expect(await ids("a_b")).toEqual([3]);
    expect(await ids("%")).toEqual([1]);
    expect(await ids("_")).toEqual([3]);
    expect(await ids("c:\\temp")).toEqual([5]);
  });

  it("orderBy, limit and offset", async () => {
    const { port, store } = await setup();
    await upsertCacheRow(port, scope.key, "product", r(1, "Zinc"), "s", ts);
    await upsertCacheRow(port, scope.key, "product", r(2, "Alpha"), "s", ts);
    await upsertCacheRow(port, scope.key, "product", r(3, "Mid"), "s", ts);
    const names = async (q: Parameters<typeof store.cache.list>[1]) => (await store.cache.list<{ name: string }>("product", q)).map((p) => p.name);
    expect(await names({})).toEqual(["Zinc", "Alpha", "Mid"]);
    expect(await names({ orderBy: "search" })).toEqual(["Alpha", "Mid", "Zinc"]);
    expect(await names({ orderBy: "search", limit: 2 })).toEqual(["Alpha", "Mid"]);
    expect(await names({ orderBy: "search", limit: 2, offset: 1 })).toEqual(["Mid", "Zinc"]);
    expect(await names({ offset: 2 })).toEqual(["Mid"]);
  });

  it("equality filters on mapper-provided filter columns", async () => {
    const { port, store } = await setup();
    await upsertCacheRow(port, scope.key, "listing", r(1, "a", { filters: { store_id: 3 } }), "s", ts);
    await upsertCacheRow(port, scope.key, "listing", r(2, "b", { filters: { store_id: 30 } }), "s", ts);
    await upsertCacheRow(port, scope.key, "listing", r(3, "c", { filters: { store_id: 3, tag: "x" } }), "s", ts);
    await upsertCacheRow(port, scope.key, "listing", r(4, "d"), "s", ts);
    const ids = async (filters: Record<string, string | number>) => (await store.cache.list<{ id: number }>("listing", { filters })).map((p) => p.id);
    expect(await ids({ store_id: 3 })).toEqual([1, 3]);
    expect(await ids({ store_id: 3, tag: "x" })).toEqual([3]);
    expect(await ids({ store_id: 99 })).toEqual([]);
    expect(await store.cache.count("listing", { filters: { store_id: 3 } })).toBe(2);
    await expect(store.cache.list("listing", { filters: { "bad key'; --": 1 } })).rejects.toThrow(/Invalid cache filter/);
  });

  it("findByKey is case-insensitive, entity-scoped, and follows upserts/deletes of the keys", async () => {
    const { port, store } = await setup();
    await upsertCacheRow(port, scope.key, "listing", r(1, "drill", { keys: ["DCH-DRL-001", "3161921000139"] }), "s", ts);
    expect((await store.cache.findByKey<{ id: number }>("listing", "dch-drl-001"))?.id).toBe(1);
    expect((await store.cache.findByKey<{ id: number }>("listing", "  DCH-Drl-001 "))?.id).toBe(1);
    expect(await store.cache.findByKey("product", "dch-drl-001")).toBeNull(); // other entity
    // re-upsert with different keys: old ones disappear
    await upsertCacheRow(port, scope.key, "listing", r(1, "drill", { keys: ["NEW-SKU"] }), "s", ts);
    expect(await store.cache.findByKey("listing", "DCH-DRL-001")).toBeNull();
    expect((await store.cache.findByKey<{ id: number }>("listing", "new-sku"))?.id).toBe(1);
    await deleteCacheRow(port, scope.key, "listing", 1);
    expect(await store.cache.findByKey("listing", "new-sku")).toBeNull();
    expect(await store.cache.get("listing", 1)).toBeNull();
  });

  it("corrupt JSON in the cache is skipped instead of crashing a list", async () => {
    const { port, store } = await setup();
    await upsertCacheRow(port, scope.key, "product", r(1, "ok"), "s", ts);
    await port.run(`INSERT INTO cache_entities (scope_key, entity, id, payload, synced_at) VALUES (?, 'product', 2, '{not json', ?)`, [scope.key, ts]);
    expect(await store.cache.list("product")).toHaveLength(1);
    expect(await store.cache.get("product", 2)).toBeNull();
  });
});

describe("outbox API", () => {
  it("enqueue defaults to queued, generates ids, is idempotent for a repeated id, and emits on the bus", async () => {
    const { store, events } = await setup();
    const a = await store.outbox.enqueue("stock.adjust", { qty: -2 }, { label: "Adjust stock: Hammer −2" });
    expect(a).toMatchObject({ kind: "stock.adjust", payload: { qty: -2 }, payloadVersion: 1, state: "queued", attempts: 0, nextAttemptAt: null, lastError: null, response: null, label: "Adjust stock: Hammer −2" });
    expect(a.id).toMatch(/^[0-9a-f-]{16,}$/);
    const again = await store.outbox.enqueue("stock.adjust", { qty: 999 }, { id: a.id });
    expect(again.payload).toEqual({ qty: -2 }); // the original, not overwritten
    expect(await store.outbox.list()).toHaveLength(1);
    expect(events).toContainEqual({ area: "outbox" });
  });

  it("draft -> submitDraft -> queued; list filters; counts include all six states", async () => {
    const { store } = await setup();
    const d = await store.outbox.enqueue("sale", { n: 1 }, { state: "draft", payloadVersion: 3 });
    expect(d).toMatchObject({ state: "draft", payloadVersion: 3 });
    await store.outbox.enqueue("stock.adjust", {}, { id: "q1" });
    expect(await store.outbox.countsByState()).toEqual({ draft: 1, queued: 1, sending: 0, confirmed: 0, rejected: 0, needs_review: 0 });
    await store.outbox.submitDraft(d.id);
    await store.outbox.submitDraft("missing"); // no-op
    expect((await store.outbox.get(d.id))?.state).toBe("queued");
    expect((await store.outbox.list({ kinds: ["sale"] })).map((i) => i.id)).toEqual([d.id]);
    expect((await store.outbox.list({ states: ["queued"], limit: 1 }))).toHaveLength(1);
    expect(await store.outbox.unsentCount()).toBe(2);
  });

  it("list is oldest first", async () => {
    const { store, clock } = await setup();
    await store.outbox.enqueue("k", {}, { id: "first" });
    clock.advance(1000);
    await store.outbox.enqueue("k", {}, { id: "second" });
    clock.advance(1000);
    await store.outbox.enqueue("k", {}, { id: "third" });
    expect((await store.outbox.list()).map((i) => i.id)).toEqual(["first", "second", "third"]);
  });

  it("retryNow only re-queues queued/needs_review/rejected; discard never deletes a row that is mid-send", async () => {
    const { port, store } = await setup();
    for (const [id, state] of [["a", "needs_review"], ["b", "rejected"], ["c", "sending"], ["d", "confirmed"], ["e", "draft"]] as const)
      await port.run(`INSERT INTO outbox (id, scope_key, kind, payload, state, attempts, next_attempt_at, last_error, created_at, updated_at) VALUES (?, ?, 'k', '{}', ?, 4, '2099-01-01T00:00:00.000Z', '{"code":"x","message":"m"}', ?, ?)`, [id, scope.key, state, "2026-01-01T00:00:00.000Z", "2026-01-01T00:00:00.000Z"]);
    for (const id of ["a", "b", "c", "d", "e"]) await store.outbox.retryNow(id);
    const states = Object.fromEntries((await store.outbox.list()).map((i) => [i.id, i]));
    expect(states.a).toMatchObject({ state: "queued", attempts: 0, nextAttemptAt: null, lastError: null });
    expect(states.b).toMatchObject({ state: "queued", attempts: 0 });
    expect(states.c!.state).toBe("sending");
    expect(states.d!.state).toBe("confirmed");
    expect(states.e!.state).toBe("draft");
    await store.outbox.discard("c");
    expect(await store.outbox.get("c")).not.toBeNull();
    await store.outbox.discard("a");
    expect(await store.outbox.get("a")).toBeNull();
  });

  it("markConfirmed records a looked-up result for unresolved rows only, never for a row mid-send", async () => {
    const { port, store } = await setup();
    for (const [id, state] of [["a", "needs_review"], ["b", "rejected"], ["c", "sending"], ["d", "draft"]] as const)
      await port.run(`INSERT INTO outbox (id, scope_key, kind, payload, state, attempts, next_attempt_at, last_error, created_at, updated_at) VALUES (?, ?, 'k', '{}', ?, 1, NULL, '{"code":"x","message":"m"}', ?, ?)`, [id, scope.key, state, ts, ts]);
    for (const id of ["a", "b", "c", "d"]) await store.outbox.markConfirmed(id, { data: { id: 7 } });
    const items = Object.fromEntries((await store.outbox.list()).map((i) => [i.id, i]));
    expect(items.a).toMatchObject({ state: "confirmed", response: { data: { id: 7 } }, lastError: null });
    expect(items.b).toMatchObject({ state: "confirmed", response: { data: { id: 7 } } });
    expect(items.c).toMatchObject({ state: "sending", response: null });
    expect(items.d!.state).toBe("draft");
  });

  it("corrupt payload/response JSON does not throw", async () => {
    const { port, store } = await setup();
    await port.run(`INSERT INTO outbox (id, scope_key, kind, payload, state, response, last_error, created_at, updated_at) VALUES ('z', ?, 'k', '{oops', 'queued', '[', 'nope', 't', 't')`, [scope.key]);
    expect(await store.outbox.get("z")).toMatchObject({ payload: null, response: null, lastError: null });
  });
});

describe("drafts, photos, meta", () => {
  it("drafts: put overwrites, get/list/remove, bus events", async () => {
    const { store, events, clock } = await setup();
    await store.drafts.put("sale", "d1", { v: 1 });
    clock.advance(1000);
    await store.drafts.put("sale", "d2", { v: 2 });
    clock.advance(1000);
    await store.drafts.put("sale", "d1", { v: 3 });
    await store.drafts.put("note", "d1", { other: true });
    expect(await store.drafts.get("sale", "d1")).toEqual({ v: 3 });
    expect((await store.drafts.list<{ v: number }>("sale")).map((d) => d.id)).toEqual(["d1", "d2"]); // most recently updated first
    expect(await store.drafts.get("sale", "nope")).toBeNull();
    await store.drafts.remove("sale", "d1");
    expect(await store.drafts.get("sale", "d1")).toBeNull();
    expect(await store.drafts.get("note", "d1")).toEqual({ other: true });
    expect(events.filter((e) => e.area === "drafts").length).toBeGreaterThanOrEqual(5);
  });

  it("photos: add/list/remove and the image is never in SQLite (only a file ref)", async () => {
    const { port, store, events } = await setup();
    await store.photos.add({ id: "p1", fileRef: "photos/p1.jpg", mime: "image/jpeg", size: 123456, purpose: "ai.analyze", hint: "drill", outboxId: "o1" });
    expect(await store.photos.list()).toEqual([{ id: "p1", fileRef: "photos/p1.jpg", mime: "image/jpeg", size: 123456, purpose: "ai.analyze", hint: "drill", outboxId: "o1", createdAt: expect.any(String) }]);
    const cols = (await port.all<{ name: string; type: string }>(`PRAGMA table_info(photo_queue)`)).map((c) => c.type);
    expect(cols).not.toContain("BLOB");
    await store.photos.remove("p1");
    expect(await store.photos.list()).toEqual([]);
    expect(events.filter((e) => e.area === "photos")).toHaveLength(2);
  });

  it("meta store: get/set/overwrite/delete", async () => {
    const { port } = await setup();
    const meta = createMetaStore(port);
    expect(await meta.get("k")).toBeNull();
    await meta.set("k", "1");
    await meta.set("k", "2");
    expect(await meta.get("k")).toBe("2");
    await meta.delete("k");
    expect(await meta.get("k")).toBeNull();
  });
});
