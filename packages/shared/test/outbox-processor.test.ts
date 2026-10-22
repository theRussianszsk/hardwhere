import { describe, expect, it, vi } from "vitest";
import { ApiError, type ApiErrorKind } from "../src/api/errors";
import { makeScope, type CommandHandler, type ScopeInfo } from "../src/contracts";
import { createLocalStore } from "../src/db/localStore";
import { migrate } from "../src/db/migrations";
import { computeBackoffMs, createOutboxProcessor, DEFAULT_BACKOFF } from "../src/sync/outbox";
import { createNodeSqlitePort, type NodeSqlitePort } from "./support/nodePort";
import { makeClock, openDb, recordBus, T0, useTmpDir } from "./support/helpers";

const tmp = useTmpDir();
const scope = makeScope(1, 5, "vendor");
const iso = (ms: number) => new Date(ms).toISOString();

const api = (kind: ApiErrorKind, o: { status?: number; code?: string; retryAfterSec?: number; details?: Record<string, unknown>; fields?: Record<string, string[]> } = {}) =>
  new ApiError({ kind, message: `${kind} error`, ...o });
const net = () => api("network");

async function setup(handlers: Record<string, CommandHandler>, o: { port?: NodeSqlitePort; scope?: ScopeInfo; random?: () => number; maxAttempts?: number; onPhotosReleased?: (r: string[]) => void } = {}) {
  const port = o.port ?? (await openDb());
  const sc = o.scope ?? scope;
  const clock = makeClock();
  const { bus, events } = recordBus();
  const store = createLocalStore(port, sc, bus, { now: clock.now });
  const proc = createOutboxProcessor({ port, scope: sc, handlers, bus, now: clock.now, random: o.random ?? (() => 0.5), maxAttempts: o.maxAttempts, onPhotosReleased: o.onPhotosReleased } as any);
  return { port, store, proc, clock, events };
}
const ok = (result: unknown = { ok: true }): CommandHandler => ({ autoRetry: true, run: vi.fn(async () => result) });

describe("(n) computeBackoffMs", () => {
  const d = (attempt: number) => Math.min(DEFAULT_BACKOFF.capMs, DEFAULT_BACKOFF.baseMs * 2 ** (attempt - 1));
  it("doubles from 2 s, is capped at 5 min BEFORE jitter, and jitter stays within ±20%", () => {
    expect(computeBackoffMs(1, DEFAULT_BACKOFF, () => 0.5)).toBe(2000);
    expect(computeBackoffMs(2, DEFAULT_BACKOFF, () => 0.5)).toBe(4000);
    expect(computeBackoffMs(3, DEFAULT_BACKOFF, () => 0.5)).toBe(8000);
    expect(computeBackoffMs(8, DEFAULT_BACKOFF, () => 0.5)).toBe(256_000);
    expect(computeBackoffMs(9, DEFAULT_BACKOFF, () => 0.5)).toBe(300_000); // capped
    expect(computeBackoffMs(60, DEFAULT_BACKOFF, () => 0.5)).toBe(300_000); // no overflow
    for (let attempt = 1; attempt <= 20; attempt++)
      for (const r of [0, 0.1, 0.25, 0.5, 0.75, 0.999999, 1]) {
        const v = computeBackoffMs(attempt, DEFAULT_BACKOFF, () => r);
        expect(v).toBeGreaterThanOrEqual(Math.round(d(attempt) * 0.8));
        expect(v).toBeLessThanOrEqual(Math.round(d(attempt) * 1.2));
      }
    expect(computeBackoffMs(1, DEFAULT_BACKOFF, () => 0)).toBe(1600);
    expect(computeBackoffMs(1, DEFAULT_BACKOFF, () => 1)).toBe(2400);
    expect(computeBackoffMs(9, DEFAULT_BACKOFF, () => 0)).toBe(240_000); // jitter applies to the capped value
  });
  it("monotonic in attempt for a fixed random, attempt <= 0 behaves like the first retry, custom options honoured", () => {
    let prev = 0;
    for (let a = 1; a <= 12; a++) { const v = computeBackoffMs(a, DEFAULT_BACKOFF, () => 0.3); expect(v).toBeGreaterThanOrEqual(prev); prev = v; }
    expect(computeBackoffMs(0, DEFAULT_BACKOFF, () => 0.5)).toBe(2000);
    expect(computeBackoffMs(3, { baseMs: 100, capMs: 250, jitter: 0 }, () => 0.9)).toBe(250);
  });
});

