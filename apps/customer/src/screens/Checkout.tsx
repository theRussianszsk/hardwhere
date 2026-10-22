import { useEffect, useMemo, useRef, useState } from "react";
import { Link, Navigate, useNavigate, useParams } from "react-router-dom";
import {
  COMMAND, formatPeso, newId, normalizePhMobile, useActive, useAuth, useOutbox,
  type FulfillmentType, type OrderDto, type OrderPlacePayload, type OrderStockConflictDetails, type PlaceOrderInput,
} from "@hardwhere/shared";
import type { OutboxItem } from "@hardwhere/shared";
import { useCart } from "../cart";
import { Btn, Card, ConfirmDialog, EmptyState, FormField, LoadingState, Money, Notice, StatusPill, cx, inputCls } from "../components/ui";
import { useOnline } from "../hooks";
import { useLiveCart } from "../liveCart";
import { lineState } from "./Cart";
import { effectivePrice } from "./Product";

const FULFILL_LABEL: Record<FulfillmentType, { t: string; d: string }> = {
  pickup: { t: "Pickup", d: "Reserve now, collect and pay at the store." },
  reservation: { t: "Reservation", d: "Hold the items for me; I'll confirm with the store." },
  delivery: { t: "Delivery", d: "The store delivers to your address (fee arranged with the store)." },
};

