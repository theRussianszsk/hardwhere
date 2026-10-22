import { useState } from "react";
import { Link, useParams } from "react-router-dom";
import {
  ApiError, callPhone, useActive, formatAge, formatManilaDateTime, humanizeError, useAuth, useCacheItem, useCacheList, useNow, useOnlineQuery, useServices, useSyncStatus,
  type OrderDto, type OrderStatus,
} from "@hardwhere/shared";
import { Screen } from "../shell";
import { Alert, Btn, Card, CachedTag, ConfirmDialog, EmptyState, ErrorState, Icon, LoadingState, Money, Pill, TextAreaField, cx } from "../ui";

const STATUS: Record<OrderStatus, { label: string; tone: "slate" | "green" | "amber" | "red" | "blue" | "purple" }> = {
  pending: { label: "Pending", tone: "amber" }, reserved: { label: "Reserved", tone: "amber" }, confirmed: { label: "Confirmed", tone: "blue" },
  preparing: { label: "Preparing", tone: "blue" }, ready_for_pickup: { label: "Ready for pickup", tone: "purple" }, fulfilled: { label: "Fulfilled", tone: "green" },
  cancelled: { label: "Cancelled", tone: "red" }, expired: { label: "Expired", tone: "slate" }, failed: { label: "Failed", tone: "red" },
};
const ACTION: Partial<Record<OrderStatus, string>> = {
  confirmed: "Confirm order", preparing: "Start preparing", ready_for_pickup: "Mark ready for pickup", fulfilled: "Mark fulfilled", cancelled: "Cancel order",
};
const FILTERS: (OrderStatus | "all")[] = ["all", "pending", "reserved", "confirmed", "preparing", "ready_for_pickup", "fulfilled", "cancelled"];
export const StatusTag = ({ status }: { status: OrderStatus }) => <Pill tone={STATUS[status]?.tone ?? "slate"}>{STATUS[status]?.label ?? status}</Pill>;

