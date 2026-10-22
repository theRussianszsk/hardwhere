import { describe, expect, it, vi } from "vitest";
import { ApiError } from "../src/api/errors";
import { makeScope, type ScopeInfo } from "../src/contracts";
import { upsertCacheRow } from "../src/db/cacheWrite";
import { createLocalStore } from "../src/db/localStore";
import { migrate } from "../src/db/migrations";
import { wipeScope } from "../src/db/wipe";
import { createSyncEngine, ENTITIES_BY_KIND, type SyncEngineHandle } from "../src/sync/engine";
import { productMapper } from "../src/sync/mappers";
import { createFakeSyncApi, netError, type FakeSyncApi } from "./support/fakeApi";
import { fx, publicData, vendorData } from "./support/fixtures";
import { createNodeSqlitePort, type NodeSqlitePort } from "./support/nodePort";
import { makeClock, openDb, recordBus, T0, useTmpDir } from "./support/helpers";

const tmp = useTmpDir();
const MIN = 60_000;
const vendor = makeScope(1, 5, "vendor");
const pub = makeScope(0, null, "public");

function engineFor(port: NodeSqlitePort, scope: ScopeInfo, fake: FakeSyncApi, o: Partial<Parameters<typeof createSyncEngine>[0]> & { pageSize?: number } = {}) {
  const clock = (o as any).clock ?? makeClock();
  const { bus, events } = recordBus();
  const engine = createSyncEngine({ api: fake.api, port, scope, bus, now: clock.now, ...o } as any) as SyncEngineHandle;
  const store = createLocalStore(port, scope, bus, { now: clock.now });
  return { engine, store, clock, events, bus };
}
const state = async (port: NodeSqlitePort, scope: ScopeInfo) => (await port.all<Record<string, any>>(`SELECT * FROM sync_state WHERE scope_key = ?`, [scope.key]))[0];

describe("sync engine: first snapshot", () => {
  it("pages every entity, stamps one snapshot id, keeps the FIRST page's cursor and marks the snapshot complete", async () => {
    const port = await openDb();
    const fake = createFakeSyncApi(vendorData());
    const { engine, store, events } = engineFor(port, vendor, fake, { pageSize: 1 });
    expect(engine.status()).toMatchObject({ snapshotComplete: false, stale: true, lastSyncedAt: null, phase: "idle" });

    const res = await engine.syncNow();
    expect(res).toMatchObject({ mode: "snapshot", cursor: "snap-1" });
    expect(res.applied).toBe(Object.values(vendorData()).flat().length);

    // every call is a snapshot call, the first is for the first entity, later pages resume from next_after
    const calls = fake.snapshotCalls();
    expect(calls[0]).toMatchObject({ entity: "category", after: 0 });
    expect(calls.map((c) => c.entity)).toEqual(expect.arrayContaining([...ENTITIES_BY_KIND.vendor]));
    expect(calls.filter((c) => c.entity === "product").map((c) => c.after)).toEqual([0, 1]); // one product per page, keyset resume from the last id
    expect((await state(port, vendor))).toMatchObject({ cursor: "snap-1", snapshot_complete: 1, pending_cursor: null, snapshot_progress: null });

    expect(await store.cache.count("category")).toBe(2);
    expect(await store.cache.count("product")).toBe(2);
    expect(await store.cache.count("listing")).toBe(2);
    expect(await store.cache.count("inventory_item")).toBe(2);
    expect(await store.cache.count("order")).toBe(2);
    const ids = await port.all<{ s: string }>(`SELECT DISTINCT snapshot_id AS s FROM cache_entities WHERE scope_key = ?`, [vendor.key]);
    expect(ids).toHaveLength(1);

    expect(engine.status()).toMatchObject({ phase: "idle", snapshotComplete: true, error: null, stale: false });
    expect(events).toContainEqual({ area: "cache", entity: "listing" });
    expect(events).toContainEqual({ area: "sync" });
  });

  it("uses the entities list the server returns (customer kind has no inventory_item)", async () => {
    const port = await openDb();
    const customer = makeScope(3, null, "customer");
    const fake = createFakeSyncApi({ category: fx.category(), order: fx.customerOrder() });
    const { engine, store } = engineFor(port, customer, fake);
    await engine.syncNow();
    expect(fake.snapshotCalls().map((c) => c.entity)).toEqual(["category", "product", "store", "listing", "order"]);
    expect(await store.cache.count("order")).toBe(2);
    // a server that serves a different list wins over the built-in one
    const fake2 = createFakeSyncApi({ category: fx.category(), store: fx.publicStore() }, { entities: ["category", "store"] });
    const e2 = engineFor(await openDb(), pub, fake2);
    await e2.engine.syncNow();
    expect(fake2.snapshotCalls().map((c) => c.entity)).toEqual(["category", "store"]);
    expect(await e2.store.cache.count("store")).toBe(2);
  });
});