export function CheckoutScreen() {
  const storeId = Number(useParams().storeId);
  const auth = useAuth();
  const online = useOnline();
  const nav = useNavigate();
  const active = useActive();
  const cart = useCart();
  const lc = useLiveCart();
  const lines = useMemo(() => cart.lines.filter((l) => (l.snap.store?.id ?? 0) === storeId), [cart.lines, storeId]);
  const store = lines[0]?.snap.store;

  const [fulfil, setFulfil] = useState<FulfillmentType | null>(null);
  const [name, setName] = useState(auth.profile?.user.name ?? "");
  const [phone, setPhone] = useState(auth.profile?.user.phone ?? "");
  const [address, setAddress] = useState("");
  const [notes, setNotes] = useState("");
  const [errs, setErrs] = useState<Record<string, string>>({});
  const [confirm, setConfirm] = useState(false);
  const [busy, setBusy] = useState(false);
  const [fail, setFail] = useState<string | null>(null);

  const liveOf = (id: number) => lc.live.get(id) ?? lines.find((l) => l.listing_id === id)!.snap;
  const options = useMemo<FulfillmentType[]>(() => {
    if (!store) return [];
    const ls = lines.map((l) => lc.live.get(l.listing_id) ?? l.snap);
    const o: FulfillmentType[] = [];
    if (store.supports_pickup && ls.every((l) => l.pickup_available)) o.push("pickup", "reservation");
    if (store.supports_delivery && ls.every((l) => l.delivery_available)) o.push("delivery");
    return o;
  }, [store, lines, lc.live]);
  const type = fulfil && options.includes(fulfil) ? fulfil : options[0] ?? null;

  if (auth.status === "signed_out") return <Navigate to={`/login?next=${encodeURIComponent(`/checkout/${storeId}`)}`} replace />;
  if (!cart.ready) return <LoadingState />;
  if (!lines.length || !store) {
    return <EmptyState title="Nothing to check out" action={<Btn onClick={() => nav("/cart")}>Back to cart</Btn>}>This store has no items in your cart.</EmptyState>;
  }
  if (!online) {
    return (
      <div className="space-y-3 p-3">
        <Notice tone="warn">Ordering needs a connection. Your cart is saved on this device; an offline draft is not a reservation.</Notice>
        <Btn variant="secondary" block onClick={() => nav("/cart")}>Back to cart</Btn>
      </div>
    );
  }

  const states = lines.map((l) => lineState(l, lc.live));
  const blocked = states.some((s) => s.kind !== "ok");
  const subtotal = lines.reduce((n, l) => n + effectivePrice(liveOf(l.listing_id)) * l.quantity, 0);

  const validate = (): PlaceOrderInput | null => {
    const e: Record<string, string> = {};
    const phoneN = normalizePhMobile(phone);
    if (name.trim().length < 2) e.name = "Enter the name the store should look for.";
    if (!phoneN) e.phone = "Enter a Philippine mobile number, like 0917 123 4567.";
    if (type === "delivery" && address.trim().length < 8) e.address = "Enter your full delivery address (street, barangay, city).";
    if (notes.length > 500) e.notes = "Notes can be up to 500 characters.";
    if (!type) e.fulfil = "This store can't fulfil these items together. Order them separately.";
    setErrs(e);
    if (Object.keys(e).length || !type || !phoneN) return null;
    return {
      items: lines.map((l) => ({ listing_id: l.listing_id, quantity: l.quantity, expected_unit_price_cents: effectivePrice(liveOf(l.listing_id)) })),
      fulfillment_type: type, contact_name: name.trim(), contact_phone: phoneN,
      ...(type === "delivery" ? { delivery_address: address.trim() } : {}), ...(notes.trim() ? { notes: notes.trim() } : {}),
    };
  };

  const place = async () => {
    const input = validate();
    if (!input || !active) return;
    setBusy(true); setFail(null);
    try {
      const key = newId(); // generated ONCE for this attempt; every retry of this order re-sends the same key
      await active.store.outbox.enqueue<OrderPlacePayload>(COMMAND.orderPlace, { input }, { id: key, label: `Order at ${store.name} · ${formatPeso(subtotal)}` });
      nav(`/attempt/${key}`, { replace: true });
    } catch (e) {
      setFail(e instanceof Error ? e.message : "Could not start the order.");
      setConfirm(false);
    } finally { setBusy(false); }
  };

  return (
    <div className="space-y-3 p-3">
      <button type="button" onClick={() => nav("/cart")} className="touch-target text-sm font-medium text-brand-ink">‹ Cart</button>
      <h1 className="text-lg font-bold">Check out</h1>
      <Card>
        <p className="text-sm font-bold">{store.business_name}</p>
        <p className="text-xs text-ink-muted">{store.name} · {[store.address_line, store.city].filter(Boolean).join(", ")}</p>
      </Card>

      {lc.checking && <Notice tone="info">Checking current prices and availability…</Notice>}
      {lc.error && <Notice tone="error">{lc.error} <button className="font-semibold underline" onClick={() => void lc.refresh()}>Retry</button></Notice>}
      {lc.changes.length > 0 && <Notice tone="warn">Prices changed since you added these items. The totals below use the current prices.</Notice>}
      {blocked && <Notice tone="error">Some items are unavailable or short on stock. <Link to="/cart" className="font-semibold underline">Fix in cart</Link></Notice>}

      <fieldset className="space-y-2">
        <legend className="text-sm font-semibold">How do you want it?</legend>
        {options.length === 0 && <Notice tone="error">This store can't fulfil these items together. <Link to="/cart" className="underline">Order them separately</Link>.</Notice>}
        {options.map((o) => (
          <label key={o} className={cx("flex touch-target cursor-pointer items-start gap-3 rounded-control border p-3", type === o ? "border-primary-600 bg-warn-soft" : "border-line bg-surface")}>
            <input type="radio" name="fulfil" checked={type === o} onChange={() => setFulfil(o)} className="mt-1 h-5 w-5" />
            <span><span className="block text-sm font-semibold">{FULFILL_LABEL[o].t}</span><span className="block text-xs text-ink-muted">{FULFILL_LABEL[o].d}</span></span>
          </label>
        ))}
        {errs.fulfil && <p role="alert" className="text-xs text-danger-ink">{errs.fulfil}</p>}
      </fieldset>

      <Card className="space-y-3">
        <FormField label="Contact name" error={errs.name}>{(p) => <input {...p} value={name} onChange={(e) => setName(e.target.value)} autoComplete="name" className={inputCls} />}</FormField>
        <FormField label="Mobile number" error={errs.phone} hint="The store will call or text this number.">
          {(p) => <input {...p} value={phone} onChange={(e) => setPhone(e.target.value)} inputMode="tel" autoComplete="tel" placeholder="0917 123 4567" className={inputCls} />}
        </FormField>
        {type === "delivery" && (
          <FormField label="Delivery address" error={errs.address}>{(p) => <textarea {...p} value={address} onChange={(e) => setAddress(e.target.value)} rows={3} autoComplete="street-address" className={cx(inputCls, "py-2")} />}</FormField>
        )}
        <FormField label="Notes for the store (optional)" error={errs.notes}>{(p) => <textarea {...p} value={notes} onChange={(e) => setNotes(e.target.value)} rows={2} maxLength={500} className={cx(inputCls, "py-2")} />}</FormField>
      </Card>

      <Card className="space-y-2">
        <h2 className="text-sm font-bold">Summary</h2>
        {lines.map((l) => (
          <div key={l.listing_id} className="flex justify-between gap-2 text-sm">
            <span className="min-w-0 truncate">{l.quantity} × {l.snap.title ?? l.snap.variant?.name}</span>
            <Money cents={effectivePrice(liveOf(l.listing_id)) * l.quantity} />
          </div>
        ))}
        <div className="flex justify-between border-t border-line pt-2 text-sm"><span>Delivery fee</span><span>{type === "delivery" ? "Arranged with store" : "—"}</span></div>
        <div className="flex justify-between text-base font-extrabold"><span>Total</span><Money cents={subtotal} className="text-brand-ink" /></div>
      </Card>

      {fail && <Notice tone="error">{fail}</Notice>}
      <Btn block disabled={blocked || lc.checking || !type} onClick={() => { if (validate()) setConfirm(true); }}>Review and place order</Btn>

      <ConfirmDialog open={confirm} title="Place this order?" confirmLabel={`Reserve · ${formatPeso(subtotal)}`} busy={busy} onConfirm={() => void place()} onCancel={() => setConfirm(false)}>
        <p>{lines.reduce((n, l) => n + l.quantity, 0)} item(s) from <strong>{store.name}</strong>, {type ? FULFILL_LABEL[type].t.toLowerCase() : ""} for <strong>{formatPeso(subtotal)}</strong>.</p>
        <p className="mt-2 text-xs text-ink-muted">The store holds your items for a limited time. You're only reserved once the next screen says <strong>Reserved</strong>.</p>
      </ConfirmDialog>
    </div>
  );
}

// ───────────────────────── attempt status ─────────────────────────
const CONFLICT = new Set(["stock_unavailable", "price_changed", "listing_unavailable", "quantity_out_of_range", "mixed_stores", "fulfillment_unsupported", "delivery_unsupported", "pickup_unsupported"]);
/** The server definitely did not place this order (rejected, or a 409 that is a business answer rather than an unknown outcome). */
export const attemptFailed = (a: Pick<OutboxItem, "state" | "lastError">) => a.state === "rejected" || (a.state === "needs_review" && !!a.lastError && CONFLICT.has(a.lastError.code));

export function AttemptScreen() {
  const id = useParams().id!;
  const nav = useNavigate();
  const active = useActive();
  const auth = useAuth();
  const online = useOnline();
  const cart = useCart();
  const { items } = useOutbox({ kinds: [COMMAND.orderPlace] });
  const item = items.find((i) => i.id === id);
  const prev = useRef<string | null>(null);
  const [busy, setBusy] = useState(false);

  const payload = item?.payload as OrderPlacePayload | undefined;
  const order = item?.state === "confirmed" ? (item.response as OrderDto | null) : null;

  useEffect(() => {
    if (!item) return;
    const justConfirmed = item.state === "confirmed" && (prev.current !== null && prev.current !== "confirmed" || Date.now() - Date.parse(item.updatedAt) < 60_000);
    prev.current = item.state;
    if (!justConfirmed || !payload) return;
    const ids = new Set(payload.input.items.map((i) => i.listing_id));
    cart.lines.filter((l) => ids.has(l.listing_id)).forEach((l) => cart.remove(l.listing_id));
    const t = setTimeout(() => order && nav(`/orders/${order.id}`, { replace: true }), 1800);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [item?.state]);

  if (!item) return items.length === 0 && !active ? <LoadingState /> : <EmptyState title="Order attempt not found" action={<Btn onClick={() => nav("/orders")}>My orders</Btn>}>It may have been discarded.</EmptyState>;

  const err = item.lastError;
  const details = (err?.details ?? {}) as Record<string, unknown>;
  const definite = attemptFailed(item);
  const inFlight = item.state === "draft" || item.state === "queued" || item.state === "sending";
  const storeId = cart.lines.find((l) => payload?.input.items.some((i) => i.listing_id === l.listing_id))?.snap.store?.id;

  const checkAgain = async () => {
    if (!active) return;
    setBusy(true);
    try { await active.store.outbox.retryNow(id); } finally { setBusy(false); }
  };
  const fixAndRetry = async (adjustTo?: number, listingId?: number) => {
    if (!active) return;
    const lid = listingId ?? (payload && payload.input.items.length === 1 ? payload.input.items[0].listing_id : undefined);
    if (adjustTo != null && lid != null) {
      if (adjustTo > 0) cart.setQty(lid, adjustTo); else cart.remove(lid);
    }
    await active.store.outbox.discard(id); // definitely not placed: a corrected order gets a NEW key
    nav(storeId ? `/checkout/${storeId}` : "/cart", { replace: true });
  };

  // the server sends an exact count only for stores that show exact stock; otherwise just the public availability bucket
  const stock = err?.code === "stock_unavailable" ? (details as Partial<OrderStockConflictDetails>) : {};
  const available = typeof stock.available === "number" ? stock.available : null;
  const shortId = typeof stock.listing_id === "number" ? stock.listing_id : undefined;
  const shortSnap = cart.lines.find((l) => l.listing_id === shortId)?.snap;
  const shortName = shortSnap ? (shortSnap.title ?? shortSnap.sku) : "An item";
  const asked = typeof stock.requested === "number" ? ` (you asked for ${stock.requested})` : "";
  const stage2 = order ? "done" : definite ? "failed" : item.state === "needs_review" ? "unknown" : "wait";

  return (
    <div className="space-y-3 p-3">
      <h1 className="text-lg font-bold">Your order</h1>
      <p className="text-sm text-ink-muted">{item.label}</p>

      <ol className="flex items-center gap-2 text-xs font-semibold" aria-label="Order progress">
        <li className="flex items-center gap-1"><span className="grid h-6 w-6 place-items-center rounded-full bg-success text-white">✓</span> Submitted</li>
        <li aria-hidden className="h-px flex-1 bg-ink-muted" />
        <li className="flex items-center gap-1">
          <span className={cx("grid h-6 w-6 place-items-center rounded-full text-white", stage2 === "done" ? "bg-success" : stage2 === "failed" ? "bg-danger" : stage2 === "unknown" ? "bg-warn" : "bg-ink-muted")}>{stage2 === "done" ? "✓" : stage2 === "failed" ? "✕" : stage2 === "unknown" ? "?" : "…"}</span>
          {stage2 === "done" ? "Reserved" : stage2 === "failed" ? "Not reserved" : stage2 === "unknown" ? "Not confirmed" : "Reserved?"}
        </li>
      </ol>

      <div aria-live="assertive" role="status">
        {order ? (
          <Card className="space-y-2 border-success bg-success-soft">
            <div className="flex items-center gap-2"><StatusPill status={order.status} /><span className="text-sm font-bold">Order {order.order_no}</span></div>
            <p className="text-sm text-success-ink">{order.status === "reserved" ? "Reserved — waiting for the store to confirm." : order.status === "pending" ? "Order received — waiting for the store to confirm." : "The store confirmed your order."} Taking you to your order…</p>
            <Btn block onClick={() => nav(`/orders/${order.id}`, { replace: true })}>View order</Btn>
          </Card>
        ) : inFlight ? (
          <Card className="space-y-2">
            <div className="flex items-center gap-2"><StatusPill status="sending" /><span className="text-sm">Sending your order…</span></div>
            <p className="text-xs text-ink-muted">Not reserved yet. Keep this screen open{!online ? " — you're offline, so it will send when the connection returns" : ""}{auth.status === "expired" ? " — sign in again to send it" : ""}.</p>
          </Card>
        ) : definite ? (
          <Card className="space-y-3 border-danger bg-danger-soft">
            <div className="flex items-center gap-2"><StatusPill status="rejected" label="Not reserved" /></div>
            {err?.code === "stock_unavailable" ? (
              <>
                <p className="text-sm text-danger-ink">
                  {available != null ? `${shortName}: only ${available} ${available === 1 ? "is" : "are"} available right now${asked}.`
                    : stock.availability === "out_of_stock" ? `${shortName} is out of stock right now.`
                    : stock.availability === "low_stock" ? `${shortName}: only a few are left, fewer than you asked for${asked}.`
                    : `${shortName}: the store doesn't have that many available right now${asked}.`} Nothing was reserved.
                </p>
                {available != null && available > 0 && (shortId != null || payload?.input.items.length === 1) && <Btn block onClick={() => void fixAndRetry(available, shortId)}>Order {available} instead</Btn>}
                {stock.availability === "out_of_stock" && shortId != null && <Btn block onClick={() => void fixAndRetry(0, shortId)}>Remove it and review my cart</Btn>}
                <Btn block variant="secondary" onClick={() => void fixAndRetry()}>Review my cart</Btn>
              </>
            ) : err?.code === "price_changed" ? (
              <>
                <p className="text-sm text-danger-ink">A price changed{typeof details.expected === "number" && typeof details.current === "number" ? ` from ${formatPeso(details.expected)} to ${formatPeso(details.current)}` : ""}. Nothing was reserved. Review the new price and confirm again.</p>
                <Btn block onClick={() => void fixAndRetry()}>Review new prices</Btn>
              </>
            ) : (
              <>
                <p className="text-sm text-danger-ink">{err?.message ?? "The store couldn't accept this order."} Nothing was reserved.</p>
                <FieldErrors details={details} />
                <Btn block onClick={() => void fixAndRetry()}>Back to checkout</Btn>
              </>
            )}
          </Card>
        ) : (
          <Card className="space-y-3 border-warn bg-warn-soft">
            <StatusPill status="needs_review" />
            <p className="text-sm text-warn-ink">We couldn't confirm whether the store received your order{err?.message ? ` (${err.message})` : ""}. It is <strong>not</strong> reserved until confirmed. Checking again is safe: it can't create a second order.</p>
            <Btn block busy={busy} disabled={!online} onClick={() => void checkAgain()}>Check again</Btn>
            {!online && <p className="text-xs text-warn-ink">You're offline. Reconnect to check.</p>}
          </Card>
        )}
      </div>
      <div className="flex gap-2">
        <Btn variant="secondary" className="flex-1" onClick={() => nav("/orders")}>My orders</Btn>
        <Btn variant="secondary" className="flex-1" onClick={() => nav("/cart")}>Cart</Btn>
      </div>
    </div>
  );
}

function FieldErrors({ details }: { details: Record<string, unknown> }) {
  const f = details.fields as Record<string, string[]> | undefined;
  if (!f) return null;
  return <ul className="list-disc pl-5 text-sm text-danger-ink">{Object.entries(f).map(([k, v]) => <li key={k}>{v[0]}</li>)}</ul>;
}