describe("outbox processor basics", () => {
  it("sends due commands oldest first, persists 'sending' BEFORE the handler runs, then confirms with the response", async () => {
    const seen: string[] = [];
    let duringSend: string | undefined;
    const { store, proc, clock, port } = await setup({
      "stock.adjust": { autoRetry: true, run: async (p: any, ctx) => { seen.push(`${p.n}:${ctx.idempotencyKey}:${ctx.attempt}`); duringSend = (await port.all<{ state: string }>(`SELECT state FROM outbox WHERE id = ?`, [ctx.idempotencyKey]))[0]!.state; return { data: { id: p.n } }; } },
    });
    await store.outbox.enqueue("stock.adjust", { n: 1 }, { id: "a" });
    clock.advance(1000);
    await store.outbox.enqueue("stock.adjust", { n: 2 }, { id: "b" });
    const r = await proc.processDue();
    expect(r).toEqual({ processed: 2, confirmed: 2, authRequired: false, nextWakeAt: null });
    expect(seen).toEqual(["1:a:1", "2:b:1"]);
    expect(duringSend).toBe("sending");
    expect(await store.outbox.get("a")).toMatchObject({ state: "confirmed", response: { data: { id: 1 } }, lastError: null, nextAttemptAt: null });
    expect((await proc.processDue()).processed).toBe(0); // confirmed rows are never re-sent
  });

  it("only touches its own scope and only 'queued' rows (draft / needs_review / rejected / confirmed stay put)", async () => {
    const h = ok();
    const { port, store, proc } = await setup({ k: h });
    const other = createLocalStore(port, makeScope(2, 5, "vendor"), recordBus().bus);
    await other.outbox.enqueue("k", {}, { id: "other-scope" });
    await store.outbox.enqueue("k", {}, { id: "draft", state: "draft" });
    for (const s of ["needs_review", "rejected", "confirmed"]) await port.run(`INSERT INTO outbox (id, scope_key, kind, payload, state, created_at, updated_at) VALUES (?, ?, 'k', '{}', ?, 't', 't')`, [`s-${s}`, scope.key, s]);
    expect(await proc.processDue()).toMatchObject({ processed: 0 });
    expect(h.run).not.toHaveBeenCalled();
    expect((await other.outbox.get("other-scope"))?.state).toBe("queued");
  });

  it("backed-off commands wait for next_attempt_at; nextWakeAt reports the earliest", async () => {
    const h: CommandHandler = { autoRetry: true, run: vi.fn(async () => { throw net(); }) };
    const { store, proc, clock } = await setup({ k: h });
    await store.outbox.enqueue("k", {}, { id: "a" });
    const r1 = await proc.processDue();
    expect(r1).toMatchObject({ processed: 1, confirmed: 0, nextWakeAt: T0 + 2000 });
    expect(await proc.processDue()).toMatchObject({ processed: 0, nextWakeAt: T0 + 2000 }); // not due yet
    expect(h.run).toHaveBeenCalledTimes(1);
    clock.advance(1999);
    expect((await proc.processDue()).processed).toBe(0);
    clock.advance(1);
    expect((await proc.processDue()).processed).toBe(1);
  });

  it("a command with no registered handler is rejected (visible to the user) instead of silently looping", async () => {
    const { store, proc } = await setup({});
    await store.outbox.enqueue("future.kind", {}, { id: "x" });
    expect(await proc.processDue()).toMatchObject({ processed: 0 });
    expect(await store.outbox.get("x")).toMatchObject({ state: "rejected", lastError: { code: "no_handler" } });
  });

  it("overlapping processDue() calls never send the same command twice", async () => {
    let release!: () => void;
    const gate = new Promise<void>((r) => { release = r; });
    const h: CommandHandler = { autoRetry: true, run: vi.fn(async () => { await gate; return {}; }) };
    const { store, proc } = await setup({ k: h });
    await store.outbox.enqueue("k", {}, { id: "a" });
    const p1 = proc.processDue();
    const p2 = proc.processDue();
    await new Promise((r) => setTimeout(r, 10));
    release();
    const [r1, r2] = await Promise.all([p1, p2]);
    expect(h.run).toHaveBeenCalledTimes(1);
    expect(r1.processed + r2.processed).toBe(1);
  });

  it("a command is never confirmed unless the handler resolved (non-resolving handler leaves it 'sending')", async () => {
    const { store, proc } = await setup({ k: { autoRetry: true, run: () => new Promise(() => {}) } });
    await store.outbox.enqueue("k", {}, { id: "a" });
    void proc.processDue();
    await new Promise((r) => setTimeout(r, 20));
    expect((await store.outbox.get("a"))?.state).toBe("sending");
  });
});