describe("(c) offline cold start", () => {
  it("reopens a file database with synced data and no network: cache still readable, snapshotComplete stays true, stale from the clock, error recorded", async () => {
    const file = tmp("cold.sqlite");
    const clock = makeClock();
    {
      const port = await openDb(file);
      const { engine } = engineFor(port, vendor, createFakeSyncApi(vendorData()), { clock } as any);
      await engine.syncNow();
      port.kill(); // app force-quit
    }
    const port = createNodeSqlitePort(file);
    await migrate(port);
    const offline = { syncSnapshot: vi.fn(async () => { throw netError(); }), syncChanges: vi.fn(async () => { throw netError(); }) };
    clock.advance(10 * MIN);
    const { engine, store } = engineFor(port, vendor, { api: offline } as any, { clock } as any);

    await engine.ready();
    expect(engine.status()).toMatchObject({ snapshotComplete: true, phase: "idle", stale: false, lastSyncedAt: new Date(T0).toISOString() });
    expect(await store.cache.count("listing")).toBe(2); // readable with no network at all
    expect((await store.cache.get<{ sku: string }>("listing", 1))?.sku).toBe("DCH-DRL-001");
    expect(await store.cache.lastSyncedAt()).toBe(new Date(T0).toISOString());

    clock.advance(10 * MIN); // now 20 minutes since the last sync
    const res = await engine.syncNow(); // resolves, never throws
    expect(res).toMatchObject({ mode: "delta", applied: 0, deleted: 0, cursor: "snap-1" });
    const s = engine.status();
    expect(s).toMatchObject({ phase: "error", snapshotComplete: true, stale: true, error: { kind: "network" }, lastAttemptAt: new Date(clock.now()).toISOString() });
    expect(offline.syncChanges).toHaveBeenCalledWith("vendor", "snap-1", 200);
    expect(offline.syncSnapshot).not.toHaveBeenCalled(); // did NOT fall back to a full re-download
    expect(await store.cache.count("listing")).toBe(2);
    expect(JSON.parse((await state(port, vendor))!.last_error)).toMatchObject({ kind: "network" });
  });
});