export function OrdersList() {
  const [status, setStatus] = useState<OrderStatus | "all">("all");
  const live = useOnlineQuery((api) => api.vendorOrders({ status: status === "all" ? undefined : status }), [status]);
  const cached = useCacheList<OrderDto>("order", { limit: 300 });
  const now = useNow(30_000);
  const sync = useSyncStatus();
  const fromServer = live.data?.data;
  const rows = fromServer ?? cached.rows.filter((o) => status === "all" || o.status === status).sort((a, b) => b.placed_at.localeCompare(a.placed_at));
  const usingCache = !fromServer;

  return (
    <Screen title="Orders">
      <div className="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1" role="group" aria-label="Filter by status">
        {FILTERS.map((f) => (
          <button key={f} type="button" aria-pressed={status === f} onClick={() => setStatus(f)}
            className={cx("min-h-11 shrink-0 rounded-full border px-4 text-sm font-semibold", status === f ? "border-orange-700 bg-orange-700 text-white" : "border-slate-300 bg-white text-slate-800")}>
            {f === "all" ? "All" : STATUS[f].label}
          </button>
        ))}
      </div>
      <div className="flex items-center justify-between text-xs text-slate-600" aria-live="polite">
        <span>{usingCache ? <><CachedTag at={sync?.lastSyncedAt} now={now} /> saved orders — may be out of date</> : `Live · as of ${formatManilaDateTime(live.fetchedAt ? new Date(live.fetchedAt) : null)}`}</span>
        <button type="button" className="min-h-11 px-2 font-semibold text-orange-800" onClick={() => void live.refetch()}>Refresh</button>
      </div>
      {!!live.error && <Alert tone="warn">Couldn't reach the server ({live.message}). Showing orders saved on this phone.</Alert>}

      {live.loading && !live.data && cached.loading ? <LoadingState label="Loading orders…" />
        : rows.length === 0 ? (live.error && cached.rows.length === 0 ? <ErrorState message={live.message ?? "Couldn't load orders."} onRetry={() => void live.refetch()} /> : <EmptyState title="No orders here">{status === "all" ? "Marketplace orders from customers show up here." : "Nothing with this status."}</EmptyState>)
        : (
          <ul className="space-y-2" aria-label="Orders">
            {rows.map((o) => (
              <li key={o.id}>
                <Link to={`/orders/${o.id}`} className="block">
                  <Card className="space-y-1">
                    <div className="flex items-center justify-between gap-2"><p className="font-semibold">{o.order_no ?? `#${o.id}`}</p><StatusTag status={o.status} /></div>
                    <p className="text-sm text-slate-700">{o.contact_name} · {o.fulfillment_type}</p>
                    <div className="flex items-center justify-between text-sm"><span className="text-slate-600">{formatAge(o.placed_at, now)} · {o.items?.length ?? "?"} item{o.items?.length === 1 ? "" : "s"}</span><span className="font-bold"><Money cents={o.total_cents} /></span></div>
                  </Card>
                </Link>
              </li>
            ))}
          </ul>
        )}
    </Screen>
  );
}

function Countdown({ expiresAt, active }: { expiresAt: string; active: boolean }) {
  const now = useNow(1000);
  if (!active) return null;
  const ms = Date.parse(expiresAt) - now;
  if (ms <= 0) return <Alert tone="warn">The reservation has expired. Stock is released when the server processes it.</Alert>;
  const s = Math.floor(ms / 1000);
  const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
  const text = `${h ? `${h}:` : ""}${String(m).padStart(h ? 2 : 1, "0")}:${String(sec).padStart(2, "0")}`;
  return <Alert tone={ms < 600_000 ? "warn" : "info"}>Stock is held for <strong className="tabular-nums" aria-label={`${h} hours ${m} minutes ${sec} seconds`}>{text}</strong> (until {formatManilaDateTime(expiresAt)}).</Alert>;
}

export function OrderDetail() {
  const id = Number(useParams().id);
  const { api } = useServices();
  const active = useActive();
  const { can } = useAuth();
  const live = useOnlineQuery((a) => a.vendorOrderGet(id), [id]);
  const cached = useCacheItem<OrderDto>("order", id);
  const order = live.data?.data ?? cached.item;
  const [pending, setPending] = useState<OrderStatus | null>(null);
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<{ tone: "success" | "error"; text: string } | null>(null);

  if (!order) {
    return <Screen title="Order" back="/orders">{live.loading || cached.loading ? <LoadingState /> : live.error ? <ErrorState message={live.message ?? ""} onRetry={() => void live.refetch()} /> : <EmptyState title="Order not found" />}</Screen>;
  }
  const isLive = !!live.data;
  const canManage = can("orders.manage");

  const go = async () => {
    if (!pending) return;
    setBusy(true); setResult(null);
    try {
      const r = await api.vendorOrderTransition(order.id, pending, pending === "cancelled" ? reason.trim() : undefined);
      setResult({ tone: "success", text: `Order is now ${STATUS[r.data.status]?.label ?? r.data.status}. The server confirmed it.` });
      setPending(null); setReason("");
      void active?.syncNow(); // keep the saved copy of this order in step
    } catch (e) {
      setResult({ tone: "error", text: e instanceof ApiError && e.code === "invalid_transition" ? `That change isn't allowed from the order's current status. ${e.message}` : humanizeError(e) });
      setPending(null);
    } finally { setBusy(false); void live.refetch(); }
  };

  return (
    <Screen title={order.order_no ?? `Order #${order.id}`} back="/orders">
      <div className="flex flex-wrap items-center gap-2" aria-live="polite">
        <StatusTag status={order.status} />
        {isLive ? <Pill tone="green">Live · {formatManilaDateTime(live.fetchedAt ? new Date(live.fetchedAt) : null)}</Pill> : <CachedTag at={order.updated_at} />}
      </div>
      {!!live.error && !isLive && <Alert tone="warn">Showing the saved copy: {live.message}. Actions need a connection.</Alert>}
      {result && <Alert tone={result.tone} live="assertive">{result.text}</Alert>}

      {order.reservation && <Countdown expiresAt={order.reservation.expires_at} active={order.reservation.status === "active" && !["fulfilled", "cancelled", "expired", "failed"].includes(order.status)} />}

      <Card className="space-y-1 text-sm">
        <p className="text-base font-semibold">{order.contact_name}</p>
        <div className="flex items-center justify-between gap-2"><span>{order.contact_phone}</span><Btn variant="secondary" aria-label={`Call ${order.contact_name}`} onClick={() => { try { callPhone(order.contact_phone); } catch { /* invalid number: nothing to dial */ } }}><Icon name="phone" className="h-4 w-4" />Call</Btn></div>
        <p className="text-slate-700">{order.fulfillment_type}{order.delivery_address ? ` · ${order.delivery_address}` : ""}</p>
        {order.notes && <p className="rounded bg-slate-50 p-2 text-slate-800">“{order.notes}”</p>}
        <p className="text-slate-600">Placed {formatManilaDateTime(order.placed_at)}</p>
        {order.cancel_reason && <p className="text-red-800">Cancelled: {order.cancel_reason}</p>}
      </Card>

      <Card className="divide-y divide-slate-100 p-0">
        {(order.items ?? []).map((i) => (
          <div key={i.id} className="flex justify-between gap-3 px-4 py-3 text-sm"><div className="min-w-0"><p className="font-medium">{i.name}</p><p className="text-xs text-slate-600">{i.sku} · {i.quantity} × <Money cents={i.unit_price_cents} /></p></div><Money cents={i.line_total_cents} className="font-semibold" /></div>
        ))}
        <div className="flex justify-between px-4 py-3 font-bold"><span>Total</span><Money cents={order.total_cents} /></div>
      </Card>

      {canManage ? (
        order.next_statuses.length > 0 ? (
          <div className="space-y-2" role="group" aria-label="Order actions">
            {order.next_statuses.map((s) => (
              <Btn key={s} block variant={s === "cancelled" ? "danger" : s === order.next_statuses[0] ? "primary" : "secondary"} disabled={!isLive} onClick={() => { setPending(s); setResult(null); }}>{ACTION[s] ?? `Mark as ${STATUS[s]?.label ?? s}`}</Btn>
            ))}
            {!isLive && <p className="text-xs text-slate-600">Connect to the internet to change this order.</p>}
          </div>
        ) : <p className="text-sm text-slate-600">No further steps — this order is {STATUS[order.status]?.label.toLowerCase() ?? order.status}.</p>
      ) : <p className="text-sm text-slate-600">Your role can view orders but not change them.</p>}

      <ConfirmDialog open={!!pending} danger={pending === "cancelled"} title={pending ? (ACTION[pending] ?? "Change status") + "?" : ""} confirmLabel={pending === "cancelled" ? "Cancel the order" : "Yes, continue"} cancelLabel="Not now" busy={busy}
        onCancel={() => setPending(null)} onConfirm={() => { if (pending !== "cancelled" || reason.trim().length >= 3) void go(); }}>
        {pending === "cancelled" ? (
          <>
            <p>The customer is told and the held stock is released.</p>
            <TextAreaField label="Reason (required)" value={reason} onChange={(e) => setReason(e.target.value)} maxLength={300} error={reason.length > 0 && reason.trim().length < 3 ? "Write a short reason." : null} />
          </>
        ) : <p>Order {order.order_no} will move to <strong>{pending ? STATUS[pending]?.label : ""}</strong>. The customer sees this change.</p>}
      </ConfirmDialog>
    </Screen>
  );
}