describe("(f) outbox durability after forced termination", () => {
  it("queued and sending rows survive an un-gracefully closed file DB; recoverInterrupted requeues sending rows (autoRetry:false -> needs_review)", async () => {
    const file = tmp("outbox-kill.sqlite");
    const handlers: Record<string, CommandHandler> = {
      "safe.kind": { autoRetry: true, run: () => new Promise(() => {}) }, // request in flight when the app dies
      "pos.checkout": { autoRetry: false, run: () => new Promise(() => {}) },
    };
    {
      const a = await setup(handlers, { port: await openDb(file) });
      await a.store.outbox.enqueue("safe.kind", { n: 1 }, { id: "inflight-safe" });
      await a.store.outbox.enqueue("pos.checkout", { total: 500 }, { id: "inflight-sale" });
      await a.store.outbox.enqueue("safe.kind", { n: 2 }, { id: "waiting" });
      await a.store.outbox.enqueue("safe.kind", { n: 3 }, { id: "unsent-draft", state: "draft" });
      await a.store.drafts.put("sale", "cart-1", { lines: [1, 2] });
      // The processor sends one command at a time, so only one row is really in flight when the plug is pulled; a second row
      // is left in 'sending' directly (as an earlier interrupted pass would have left it).
      await a.port.run(`UPDATE outbox SET state = 'sending' WHERE id = 'inflight-sale'`);
      void a.proc.processDue();
      for (let i = 0; i < 50 && (await a.store.outbox.get("inflight-safe"))?.state !== "sending"; i++) await new Promise((r) => setTimeout(r, 5));
      expect((await a.store.outbox.get("inflight-safe"))?.state).toBe("sending");
      // an uncommitted write at the moment of death must vanish
      void a.port.transaction(async (tx) => { await tx.run(`UPDATE outbox SET label = 'uncommitted' WHERE id = 'waiting'`); await new Promise(() => {}); }).catch(() => {});
      await new Promise((r) => setTimeout(r, 10));
      a.port.kill();
    }

    const port = createNodeSqlitePort(file);
    expect(await migrate(port)).toEqual({ from: 2, to: 2 });
    const b = await setup(handlers, { port });
    const byId = async () => Object.fromEntries((await b.store.outbox.list()).map((i) => [i.id, i]));
    let items = await byId();
    expect(items["inflight-safe"]).toMatchObject({ state: "sending", payload: { n: 1 } });
    expect(items["waiting"]).toMatchObject({ state: "queued", label: "" }); // 'uncommitted' label rolled back
    expect(items["unsent-draft"]!.state).toBe("draft");
    expect(await b.store.drafts.get("sale", "cart-1")).toEqual({ lines: [1, 2] });
    expect(await b.store.outbox.unsentCount()).toBe(4);

    expect(await b.proc.recoverInterrupted()).toBe(2);
    items = await byId();
    expect(items["inflight-safe"]).toMatchObject({ state: "queued", nextAttemptAt: null, attempts: 0 }); // same id => same idempotency key on resend
    expect(items["inflight-sale"]).toMatchObject({ state: "needs_review", lastError: { code: "interrupted" } }); // a human decides about the sale
    expect(await b.proc.recoverInterrupted()).toBe(0);
  });

  it("after recovery the resend carries the SAME id as idempotency key", async () => {
    const keys: string[] = [];
    const h: CommandHandler = { autoRetry: true, run: async (_p, ctx) => { keys.push(ctx.idempotencyKey); return {}; } };
    const { port, store, proc } = await setup({ k: h });
    await store.outbox.enqueue("k", {}, { id: "cmd-42" });
    await port.run(`UPDATE outbox SET state = 'sending' WHERE id = 'cmd-42'`); // as left by a crash
    await proc.recoverInterrupted();
    await proc.processDue();
    expect(keys).toEqual(["cmd-42"]);
    expect((await store.outbox.get("cmd-42"))?.state).toBe("confirmed");
  });
});