describe("(d) cursor commit is atomic", () => {
  async function synced() {
    const port = await openDb();
    const fake = createFakeSyncApi(vendorData());
    let boom = false;
    let n = 0;
    const mappers = { product: (d: any) => { if (boom && ++n === 2) throw new Error("mapper blew up on op 2"); return productMapper(d); } };
    const ctx = engineFor(port, vendor, fake, { mappers });
    await ctx.engine.syncNow();
    return { port, fake, ...ctx, arm: () => { boom = true; n = 0; }, disarm: () => { boom = false; } };
  }
  const renamed = (p: any, name: string) => ({ ...p, name, row_version: p.row_version + 1 });

  it("a delta batch that fails halfway leaves cursor AND rows untouched; the next run applies it", async () => {
    const { port, fake, engine, store, arm, disarm } = await synced();
    const [p1, p2] = fx.product();
    const batch = { changes: [
      { entity: "product", id: 1, op: "upsert" as const, data: renamed(p1, "RENAMED ONE") },
      { entity: "product", id: 2, op: "upsert" as const, data: renamed(p2, "RENAMED TWO") },
      { entity: "category", id: 1, op: "delete" as const, data: null },
    ], cursor: "9.9", has_more: false };
    fake.onChanges("snap-1", batch);
    arm();
    const before = await port.all(`SELECT * FROM cache_entities WHERE scope_key = ? ORDER BY entity, id`, [vendor.key]);

    const res = await engine.syncNow();
    expect(res).toMatchObject({ mode: "delta", applied: 0, deleted: 0, cursor: "snap-1" });
    expect(engine.status()).toMatchObject({ phase: "error", error: { kind: "local", message: "mapper blew up on op 2" }, snapshotComplete: true });
    expect((await state(port, vendor))!.cursor).toBe("snap-1");
    expect(await port.all(`SELECT * FROM cache_entities WHERE scope_key = ? ORDER BY entity, id`, [vendor.key])).toEqual(before); // op 1 was rolled back too
    expect((await store.cache.get<{ name: string }>("product", 1))?.name).toBe("Hammer Drill 16mm");
    expect(await store.cache.get("category", 1)).not.toBeNull();

    disarm();
    fake.onChanges("snap-1", batch); // the server still has the same position to serve
    const retry = await engine.syncNow();
    expect(retry).toMatchObject({ mode: "delta", applied: 2, deleted: 1, cursor: "9.9" });
    expect(engine.status()).toMatchObject({ phase: "idle", error: null });
    expect((await state(port, vendor))!.cursor).toBe("9.9");
    expect((await store.cache.get<{ name: string }>("product", 1))?.name).toBe("RENAMED ONE");
    expect(await store.cache.get("category", 1)).toBeNull();
  });

  it("multi-page deltas advance the cursor batch by batch; a later failing batch keeps the earlier committed ones", async () => {
    const { port, fake, engine, store, arm } = await synced();
    const [p1, p2] = fx.product();
    fake.onChanges("snap-1", { changes: [{ entity: "product", id: 1, op: "upsert", data: renamed(p1, "BATCH ONE") }], cursor: "2.1", has_more: true });
    fake.onChanges("2.1", { changes: [{ entity: "product", id: 2, op: "upsert", data: renamed(p2, "BATCH TWO") }], cursor: "3.1", has_more: false });
    arm();
    // mapper counter fires on the 2nd product mapped: that is batch two's op
    const res = await engine.syncNow();
    expect(res).toMatchObject({ applied: 1, cursor: "2.1" });
    expect((await state(port, vendor))!.cursor).toBe("2.1");
    expect((await store.cache.get<{ name: string }>("product", 1))?.name).toBe("BATCH ONE");
    expect((await store.cache.get<{ name: string }>("product", 2))?.name).not.toBe("BATCH TWO");
    expect(engine.status().error).not.toBeNull();
  });

  it("an interrupted snapshot resumes from the saved progress (no restart) and still uses the FIRST page's cursor", async () => {
    const port = await openDb();
    const data = { ...vendorData(), product: Array.from({ length: 5 }, (_, i) => ({ ...fx.product()[0], id: i + 1, name: `Product ${i + 1}` })) };
    const fake = createFakeSyncApi(data);
    const { engine, store } = engineFor(port, vendor, fake, { pageSize: 2 });
    // calls: 1 category, 2 product(after 0), 3 product(after 2) <- fail
    fake.failWhen((c) => (c.type === "snapshot" && c.n === 3 ? netError() : null));

    const first = await engine.syncNow();
    expect(first.mode).toBe("snapshot");
    expect(engine.status()).toMatchObject({ phase: "error", snapshotComplete: false, error: { kind: "network" } });
    const mid = (await state(port, vendor))!;
    expect(mid).toMatchObject({ snapshot_complete: 0, cursor: null, pending_cursor: "snap-1" });
    expect(JSON.parse(mid.snapshot_progress)).toMatchObject({ entity: "product", after: 2 });
    expect(await store.cache.count("product")).toBe(2); // page 1 is already readable
    const snapshotId = mid.snapshot_id;

    fake.failWhen(null);
    const countBefore = fake.snapshotCalls().length;
    const second = await engine.syncNow();
    expect(second.mode).toBe("snapshot");
    const resumed = fake.snapshotCalls().slice(countBefore);
    expect(resumed[0]).toMatchObject({ entity: "product", after: 2 }); // resumed, did not go back to after 0
    expect(resumed.some((c) => c.entity === "category")).toBe(false); // finished entities are not fetched again
    expect(resumed.some((c) => c.entity === "product" && c.after === 0)).toBe(false);
    const end = (await state(port, vendor))!;
    expect(end).toMatchObject({ snapshot_complete: 1, cursor: "snap-1", snapshot_id: snapshotId, snapshot_progress: null, pending_cursor: null }); // run 2's cursors (snap-4...) ignored
    expect(await store.cache.count("product")).toBe(5);
    expect(await store.cache.count("order")).toBe(2);
    expect(engine.status()).toMatchObject({ phase: "idle", snapshotComplete: true, error: null });
  });

  it("an interrupted snapshot also resumes after a process restart (state is in SQLite, not memory)", async () => {
    const file = tmp("resume.sqlite");
    const data = vendorData();
    {
      const port = await openDb(file);
      const fake = createFakeSyncApi(data);
      const { engine } = engineFor(port, vendor, fake);
      fake.failWhen((c) => (c.type === "snapshot" && c.entity === "listing" ? netError() : null));
      await engine.syncNow();
      port.kill();
    }
    const port = createNodeSqlitePort(file);
    await migrate(port);
    const fake = createFakeSyncApi(data, { cursorPrefix: "run2" });
    const { engine, store } = engineFor(port, vendor, fake);
    await engine.ready();
    expect(engine.status().snapshotComplete).toBe(false);
    await engine.syncNow();
    expect(fake.snapshotCalls().map((c) => c.entity)).toEqual(["listing", "inventory_item", "order"]); // category/product/store were done
    expect((await state(port, vendor))!.cursor).toBe("snap-1"); // first page of the FIRST process, not "run2-*"
    expect(await store.cache.count("listing")).toBe(2);
    expect(engine.status().snapshotComplete).toBe(true);
  });
});

