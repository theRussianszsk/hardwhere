import { useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { COMMAND, formatPeso, humanizeError, newId, normalizePhMobile, useActive, type CheckoutInput, type PaymentMethod, type PosCheckoutPayload } from "@hardwhere/shared";
import { useCart } from "../lib/cart";
import { useOnline } from "../lib/hooks";
import { Screen } from "../shell";
import { Alert, Btn, Card, ConfirmDialog, EmptyState, LoadingState, Money, PesoInput, TextField, cx } from "../ui";

const METHODS: { value: PaymentMethod; label: string }[] = [
  { value: "cash", label: "Cash" }, { value: "gcash", label: "GCash" }, { value: "maya", label: "Maya" },
  { value: "card", label: "Card" }, { value: "bank_transfer", label: "Bank transfer" }, { value: "other", label: "Other" },
];
const looksLikeCardNumber = (s: string) => /^\d{13,19}$/.test(s.replace(/[\s-]/g, ""));

export function Checkout() {
  const cartApi = useCart();
  const { cart, totals, ready } = cartApi;
  const active = useActive();
  const { online } = useOnline();
  const nav = useNavigate();
  const [method, setMethod] = useState<PaymentMethod>("cash");
  const [tendered, setTendered] = useState<number | null>(null);
  const [reference, setReference] = useState("");
  const [name, setName] = useState("");
  const [phone, setPhone] = useState("");
  const [confirm, setConfirm] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (!ready) return <Screen title="Checkout" back="/pos"><LoadingState /></Screen>;
  if (cart.lines.length === 0) return <Screen title="Checkout" back="/pos"><EmptyState title="The cart is empty" action={<Link to="/pos"><Btn>Back to products</Btn></Link>} /></Screen>;

  const cash = method === "cash";
  const change = cash && tendered !== null ? tendered - totals.total : null;
  const refTrim = reference.trim();
  const refError = !cash && refTrim.length > 0 && looksLikeCardNumber(refTrim) ? "That looks like a card number. Enter the approval or reference code instead — never card numbers." : null;
  const phoneNorm = phone.trim() ? normalizePhMobile(phone) : null;
  const phoneError = phone.trim() && !phoneNorm ? "Use a Philippine mobile number, like 0917 123 4567." : null;
  const tenderError = cash && tendered !== null && tendered < totals.total ? `Cash given is ${formatPeso(totals.total - tendered)} short.` : null;
  const valid = totals.total > 0 && !refError && !phoneError && (cash ? tendered !== null && tendered >= totals.total : refTrim.length > 0);

  const pay = async () => {
    if (!active) return;
    setError(null);
    if (!online) { setConfirm(false); setError("Checkout needs a connection — your cart is saved."); return; }
    setBusy(true);
    try {
      const input: CheckoutInput = {
        store_id: cart.storeId ?? undefined,
        items: cart.lines.map((l) => ({ listing_id: l.listingId, quantity: l.qty, expected_unit_price_cents: l.unitPriceCents, ...(l.discountCents > 0 ? { discount_cents: l.discountCents } : {}) })),
        payment: cash ? { method, tendered_cents: tendered ?? undefined } : { method, reference: refTrim },
        ...(name.trim() || phoneNorm ? { customer: { ...(name.trim() ? { name: name.trim() } : {}), ...(phoneNorm ? { phone: phoneNorm } : {}) } } : {}),
      };
      // ONE key per attempt: generated here, kept in the cart draft, reused by "Check again" so the server replays instead of selling twice.
      let key = cart.checkoutKey ?? newId();
      const existing = await active.store.outbox.get(key);
      if (existing?.state === "rejected") key = newId();
      cartApi.setKey(key);
      if (!existing || existing.state === "rejected") {
        await active.store.outbox.enqueue<PosCheckoutPayload>(COMMAND.posCheckout, { input }, { id: key, label: `Sale ${formatPeso(totals.total)} (${totals.count} item${totals.count === 1 ? "" : "s"}, ${method})` });
      }
      void active.kickOutbox();
      nav(`/pos/pay/${key}`);
    } catch (e) { setError(humanizeError(e)); setConfirm(false); } finally { setBusy(false); }
  };

  return (
    <Screen title="Checkout" back="/pos">
      {!online && <Alert tone="warn" live="assertive"><strong>Checkout needs a connection — your cart is saved.</strong> Stock and prices are confirmed by the server at the moment of sale.</Alert>}

      <Card className="space-y-1 text-sm">
        {cart.lines.map((l) => (
          <div key={l.listingId} className="flex justify-between gap-2"><span className="min-w-0 truncate">{l.qty} × {l.name}</span><Money cents={l.unitPriceCents * l.qty - l.discountCents} /></div>
        ))}
        <div className="flex justify-between border-t border-slate-200 pt-2 text-xl font-bold"><span>Total due</span><Money cents={totals.total} /></div>
        <p className="text-xs text-slate-600">Includes about {formatPeso(totals.vat)} VAT.</p>
      </Card>

      <fieldset className="space-y-2">
        <legend className="text-sm font-medium text-slate-800">Payment method</legend>
        <div className="grid grid-cols-3 gap-2">
          {METHODS.map((m) => (
            <label key={m.value} className={cx("flex min-h-11 cursor-pointer items-center justify-center rounded-lg border px-2 text-center text-sm font-semibold", method === m.value ? "border-orange-700 bg-orange-700 text-white" : "border-slate-300 bg-white text-slate-800")}>
              <input type="radio" name="method" className="sr-only" checked={method === m.value} onChange={() => setMethod(m.value)} />
              {m.label}
            </label>
          ))}
        </div>
      </fieldset>

      {cash ? (
        <div className="space-y-2">
          <PesoInput label="Cash received" cents={tendered} onChange={setTendered} error={tenderError} />
          <div className="flex flex-wrap gap-2" role="group" aria-label="Quick amounts">
            <Btn variant="secondary" onClick={() => setTendered(totals.total)}>Exact</Btn>
            {[100, 200, 500, 1000].filter((n) => n * 100 >= totals.total).slice(0, 3).map((n) => <Btn key={n} variant="secondary" onClick={() => setTendered(n * 100)}>₱{n}</Btn>)}
          </div>
          <p className="text-lg font-bold text-emerald-800" aria-live="polite">Change: {change !== null && change >= 0 ? formatPeso(change) : "—"}</p>
        </div>
      ) : (
        <TextField label={`${METHODS.find((m) => m.value === method)!.label} reference number`} value={reference} onChange={(e) => setReference(e.target.value)} maxLength={64} autoComplete="off" error={refError} hint="The approval, transaction or reference code. Never type a card number." />
      )}

      <details className="rounded-lg border border-slate-200 bg-white p-3">
        <summary className="min-h-11 cursor-pointer py-2 text-sm font-semibold">Customer (optional)</summary>
        <div className="space-y-2">
          <TextField label="Name" value={name} onChange={(e) => setName(e.target.value)} maxLength={120} autoComplete="off" />
          <TextField label="Mobile number" inputMode="tel" value={phone} onChange={(e) => setPhone(e.target.value)} error={phoneError} autoComplete="off" />
        </div>
      </details>

      {error && <Alert tone="error" live="assertive">{error}</Alert>}
      <Btn block variant="success" className="min-h-14 text-base" disabled={!valid || !online} onClick={() => setConfirm(true)}>Charge {formatPeso(totals.total)}</Btn>

      <ConfirmDialog open={confirm} title="Complete this sale?" confirmLabel="Yes, charge it" busy={busy} onCancel={() => setConfirm(false)} onConfirm={() => void pay()}>
        <p>Total <strong>{formatPeso(totals.total)}</strong> by <strong>{METHODS.find((m) => m.value === method)!.label}</strong>.</p>
        {cash && change !== null && <p>Received {formatPeso(tendered)} — give <strong>{formatPeso(change)}</strong> change.</p>}
        <p className="text-sm text-slate-700">This takes {totals.count} unit{totals.count === 1 ? "" : "s"} out of stock once the server confirms it.</p>
      </ConfirmDialog>
    </Screen>
  );
}