describe("(g) duplicate retry idempotency", () => {
  it("lost response: the handler gets the SAME idempotency key on every attempt and the server effect happens once", async () => {
    const serverEffects = new Map<string, { sale: number }>(); // what the Laravel idempotency middleware remembers
    const keysSeen: string[] = [];
    let netFailuresLeft = 1;
    const handler: CommandHandler = {
      autoRetry: true,
      async run(payload: { amount: number }, ctx) {
        keysSeen.push(ctx.idempotencyKey);
        const replayed = serverEffects.has(ctx.idempotencyKey);
        if (!replayed) serverEffects.set(ctx.idempotencyKey, { sale: payload.amount }); // the server DID process it...
        if (netFailuresLeft-- > 0) throw net(); // ...but the response never made it back
        return { data: serverEffects.get(ctx.idempotencyKey), replayed };
      },
    };
    const { store, proc, clock } = await setup({ "stock.adjust": handler });
    const item = await store.outbox.enqueue("stock.adjust", { amount: 250 });

    expect(await proc.processDue()).toMatchObject({ processed: 1, confirmed: 0 });
    expect(await store.outbox.get(item.id)).toMatchObject({ state: "queued", attempts: 1, lastError: { code: "network" } });
    clock.advance(10_000);
    expect(await proc.processDue()).toMatchObject({ processed: 1, confirmed: 1 });

    expect(keysSeen).toEqual([item.id, item.id]);
    expect(serverEffects.size).toBe(1); // counted once
    expect(await store.outbox.get(item.id)).toMatchObject({ state: "confirmed", attempts: 2, response: { replayed: true, data: { sale: 250 } } });
  });

  it("autoRetry:false (POS sale) with a lost response goes to needs_review instead of being re-sent in the background", async () => {
    const h: CommandHandler = { autoRetry: false, run: vi.fn(async () => { throw api("timeout"); }) };
    const { store, proc, clock } = await setup({ "pos.checkout": h });
    await store.outbox.enqueue("pos.checkout", { total: 1 }, { id: "sale-1" });
    await proc.processDue();
    expect(await store.outbox.get("sale-1")).toMatchObject({ state: "needs_review", lastError: { code: "timeout" }, nextAttemptAt: null });
    clock.advance(3_600_000);
    await proc.processDue();
    expect(h.run).toHaveBeenCalledTimes(1);
    // a human re-sends it: same id, so the server replays instead of double-charging
    await store.outbox.retryNow("sale-1");
    await proc.processDue();
    expect(h.run).toHaveBeenCalledTimes(2);
    expect((h.run as any).mock.calls.map((c: any[]) => c[1].idempotencyKey)).toEqual(["sale-1", "sale-1"]);
  });
});