describe("(e) tombstones and stale rows", () => {
  it("a delete op removes the row AND its exact-lookup keys", async () => {
    const port = await openDb();
    const fake = createFakeSyncApi(vendorData());
    const { engine, store } = engineFor(port, vendor, fake);
    await engine.syncNow();
    expect((await store.cache.findByKey<{ id: number }>("listing", "dch-drl-001"))?.id).toBe(1);
    fake.onChanges("snap-1", { changes: [{ entity: "listing", id: 1, op: "delete", data: null }], cursor: "5.5", has_more: false });
    const res = await engine.syncNow();
    expect(res).toMatchObject({ deleted: 1, applied: 0, cursor: "5.5" });
    expect(await store.cache.get("listing", 1)).toBeNull();
    expect(await store.cache.findByKey("listing", "DCH-DRL-001")).toBeNull();
    expect(await port.all(`SELECT 1 FROM cache_keys WHERE scope_key = ? AND entity = 'listing' AND id = 1`, [vendor.key])).toHaveLength(0);
    expect(await store.cache.get("listing", 2)).not.toBeNull(); // only the tombstoned row
  });

  it("revoked visibility (public feed): an unpublished listing arrives as a tombstone while product upserts from the real changes fixture apply", async () => {
    const port = await openDb();
    const fake = createFakeSyncApi(publicData());
    const { engine, store, events } = engineFor(port, pub, fake);
    await engine.syncNow();
    expect(await store.cache.count("listing")).toBe(2);

    const feed = fx.publicChanges(); // two real product upserts (ids normalised to 1 by the fixture writer)
    feed.changes[1]!.id = 2;
    feed.changes[1]!.data.id = 2;
    feed.changes[0]!.data.name = "Hammer Drill 16mm (v2)";
    feed.changes.push({ entity: "listing", id: 2, op: "delete", data: null }); // seller unpublished listing 2
    fake.onChanges("snap-1", { changes: feed.changes, cursor: "7.7", has_more: false });
    events.length = 0;
    const res = await engine.syncNow();
    expect(res).toMatchObject({ mode: "delta", applied: 2, deleted: 1, cursor: "7.7" });
    expect((await store.cache.get<{ name: string }>("product", 1))?.name).toBe("Hammer Drill 16mm (v2)");
    expect(await store.cache.get("listing", 2)).toBeNull();
    expect(await store.cache.count("listing")).toBe(1);
    expect(events).toEqual(expect.arrayContaining([{ area: "cache", entity: "product" }, { area: "cache", entity: "listing" }]));
  });

  it("deltas for entities this build does not cache are skipped; upsert without data is ignored, not treated as a delete", async () => {
    const port = await openDb();
    const fake = createFakeSyncApi(vendorData());
    const { engine, store } = engineFor(port, vendor, fake);
    await engine.syncNow();
    fake.onChanges("snap-1", { changes: [
      { entity: "mystery", id: 1, op: "upsert", data: { id: 1 } },
      { entity: "listing", id: 1, op: "upsert", data: null },
    ], cursor: "8.8", has_more: false });
    expect(await engine.syncNow()).toMatchObject({ applied: 0, deleted: 0, cursor: "8.8" });
    expect(await store.cache.get("listing", 1)).not.toBeNull();
  });

  it("forced re-snapshot: rows deleted on the server stay visible while it runs and vanish only in the final transaction", async () => {
    const port = await openDb();
    const fake = createFakeSyncApi(vendorData());
    const { engine, store } = engineFor(port, vendor, fake);
    await engine.syncNow();
    const firstSnapshotId = (await state(port, vendor))!.snapshot_id;
    expect(await store.cache.count("product")).toBe(2);

    fake.data.product = fake.data.product!.filter((p) => p.id !== 2); // product 2 disappeared server-side
    fake.data.product![0]!.name = "Changed on server";
    const seen: Record<string, unknown> = {};
    fake.beforeCall(async (c) => {
      if (c.type === "snapshot" && c.entity === "order") { // last entity: everything else was already re-fetched
        seen.stale = await store.cache.get("product", 2);
        seen.complete = engine.status().snapshotComplete;
        seen.renamed = (await store.cache.get<{ name: string }>("product", 1))?.name;
        seen.cursorStillOld = (await state(port, vendor))!.cursor;
      }
    });
    const res = await engine.syncNow({ forceSnapshot: true });

    expect(res.mode).toBe("snapshot");
    expect(seen.stale).not.toBeNull(); // still shown while the re-snapshot is in flight
    expect(seen.complete).toBe(true); // UI never falls back to the "first launch" state
    expect(seen.renamed).toBe("Changed on server"); // fresh rows replaced in place as pages arrive
    expect(seen.cursorStillOld).toBe("snap-1");
    expect(await store.cache.get("product", 2)).toBeNull(); // gone after the final swap
    expect(await store.cache.count("product")).toBe(1);
    const after = (await state(port, vendor))!;
    expect(after.snapshot_id).not.toBe(firstSnapshotId);
    expect(after.cursor).toBe("snap-7"); // first page of the forced snapshot (calls 1-6 were the initial one)
    expect((await port.all<{ s: string }>(`SELECT DISTINCT snapshot_id AS s FROM cache_entities WHERE scope_key = ?`, [vendor.key])).map((r) => r.s)).toEqual([after.snapshot_id]);
    expect(await port.all(`SELECT 1 FROM cache_keys WHERE scope_key = ? AND entity = 'product' AND id = 2`, [vendor.key])).toHaveLength(0);
  });

  it("a failed forced re-snapshot keeps the old data and the old cursor, and the next plain syncNow finishes it", async () => {
    const port = await openDb();
    const fake = createFakeSyncApi(vendorData());
    const { engine, store } = engineFor(port, vendor, fake);
    await engine.syncNow();
    fake.data.product = [];
    fake.failWhen((c) => (c.type === "snapshot" && c.entity === "store" ? netError() : null));
    await engine.syncNow({ forceSnapshot: true });
    expect(engine.status()).toMatchObject({ phase: "error", snapshotComplete: true });
    expect(await store.cache.count("product")).toBe(2);
    expect((await state(port, vendor))!.cursor).toBe("snap-1");
    fake.failWhen(null);
    const res = await engine.syncNow(); // resumes the pending snapshot, not a delta
    expect(res.mode).toBe("snapshot");
    expect(await store.cache.count("product")).toBe(0);
    expect((await state(port, vendor))!.snapshot_progress).toBeNull();
  });
});

