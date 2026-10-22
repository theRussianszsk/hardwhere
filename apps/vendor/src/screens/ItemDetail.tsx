import { useCallback, useEffect, useState } from "react";
import { useParams } from "react-router-dom";
import {
  ApiError, COMMAND, formatManilaDateTime, humanizeError, newId, useActive, useAuth, useCacheItem, useNow, useOnlineQuery, useServices, useSyncStatus,
  type InventoryItemDto, type LocationDto, type PurchaseReceivePayload, type StockAdjustPayload, type TenantListingDto,
} from "@hardwhere/shared";
import { CommandStatus } from "../lib/CommandStatus";
import { fieldError, useOnline } from "../lib/hooks";
import { Screen } from "../shell";
import { Alert, Btn, Card, CachedTag, ConfirmDialog, EmptyState, ErrorState, LoadingState, Money, PesoInput, Pill, QuantityStepper, SelectField, Segmented, Sheet, StockBadge, TextAreaField } from "../ui";

// "Received new stock" is recorded as a purchase receipt (receipt row + unit cost), never as a bare adjustment.
const TYPES = [
  { value: "purchase_receipt", label: "Received new stock", sign: 1, note: false },
  { value: "return", label: "Customer return", sign: 1, note: false },
  { value: "damage", label: "Damaged", sign: -1, note: true },
  { value: "loss", label: "Lost or stolen", sign: -1, note: true },
  { value: "correction", label: "Stock count correction", sign: 0, note: true },
  { value: "manual_adjustment", label: "Other manual adjustment", sign: 0, note: true },
] as const;
const MOVEMENT_LABEL: Record<string, string> = {
  opening_balance: "Opening balance", purchase_receipt: "Received", manual_adjustment: "Manual adjustment", sale: "Sale", return: "Return", reservation: "Reserved",
  reservation_release: "Reservation released", order_fulfillment: "Order fulfilled", transfer_in: "Transfer in", transfer_out: "Transfer out", damage: "Damaged", loss: "Lost", correction: "Correction",
};

