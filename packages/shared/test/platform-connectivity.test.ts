import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createConnectivity, type ConnectivityEnv } from "../src/platform/connectivity";

function fakeEnv(initialOnline = true) {
  let onlineCb: ((o: boolean) => void) | null = null;
  let activeCb: ((a: boolean) => void) | null = null;
  const env: ConnectivityEnv = {
    deviceOnline: async () => initialOnline,
    watchDeviceOnline: (cb) => { onlineCb = cb; return () => { onlineCb = null; }; },
    watchActive: (cb) => { activeCb = cb; return () => { activeCb = null; }; },
  };
  return { env, setOnline: (o: boolean) => onlineCb?.(o), setActive: (a: boolean) => activeCb?.(a), listening: () => !!onlineCb || !!activeCb };
}

beforeEach(() => { vi.useFakeTimers(); });
afterEach(() => { vi.useRealTimers(); });

describe("connectivity", () => {
  it("device online is NOT treated as server reachable", async () => {
    const f = fakeEnv(true);
    const probe = vi.fn(async () => true);
    const c = createConnectivity({ probe, env: f.env });
    await vi.advanceTimersByTimeAsync(10);
    expect(c.snapshot()).toMatchObject({ deviceOnline: true, serverReachable: null, lastProbeAt: null });
    expect(probe).not.toHaveBeenCalled();
    c.dispose();
  });

  it("probe() records reachability and the time; a failing or throwing probe means unreachable", async () => {
    let t = 1_000;
    const f = fakeEnv();
    const results: (boolean | Error)[] = [true, false, new Error("boom")];
    const c = createConnectivity({ probe: async () => { const r = results.shift()!; if (r instanceof Error) throw r; return r; }, env: f.env, now: () => t });
    expect(await c.probe()).toBe(true);
    expect(c.snapshot()).toMatchObject({ serverReachable: true, lastProbeAt: 1_000 });
    t = 2_000;
    expect(await c.probe()).toBe(false);
    expect(c.snapshot()).toMatchObject({ serverReachable: false, lastProbeAt: 2_000 });
    t = 3_000;
    expect(await c.probe()).toBe(false);
    expect(c.snapshot().lastProbeAt).toBe(3_000);
    c.dispose();
  });

  it("concurrent probes share one request", async () => {
    const f = fakeEnv();
    const probe = vi.fn(async () => true);
    const c = createConnectivity({ probe, env: f.env });
    await Promise.all([c.probe(), c.probe(), c.probe()]);
    expect(probe).toHaveBeenCalledTimes(1);
    c.dispose();
  });

  it("a hanging probe is cut off and counted as unreachable", async () => {
    const f = fakeEnv();
    const c = createConnectivity({ probe: () => new Promise(() => undefined), env: f.env, probeTimeoutMs: 4000 });
    const p = c.probe();
    await vi.advanceTimersByTimeAsync(4001);
    expect(await p).toBe(false);
    expect(c.snapshot().serverReachable).toBe(false);
    c.dispose();
  });

  it("a successful probe corrects a stale 'offline' device flag", async () => {
    const f = fakeEnv(true);
    const c = createConnectivity({ probe: async () => true, env: f.env });
    await vi.advanceTimersByTimeAsync(5);
    f.setOnline(false);
    expect(c.snapshot().deviceOnline).toBe(false);
    await c.probe();
    expect(c.snapshot()).toMatchObject({ deviceOnline: true, serverReachable: true });
    c.dispose();
  });

  it("reconnect: fires once after the 1 s debounce and re-probes; going offline fires nothing", async () => {
    const f = fakeEnv(true);
    const probe = vi.fn(async () => true);
    const c = createConnectivity({ probe, env: f.env });
    const fn = vi.fn();
    c.onResumeOrReconnect(fn);
    await vi.advanceTimersByTimeAsync(5);
    f.setOnline(false);
    await vi.advanceTimersByTimeAsync(5_000);
    expect(fn).not.toHaveBeenCalled();
    f.setOnline(true);
    f.setOnline(false);
    f.setOnline(true); // flapping inside the window
    await vi.advanceTimersByTimeAsync(999);
    expect(fn).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(2);
    expect(fn).toHaveBeenCalledTimes(1);
    expect(probe).toHaveBeenCalledTimes(1);
    c.dispose();
  });

  it("resume: foregrounding fires (debounced, merged with a simultaneous reconnect); backgrounding does not", async () => {
    const f = fakeEnv(true);
    const probe = vi.fn(async () => true);
    const c = createConnectivity({ probe, env: f.env });
    const fn = vi.fn();
    c.onResumeOrReconnect(fn);
    await vi.advanceTimersByTimeAsync(5);
    f.setActive(false);
    await vi.advanceTimersByTimeAsync(5_000);
    expect(fn).not.toHaveBeenCalled();
    f.setOnline(false);
    f.setOnline(true);
    f.setActive(true);
    await vi.advanceTimersByTimeAsync(1_001);
    expect(fn).toHaveBeenCalledTimes(1);
    expect(probe).toHaveBeenCalledTimes(1);
    c.dispose();
  });

  it("unsubscribe stops callbacks; subscribers see snapshot changes; unchanged state keeps the same snapshot object", async () => {
    const f = fakeEnv();
    const c = createConnectivity({ probe: async () => true, env: f.env });
    const fn = vi.fn();
    const off = c.onResumeOrReconnect(fn);
    off();
    const sub = vi.fn();
    c.subscribe(sub);
    const before = c.snapshot();
    f.setOnline(true); // no change
    expect(c.snapshot()).toBe(before);
    expect(sub).not.toHaveBeenCalled();
    f.setActive(true);
    await vi.advanceTimersByTimeAsync(1_001);
    expect(fn).not.toHaveBeenCalled();
    expect(sub).toHaveBeenCalled(); // the probe result arrived
    c.dispose();
  });

  it("interval probing only while active and online", async () => {
    const f = fakeEnv(true);
    const probe = vi.fn(async () => true);
    const c = createConnectivity({ probe, env: f.env, probeIntervalMs: 30_000 });
    await vi.advanceTimersByTimeAsync(30_001);
    expect(probe).toHaveBeenCalledTimes(1);
    f.setActive(false);
    await vi.advanceTimersByTimeAsync(60_000);
    expect(probe).toHaveBeenCalledTimes(1);
    f.setOnline(false);
    f.setActive(true);
    await vi.advanceTimersByTimeAsync(60_000);
    expect(probe).toHaveBeenCalledTimes(1);
    c.dispose();
  });

  it("dispose removes every listener and silences timers", async () => {
    const f = fakeEnv(false);
    const probe = vi.fn(async () => true);
    const c = createConnectivity({ probe, env: f.env, probeIntervalMs: 1_000 });
    const fn = vi.fn();
    c.onResumeOrReconnect(fn);
    await vi.advanceTimersByTimeAsync(5);
    f.setOnline(true); // schedules a debounced fire...
    c.dispose(); // ...which must never run
    expect(f.listening()).toBe(false);
    await vi.advanceTimersByTimeAsync(10_000);
    expect(fn).not.toHaveBeenCalled();
    expect(probe).not.toHaveBeenCalled();
  });
});