describe("(l) stale stock labelling", () => {
  it("SyncStatus.stale follows the injected clock; cached available_quantity is plain payload that the engine never freshens", async () => {
    const port = await openDb();
    const clock = makeClock();
    const fake = createFakeSyncApi(vendorData());
    const { engine, store } = engineFor(port, vendor, fake, { clock, staleAfterMs: 15 * MIN } as any);
    expect(engine.status().stale).toBe(true); // never synced
    await engine.syncNow();
    expect(engine.status().stale).toBe(false);
    const listing = await store.cache.get<{ available_quantity: number }>("listing", 2);
    expect(listing?.available_quantity).toBe(6);
    const syncedAtRow = (await port.all<{ synced_at: string }>(`SELECT synced_at FROM cache_entities WHERE scope_key = ? AND entity = 'listing' AND id = 2`, [vendor.key]))[0]!.synced_at;

    clock.advance(14 * MIN + 59_000);
    expect(engine.status().stale).toBe(false);
    clock.advance(2_000);
    expect(engine.status().stale).toBe(true);

    // offline attempts do not freshen anything
    fake.failWhen(() => netError());
    await engine.syncNow();
    expect(engine.status()).toMatchObject({ stale: true, error: { kind: "network" } });
    expect((await store.cache.get<{ available_quantity: number }>("listing", 2))?.available_quantity).toBe(6);

    // a successful no-change delta freshens the *status*, but the cached payload and its own timestamp stay as the server left them
    fake.failWhen(null);
    await engine.syncNow();
    expect(engine.status().stale).toBe(false);
    expect(engine.status().lastSyncedAt).toBe(new Date(clock.now()).toISOString());
    expect((await store.cache.get<{ available_quantity: number }>("listing", 2))?.available_quantity).toBe(6);
    expect((await port.all<{ synced_at: string }>(`SELECT synced_at FROM cache_entities WHERE scope_key = ? AND entity = 'listing' AND id = 2`, [vendor.key]))[0]!.synced_at).toBe(syncedAtRow);

    // only a server delta changes stock
    fake.onChanges((await state(port, vendor))!.cursor, { changes: [{ entity: "listing", id: 2, op: "upsert", data: { ...fx.vendorListing()[1], available_quantity: 1 } }], cursor: "4.4", has_more: false });
    await engine.syncNow();
    expect((await store.cache.get<{ available_quantity: number }>("listing", 2))?.available_quantity).toBe(1);
  });

  it("staleAfterMs is configurable and defaults to 15 minutes", async () => {
    const port = await openDb();
    const clock = makeClock();
    const { engine } = engineFor(port, vendor, createFakeSyncApi(vendorData()), { clock, staleAfterMs: MIN } as any);
    await engine.syncNow();
    clock.advance(MIN);
    expect(engine.status().stale).toBe(false); // exactly at the limit is not older than it
    clock.advance(1);
    expect(engine.status().stale).toBe(true);
  });
});

