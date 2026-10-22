import { useCallback, useEffect, useRef, useState } from "react";
import { humanizeError, useServices, type PublicListingDto } from "@hardwhere/shared";
import { useCart } from "./cart";
import { useOnline } from "./hooks";
import { effectivePrice } from "./screens/Product";

export interface PriceChange { listingId: number; from: number; to: number }

/**
 * Live re-check of the local cart against the server. `cartReplace` mirrors the local cart to the server and returns every line with its
 * CURRENT listing; a line the server dropped (or returned with listing:null) is no longer available.
 * live.get(id) === null  -> no longer available;  undefined -> not checked yet.
 */
export function useLiveCart(auto = true) {
  const { api } = useServices();
  const cart = useCart();
  const online = useOnline();
  const [live, setLive] = useState<Map<number, PublicListingDto | null>>(new Map());
  const [checking, setChecking] = useState(false);
  const [checkedAt, setCheckedAt] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [changes, setChanges] = useState<PriceChange[]>([]);
  const lines = useRef(cart.lines);
  lines.current = cart.lines;

  const refresh = useCallback(async () => {
    const cur = lines.current;
    if (!cur.length) { setLive(new Map()); return; }
    setChecking(true);
    setError(null);
    try {
      const r = await api.cartReplace(cur.map((l) => ({ listing_id: l.listing_id, quantity: l.quantity })));
      const m = new Map<number, PublicListingDto | null>();
      r.data.items.forEach((i) => m.set(i.listing_id, i.listing));
      cur.forEach((l) => { if (!m.has(l.listing_id)) m.set(l.listing_id, null); });
      const ch: PriceChange[] = [];
      cur.forEach((l) => { const n = m.get(l.listing_id); if (n && effectivePrice(n) !== effectivePrice(l.snap)) ch.push({ listingId: l.listing_id, from: effectivePrice(l.snap), to: effectivePrice(n) }); });
      setChanges(ch);
      setLive(m);
      setCheckedAt(Date.now());
      cart.refreshSnaps([...m.values()].filter((x): x is PublicListingDto => !!x));
    } catch (e) {
      setError(humanizeError(e));
    } finally {
      setChecking(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [api]);

  const started = useRef(false);
  useEffect(() => {
    if (!auto || !online || !cart.ready || started.current || !cart.lines.length) return;
    started.current = true;
    void refresh();
  }, [auto, online, cart.ready, cart.lines.length, refresh]);

  return { live, checking, checkedAt, error, changes, refresh };
}