describe("(h) expired credentials", () => {
  it("401 -> authRequired, stops the pass, command stays queued, attempts and schedule unchanged; resumes after re-login", async () => {
    let signedIn = false;
    const run = vi.fn(async (p: { n: number }) => { if (!signedIn) throw api("unauthenticated", { status: 401 }); return { n: p.n }; });
    const { store, proc } = await setup({ k: { autoRetry: true, run } });
    await store.outbox.enqueue("k", { n: 1 }, { id: "a" });
    await store.outbox.enqueue("k", { n: 2 }, { id: "b" });

    const r = await proc.processDue();
    expect(r).toEqual({ processed: 1, confirmed: 0, authRequired: true, nextWakeAt: null });
    expect(run).toHaveBeenCalledTimes(1); // "b" was not even tried
    expect(await store.outbox.get("a")).toMatchObject({ state: "queued", attempts: 0, nextAttemptAt: null });
    expect(await store.outbox.get("b")).toMatchObject({ state: "queued", attempts: 0 });
    for (let i = 0; i < 3; i++) await proc.processDue();
    expect((await store.outbox.get("a"))?.attempts).toBe(0); // repeated 401s never consume attempts

    signedIn = true;
    expect(await proc.processDue()).toMatchObject({ processed: 2, confirmed: 2, authRequired: false });
    expect((await store.outbox.list()).map((i) => i.state)).toEqual(["confirmed", "confirmed"]);
  });
});

describe("(j) image upload retry", () => {
  it("an ai.analyze command with a queued photo retries with growing, jittered backoff, then confirms and its photo_queue row is removed", async () => {
    const released: string[][] = [];
    const randoms = [0, 1, 0.5, 0.25];
    let n = 0;
    let failures = 3;
    const handler: CommandHandler = {
      autoRetry: true,
      run: vi.fn(async (p: { photoId: string }) => { if (failures-- > 0) throw net(); return { data: { id: 77, photo: p.photoId } }; }),
    };
    const { store, proc, clock } = await setup({ "ai.analyze": handler }, { random: () => randoms[n++ % randoms.length]!, onPhotosReleased: (r) => released.push(r) });
    const item = await store.outbox.enqueue("ai.analyze", { photoId: "ph1" }, { label: "Analyze photo" });
    await store.photos.add({ id: "ph1", fileRef: "photos/ph1.jpg", mime: "image/jpeg", size: 2048, purpose: "ai.analyze", hint: "drill", outboxId: item.id });

    const delays: number[] = [];
    for (let attempt = 1; attempt <= 3; attempt++) {
      const before = clock.now();
      await proc.processDue();
      const row = (await store.outbox.get(item.id))!;
      expect(row).toMatchObject({ state: "queued", attempts: attempt, lastError: { code: "network" } });
      const delay = Date.parse(row.nextAttemptAt!) - before;
      delays.push(delay);
      const base = 2000 * 2 ** (attempt - 1);
      expect(delay).toBeGreaterThanOrEqual(base * 0.8);
      expect(delay).toBeLessThanOrEqual(base * 1.2);
      expect(await store.photos.list()).toHaveLength(1); // the photo must survive failed uploads
      clock.set(Date.parse(row.nextAttemptAt!));
    }
    expect(delays).toEqual([1600, 4800, 8000]); // random 0 -> -20%, 1 -> +20%, 0.5 -> exact
    expect(delays[1]).toBeGreaterThan(delays[0]!);
    expect(delays[2]).toBeGreaterThan(delays[1]!);

    expect(await proc.processDue()).toMatchObject({ processed: 1, confirmed: 1 });
    expect(await store.outbox.get(item.id)).toMatchObject({ state: "confirmed", attempts: 4, response: { data: { id: 77 } } });
    expect(await store.photos.list()).toEqual([]); // queue row removed
    expect(released).toEqual([["photos/ph1.jpg"]]); // and the app is told which file to delete
    expect((handler.run as any).mock.calls.map((c: any[]) => c[1].attempt)).toEqual([1, 2, 3, 4]);
  });

  it("photo rows of OTHER commands are left alone, and a throwing onPhotosReleased cannot undo a confirmation", async () => {
    const { store, proc } = await setup({ k: ok() }, { onPhotosReleased: () => { throw new Error("disk error"); } });
    const a = await store.outbox.enqueue("k", {}, { id: "a" });
    await store.photos.add({ id: "p-a", fileRef: "fa", mime: "image/jpeg", size: 1, purpose: "x", hint: null, outboxId: a.id });
    await store.photos.add({ id: "p-other", fileRef: "fo", mime: "image/jpeg", size: 1, purpose: "x", hint: null, outboxId: "not-sent-yet" });
    await store.photos.add({ id: "p-loose", fileRef: "fl", mime: "image/jpeg", size: 1, purpose: "x", hint: null, outboxId: null });
    await proc.processDue();
    expect((await store.outbox.get("a"))?.state).toBe("confirmed");
    expect((await store.photos.list()).map((p) => p.id)).toEqual(["p-other", "p-loose"]);
  });
});

