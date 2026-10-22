import { useEffect, useMemo, useState, type FormEvent } from "react";
import { useNavigate } from "react-router-dom";
import {
  downscaleImage, formatPeso, humanizeError, isBarcodeScanSupported, newId, scanBarcode, useActive, useAuth, useCacheItem, useCacheList, useNow, useServices, useSyncStatus,
  type PosPhotoSearchResult, type TenantListingDto,
} from "@hardwhere/shared";
import { listingName, listingPrice, type CartLine, useCart } from "../lib/cart";
import { useInventoryMap, useOnline, useStores } from "../lib/hooks";
import { photoErrorText, PhotoPicker } from "../lib/PhotoPicker";
import { Screen } from "../shell";
import {
  Alert, Btn, Card, CachedTag, ConfidenceBadge, ConfirmDialog, DemoAiBadge, EmptyState, Icon, LoadingState, MatchBadge, Money, PesoInput, QuantityStepper, Segmented, SelectField, Sheet, StockBadge, TextField, WarningList, cx,
} from "../ui";

export function Pos() {
  const { profile, can } = useAuth();
  const sync = useSyncStatus();
  const active = useActive();
  const now = useNow(30_000);
  const { stores } = useStores();
  const cartApi = useCart();
  const { cart, ready, totals } = cartApi;
  const nav = useNavigate();
  const inv = useInventoryMap();

  const [tab, setTab] = useState<"products" | "cart">("products");
  const [search, setSearch] = useState("");
  const [category, setCategory] = useState<string | null>(null);
  const [notice, setNotice] = useState<{ tone: "info" | "warn"; text: string } | null>(null);
  const [scanOk, setScanOk] = useState(false);
  const [photoOpen, setPhotoOpen] = useState(false);
  const [clearAsk, setClearAsk] = useState(false);

  const storeId = cart.storeId ?? profile?.storeId ?? stores[0]?.id ?? null;
  const store = stores.find((s) => s.id === storeId);
  useEffect(() => { void isBarcodeScanSupported().then(setScanOk); }, []);

  const filters = storeId ? { store_id: storeId } : undefined;
  const results = useCacheList<TenantListingDto>("listing", { search, filters, orderBy: "search", limit: 120 });
  const everything = useCacheList<TenantListingDto>("listing", { filters, limit: 3000 });
  const categories = useMemo(() => [...new Set(everything.rows.map((l) => l.product?.category).filter((c): c is string => !!c))].sort(), [everything.rows]);
  const shown = category ? results.rows.filter((l) => l.product?.category === category) : results.rows;
  const firstLaunch = !!sync && !sync.snapshotComplete && everything.rows.length === 0;

  const addListing = (l: TenantListingDto) => {
    cartApi.add(l);
    setNotice({ tone: "info", text: `Added ${listingName(l)}.` });
  };

  const lookupExact = async (code: string): Promise<boolean> => {
    const c = code.trim();
    if (!c || !active) return false;
    // The same SKU/barcode is listed once per store, so look it up inside the selling store first (findByKey alone returns any store's row).
    const lc = c.toLowerCase();
    const inStore = storeId ? (await active.store.cache.list<TenantListingDto>("listing", { search: c, filters: { store_id: storeId }, limit: 50 })).find((x) => x.sku.toLowerCase() === lc || x.variant?.barcodes?.some((b) => b.code.toLowerCase() === lc)) : null;
    const l = inStore ?? (await active.store.cache.findByKey<TenantListingDto>("listing", c));
    if (!l) return false;
    if (storeId && l.store_id !== storeId) { setNotice({ tone: "warn", text: `${listingName(l)} is not sold at ${store?.name ?? "this store"}.` }); return true; }
    addListing(l);
    setSearch("");
    return true;
  };
  const onSubmit = async (e: FormEvent) => {
    e.preventDefault();
    if (!search.trim()) return;
    if (!(await lookupExact(search))) setNotice({ tone: "info", text: "No exact SKU or barcode match — showing name matches below." });
  };
  const onScan = async () => {
    try {
      const code = await scanBarcode();
      if (!(await lookupExact(code))) { setSearch(code); setNotice({ tone: "warn", text: `Scanned ${code}, but no item in this store has that barcode.` }); }
    } catch (e) { const t = photoErrorText(e); if (t) setNotice({ tone: "warn", text: t }); }
  };

  if (!ready) return <Screen title="Point of sale"><LoadingState /></Screen>;

  return (
    <Screen title="Point of sale" right={store ? <span className="hidden text-xs text-slate-600 min-[380px]:inline">{store.name}</span> : undefined}>
      {profile?.storeId == null && stores.length > 1 && (
        <SelectField label="Selling at" value={storeId ?? ""} disabled={cart.lines.length > 0} hint={cart.lines.length ? "Clear the cart to switch stores." : undefined} onChange={(e) => cartApi.setStore(Number(e.target.value))}>
          {stores.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
        </SelectField>
      )}

      <Segmented label="Sale view" value={tab} onChange={(t) => { setNotice(null); setTab(t); }} options={[{ value: "products", label: "Products" }, { value: "cart", label: `Cart (${totals.count})` }]} />
      {tab === "products" ? (
        <>
          <form onSubmit={(e) => void onSubmit(e)} className="space-y-2" role="search">
            <TextField label="Find a product" type="search" enterKeyHint="search" placeholder="Name, brand, SKU or barcode" value={search} onChange={(e) => setSearch(e.target.value)} hint="Press Enter on an exact SKU or barcode to add it straight away." />
            <div className="grid grid-cols-2 gap-2">
              <Btn type="button" variant="secondary" disabled={!scanOk} onClick={() => void onScan()}><Icon name="scan" />{scanOk ? "Scan barcode" : "Scan (phone only)"}</Btn>
              <Btn type="button" variant="secondary" onClick={() => setPhotoOpen(true)}><Icon name="camera" />Photo search</Btn>
            </div>
          </form>

          {categories.length > 0 && (
            <div className="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1" role="group" aria-label="Categories">
              {[null, ...categories].map((c) => (
                <button key={c ?? "all"} type="button" aria-pressed={category === c} onClick={() => setCategory(c)}
                  className={cx("min-h-11 shrink-0 rounded-full border px-4 text-sm font-semibold", category === c ? "border-orange-700 bg-orange-700 text-white" : "border-slate-300 bg-white text-slate-800")}>{c ?? "All"}</button>
              ))}
            </div>
          )}

          <p className="text-xs text-slate-600">Prices and stock are from your saved data{sync?.lastSyncedAt ? <> · <CachedTag at={sync.lastSyncedAt} now={now} /></> : null}</p>

          {results.loading ? <LoadingState label="Searching saved products…" />
            : firstLaunch ? <EmptyState title="Catalogue not downloaded yet">Connect to the internet once so products and prices can be saved on this phone.</EmptyState>
            : !storeId ? <EmptyState title="No store available">Your account has no store to sell from.</EmptyState>
            : shown.length === 0 ? <EmptyState title="No products match">{search ? "Check the spelling, or try the SKU or barcode." : "Nothing is listed for this store yet."}</EmptyState>
            : (
              <ul className="space-y-2" aria-label="Products">
                {shown.map((l) => {
                  const it = inv.map.get(l.inventory_item_id);
                  const inCart = cart.lines.find((x) => x.listingId === l.id)?.qty ?? 0;
                  return (
                    <li key={l.id}>
                      <Card className="flex items-start gap-3 p-3">
                        <div className="min-w-0 flex-1 space-y-1">
                          <p className="font-semibold text-slate-900">{listingName(l)}</p>
                          <p className="text-xs text-slate-600">{[l.product?.brand, l.sku].filter(Boolean).join(" · ")}</p>
                          <div className="flex flex-wrap items-center gap-2">
                            <span className="font-bold"><Money cents={listingPrice(l)} /></span>
                            {l.sale_price_cents != null && <span className="text-xs text-slate-500 line-through"><Money cents={l.price_cents} /></span>}
                          </div>
                          <StockBadge available={l.available_quantity} low={it?.low_stock} cached />
                        </div>
                        <Btn className="shrink-0" aria-label={`Add ${listingName(l)} to cart`} onClick={() => addListing(l)}>{inCart ? `+1 (${inCart})` : "Add"}</Btn>
                      </Card>
                    </li>
                  );
                })}
              </ul>
            )}

          <div className="sticky bottom-16 z-10 space-y-2" aria-live="polite">
            {notice && <Alert tone={notice.tone} className="shadow">{notice.text}</Alert>}
            {totals.count > 0 && (
              <Btn block variant="success" className="min-h-14 shadow-lg" onClick={() => setTab("cart")}>View cart · {totals.count} item{totals.count === 1 ? "" : "s"} · {formatPeso(totals.total)}</Btn>
            )}
          </div>
        </>
      ) : (
        <CartView cartApi={cartApi} canDiscount={can("pos.discount")} onClear={() => setClearAsk(true)} onCheckout={() => nav("/pos/checkout")} />
      )}

      <PhotoSearchSheet open={photoOpen} onClose={() => setPhotoOpen(false)} storeId={storeId} onAdd={(l) => { addListing(l); }} />
      <ConfirmDialog open={clearAsk} title="Clear the cart?" danger confirmLabel="Clear cart" onCancel={() => setClearAsk(false)} onConfirm={() => { void cartApi.clear(); setClearAsk(false); }}>
        <p>All {totals.count} item{totals.count === 1 ? "" : "s"} will be removed. Stock is not affected.</p>
      </ConfirmDialog>
    </Screen>
  );
}

function CartView({ cartApi, canDiscount, onClear, onCheckout }: { cartApi: ReturnType<typeof useCart>; canDiscount: boolean; onClear: () => void; onCheckout: () => void }) {
  const { cart, totals, vatBps } = cartApi;
  if (cart.lines.length === 0) return <EmptyState title="The cart is empty">Search or scan a product, then tap Add.</EmptyState>;
  return (
    <div className="space-y-3">
      <ul className="space-y-2" aria-label="Cart lines">
        {cart.lines.map((l) => <li key={l.listingId}><CartRow line={l} cartApi={cartApi} canDiscount={canDiscount} /></li>)}
      </ul>
      <Card className="space-y-1 text-sm">
        <div className="flex justify-between"><span>Subtotal</span><Money cents={totals.gross} /></div>
        {totals.discount > 0 && <div className="flex justify-between text-emerald-800"><span>Discount</span><span>−<Money cents={totals.discount} /></span></div>}
        <div className="flex justify-between border-t border-slate-200 pt-2 text-lg font-bold"><span>Total</span><Money cents={totals.total} /></div>
        <p className="text-xs text-slate-600">Prices include {(vatBps / 100).toFixed(0)}% VAT — about {formatPeso(totals.vat)} of the total. The receipt shows the server's final figure.</p>
      </Card>
      <p className="text-xs text-slate-600">The cart is saved on this phone. Adding items here does not take them out of stock — only a confirmed checkout does.</p>
      <Btn block variant="success" className="min-h-14 text-base" onClick={onCheckout}>Checkout · {formatPeso(totals.total)}</Btn>
      <Btn block variant="ghost" onClick={onClear}><Icon name="trash" className="h-4 w-4" />Clear cart</Btn>
    </div>
  );
}

function CartRow({ line, cartApi, canDiscount }: { line: CartLine; cartApi: ReturnType<typeof useCart>; canDiscount: boolean }) {
  const { item: cached } = useCacheItem<TenantListingDto>("listing", line.listingId);
  const inv = useInventoryMap();
  const sync = useSyncStatus();
  const now = useNow(60_000);
  const avail = cached?.available_quantity;
  const it = inv.map.get(line.inventoryItemId);
  const currentPrice = cached ? listingPrice(cached) : null;
  const priceChanged = currentPrice !== null && currentPrice !== line.unitPriceCents;
  const over = avail !== undefined && line.qty > avail;
  const lowAfter = !over && avail !== undefined && it && avail - line.qty <= it.reorder_threshold;

  return (
    <Card className="space-y-2 p-3">
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="font-semibold text-slate-900">{line.name}</p>
          <p className="text-xs text-slate-600">{line.sku} · <Money cents={line.unitPriceCents} /> each</p>
        </div>
        <button type="button" aria-label={`Remove ${line.name}`} onClick={() => cartApi.remove(line.listingId)} className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg text-red-700 hover:bg-red-50"><Icon name="trash" /></button>
      </div>
      <div className="flex items-center justify-between gap-2">
        <QuantityStepper label={`Quantity of ${line.name}`} value={line.qty} onChange={(n) => cartApi.patch(line.listingId, { qty: n })} />
        <p className="text-lg font-bold"><Money cents={line.unitPriceCents * line.qty - line.discountCents} /></p>
      </div>
      {canDiscount && <PesoInput label="Discount on this line (₱)" cents={line.discountCents || null} optional onChange={(c) => cartApi.patch(line.listingId, { discountCents: Math.min(c ?? 0, line.unitPriceCents * line.qty) })} />}
      {avail !== undefined && <p className="text-xs text-slate-600">{avail} in stock <CachedTag at={sync?.lastSyncedAt} now={now} /></p>}
      {over && <Alert tone="warn">Saved stock shows only {avail}. The server will refuse the sale if that is still true at checkout.</Alert>}
      {lowAfter && <Alert tone="info">This leaves {avail! - line.qty} — at or below the reorder level.</Alert>}
      {priceChanged && (
        <Alert tone="warn">The price is now <strong>{formatPeso(currentPrice)}</strong> (you added it at {formatPeso(line.unitPriceCents)}).{" "}
          <button type="button" className="font-semibold underline" onClick={() => cartApi.patch(line.listingId, { unitPriceCents: currentPrice! })}>Use the new price</button></Alert>
      )}
    </Card>
  );
}

function PhotoSearchSheet({ open, onClose, storeId, onAdd }: { open: boolean; onClose: () => void; storeId: number | null; onAdd: (l: TenantListingDto) => void }) {
  const { api } = useServices();
  const { online } = useOnline();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [res, setRes] = useState<PosPhotoSearchResult | null>(null);
  const [added, setAdded] = useState<number[]>([]);

  useEffect(() => { if (!open) { setRes(null); setError(null); setAdded([]); } }, [open]);

  const search = async (blob: Blob, hint?: string) => {
    setBusy(true); setError(null); setRes(null);
    try {
      const small = await downscaleImage(blob);
      setRes(await api.posPhotoSearch(small, newId(), storeId ?? undefined, hint));
    } catch (e) { setError(humanizeError(e)); } finally { setBusy(false); }
  };
  const a = res?.analysis;
  const stocked = res?.results.filter((r) => r.listing) ?? [];

  return (
    <Sheet open={open} onClose={onClose} title="Search by photo">
      {!online && <Alert tone="warn">Photo search needs a connection. Search by name, SKU or barcode instead — that works offline.</Alert>}
      {!res && <PhotoPicker onPhoto={search} busy={busy || !online} cameraLabel="Take photo" />}
      {busy && <LoadingState label="Looking for matches in your store…" />}
      {error && <Alert tone="error">{error}</Alert>}
      {res && a && (
        <div className="space-y-3" aria-live="polite">
          <div className="flex flex-wrap items-center gap-2">
            {a.is_mock && <DemoAiBadge label={a.provider_label} />}
            <MatchBadge value={a.classification} />
            <ConfidenceBadge value={a.overall_confidence} label="Overall" />
          </div>
          <p className="text-sm text-slate-700">Looks like: <strong>{[a.extracted.brand, a.extracted.name, a.extracted.model].filter(Boolean).join(" ") || "unknown item"}</strong></p>
          <WarningList warnings={a.warnings} />
          {stocked.length === 0 ? <EmptyState title="Your store doesn't seem to carry this">Try another photo or search by name.</EmptyState> : (
            <>
              <p className="text-sm font-semibold text-slate-900">{stocked.length > 1 || a.classification !== "exact_match" ? "Choose the right one before adding:" : "Best match:"}</p>
              <ul className="space-y-2">
                {res.results.map((r) => (
                  <li key={r.rank}>
                    <Card className="space-y-1 p-3">
                      {r.listing ? (
                        <>
                          <div className="flex items-start justify-between gap-2">
                            <div className="min-w-0">
                              <p className="font-semibold">{listingName(r.listing)}</p>
                              <p className="text-xs text-slate-600">{r.listing.sku} · {Object.entries(r.listing.variant?.attributes ?? {}).map(([k, v]) => `${k.replace(/_/g, " ")}: ${v}`).join(", ") || r.listing.variant?.name}</p>
                            </div>
                            <ConfidenceBadge value={r.score} label="Match" />
                          </div>
                          <div className="flex flex-wrap items-center gap-2"><MatchBadge value={r.classification} /><span className="font-bold"><Money cents={listingPrice(r.listing)} /></span><StockBadge available={r.listing.available_quantity} /></div>
                          {r.warnings.length > 0 && <p className="text-xs text-amber-900">{r.warnings.join(" · ")}</p>}
                          <Btn block variant={added.includes(r.listing.id) ? "secondary" : "primary"} onClick={() => { onAdd(r.listing!); setAdded((p) => [...p, r.listing!.id]); }}>{added.includes(r.listing.id) ? "Added — add another" : "Add this one to the cart"}</Btn>
                        </>
                      ) : <p className="text-sm text-slate-700">A catalogue match that your store doesn't stock (score {Math.round(r.score * 100)}%).</p>}
                    </Card>
                  </li>
                ))}
              </ul>
            </>
          )}
          <div className="flex gap-2"><Btn variant="secondary" className="flex-1" onClick={() => { setRes(null); setAdded([]); }}>Try another photo</Btn><Btn className="flex-1" onClick={onClose}>Done</Btn></div>
        </div>
      )}
      <p className="text-xs text-slate-600">Photos are sent to the server for matching. Results are limited to products your store carries.</p>
    </Sheet>
  );
}
