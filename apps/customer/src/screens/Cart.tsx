import { Link, useNavigate } from "react-router-dom";
import { formatAge, formatPeso, useAuth, useNow, type PublicListingDto } from "@hardwhere/shared";
import { useCart, type CartLine } from "../cart";
import { Btn, Card, EmptyState, Money, Notice, QuantityStepper, StockBadge, cx } from "../components/ui";
import { useOnline } from "../hooks";
import { useLiveCart } from "../liveCart";
import { effectivePrice } from "./Product";

export type LineState = { kind: "ok" } | { kind: "gone" } | { kind: "out" } | { kind: "short"; available: number };
export function lineState(l: CartLine, live: Map<number, PublicListingDto | null>): LineState {
  if (!live.has(l.listing_id)) return { kind: "ok" };
  const n = live.get(l.listing_id);
  if (!n) return { kind: "gone" };
  if (n.availability.status === "out_of_stock") return { kind: "out" };
  if (n.availability.quantity != null && n.availability.quantity < l.quantity) return { kind: "short", available: n.availability.quantity };
  return { kind: "ok" };
}

export function groupByStore(lines: CartLine[]) {
  const m = new Map<number, CartLine[]>();
  lines.forEach((l) => { const id = l.snap.store?.id ?? 0; m.set(id, [...(m.get(id) ?? []), l]); });
  return [...m.entries()];
}

export function CartScreen() {
  const auth = useAuth();
  const cart = useCart();
  const online = useOnline();
  const nav = useNavigate();
  const now = useNow(15_000);
  const lc = useLiveCart();

  if (auth.status === "restoring") return null;
  if (auth.status === "signed_out") {
    return <EmptyState title="Sign in to use your cart" action={<Btn onClick={() => nav("/login?next=/cart")}>Sign in or create account</Btn>}>Your cart is saved on this device and works offline once you're signed in.</EmptyState>;
  }
  if (cart.ready && cart.lines.length === 0) {
    return <EmptyState title="Your cart is empty" action={<Btn onClick={() => nav("/")}>Find hardware</Btn>}>Search for a product, compare stores, then add it here.</EmptyState>;
  }

  return (
    <div className="space-y-3 p-3">
      <div className="flex items-center justify-between">
        <h1 className="text-lg font-bold">Your cart</h1>
        <Btn variant="ghost" disabled={!online} busy={lc.checking} onClick={() => void lc.refresh()}>Refresh prices</Btn>
      </div>
      <p className="text-xs text-ink-muted" aria-live="polite">
        {lc.checkedAt ? `Prices and availability checked ${formatAge(lc.checkedAt, now)}` : online ? (lc.checking ? "Checking current prices…" : "Prices not checked yet") : "Offline: prices are as of when you added items"}
      </p>
      {!online && <Notice tone="warn">Ordering needs a connection. Your cart is saved on this device.</Notice>}
      {lc.error && <Notice tone="error">{lc.error}</Notice>}
      {lc.changes.length > 0 && <Notice tone="warn">{lc.changes.length === 1 ? "A price changed" : `${lc.changes.length} prices changed`} since you added {lc.changes.length === 1 ? "it" : "them"}: {lc.changes.map((c) => `${formatPeso(c.from)} → ${formatPeso(c.to)}`).join(", ")}.</Notice>}
      <p className="text-xs text-ink-muted">Each order is for one store. Check out each store separately.</p>

      {groupByStore(cart.lines).map(([storeId, lines]) => {
        const s = lines[0].snap.store;
        const states = lines.map((l) => lineState(l, lc.live));
        const blocked = states.some((x) => x.kind !== "ok");
        const subtotal = lines.reduce((n, l) => n + effectivePrice(lc.live.get(l.listing_id) ?? l.snap) * l.quantity, 0);
        return (
          <Card key={storeId} className="space-y-3">
            <div>
              <p className="text-sm font-bold">{s?.business_name}</p>
              <p className="text-xs text-ink-muted">{s?.name}{s?.city ? ` · ${s.city}` : ""}</p>
            </div>
            <ul className="divide-y divide-line">
              {lines.map((l, i) => {
                const lv = lc.live.get(l.listing_id) ?? l.snap;
                const st = states[i];
                return (
                  <li key={l.listing_id} className="space-y-2 py-2">
                    <div className="flex justify-between gap-2">
                      <div className="min-w-0">
                        <Link to={`/product/${l.snap.product_id}`} className="line-clamp-2 text-sm font-medium text-ink">{l.snap.title ?? l.snap.variant?.name}</Link>
                        <p className="text-xs text-ink-muted">{formatPeso(effectivePrice(lv))} each{l.snap.uom ? ` / ${l.snap.uom}` : ""}</p>
                      </div>
                      <Money cents={effectivePrice(lv) * l.quantity} className="text-sm font-bold" />
                    </div>
                    {st.kind === "gone" && <Notice tone="error">No longer available. Remove it to continue.</Notice>}
                    {st.kind === "out" && <Notice tone="error">Out of stock at this store. Remove it or pick another store.</Notice>}
                    {st.kind === "short" && <Notice tone="warn">Only {st.available} available. Lower the quantity to continue.</Notice>}
                    {st.kind === "ok" && lc.live.get(l.listing_id) && lc.live.get(l.listing_id)!.availability.status === "low_stock" && <StockBadge status="low_stock" />}
                    <div className={cx("flex items-center justify-between", st.kind === "gone" && "opacity-60")}>
                      <QuantityStepper value={l.quantity} min={lv.min_order_qty} max={lv.max_order_qty} onChange={(n) => cart.setQty(l.listing_id, n)} label={`Quantity for ${l.snap.title ?? "item"}`} />
                      <Btn variant="ghost" onClick={() => cart.remove(l.listing_id)}>Remove</Btn>
                    </div>
                    {(lv.min_order_qty > 1 || lv.max_order_qty) && <p className="text-xs text-ink-muted">{lv.min_order_qty > 1 ? `Minimum ${lv.min_order_qty}` : ""}{lv.min_order_qty > 1 && lv.max_order_qty ? " · " : ""}{lv.max_order_qty ? `Maximum ${lv.max_order_qty}` : ""}</p>}
                  </li>
                );
              })}
            </ul>
            <div className="flex items-center justify-between border-t border-line pt-2">
              <span className="text-sm text-ink-muted">Subtotal</span>
              <Money cents={subtotal} className="text-lg font-extrabold text-brand-ink" />
            </div>
            <Btn block disabled={!online || blocked || lc.checking} onClick={() => nav(`/checkout/${storeId}`)}>
              {!online ? "Ordering needs a connection" : blocked ? "Fix unavailable items to continue" : `Check out · ${s?.name ?? "store"}`}
            </Btn>
          </Card>
        );
      })}
    </div>
  );
}