describe("(k) server conflict (409)", () => {
  it("-> needs_review with details, never auto-retried; the cache is untouched; retryNow re-queues with attempts reset", async () => {
    let conflict = true;
    const run = vi.fn(async () => {
      if (conflict) throw api("conflict", { status: 409, code: "stock_unavailable", details: { available: 1, requested: 3 } });
      return { data: "ok" };
    });
    const { port, store, proc, clock } = await setup({ "stock.adjust": { autoRetry: true, run } });
    await port.run(`INSERT INTO cache_entities (scope_key, entity, id, payload, search, synced_at) VALUES (?, 'listing', 1, '{"available_quantity":5}', 'drill', 't')`, [scope.key]);
    const cacheBefore = await port.all(`SELECT * FROM cache_entities`);

    await store.outbox.enqueue("stock.adjust", { qty: 3 }, { id: "a" });
    await proc.processDue();
    expect(await store.outbox.get("a")).toMatchObject({
      state: "needs_review", attempts: 1, nextAttemptAt: null,
      lastError: { code: "stock_unavailable", message: "conflict error", details: { available: 1, requested: 3 } },
    });
    clock.advance(86_400_000);
    expect((await proc.processDue()).processed).toBe(0); // needs a human
    expect(run).toHaveBeenCalledTimes(1);
    expect(await port.all(`SELECT * FROM cache_entities`)).toEqual(cacheBefore); // server is authoritative: nothing "fixed up" locally
    expect(await store.cache.get("listing", 1)).toEqual({ available_quantity: 5 });

    conflict = false;
    await store.outbox.retryNow("a");
    expect(await store.outbox.get("a")).toMatchObject({ state: "queued", attempts: 0, lastError: null });
    expect(await proc.processDue()).toMatchObject({ processed: 1, confirmed: 1 });
    expect(await store.outbox.get("a")).toMatchObject({ state: "confirmed", response: { data: "ok" } });
  });
});

