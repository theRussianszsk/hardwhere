/**
 * The cart lives in store.drafts (kind "cart", id "current") of the signed-in customer scope: it survives restarts and works offline.
 * Each line keeps a snapshot of the listing as the shopper saw it (so the cart renders offline); live price/availability is
 * re-checked against the server before checkout. A best-effort `cart.replace` outbox command mirrors the cart to the server.
 */
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { COMMAND, newId, useActive, type PublicListingDto } from "@hardwhere/shared";

export interface CartLine { listing_id: number; quantity: number; snap: PublicListingDto }
interface CartDraft { lines: CartLine[] }

interface CartApi {
  lines: CartLine[];
  count: number;
  ready: boolean;
  add(l: PublicListingDto, qty: number): void;
  setQty(listingId: number, qty: number): void;
  remove(listingId: number): void;
  removeStore(storeId: number): void;
  clear(): void;
  /** Replace snapshots with fresher listings (after a live check). */
  refreshSnaps(live: PublicListingDto[]): void;
}
const Ctx = createContext<CartApi | null>(null);
export const useCart = (): CartApi => {
  const c = useContext(Ctx);
  if (!c) throw new Error("useCart outside CartProvider");
  return c;
};

export const clampQty = (l: Pick<PublicListingDto, "min_order_qty" | "max_order_qty">, q: number) =>
  Math.max(l.min_order_qty, l.max_order_qty ? Math.min(l.max_order_qty, q) : q);

export function CartProvider({ children }: { children: ReactNode }) {
  const active = useActive();
  const [lines, setLines] = useState<CartLine[]>([]);
  const [ready, setReady] = useState(false);
  const linesRef = useRef<CartLine[]>([]);
  const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
  const chain = useRef<Promise<unknown>>(Promise.resolve());
  const isCustomer = active?.scope.kind === "customer";

  useEffect(() => {
    let alive = true;
    clearTimeout(timer.current);
    setReady(false);
    linesRef.current = [];
    setLines([]);
    if (!active || !isCustomer) { setReady(true); return; }
    void active.store.drafts.get<CartDraft>("cart", "current").then((d) => {
      if (!alive) return;
      linesRef.current = d?.lines ?? [];
      setLines(linesRef.current);
      setReady(true);
    });
    return () => { alive = false; };
  }, [active, isCustomer]);

  const commit = useCallback((next: CartLine[]) => {
    linesRef.current = next;
    setLines(next);
    if (!active || !isCustomer) return;
    const store = active.store;
    chain.current = chain.current.then(() => (next.length ? store.drafts.put("cart", "current", { lines: next } satisfies CartDraft) : store.drafts.remove("cart", "current"))).catch(() => undefined);
    // best-effort mirror to the server cart (last write wins): drop older queued mirrors, enqueue the latest
    clearTimeout(timer.current);
    timer.current = setTimeout(() => {
      void (async () => {
        try {
          const old = await store.outbox.list({ kinds: [COMMAND.cartReplace], states: ["queued", "draft"] });
          await Promise.all(old.map((o) => store.outbox.discard(o.id)));
          await store.outbox.enqueue(COMMAND.cartReplace, { items: linesRef.current.map((l) => ({ listing_id: l.listing_id, quantity: l.quantity })) }, { id: newId(), label: "Sync cart" });
        } catch { /* mirror is optional */ }
      })();
    }, 1500);
  }, [active, isCustomer]);

  const api = useMemo<CartApi>(() => ({
    lines,
    count: lines.reduce((n, l) => n + l.quantity, 0),
    ready,
    add: (l, qty) => {
      const cur = linesRef.current;
      const ex = cur.find((x) => x.listing_id === l.id);
      commit(ex ? cur.map((x) => (x === ex ? { ...x, quantity: clampQty(l, x.quantity + qty), snap: l } : x)) : [...cur, { listing_id: l.id, quantity: clampQty(l, qty), snap: l }]);
    },
    setQty: (id, qty) => commit(linesRef.current.map((x) => (x.listing_id === id ? { ...x, quantity: clampQty(x.snap, qty) } : x))),
    remove: (id) => commit(linesRef.current.filter((x) => x.listing_id !== id)),
    removeStore: (sid) => commit(linesRef.current.filter((x) => x.snap.store?.id !== sid)),
    clear: () => commit([]),
    refreshSnaps: (live) => {
      const m = new Map(live.map((l) => [l.id, l]));
      const cur = linesRef.current;
      if (!cur.some((x) => m.has(x.listing_id))) return;
      commit(cur.map((x) => (m.has(x.listing_id) ? { ...x, snap: m.get(x.listing_id)! } : x)));
    },
  }), [lines, ready, commit]);

  return <Ctx.Provider value={api}>{children}</Ctx.Provider>;
}
