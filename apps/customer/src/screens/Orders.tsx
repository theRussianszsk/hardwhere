import { useCallback, useEffect, useRef, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import {
  COMMAND, callPhone, formatAge, formatManilaDateTime, formatPeso, humanizeError, openDirections, useActive, useAuth, useCacheItem, useCacheList, useNow, useOnlineQuery, useOutbox, useServices, useSyncStatus,
  type OrderDto,
} from "@hardwhere/shared";
import { Btn, Card, ConfirmDialog, EmptyState, ErrorState, LoadingState, Money, Notice, StatusPill } from "../components/ui";
import { useOnline } from "../hooks";
import { formatHours } from "./Product";

const placedDesc = (a: OrderDto, b: OrderDto) => Date.parse(b.placed_at) - Date.parse(a.placed_at) || b.id - a.id;

export function OrdersScreen() {
  const auth = useAuth();
  const online = useOnline();
  const nav = useNavigate();
  const { api } = useServices();
  const sync = useSyncStatus();
  const now = useNow(15_000);
  const cached = useCacheList<OrderDto>("order", { limit: 200 });
  const { items: attempts } = useOutbox({ kinds: [COMMAND.orderPlace] });
  const [pg, setPg] = useState<{ items: OrderDto[]; page: number; last: number; at: number } | null>(null);
  const [err, setErr] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const seq = useRef(0);

  const load = useCallback(async (page: number) => {
    const my = ++seq.current;
    setBusy(true);
    try {
      const r = await api.ordersList(page);
      if (my !== seq.current) return;
      setErr(null);
      setPg((p) => ({ items: page === 1 || !p ? r.data : [...p.items, ...r.data.filter((o) => !p.items.some((x) => x.id === o.id))], page: r.meta.current_page, last: r.meta.last_page, at: Date.now() }));
    } catch (e) {
      if (my === seq.current) setErr(humanizeError(e));
    } finally { if (my === seq.current) setBusy(false); }
  }, [api]);

  const signedIn = auth.status === "signed_in" || auth.status === "expired";
  useEffect(() => { if (online && auth.status === "signed_in") void load(1); }, [online, auth.status, load, attempts.filter((a) => a.state === "confirmed").length]);

  if (auth.status === "restoring") return null;
  if (!signedIn) return <EmptyState title="Sign in to see your orders" action={<Btn onClick={() => nav("/login?next=/orders")}>Sign in or create account</Btn>}>Orders you place are tracked here.</EmptyState>;

  const pending = attempts.filter((a) => a.state !== "confirmed");
  const live = online && pg;
  const list = live ? pg!.items : [...cached.rows].sort(placedDesc);
  const loading = online ? busy && !pg : cached.loading;

  return (
    <div className="space-y-3 p-3">
      <h1 className="text-lg font-bold">My orders</h1>
      {pending.length > 0 && (
        <section aria-label="Orders waiting for confirmation" className="space-y-2">
          <h2 className="text-sm font-semibold text-ink">Waiting for confirmation</h2>
          {pending.map((a) => (
            <Link key={a.id} to={`/attempt/${a.id}`} className="flex items-center justify-between gap-2 rounded-card border border-warn bg-warn-soft p-3">
              <span className="min-w-0 truncate text-sm">{a.label}</span>
              <StatusPill status={a.state === "rejected" || (a.state === "needs_review" && a.lastError && ["stock_unavailable", "price_changed", "listing_unavailable"].includes(a.lastError.code)) ? "rejected" : a.state} label={a.state === "sending" || a.state === "queued" || a.state === "draft" ? "Submitted" : undefined} />
            </Link>
          ))}
          <p className="text-xs text-ink-muted">These are not reservations until the store confirms them.</p>
        </section>
      )}
      {!online && <Notice tone="warn">Offline: showing saved orders{sync?.lastSyncedAt ? ` (saved ${formatAge(sync.lastSyncedAt, now)})` : ""}. Status may have changed.</Notice>}
      {online && err && <Notice tone="error">{err} <button className="font-semibold underline" onClick={() => void load(1)}>Retry</button></Notice>}
      {loading ? <LoadingState label="Loading orders…" /> : list.length === 0 ? (
        <EmptyState title="No orders yet" action={<Btn onClick={() => nav("/")}>Find hardware</Btn>}>When you reserve something, it shows up here.</EmptyState>
      ) : (
        <ul className="space-y-2" aria-label="Orders">
          {live && <li className="text-xs text-ink-muted">Updated {formatAge(pg!.at, now)}</li>}
          {list.map((o) => (
            <li key={o.id}>
              <Link to={`/orders/${o.id}`} className="block rounded-card border border-line bg-surface p-3 shadow-sm">
                <div className="flex items-center justify-between gap-2">
                  <span className="text-sm font-bold">{o.order_no}</span>
                  <StatusPill status={o.status} />
                </div>
                <p className="mt-1 truncate text-sm text-ink">{o.store?.business_name} · {o.store?.name}</p>
                <div className="mt-1 flex items-center justify-between text-xs text-ink-muted">
                  <span>{formatManilaDateTime(o.placed_at)} · {o.items?.length ?? 0} {(o.items?.length ?? 0) === 1 ? "item" : "items"}</span>
                  <Money cents={o.total_cents} className="text-sm font-bold text-ink" />
                </div>
              </Link>
            </li>
          ))}
          {live && pg!.page < pg!.last && <li><Btn variant="secondary" block busy={busy} onClick={() => void load(pg!.page + 1)}>Load more</Btn></li>}
        </ul>
      )}
    </div>
  );
}

function Countdown({ order, refetch }: { order: OrderDto; refetch: () => void }) {
  const now = useNow(1000);
  const exp = order.reservation ? Date.parse(order.reservation.expires_at) : 0;
  const left = exp - now;
  const fired = useRef(false);
  useEffect(() => { if (left <= 0 && !fired.current) { fired.current = true; refetch(); } }, [left, refetch]);
  // Every status the server can still auto-expire (ReservationService::EXPIRABLE): a confirmed or ready order lapses too if nobody collects it.
  if (!["reserved", "confirmed", "ready_for_pickup"].includes(order.status) || order.reservation?.status !== "active") return null;
  if (left <= 0) return <Notice tone="warn">Your reservation time is up. The store may release these items. Checking the latest status…</Notice>;
  const m = Math.floor(left / 60000), s = Math.floor((left % 60000) / 1000);
  const mins = m >= 60 ? `${Math.floor(m / 60)} h ${m % 60} min` : `${m}:${String(s).padStart(2, "0")}`;
  return (
    <Card className="border-warn bg-warn-soft">
      <p className="text-xs font-semibold tracking-wide text-warn-ink uppercase">{order.status === "reserved" ? "Reserved for you" : "Pick up within"}</p>
      <p className="text-2xl font-extrabold text-warn-ink tabular-nums" role="timer" aria-label={`Reservation expires in ${mins}`}>{mins}</p>
      <p className="text-xs text-warn-ink">Expires {formatManilaDateTime(order.reservation.expires_at)}. Collect before then or the items go back on the shelf.</p>
    </Card>
  );
}

export function OrderDetailScreen() {
  const id = Number(useParams().id);
  const nav = useNavigate();
  const online = useOnline();
  const active = useActive();
  const { api } = useServices();
  const now = useNow(15_000);
  const q = useOnlineQuery((a) => a.orderGet(id), [id], { enabled: online });
  const cached = useCacheItem<OrderDto>("order", id);
  const order = q.data?.data ?? cached.item;
  const [ask, setAsk] = useState(false);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  const [actionErr, setActionErr] = useState<string | null>(null);
  const refetch = q.refetch;

  if (!order) {
    if (q.loading || cached.loading) return <LoadingState label="Loading order…" />;
    return <ErrorState title="Order not available" message={q.message ?? "This order isn't saved on this device. Connect to the internet to view it."} onRetry={online ? () => void q.refetch() : undefined} />;
  }

  const cancel = async () => {
    setBusy(true); setErr(null);
    try {
      await api.orderCancel(order.id);
      setAsk(false);
      await refetch();
      void active?.syncNow();
    } catch (e) {
      setErr(humanizeError(e)); setAsk(false);
      void refetch();
    } finally { setBusy(false); }
  };
  const safely = (fn: () => void) => { try { setActionErr(null); fn(); } catch (e) { setActionErr(humanizeError(e)); } };
  const s = order.store;
  const stale = !q.data;
  const NEXT: Partial<Record<string, string>> = {
    reserved: "The store is holding these items for you.", confirmed: "The store confirmed your order and is getting it ready.", preparing: "The store is preparing your order.",
    ready_for_pickup: "Ready! Bring your order number to the store.", fulfilled: "Completed. Thank you for shopping local.", cancelled: "This order was cancelled and the items went back to stock.",
    expired: "The reservation time ran out and the items were released.", failed: "This order couldn't be completed. Contact the store if you need help.", pending: "Waiting for the store to reserve your items.",
  };

  return (
    <div className="space-y-3 p-3">
      <button type="button" onClick={() => nav("/orders")} className="touch-target text-sm font-medium text-brand-ink">‹ My orders</button>
      <div className="flex items-center justify-between gap-2">
        <div><p className="text-xs text-ink-muted">Order number</p><h1 className="text-xl font-extrabold">{order.order_no}</h1></div>
        <StatusPill status={order.status} />
      </div>
      <p className="text-sm text-ink" aria-live="polite">{NEXT[order.status]}</p>
      {stale && <Notice tone="warn">{online && q.message ? `${q.message} ` : "You're offline. "}Showing a saved copy; the status may have changed.</Notice>}
      {!stale && <p className="text-xs text-ink-muted">Status checked {q.fetchedAt ? formatAge(q.fetchedAt, now) : "just now"}</p>}
      <Countdown order={order} refetch={() => void refetch()} />

      <Card className="space-y-2">
        <h2 className="text-sm font-bold">Items</h2>
        <ul className="divide-y divide-line">
          {(order.items ?? []).map((i) => (
            <li key={i.id} className="flex justify-between gap-2 py-2 text-sm">
              <span className="min-w-0">{i.quantity} × {i.name}<span className="block text-xs text-ink-muted">{formatPeso(i.unit_price_cents)} each · {i.sku}</span></span>
              <Money cents={i.line_total_cents} className="font-medium" />
            </li>
          ))}
        </ul>
        <div className="flex justify-between text-sm"><span>Subtotal</span><Money cents={order.subtotal_cents} /></div>
        {order.delivery_fee_cents > 0 && <div className="flex justify-between text-sm"><span>Delivery fee</span><Money cents={order.delivery_fee_cents} /></div>}
        <div className="flex justify-between border-t border-line pt-2 text-base font-extrabold"><span>Total</span><Money cents={order.total_cents} className="text-brand-ink" /></div>
        <p className="text-xs text-ink-muted">Pay at the store. No online payment is taken.</p>
      </Card>

      <Card className="space-y-1 text-sm">
        <h2 className="text-sm font-bold">Details</h2>
        <p><span className="text-ink-muted">Type:</span> {order.fulfillment_type[0].toUpperCase() + order.fulfillment_type.slice(1)}</p>
        <p><span className="text-ink-muted">Contact:</span> {order.contact_name} · {order.contact_phone}</p>
        {order.delivery_address && <p><span className="text-ink-muted">Deliver to:</span> {order.delivery_address}</p>}
        {order.notes && <p><span className="text-ink-muted">Notes:</span> {order.notes}</p>}
        <p><span className="text-ink-muted">Placed:</span> {formatManilaDateTime(order.placed_at)}</p>
        {order.confirmed_at && <p><span className="text-ink-muted">Confirmed:</span> {formatManilaDateTime(order.confirmed_at)}</p>}
        {order.ready_at && <p><span className="text-ink-muted">Ready:</span> {formatManilaDateTime(order.ready_at)}</p>}
        {order.fulfilled_at && <p><span className="text-ink-muted">Fulfilled:</span> {formatManilaDateTime(order.fulfilled_at)}</p>}
        {order.cancelled_at && <p><span className="text-ink-muted">Cancelled:</span> {formatManilaDateTime(order.cancelled_at)}{order.cancel_reason ? ` · ${order.cancel_reason}` : ""}</p>}
      </Card>

      {s && (
        <Card className="space-y-2">
          <h2 className="text-sm font-bold">Store</h2>
          <div className="text-sm"><Link to={`/store/${s.id}`} className="font-semibold underline decoration-line underline-offset-2">{s.business_name} · {s.name}</Link>
            <p className="text-xs text-ink-muted">{[s.address_line, s.barangay, s.city, s.province].filter(Boolean).join(", ")}</p>
            {formatHours(s.hours) && <p className="text-xs text-ink-muted">Hours: {formatHours(s.hours)}</p>}
            {s.phone && <p className="text-xs text-ink-muted">Phone: {s.phone}</p>}</div>
          {actionErr && <Notice tone="error">{actionErr}</Notice>}
          <div className="grid grid-cols-2 gap-2">
            <Btn variant="secondary" onClick={() => safely(() => openDirections(s.directions_url))}>Directions</Btn>
            <Btn variant="secondary" disabled={!s.phone} onClick={() => safely(() => callPhone(s.phone!))}>Call store</Btn>
          </div>
        </Card>
      )}

      {err && <Notice tone="error">{err}</Notice>}
      {order.customer_can_cancel && <Btn variant="danger" block disabled={!online} onClick={() => setAsk(true)}>Cancel order</Btn>}
      {order.customer_can_cancel && !online && <p className="text-xs text-ink-muted">Reconnect to cancel this order.</p>}
      <ConfirmDialog open={ask} danger title="Cancel this order?" confirmLabel="Yes, cancel order" cancelLabel="Keep order" busy={busy} onConfirm={() => void cancel()} onCancel={() => setAsk(false)}>
        Order {order.order_no} will be cancelled and the items go back on the shelf.
      </ConfirmDialog>
    </div>
  );
}