describe("(m) 429, max attempts, 422 and other terminal errors", () => {
  it("429 honours Retry-After as a minimum, but never goes below the normal backoff", async () => {
    const waits: (number | undefined)[] = [120, 1];
    const run = vi.fn(async () => { throw api("rate_limited", { status: 429, retryAfterSec: waits.shift() }); });
    const { store, proc, clock } = await setup({ k: { autoRetry: true, run } });
    await store.outbox.enqueue("k", {}, { id: "a" });
    await proc.processDue();
    let row = (await store.outbox.get("a"))!;
    expect(row).toMatchObject({ state: "queued", attempts: 1, lastError: { code: "rate_limited" } });
    expect(Date.parse(row.nextAttemptAt!) - clock.now()).toBe(120_000); // Retry-After 120 s beats the 2 s backoff
    clock.advance(119_999);
    expect((await proc.processDue()).processed).toBe(0);
    clock.advance(1);
    await proc.processDue(); // Retry-After: 1 s -> the 4 s backoff of attempt 2 wins
    row = (await store.outbox.get("a"))!;
    expect(Date.parse(row.nextAttemptAt!) - clock.now()).toBe(4000);
    expect(row.attempts).toBe(2);
  });

  it("gives up after maxAttempts: needs_review, and the handler was called exactly maxAttempts times", async () => {
    const run = vi.fn(async () => { throw api("server", { status: 503 }); });
    const { store, proc, clock } = await setup({ k: { autoRetry: true, run } }, { maxAttempts: 3 });
    await store.outbox.enqueue("k", {}, { id: "a" });
    for (let i = 0; i < 6; i++) { await proc.processDue(); clock.advance(10 * 60_000); }
    expect(run).toHaveBeenCalledTimes(3);
    expect(await store.outbox.get("a")).toMatchObject({ state: "needs_review", attempts: 3, nextAttemptAt: null, lastError: { code: "server" } });
  });

  it("default maxAttempts is 8", async () => {
    const run = vi.fn(async () => { throw net(); });
    const { store, proc, clock } = await setup({ k: { autoRetry: true, run } });
    await store.outbox.enqueue("k", {}, { id: "a" });
    for (let i = 0; i < 20; i++) { await proc.processDue(); clock.advance(10 * 60_000); }
    expect(run).toHaveBeenCalledTimes(8);
    expect((await store.outbox.get("a"))?.state).toBe("needs_review");
  });

  it("422 -> rejected with the field errors, no retry; 403/404/other 4xx -> rejected too", async () => {
    const kinds: [ApiErrorKind, number, string][] = [["validation", 422, "validation_failed"], ["forbidden", 403, "forbidden"], ["not_found", 404, "not_found"], ["client", 400, "bad_request"]];
    for (const [kind, status, code] of kinds) {
      const run = vi.fn(async () => { throw api(kind, { status, code, fields: kind === "validation" ? { "payment.reference": ["Required."] } : {} }); });
      const { store, proc, clock } = await setup({ k: { autoRetry: true, run } });
      await store.outbox.enqueue("k", {}, { id: "a" });
      await proc.processDue();
      clock.advance(86_400_000);
      await proc.processDue();
      expect(run).toHaveBeenCalledTimes(1);
      const item = (await store.outbox.get("a"))!;
      expect(item).toMatchObject({ state: "rejected", nextAttemptAt: null, lastError: { code } });
      if (kind === "validation") expect(item.lastError!.details).toEqual({ fields: { "payment.reference": ["Required."] } });
    }
  });

  it("a non-ApiError exception (a bug / bad payload) -> rejected, not retried", async () => {
    const run = vi.fn(async () => { throw new TypeError("cannot read x"); });
    const { store, proc } = await setup({ k: { autoRetry: true, run } });
    await store.outbox.enqueue("k", {}, { id: "a" });
    await proc.processDue();
    await proc.processDue();
    expect(run).toHaveBeenCalledTimes(1);
    expect(await store.outbox.get("a")).toMatchObject({ state: "rejected", lastError: { code: "client_error", message: "cannot read x" } });
  });

  it("retryNow on a rejected command gives it a fresh start", async () => {
    let fail = true;
    const run = vi.fn(async () => { if (fail) throw api("validation", { status: 422 }); return {}; });
    const { store, proc } = await setup({ k: { autoRetry: true, run } });
    await store.outbox.enqueue("k", {}, { id: "a" });
    await proc.processDue();
    expect((await store.outbox.get("a"))?.state).toBe("rejected");
    fail = false;
    await store.outbox.retryNow("a");
    await proc.processDue();
    expect((await store.outbox.get("a"))?.state).toBe("confirmed");
  });
});

describe("bus events", () => {
  it("emits outbox events as state changes", async () => {
    const { store, proc, events } = await setup({ k: ok() });
    await store.outbox.enqueue("k", {});
    events.length = 0;
    await proc.processDue();
    expect(events.filter((e) => e.area === "outbox").length).toBeGreaterThanOrEqual(2); // sending, confirmed
    expect(iso(T0)).toMatch(/Z$/);
  });
});