export function ItemDetail() {
  const id = Number(useParams().id);
  const { can } = useAuth();
  const now = useNow(30_000);
  const live = useOnlineQuery((api) => api.inventoryGet(id), [id]);
  const cachedItem = useCacheItem<InventoryItemDto>("inventory_item", id);
  const sync = useSyncStatus();
  const ledger = useOnlineQuery((api) => api.inventoryLedger(id), [id], { enabled: can("inventory.adjust") });
  const locs = useOnlineQuery((api) => api.locations(), []);
  const [adjustOpen, setAdjustOpen] = useState(false);
  const [queued, setQueued] = useState<{ id: string; title: string }[]>([]);

  const item: InventoryItemDto | null = live.data?.data ?? cachedItem.item;
  const isLive = !!live.data?.data;
  const locations = locs.data?.data ?? [];
  const locName = (lid: number) => locations.find((l) => l.id === lid)?.name ?? `Location ${lid}`;

  const refreshAll = useCallback(() => { void live.refetch(); void ledger.refetch(); }, [live, ledger]);

  if (!item) {
    return (
      <Screen title="Item" back="/stock">
        {live.loading || cachedItem.loading ? <LoadingState /> : live.error ? <ErrorState message={live.message ?? "Couldn't load this item."} onRetry={() => void live.refetch()} /> : <EmptyState title="Item not found">It may have been removed.</EmptyState>}
      </Screen>
    );
  }

  return (
    <Screen title={item.name ?? item.product_name ?? item.sku} back="/stock">
      <div className="flex flex-wrap items-center gap-2 text-sm text-slate-700" aria-live="polite">
        {isLive ? <Pill tone="green">Live · as of {live.fetchedAt ? formatManilaDateTime(new Date(live.fetchedAt)) : ""}</Pill> : <CachedTag at={sync?.lastSyncedAt} now={now} />}
        {live.loading && <span>Refreshing…</span>}
      </div>
      {!isLive && !!live.error && <Alert tone="warn">Couldn't get live numbers ({live.message}). These are saved values and may be out of date. <button type="button" className="font-semibold underline" onClick={() => void live.refetch()}>Try again</button></Alert>}
      {isLive && !!live.error && <Alert tone="warn">Couldn't refresh: {live.message}</Alert>}

      <Card className="space-y-3">
        <div>
          <p className="text-sm text-slate-600">{[item.brand, item.sku].filter(Boolean).join(" · ")}</p>
          {item.barcodes && item.barcodes.length > 0 && <p className="text-sm text-slate-600">Barcode {item.barcodes.join(", ")}</p>}
        </div>
        <dl className="grid grid-cols-3 gap-2 text-center">
          <div className="rounded-lg bg-slate-50 p-2"><dt className="text-xs text-slate-600">On hand</dt><dd className="text-xl font-bold tabular-nums">{item.on_hand ?? "—"}</dd></div>
          <div className="rounded-lg bg-slate-50 p-2"><dt className="text-xs text-slate-600">Reserved</dt><dd className="text-xl font-bold tabular-nums">{item.reserved ?? "—"}</dd></div>
          <div className="rounded-lg bg-slate-50 p-2"><dt className="text-xs text-slate-600">Available</dt><dd className="text-xl font-bold tabular-nums">{item.available ?? "—"}</dd></div>
        </dl>
        <div className="flex flex-wrap items-center gap-2">
          <StockBadge available={item.available} low={item.low_stock} cached={!isLive} />
          <span className="text-sm text-slate-700">Reorder at {item.reorder_threshold}</span>
          {item.cost_cents !== undefined && <span className="text-sm text-slate-700">· Cost <Money cents={item.cost_cents} /></span>}
        </div>
        {item.locations && item.locations.length > 0 && (
          <ul className="divide-y divide-slate-100 text-sm">
            {item.locations.map((l) => <li key={l.location_id} className="flex justify-between py-1.5"><span>{locName(l.location_id)}</span><span className="tabular-nums">{l.on_hand} on hand{l.reserved ? `, ${l.reserved} reserved` : ""}</span></li>)}
          </ul>
        )}
        {can("inventory.adjust") && <Btn block onClick={() => setAdjustOpen(true)}>Adjust stock</Btn>}
      </Card>

      {queued.map((q) => (
        <CommandStatus key={q.id} id={q.id} title={q.title} confirmedText="The server applied this adjustment." onConfirmed={refreshAll} onDismiss={() => setQueued((p) => p.filter((x) => x.id !== q.id))} />
      ))}

      {can("inventory.view") && <ListingEditor item={item} />}

      {can("inventory.adjust") && (
        <section aria-label="Stock history" className="space-y-2">
          <h2 className="text-base font-semibold text-slate-900">Stock history</h2>
          {ledger.loading && !ledger.data ? <LoadingState label="Loading history…" />
            : ledger.error && !ledger.data ? <Alert tone="warn">History needs a connection. {ledger.message}</Alert>
            : !ledger.data?.data.length ? <EmptyState title="No movements yet" />
            : (
              <Card className="divide-y divide-slate-100 p-0">
                {ledger.data.data.map((e) => (
                  <div key={e.id} className="flex items-start justify-between gap-3 px-4 py-3">
                    <div className="min-w-0">
                      <p className="font-medium text-slate-900">{MOVEMENT_LABEL[e.movement_type] ?? e.movement_type}</p>
                      <p className="text-xs text-slate-600">{formatManilaDateTime(e.occurred_at)} · {locName(e.location_id)}</p>
                      {e.note && <p className="text-sm text-slate-700">{e.note}</p>}
                    </div>
                    <div className="shrink-0 text-right">
                      <p className={e.quantity_delta < 0 ? "font-bold text-red-700" : e.quantity_delta > 0 ? "font-bold text-emerald-700" : "font-bold"}>{e.quantity_delta > 0 ? "+" : ""}{e.quantity_delta}{e.reserved_delta ? ` (${e.reserved_delta > 0 ? "+" : ""}${e.reserved_delta} res.)` : ""}</p>
                      <p className="text-xs text-slate-600">→ {e.after_quantity}</p>
                    </div>
                  </div>
                ))}
              </Card>
            )}
        </section>
      )}

      <AdjustSheet open={adjustOpen} onClose={() => setAdjustOpen(false)} item={item} locations={locations} onQueued={(qid, title) => { setQueued((p) => [{ id: qid, title }, ...p]); setAdjustOpen(false); }} />
    </Screen>
  );
}

function AdjustSheet({ open, onClose, item, locations, onQueued }: { open: boolean; onClose: () => void; item: InventoryItemDto; locations: LocationDto[]; onQueued: (id: string, title: string) => void }) {
  const active = useActive();
  const { can } = useAuth();
  const types = TYPES.filter((x) => x.value !== "purchase_receipt" || can("purchase.receive"));
  const [type, setType] = useState<(typeof TYPES)[number]["value"]>(types[0]!.value);
  const [dir, setDir] = useState<"add" | "remove">("add");
  const [qty, setQty] = useState(1);
  const [note, setNote] = useState("");
  const [unitCost, setUnitCost] = useState<number | null>(item.cost_cents ?? null);
  const [locId, setLocId] = useState<number | null>(item.default_location_id);
  const [confirm, setConfirm] = useState(false);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  const t = TYPES.find((x) => x.value === type)!;
  const sign = t.sign === 0 ? (dir === "add" ? 1 : -1) : t.sign;
  const delta = sign * qty;
  // Locations already holding this item plus every sellable location the user may access (the server scopes both to their store).
  const accessible = [...new Set([...(item.locations ?? []).map((l) => l.location_id), ...locations.filter((l) => l.is_sellable).map((l) => l.id)])];
  const itemLocs = accessible.length ? accessible : item.default_location_id ? [item.default_location_id] : [];
  const effectiveLoc = locId !== null && itemLocs.includes(locId) ? locId : itemLocs[0] ?? null;
  const locOnHand = item.locations ? (item.locations.find((l) => l.location_id === effectiveLoc)?.on_hand ?? 0) : item.on_hand;
  const noteMissing = t.note && note.trim().length < 3;
  const receiving = type === "purchase_receipt";
  const costMissing = receiving && unitCost === null;
  const tooMany = delta < 0 && locOnHand !== null && locOnHand !== undefined && -delta > locOnHand;

  useEffect(() => { if (open) { setErr(null); setConfirm(false); } }, [open]);
  const locLabel = (lid: number | null) => (lid === null ? "this location" : locations.find((l) => l.id === lid)?.name ?? `location ${lid}`);

  const submit = async () => {
    if (!active || effectiveLoc === null || (receiving && unitCost === null)) return;
    setBusy(true); setErr(null);
    try {
      const name = item.name ?? item.sku;
      let title: string;
      let row: { id: string };
      if (receiving) {
        const payload: PurchaseReceivePayload = { input: { location_id: effectiveLoc, notes: note.trim() || undefined, items: [{ inventory_item_id: item.id, quantity: qty, unit_cost_cents: unitCost! }] } };
        title = `Receive stock: ${name} +${qty}`;
        row = await active.store.outbox.enqueue(COMMAND.purchaseReceive, payload, { id: newId(), label: title });
      } else {
        const payload: StockAdjustPayload = { itemId: item.id, locationId: effectiveLoc, movement_type: type, quantity_delta: delta, note: note.trim() || undefined };
        title = `Adjust stock: ${name} ${delta > 0 ? "+" : "−"}${Math.abs(delta)}`;
        row = await active.store.outbox.enqueue(COMMAND.stockAdjust, payload, { id: newId(), label: title });
      }
      void active.kickOutbox();
      setConfirm(false); setNote(""); setQty(1);
      onQueued(row.id, title);
    } catch (e) { setErr(humanizeError(e)); setConfirm(false); } finally { setBusy(false); }
  };

  return (
    <>
      <Sheet open={open} onClose={onClose} title="Adjust stock">
        <p className="text-sm text-slate-700">{item.name ?? item.sku} · {locOnHand ?? "?"} on hand at {locLabel(effectiveLoc)}</p>
        <SelectField label="What happened?" value={type} onChange={(e) => setType(e.target.value as typeof type)}>
          {types.map((x) => <option key={x.value} value={x.value}>{x.label}</option>)}
        </SelectField>
        {t.sign === 0 && <Segmented label="Direction" value={dir} onChange={setDir} options={[{ value: "add", label: "Add units" }, { value: "remove", label: "Remove units" }]} />}
        {itemLocs.length > 1 && (
          <SelectField label="Location" value={effectiveLoc ?? ""} onChange={(e) => setLocId(Number(e.target.value))}>
            {itemLocs.map((l) => <option key={l} value={l}>{locLabel(l)}</option>)}
          </SelectField>
        )}
        <div className="space-y-1">
          <p className="text-sm font-medium text-slate-800" id="qty-label">Quantity</p>
          <QuantityStepper label="Quantity" value={qty} onChange={setQty} max={1_000_000} />
        </div>
        {receiving && <PesoInput label="Cost per unit" cents={unitCost} onChange={setUnitCost} hint="Recorded on the purchase receipt; it becomes this item's cost." error={costMissing ? "Enter what you paid per unit." : null} />}
        <TextAreaField label={t.note ? "Reason (required)" : "Note (optional)"} value={note} onChange={(e) => setNote(e.target.value)} maxLength={300} error={t.note && note.length > 0 && noteMissing ? "Write a short reason." : null} />
        {tooMany && <Alert tone="warn">This removes more than the {locOnHand} on hand here. The server will refuse it if the stock isn't there.</Alert>}
        {err && <Alert tone="error">{err}</Alert>}
        <Btn block disabled={noteMissing || costMissing || effectiveLoc === null} onClick={() => setConfirm(true)}>Review adjustment</Btn>
      </Sheet>
      <ConfirmDialog open={confirm} title="Confirm stock change" confirmLabel="Queue adjustment" busy={busy} onCancel={() => setConfirm(false)} onConfirm={() => void submit()}>
        <p><strong>{delta > 0 ? "Add" : "Remove"} {Math.abs(delta)}</strong> unit{Math.abs(delta) === 1 ? "" : "s"} of {item.name ?? item.sku} at {locLabel(effectiveLoc)}.</p>
        {locOnHand !== null && locOnHand !== undefined && <p className="text-sm text-slate-700">Expected: {locOnHand} → {locOnHand + delta}</p>}
        {receiving && unitCost !== null && <p className="text-sm text-slate-700">Recorded as a purchase receipt at <Money cents={unitCost} /> per unit.</p>}
        <p className="text-sm text-slate-700">It is saved on this phone first and only counts once the server confirms it.</p>
      </ConfirmDialog>
    </>
  );
}

function ListingEditor({ item }: { item: InventoryItemDto }) {
  const { can } = useAuth();
  const { online } = useOnline();
  const q = useOnlineQuery((api) => api.listingsList({ q: item.sku }), [item.sku]);
  const mine = (q.data?.data ?? []).filter((l) => l.sku.toLowerCase() === item.sku.toLowerCase() && l.inventory_item_id === item.id);

  return (
    <section aria-label="Price and publishing" className="space-y-2">
      <h2 className="text-base font-semibold text-slate-900">Price &amp; publish</h2>
      {q.loading && !q.data ? <LoadingState label="Loading listing…" />
        : q.error && !q.data ? <Alert tone="warn">{online ? q.message : "Price and publishing need a connection."} <button type="button" className="font-semibold underline" onClick={() => void q.refetch()}>Try again</button></Alert>
        : mine.length === 0 ? <EmptyState title="No marketplace listing for this item yet" />
        : mine.map((l) => <ListingForm key={l.id} listing={l} canEdit={can("listings.manage")} canPrice={can("prices.manage")} onSaved={() => q.refetch()} />)}
    </section>
  );
}

function ListingForm({ listing, canEdit, canPrice, onSaved }: { listing: TenantListingDto; canEdit: boolean; canPrice: boolean; onSaved: () => Promise<void> }) {
  const { api } = useServices();
  const active = useActive();
  const [price, setPrice] = useState<number | null>(listing.price_cents);
  const [sale, setSale] = useState<number | null>(listing.sale_price_cents);
  const [publish, setPublish] = useState(listing.publication_status === "published");
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<{ tone: "success" | "error" | "warn"; text: string } | null>(null);
  const [errObj, setErrObj] = useState<unknown>(null);

  // A refetch after saving (or after a version conflict) brings a new row_version: load its values into the form but keep the
  // form mounted, so the "Saved" / "someone else changed it" message survives the reload.
  useEffect(() => {
    setPrice(listing.price_cents); setSale(listing.sale_price_cents); setPublish(listing.publication_status === "published");
  }, [listing.row_version, listing.price_cents, listing.sale_price_cents, listing.publication_status]);

  const dirty = price !== listing.price_cents || sale !== listing.sale_price_cents || publish !== (listing.publication_status === "published");
  const save = async () => {
    if (price === null) return;
    setBusy(true); setMsg(null); setErrObj(null);
    try {
      const body: Record<string, unknown> = { expected_row_version: listing.row_version, publish };
      if (canPrice) { body.price_cents = price; body.sale_price_cents = sale; }
      await api.listingUpdate(listing.id, body);
      await onSaved();
      void active?.syncNow();
      setMsg({ tone: "success", text: "Saved. The server accepted your changes." });
    } catch (e) {
      setErrObj(e);
      if (e instanceof ApiError && e.code === "version_conflict") {
        await onSaved();
        setMsg({ tone: "warn", text: "Someone else changed this listing while you were editing. We reloaded the latest values — review them and save again." });
      } else setMsg({ tone: "error", text: humanizeError(e) });
    } finally { setBusy(false); }
  };

  return (
    <Card className="space-y-3">
      <div className="flex items-center justify-between gap-2">
        <p className="font-semibold text-slate-900">{listing.title_override ?? listing.title ?? listing.sku}</p>
        <Pill tone={listing.publication_status === "published" ? "green" : "slate"}>{listing.publication_status}</Pill>
      </div>
      <PesoInput label="Price" cents={price} onChange={setPrice} disabled={!canEdit || !canPrice} error={fieldError(errObj, "price_cents")} />
      <PesoInput label="Sale price (optional, must be lower)" cents={sale} onChange={setSale} optional disabled={!canEdit || !canPrice} error={fieldError(errObj, "sale_price_cents")} />
      <label className="flex min-h-11 items-center gap-3">
        <input type="checkbox" className="h-5 w-5" checked={publish} disabled={!canEdit} onChange={(e) => setPublish(e.target.checked)} />
        <span className="text-sm font-medium text-slate-800">Show this item on the Hardwhere marketplace</span>
      </label>
      {!canEdit && <p className="text-sm text-slate-600">Your role can view this listing but not change it.</p>}
      {msg && <Alert tone={msg.tone}>{msg.text}</Alert>}
      {canEdit && <Btn block busy={busy} disabled={!dirty || price === null} onClick={() => void save()}>Save changes</Btn>}
    </Card>
  );
}
