/**
 * Connectivity = two separate facts, never conflated:
 *   deviceOnline    what the OS/Network plugin says (radio up). A captive portal or dead mobile data still reports `true`.
 *   serverReachable result of a real probe of the API health endpoint (the lead passes `() => api.health()`).
 * The probe re-runs on reconnect and when the app returns to the foreground, and optionally on an interval while active + online.
 * Plugin access sits behind `ConnectivityEnv` so the state machine is unit-testable in Node.
 */
import { Network } from "@capacitor/network";
import type { Connectivity, ConnectivitySnapshot } from "../contracts";
import { capListener, isNative, watchAppActive } from "./lifecycle";

export interface ConnectivityEnv {
  deviceOnline(): Promise<boolean>;
  watchDeviceOnline(cb: (online: boolean) => void): () => void;
  watchActive(cb: (active: boolean) => void): () => void;
}

/** `navigator.onLine` is undefined in some runtimes (Node, odd WebViews): only an explicit `false` means offline. */
const browserOnline = (): boolean => typeof navigator === "undefined" || navigator.onLine !== false;

export function defaultConnectivityEnv(): ConnectivityEnv {
  if (isNative()) {
    return {
      deviceOnline: async () => (await Network.getStatus()).connected,
      watchDeviceOnline: (cb) => capListener(Network.addListener("networkStatusChange", (s) => cb(s.connected))),
      watchActive: watchAppActive,
    };
  }
  return {
    deviceOnline: async () => browserOnline(),
    watchDeviceOnline(cb) {
      if (typeof window === "undefined") return () => undefined;
      const on = () => cb(true), off = () => cb(false);
      window.addEventListener("online", on);
      window.addEventListener("offline", off);
      return () => { window.removeEventListener("online", on); window.removeEventListener("offline", off); };
    },
    watchActive: watchAppActive,
  };
}

export interface ConnectivityOptions {
  /** Resolves true when the API answered. Never needs to throw: a throw counts as "unreachable". */
  probe: () => Promise<boolean>;
  /** If set, probe on this interval while the app is active and the device reports online. */
  probeIntervalMs?: number;
  /** Hard cap on one probe (the contract says 4 s; the API client's own health call already aborts at 4 s). Default 5000. */
  probeTimeoutMs?: number;
  /** Coalescing window for resume/reconnect bursts. Default 1000. */
  debounceMs?: number;
  now?: () => number;
  env?: ConnectivityEnv;
}

export function createConnectivity(o: ConnectivityOptions): Connectivity {
  const env = o.env ?? defaultConnectivityEnv();
  const now = o.now ?? Date.now;
  const debounceMs = o.debounceMs ?? 1000;
  const probeTimeoutMs = o.probeTimeoutMs ?? 5000;

  let snap: ConnectivitySnapshot = { deviceOnline: browserOnline(), serverReachable: null, lastProbeAt: null };
  let active = true;
  let disposed = false;
  const subs = new Set<() => void>();
  const resumeFns = new Set<() => void>();
  const guard = (fn: () => void) => { try { fn(); } catch (e) { console.error("connectivity listener failed", e); } };

  // The snapshot object only changes identity when a value changes (React useSyncExternalStore needs that).
  const set = (patch: Partial<ConnectivitySnapshot>) => {
    const next = { ...snap, ...patch };
    if (next.deviceOnline === snap.deviceOnline && next.serverReachable === snap.serverReachable && next.lastProbeAt === snap.lastProbeAt) return;
    snap = next;
    for (const l of [...subs]) guard(l);
  };

  let inflight: Promise<boolean> | null = null;
  const probe = (): Promise<boolean> => {
    if (inflight) return inflight;
    inflight = (async () => {
      let ok = false;
      let timer: ReturnType<typeof setTimeout> | undefined;
      try {
        ok = !!(await Promise.race([o.probe(), new Promise<boolean>((r) => { timer = setTimeout(() => r(false), probeTimeoutMs); })]));
      } catch { ok = false; } finally { clearTimeout(timer); }
      if (!disposed) set(ok ? { deviceOnline: true, serverReachable: true, lastProbeAt: now() } : { serverReachable: false, lastProbeAt: now() }); // a real answer proves the radio is up
      return ok;
    })().finally(() => { inflight = null; });
    return inflight;
  };

  let debounce: ReturnType<typeof setTimeout> | undefined;
  const resumeOrReconnect = () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
      if (disposed || !snap.deviceOnline) return; // radio down: nothing to retry now, the reconnect will fire again
      void probe();
      for (const f of [...resumeFns]) guard(f);
    }, debounceMs);
  };

  const applyOnline = (online: boolean) => {
    const was = snap.deviceOnline;
    set({ deviceOnline: online });
    if (online && !was) resumeOrReconnect();
  };

  const stops = [
    env.watchDeviceOnline(applyOnline),
    env.watchActive((a) => { active = a; if (a) resumeOrReconnect(); }),
  ];
  env.deviceOnline().then((v) => { if (!disposed) applyOnline(v); }, () => undefined);

  const interval = o.probeIntervalMs ? setInterval(() => { if (active && snap.deviceOnline) void probe(); }, o.probeIntervalMs) : undefined;

  return {
    snapshot: () => snap,
    subscribe(l) { subs.add(l); return () => { subs.delete(l); }; },
    probe,
    onResumeOrReconnect(fn) { resumeFns.add(fn); return () => { resumeFns.delete(fn); }; },
    dispose() {
      disposed = true;
      stops.forEach((s) => s());
      clearTimeout(debounce);
      clearInterval(interval);
      subs.clear();
      resumeFns.clear();
    },
  };
}
