import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useActive, useCacheList, useConnectivity, useServices, type InventoryItemDto, type OutboxItem, type StoreDto } from "@hardwhere/shared";

/** True when the device has a network AND the last probe did not say the API is down. Mutating online-only actions check this. */
export function useOnline() {
  const c = useConnectivity();
  return { online: c.deviceOnline && c.serverReachable !== false, snapshot: c };
}

/** Live view of one outbox command (state, response, lastError). Updates on every outbox change. */
export function useOutboxItem<R = unknown, P = unknown>(id: string | null | undefined): { item: OutboxItem<P, R> | null; loading: boolean } {
  const active = useActive();
  const [s, setS] = useState<{ item: OutboxItem<P, R> | null; loading: boolean }>({ item: null, loading: true });
  useEffect(() => {
    if (!active || !id) { setS({ item: null, loading: false }); return; }
    // A new id must not briefly report the previous id's row (or "not found") as its own.
    setS((p) => (p.item?.id === id ? p : { item: null, loading: true }));
    let alive = true;
    const load = async () => { const item = await active.store.outbox.get<P, R>(id); if (alive) setS({ item, loading: false }); };
    void load();
    const off = active.store.onChange((e) => { if (e.area === "outbox") void load(); });
    return () => { alive = false; off(); };
  }, [active, id]);
  return s;
}

/**
 * A persisted draft (SQLite `drafts` table, bound to the signed-in scope) with React state in front of it.
 * Writes are debounced (default 250 ms) and flushed on unmount, so typing is cheap and a restart loses nothing.
 */
export function useDraft<T>(kind: string, id: string, initial: T, debounceMs = 250) {
  const active = useActive();
  const ref = useRef<T>(initial);
  const [value, setValue] = useState<T>(initial);
  const [ready, setReady] = useState(false);
  const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
  const dirty = useRef(false);

  const flush = useCallback(async () => {
    clearTimeout(timer.current);
    if (!dirty.current || !active) return;
    dirty.current = false;
    await active.store.drafts.put(kind, id, ref.current);
  }, [active, kind, id]);

  useEffect(() => {
    let alive = true;
    setReady(false);
    if (!active) { setReady(true); return; }
    active.store.drafts.get<T>(kind, id).then((v) => {
      if (!alive) return;
      if (v) { ref.current = v; setValue(v); }
      setReady(true);
    }, () => alive && setReady(true));
    return () => { alive = false; void flush(); };
  }, [active, kind, id, flush]);

  const set = useCallback((v: T | ((p: T) => T)) => {
    const next = typeof v === "function" ? (v as (p: T) => T)(ref.current) : v;
    ref.current = next;
    setValue(next);
    dirty.current = true;
    clearTimeout(timer.current);
    timer.current = setTimeout(() => void flush(), debounceMs);
  }, [flush, debounceMs]);

  const clear = useCallback(async () => {
    clearTimeout(timer.current);
    dirty.current = false;
    ref.current = initial;
    setValue(initial);
    await active?.store.drafts.remove(kind, id);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [active, kind, id]);

  return { value, set, ready, flush, clear };
}

/** inventory_item cache rows by id: gives POS/search rows their low-stock flag and reorder threshold without extra calls. */
export function useInventoryMap() {
  const { rows, loading } = useCacheList<InventoryItemDto>("inventory_item", { limit: 5000 });
  const map = useMemo(() => new Map(rows.map((r) => [r.id, r])), [rows]);
  return { map, loading };
}

export function useStores() {
  const { rows, loading } = useCacheList<StoreDto>("store", { orderBy: "search" });
  return { stores: rows.filter((s) => s.is_active !== false), loading };
}

/** Object URL for a blob, revoked when it changes/unmounts. */
export function useObjectUrl(blob: Blob | null): string | null {
  const url = useMemo(() => (blob ? URL.createObjectURL(blob) : null), [blob]);
  useEffect(() => () => { if (url) URL.revokeObjectURL(url); }, [url]);
  return url;
}

/** Loads a stored photo (PhotoStore) for display; null while loading or if the file is gone. */
export function useStoredPhoto(ref: string | null | undefined): Blob | null {
  const { photos } = useServices();
  const [blob, setBlob] = useState<Blob | null>(null);
  useEffect(() => {
    let alive = true;
    setBlob(null);
    if (ref) photos.load(ref).then((b) => alive && setBlob(b), () => undefined);
    return () => { alive = false; };
  }, [photos, ref]);
  return blob;
}

/** First message for a (possibly nested) validation field, e.g. fieldError(err, "inventory.price_cents"). */
export function fieldError(err: unknown, ...names: string[]): string | null {
  const f = (err as { fields?: Record<string, string[]> } | null)?.fields;
  if (!f) return null;
  for (const n of names) if (f[n]?.[0]) return f[n]![0]!;
  return null;
}
