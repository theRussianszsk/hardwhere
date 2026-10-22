import { useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { ApiError, formatManilaDateTime, formatPeso, humanizeError, useActive, useAuth, useCacheList, useServices, type PosCheckoutPayload, type SaleDto, type StoreDto } from "@hardwhere/shared";
import { CHECKOUT_REFUSALS, useCart } from "../lib/cart";
import { lastErrorText } from "../lib/CommandStatus";
import { useOnline, useOutboxItem } from "../lib/hooks";
import { Screen } from "../shell";
import { Alert, Btn, Card, ConfirmDialog, EmptyState, Icon, LoadingState, Money, Pill } from "../ui";

export function PaymentStatus() {
  const id = useParams().id!;
  const { item, loading } = useOutboxItem<SaleDto, PosCheckoutPayload>(id);
  const active = useActive();
  const { api } = useServices();
  const { online } = useOnline();
  const cartApi = useCart();
  const nav = useNavigate();
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  const [releaseAsk, setReleaseAsk] = useState(false);
  const [notFound, setNotFound] = useState(false);
  const [resendAsk, setResendAsk] = useState(false);

  if (loading) return <Screen title="Checkout"><LoadingState /></Screen>;
  if (!item) return <Screen title="Checkout" back="/pos"><EmptyState title="This checkout isn't on this phone" action={<Link to="/pos"><Btn>Back to POS</Btn></Link>}>It may have been discarded. Check Sales on the server before ringing it up again.</EmptyState></Screen>;

  if (item.state === "confirmed" && item.response) return <Receipt sale={item.response} onNewSale={() => { void cartApi.clear(); nav("/pos"); }} />;

  const retry = async () => {
    setBusy(true); setMsg(null); setResendAsk(false); setNotFound(false);
    try { await active?.store.outbox.retryNow(id); await active?.kickOutbox(); } catch (e) { setMsg(humanizeError(e)); } finally { setBusy(false); }
  };
  // Read-only: asks the server whether a sale was recorded under this key. Found -> it IS this checkout's result; nothing is re-sent.
  const check = async () => {
    setBusy(true); setMsg(null); setNotFound(false);
    try { await active?.store.outbox.markConfirmed(id, (await api.posCheckoutStatus(id)).data); }
    catch (e) { if (e instanceof ApiError && e.kind === "not_found") setNotFound(true); else setMsg(humanizeError(e)); }
    finally { setBusy(false); }
  };
  const err = item.lastError;
  const details = (err?.details ?? {}) as Record<string, unknown>;
  const refused = CHECKOUT_REFUSALS.includes(err?.code ?? "");
  // The server already holds a sale under this key, made from a different payload: only a lookup can resolve it (a re-send gets the same 409).
  const reused = err?.code === "idempotency_key_reused";

  // Server said no (409 stock/price): nothing was sold. Refresh the cache, keep the cart, send the cashier back to fix it.
  const refreshCart = async (fix?: () => void) => {
    setBusy(true); setMsg(null);
    try {
      await active?.store.outbox.discard(id);
      await active?.syncNow();
      cartApi.setKey(null);
      for (const l of cartApi.cart.lines) {
        const fresh = await active?.store.cache.get<{ sale_price_cents: number | null; price_cents: number }>("listing", l.listingId);
        if (fresh) cartApi.patch(l.listingId, { unitPriceCents: fresh.sale_price_cents ?? fresh.price_cents });
      }
      fix?.();
      nav("/pos");
    } catch (e) { setMsg(humanizeError(e)); } finally { setBusy(false); }
  };

  // Only after the cashier has checked: the attempt is deleted (so nothing can re-send it) and the cart unlocks for a fresh sale.
  const release = async () => {
    setBusy(true); setMsg(null);
    try { await cartApi.release(id); setReleaseAsk(false); nav("/pos"); } catch (e) { setMsg(humanizeError(e)); setReleaseAsk(false); } finally { setBusy(false); }
  };

  const line = cartApi.cart.lines.find((l) => l.inventoryItemId === details.inventory_item_id || l.listingId === details.listing_id);
  const available = typeof details.available === "number" ? details.available : null;

  return (
    <Screen title="Checkout" back="/pos">
      <div aria-live="polite" className="space-y-3">
        {(item.state === "queued" || item.state === "sending") && (
          item.state === "queued" && !online
            ? <Alert tone="warn" live="assertive"><strong>Checkout needs a connection — your cart is saved.</strong> Nothing has been sent, so nothing was sold. <Btn variant="secondary" className="mt-2" onClick={() => void active?.kickOutbox()}>Try again</Btn></Alert>
            : <LoadingState label="Waiting for the server to confirm the sale…" />
        )}

        {item.state === "needs_review" && !refused && (
          <Alert tone="warn" live="assertive">
            <p className="font-semibold">Not confirmed yet</p>
            {reused
              ? <p>The server already has a sale with this sale number, recorded with different details than this phone last sent. Check its status to see what was charged.</p>
              : <p>We couldn't get an answer from the server, so we don't know whether this sale went through. {lastErrorText(err)}</p>}
            <p className="mt-1">“Check status” only asks the server whether this sale was recorded. It never charges anything.</p>
            <div className="mt-2 flex flex-col gap-2">
              <Btn busy={busy} disabled={!online} onClick={() => void check()}>Check status</Btn>
              {notFound && <p role="status">The server has no sale with this number, so nothing has been charged for it so far.</p>}
              {!reused && <Btn variant="secondary" disabled={busy || !online} onClick={() => setResendAsk(true)}>Send again</Btn>}
            </div>
            {!online && <p className="mt-1 text-xs">Waiting for a connection.</p>}
            <p className="mt-2">The cart stays locked until this is resolved, so the same goods can't be charged twice.</p>
            <Btn variant="ghost" className="mt-1" disabled={busy} onClick={() => setReleaseAsk(true)}>I confirm nothing was sold</Btn>
          </Alert>
        )}

        {refused && err?.code === "stock_unavailable" && (
          <Alert tone="error" live="assertive">
            <p className="font-semibold">Not enough stock — nothing was sold</p>
            <p>{line ? `${line.name}: you asked for ${typeof details.requested === "number" ? details.requested : line.qty}, but only ${available ?? "fewer"} ${available === 1 ? "is" : "are"} available.` : `Only ${available ?? "fewer"} available for one of the items.`} Your cart is kept.</p>
            <div className="mt-2 flex flex-col gap-2">
              {line && available !== null && available > 0 && <Btn busy={busy} onClick={() => void refreshCart(() => cartApi.patch(line.listingId, { qty: available }))}>Reduce to {available} and refresh</Btn>}
              <Btn variant="secondary" busy={busy} onClick={() => void refreshCart()}>Refresh stock &amp; edit cart</Btn>
            </div>
          </Alert>
        )}
        {refused && err?.code === "price_changed" && (
          <Alert tone="error" live="assertive">
            <p className="font-semibold">A price changed — nothing was sold</p>
            <p>{line ? `${line.name} is now ${formatPeso(Number(details.current))} (was ${formatPeso(Number(details.expected))}).` : "One of the prices changed."} Your cart is kept; the new prices will be applied.</p>
            <Btn className="mt-2" busy={busy} onClick={() => void refreshCart()}>Update prices &amp; review cart</Btn>
          </Alert>
        )}

        {item.state === "rejected" && (
          <Alert tone="error" live="assertive">
            <p className="font-semibold">The server refused this sale — nothing was sold</p>
            <p>{lastErrorText(err)}</p>
            <div className="mt-2 flex gap-2">
              <Btn variant="secondary" className="flex-1" busy={busy} onClick={() => void refreshCart()}>Edit cart</Btn>
              <Link to="/pos/checkout" className="flex-1"><Btn variant="secondary" block onClick={() => void active?.store.outbox.discard(id).then(() => cartApi.setKey(null))}>Change payment</Btn></Link>
            </div>
          </Alert>
        )}
        {msg && <Alert tone="error">{msg}</Alert>}
        <Card className="text-sm text-slate-700">{item.label}</Card>
      </div>
      <ConfirmDialog open={releaseAsk} title="Nothing was sold?" danger confirmLabel="Yes, nothing was sold" busy={busy} onCancel={() => setReleaseAsk(false)} onConfirm={() => void release()}>
        <p>Only continue if you are sure this sale is <strong>not</strong> on the server (for example, “Check status” found no sale, or the customer did not pay).</p>
        <p className="mt-1">This attempt is removed from the phone and will never be sent again; the cart unlocks for a new sale. If the server did record it, charging the cart again sells it twice.</p>
      </ConfirmDialog>
      <ConfirmDialog open={resendAsk} title="Send this sale again?" confirmLabel="Send again" busy={busy} onCancel={() => setResendAsk(false)} onConfirm={() => void retry()}>
        <p>The same sale is sent again with the same sale number, exactly as first entered. If the server already recorded it, it answers with that sale instead of charging twice.</p>
      </ConfirmDialog>
    </Screen>
  );
}

function Receipt({ sale, onNewSale }: { sale: SaleDto; onNewSale: () => void }) {
  const { profile } = useAuth();
  const stores = useCacheList<StoreDto>("store");
  const store = stores.rows.find((s) => s.id === sale.store_id);
  const rate = (profile?.tenant?.vat_rate_bps ?? 1200) / 100;
  const label: Record<string, string> = { cash: "Cash", gcash: "GCash", maya: "Maya", card: "Card", bank_transfer: "Bank transfer", other: "Other" };

  return (
    <Screen title="Receipt">
      <Alert tone="success" className="no-print"><strong>Sale confirmed by the server.</strong> Stock has been updated.</Alert>

      <div className="print-area rounded-xl border border-slate-200 bg-white p-4 font-mono text-sm shadow-sm">
        <div className="text-center">
          <p className="text-base font-bold">{profile?.tenant?.name}</p>
          {store && <p>{store.name}</p>}
          <p>Receipt {sale.receipt_no ?? `#${sale.id}`}</p>
          <p>{formatManilaDateTime(sale.completed_at)}</p>
        </div>
        <hr className="my-2 border-dashed border-slate-400" />
        <ul className="space-y-1">
          {(sale.items ?? []).map((i) => (
            <li key={i.id}>
              <div className="flex justify-between gap-2"><span className="min-w-0">{i.name}</span><Money cents={i.line_total_cents} /></div>
              <div className="text-xs text-slate-600">{i.quantity} × <Money cents={i.unit_price_cents} />{i.discount_cents > 0 ? ` − ${formatPeso(i.discount_cents)} discount` : ""} · {i.sku}</div>
            </li>
          ))}
        </ul>
        <hr className="my-2 border-dashed border-slate-400" />
        <div className="space-y-0.5">
          <div className="flex justify-between"><span>Subtotal</span><Money cents={sale.subtotal_cents} /></div>
          {sale.discount_cents > 0 && <div className="flex justify-between"><span>Discount</span><span>−<Money cents={sale.discount_cents} /></span></div>}
          <div className="flex justify-between text-base font-bold"><span>TOTAL</span><Money cents={sale.total_cents} /></div>
          <div className="flex justify-between text-xs"><span>VAT {rate.toFixed(0)}% (included)</span><Money cents={sale.vat_cents} /></div>
          <div className="flex justify-between"><span>Paid by</span><span>{label[sale.payment_method] ?? sale.payment_method}{sale.payment_reference ? ` · ${sale.payment_reference}` : ""}</span></div>
          {sale.tendered_cents !== null && <div className="flex justify-between"><span>Cash received</span><Money cents={sale.tendered_cents} /></div>}
          {sale.payment_method === "cash" && <div className="flex justify-between font-bold"><span>Change</span><Money cents={sale.change_cents} /></div>}
          {sale.customer_name && <div className="flex justify-between"><span>Customer</span><span>{sale.customer_name}</span></div>}
        </div>
        <p className="mt-3 text-center text-xs">Thank you!</p>
      </div>

      {sale.low_stock && sale.low_stock.length > 0 && (
        <Alert tone="warn" className="no-print">
          <p className="font-semibold">Running low after this sale</p>
          <ul className="list-disc pl-5">{sale.low_stock.map((l) => <li key={l.inventory_item_id}><Link className="underline" to={`/stock/${l.inventory_item_id}`}>{l.sku}</Link>: {l.remaining} left (reorder at {l.reorder_threshold})</li>)}</ul>
        </Alert>
      )}

      <div className="no-print grid grid-cols-2 gap-2">
        <Btn variant="secondary" onClick={() => window.print()}><Icon name="print" />Print</Btn>
        <Btn variant="success" onClick={onNewSale}>New sale</Btn>
      </div>
      <Pill tone="green">Sale {sale.status}</Pill>
    </Screen>
  );
}
