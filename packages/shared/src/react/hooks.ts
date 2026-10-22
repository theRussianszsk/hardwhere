import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import type { ApiClient } from "../api/client";
import { humanizeError } from "../api/errors";
import type { AuthState, CacheQuery, ConnectivitySnapshot, OutboxItem, OutboxState, SyncStatus } from "../contracts";
import { useServices } from "./AppProvider";
import type { ActiveScope } from "./runtime";

/** Subscribe to an external source and re-read it on every notification (no tearing concerns for these low-rate sources). */
function useExternal<T>(subscribe: (l: () => void) => () => void, read: () => T): T {
  const [value, setValue] = useState<T>(read);
  useEffect(() => {
    setValue(read());
    return subscribe(() => setValue(read()));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [subscribe]);
  return value;
}

export function useAuth() {
  const { session } = useServices();
  const state: AuthState = useExternal(session.subscribe, session.state);
  return { state, status: state.status, profile: state.profile, session, can: (p: string) => state.profile?.permissions.includes(p) ?? false };
}

export function useConnectivity(): ConnectivitySnapshot {
  const { connectivity } = useServices();
  return useExternal(connectivity.subscribe, connectivity.snapshot);
}

export function useActive(): ActiveScope | null {
  const s = useServices();
  return useExternal(s.subscribeActive, s.active);
}

export function useSyncStatus(): SyncStatus | null {
  const active = useActive();
  const [st, setSt] = useState<SyncStatus | null>(active?.sync.status() ?? null);
  useEffect(() => {
    setSt(active?.sync.status() ?? null);
    return active?.sync.subscribe(() => setSt(active.sync.status()));
  }, [active]);
  return st;
}

/** Re-render every `ms` so "3 min ago" labels and stale flags age while a screen stays open. */
export function useNow(ms = 30_000): number {
  const [now, setNow] = useState(Date.now());
  useEffect(() => { const t = setInterval(() => setNow(Date.now()), ms); return () => clearInterval(t); }, [ms]);
  return now;
}

export interface CacheState<T> { rows: T[]; loading: boolean; count: number }
/** Live, offline-capable list from the local cache. Re-queries when sync applies changes to this entity. */
export function useCacheList<T>(entity: string, query: CacheQuery = {}): CacheState<T> {
  const active = useActive();
  const [state, setState] = useState<CacheState<T>>({ rows: [], loading: true, count: 0 });
  const key = JSON.stringify(query);
  useEffect(() => {
    if (!active) { setState({ rows: [], loading: false, count: 0 }); return; }
    let alive = true;
    const load = async () => {
      const [rows, count] = await Promise.all([active.store.cache.list<T>(entity, query), active.store.cache.count(entity, { ...query, limit: undefined, offset: undefined })]);
      if (alive) setState({ rows, loading: false, count });
    };
    void load();
    const off = active.store.onChange((e) => { if (e.area === "cache" && (!e.entity || e.entity === entity)) void load(); });
    return () => { alive = false; off(); };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [active, entity, key]);
  return state;
}

export function useCacheItem<T>(entity: string, id: number | null | undefined): { item: T | null; loading: boolean } {
  const active = useActive();
  const [state, setState] = useState<{ item: T | null; loading: boolean }>({ item: null, loading: true });
  useEffect(() => {
    if (!active || id === null || id === undefined) { setState({ item: null, loading: false }); return; }
    let alive = true;
    const load = async () => { const item = await active.store.cache.get<T>(entity, id); if (alive) setState({ item, loading: false }); };
    void load();
    const off = active.store.onChange((e) => { if (e.area === "cache" && (!e.entity || e.entity === entity)) void load(); });
    return () => { alive = false; off(); };
  }, [active, entity, id]);
  return state;
}

export function useOutbox(filter?: { kinds?: string[]; states?: OutboxState[] }) {
  const active = useActive();
  const [items, setItems] = useState<OutboxItem[]>([]);
  const [unsent, setUnsent] = useState(0);
  const key = JSON.stringify(filter ?? {});
  useEffect(() => {
    if (!active) { setItems([]); setUnsent(0); return; }
    let alive = true;
    const load = async () => {
      const [list, n] = await Promise.all([active.store.outbox.list(filter), active.store.outbox.unsentCount()]);
      if (alive) { setItems(list); setUnsent(n); }
    };
    void load();
    const off = active.store.onChange((e) => { if (e.area === "outbox" || e.area === "photos") void load(); });
    return () => { alive = false; off(); };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [active, key]);
  return { items, unsent, outbox: active?.store.outbox ?? null };
}

export interface OnlineQuery<T> { data: T | null; error: unknown; message: string | null; loading: boolean; fetchedAt: number | null; refetch: () => Promise<void> }
/**
 * Server-authoritative read (live stock, ledger, order detail). Keeps showing the previous data while refetching and
 * reports `fetchedAt` so screens can label what they show as "as of ..." and never present it as live when it failed.
 */
export function useOnlineQuery<T>(fn: (api: ApiClient) => Promise<T>, deps: unknown[], opts: { enabled?: boolean } = {}): OnlineQuery<T> {
  const { api } = useServices();
  const [s, setS] = useState<{ data: T | null; error: unknown; loading: boolean; fetchedAt: number | null }>({ data: null, error: null, loading: opts.enabled !== false, fetchedAt: null });
  const run = useRef(fn);
  run.current = fn;
  const seq = useRef(0);
  const refetch = useCallback(async () => {
    const my = ++seq.current;
    setS((p) => ({ ...p, loading: true }));
    try {
      const data = await run.current(api);
      if (my === seq.current) setS({ data, error: null, loading: false, fetchedAt: Date.now() });
    } catch (error) {
      if (my === seq.current) setS((p) => ({ ...p, error, loading: false }));
    }
  }, [api]);
  useEffect(() => {
    if (opts.enabled === false) return;
    void refetch();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [refetch, opts.enabled, ...deps]);
  return useMemo(() => ({ ...s, message: s.error ? humanizeError(s.error) : null, refetch }), [s, refetch]);
}