describe("(i) account / tenant switching", () => {
  it("each scope has its own sync state; a second scope starts empty and unsynced while the first stays intact", async () => {
    const port = await openDb();
    const a = makeScope(1, 5, "vendor");
    const b = makeScope(2, 5, "vendor");
    expect(a.key).not.toBe(b.key);
    const ea = engineFor(port, a, createFakeSyncApi(vendorData()));
    await ea.engine.syncNow();
    const eb = engineFor(port, b, createFakeSyncApi({ category: [], product: [], store: [], listing: [], inventory_item: [], order: [] }));
    await eb.engine.ready();
    expect(eb.engine.status()).toMatchObject({ snapshotComplete: false, lastSyncedAt: null, stale: true });
    expect(await eb.store.cache.count("listing")).toBe(0);
    expect(ea.engine.status().snapshotComplete).toBe(true);
    expect(await ea.store.cache.count("listing")).toBe(2);
    await eb.engine.syncNow();
    expect(await eb.store.cache.count("listing")).toBe(0);
    expect(await ea.store.cache.count("listing")).toBe(2);
    expect((await port.all<{ k: string }>(`SELECT scope_key AS k FROM sync_state ORDER BY k`)).map((r) => r.k)).toEqual([a.key, b.key]);
  });
});

describe("error handling, callbacks, coalescing", () => {
  it("401 -> onUnauthenticated + error kind 'unauthenticated'; resolves instead of throwing", async () => {
    const port = await openDb();
    const fake = createFakeSyncApi(vendorData());
    const onUnauthenticated = vi.fn();
    const onAccessRevoked = vi.fn();
    const { engine } = engineFor(port, vendor, fake, { onUnauthenticated, onAccessRevoked });
    fake.failWhen(() => new ApiError({ kind: "unauthenticated", status: 401, message: "Please sign in." }));
    const res = await engine.syncNow();
    expect(res.mode).toBe("snapshot");
    expect(onUnauthenticated).toHaveBeenCalledTimes(1);
    expect(onAccessRevoked).not.toHaveBeenCalled();
    expect(engine.status()).toMatchObject({ phase: "error", error: { kind: "unauthenticated", code: "unauthenticated" } });
  });

  it("403 membership_revoked / no_business_access -> onAccessRevoked; other 403s and 5xx are plain errors", async () => {
    const port = await openDb();
    const fake = createFakeSyncApi(vendorData());
    const onAccessRevoked = vi.fn();
    const onUnauthenticated = vi.fn();
    const { engine } = engineFor(port, vendor, fake, { onAccessRevoked, onUnauthenticated });
    for (const code of ["membership_revoked", "no_business_access"]) {
      fake.failWhen(() => new ApiError({ kind: "forbidden", status: 403, code, message: "revoked" }));
      await engine.syncNow();
      expect(engine.status().error).toMatchObject({ kind: "forbidden", code });
    }
    expect(onAccessRevoked).toHaveBeenCalledTimes(2);
    fake.failWhen(() => new ApiError({ kind: "forbidden", status: 403, code: "forbidden", message: "no" }));
    await engine.syncNow();
    fake.failWhen(() => new ApiError({ kind: "server", status: 503, message: "down" }));
    await engine.syncNow();
    expect(onAccessRevoked).toHaveBeenCalledTimes(2);
    expect(onUnauthenticated).not.toHaveBeenCalled();
    expect(engine.status().error).toMatchObject({ kind: "server" });
    fake.failWhen(null);
    await engine.syncNow();
    expect(engine.status()).toMatchObject({ phase: "idle", error: null, snapshotComplete: true });
  });

  it("a throwing session callback does not break the engine", async () => {
    const port = await openDb();
    const fake = createFakeSyncApi(vendorData());
    const { engine } = engineFor(port, vendor, fake, { onUnauthenticated: () => { throw new Error("cb"); } });
    fake.failWhen(() => new ApiError({ kind: "unauthenticated", status: 401, message: "x" }));
    await expect(engine.syncNow()).resolves.toMatchObject({ mode: "snapshot" });
  });

  it("concurrent syncNow() calls coalesce into one run; the engine is reusable afterwards (also after a failure)", async () => {
    const port = await openDb();
    const fake = createFakeSyncApi(vendorData());
    const { engine } = engineFor(port, vendor, fake);
    const [a, b, c] = await Promise.all([engine.syncNow(), engine.syncNow(), engine.syncNow()]);
    expect(b).toBe(a);
    expect(c).toBe(a);
    const calls = fake.snapshotCalls().length;
    expect(calls).toBe(ENTITIES_BY_KIND.vendor.length);

    fake.failWhen(() => netError());
    const [d, e] = await Promise.all([engine.syncNow(), engine.syncNow()]);
    expect(e).toBe(d);
    expect(fake.changeCalls()).toHaveLength(1);
    fake.failWhen(null);
    await expect(engine.syncNow()).resolves.toMatchObject({ mode: "delta" }); // in-flight marker was cleared in finally
  });

  it("forceSnapshot requested during a normal run waits for it and then re-snapshots once", async () => {
    const port = await openDb();
    const fake = createFakeSyncApi(vendorData());
    const { engine } = engineFor(port, vendor, fake);
    await engine.syncNow();
    fake.reset();
    const p1 = engine.syncNow();
    const p2 = engine.syncNow({ forceSnapshot: true });
    const p3 = engine.syncNow({ forceSnapshot: true });
    expect((await p1).mode).toBe("delta");
    expect((await p2).mode).toBe("snapshot");
    expect((await p3).mode).toBe("snapshot");
    expect(fake.changeCalls()).toHaveLength(1);
    expect(fake.snapshotCalls()).toHaveLength(ENTITIES_BY_KIND.vendor.length); // one forced snapshot, not two
  });

  it("subscribe() fires on phase changes and can be unsubscribed", async () => {
    const port = await openDb();
    const { engine } = engineFor(port, vendor, createFakeSyncApi(vendorData()));
    const phases: string[] = [];
    const off = engine.subscribe(() => phases.push(engine.status().phase));
    await engine.syncNow();
    expect(phases).toEqual(["syncing", "idle"]);
    off();
    await engine.syncNow();
    expect(phases).toHaveLength(2);
  });

  it("a server whose cursor never advances while claiming has_more cannot loop forever", async () => {
    const port = await openDb();
    const fake = createFakeSyncApi(vendorData());
    const { engine } = engineFor(port, vendor, fake);
    await engine.syncNow();
    fake.onChanges("snap-1", { changes: [], cursor: "snap-1", has_more: true });
    await engine.syncNow();
    expect(engine.status().error).toMatchObject({ message: "Sync cursor did not advance" });
  });

  it("a snapshot page whose next_after does not advance ends the entity instead of looping", async () => {
    const port = await openDb();
    const fake = createFakeSyncApi(vendorData());
    const real = fake.api.syncSnapshot;
    fake.api.syncSnapshot = async (k, e, after, l) => ({ ...(await real(k, e, after, l)), next_after: after }); // always claims "more from the same place"
    const { engine } = engineFor(port, vendor, fake);
    await engine.syncNow();
    expect(engine.status()).toMatchObject({ phase: "idle", snapshotComplete: true });
  });

  it("a scope wiped while a sync is in flight is NOT resurrected by the late response", async () => {
    const port = await openDb();
    const fake = createFakeSyncApi(vendorData());
    const { engine, store } = engineFor(port, vendor, fake);
    fake.beforeCall(async (c) => { if (c.type === "snapshot" && c.entity === "product") await wipeScope(port, vendor.key, { keepUnsent: false }); }); // logout lands mid-sync
    await engine.syncNow();
    expect(await store.cache.count("category")).toBe(0);
    expect(await port.all(`SELECT 1 FROM cache_entities WHERE scope_key = ?`, [vendor.key])).toHaveLength(0);
    expect(await port.all(`SELECT 1 FROM cache_keys WHERE scope_key = ?`, [vendor.key])).toHaveLength(0);
    expect(await port.all(`SELECT 1 FROM sync_state WHERE scope_key = ?`, [vendor.key])).toHaveLength(0);
    expect(engine.status()).toMatchObject({ phase: "idle", error: null });
  });

  it("rows without the current snapshot id (e.g. left by an older build) are removed by the next full snapshot", async () => {
    const port = await openDb();
    await upsertCacheRow(port, vendor.key, "product", { id: 99, payload: { id: 99 }, search: "ghost", keys: ["ghost-key"], rowVersion: 1 }, null, "2026-01-01T00:00:00.000Z");
    const fake = createFakeSyncApi(vendorData());
    const { engine, store } = engineFor(port, vendor, fake);
    await engine.syncNow();
    expect(await store.cache.get("product", 99)).toBeNull();
    expect(await store.cache.findByKey("product", "ghost-key")).toBeNull();
  });
});
