import { useCallback, useState, useSyncExternalStore } from "react";
import { PermissionDeniedError, getApproxLocation, humanizeError } from "@hardwhere/shared";

export interface Geo { lat: number; lng: number }

/**
 * The shopper's approximate location, shared by search, product and store screens so "nearest" means the same thing everywhere.
 * Kept in memory only (never persisted, never put in a URL); asking again refreshes it.
 */
let current: Geo | null = null;
const subs = new Set<() => void>();
const set = (g: Geo | null) => { current = g; subs.forEach((f) => f()); };

export function useShopperLocation() {
  const geo = useSyncExternalStore((f) => { subs.add(f); return () => subs.delete(f); }, () => current);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const locate = useCallback(async () => {
    setBusy(true); setError(null);
    try {
      const p = await getApproxLocation();
      if (p) set({ lat: p.lat, lng: p.lng }); else setError("We couldn't find your location. Filter by city or area instead.");
    } catch (e) {
      setError(e instanceof PermissionDeniedError ? e.message : humanizeError(e));
    } finally { setBusy(false); }
  }, []);
  const clear = useCallback(() => set(null), []);
  return { geo, busy, error, locate, clear };
}
