import { useCallback, useEffect, useRef, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { useToast } from "@hardwhere/shared/ui";
import {
  callPhone, formatAge, formatKm, humanizeError, openDirections, useAuth, useCacheItem, useCacheList, useNow, useOnlineQuery, useServices, useSyncStatus,
  type PublicListingDto, type PublicStoreDto,
} from "@hardwhere/shared";
import { useCart } from "../cart";
import { Btn, Card, EmptyState, ErrorState, LoadingState, Money, Notice, PageTitle, PhotoThumb, StockBadge, cx, inputCls } from "../components/ui";
import { useOnline } from "../hooks";
import { useShopperLocation } from "../location";
import { effectivePrice, formatHours } from "./Product";
import { LocationToggle } from "./Search";

const address = (s: PublicStoreDto) => [s.address_line, s.barangay, s.city, s.province].filter(Boolean).join(", ");

function useDebounced(v: string, ms = 350) {
  const [d, setD] = useState(v);
  useEffect(() => { const t = setTimeout(() => setD(v.trim()), ms); return () => clearTimeout(t); }, [v, ms]);
  return d;
}

// ───────────────────────── directory ─────────────────────────
export function StoresScreen() {
  const online = useOnline();
  const { api } = useServices();
  const { geo } = useShopperLocation();
  const now = useNow(15_000);
  const [text, setText] = useState("");
  const q = useDebounced(text);
  const [pg, setPg] = useState<{ items: PublicStoreDto[]; page: number; last: number; total: number; at: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  const seq = useRef(0);

  const load = useCallback(async (page: number) => {
    const my = ++seq.current;
    setBusy(true);
    try {
      const r = await api.publicStores({ q: q || undefined, ...(geo ?? {}), page, per_page: 20 });
      if (my !== seq.current) return;
      setErr(null);
      setPg((p) => ({ items: page === 1 || !p ? r.data : [...p.items, ...r.data.filter((s) => !p.items.some((x) => x.id === s.id))], page: r.meta.current_page, last: r.meta.last_page, total: r.meta.total, at: r.meta_extra?.server_time ?? new Date().toISOString() }));
    } catch (e) {
      if (my === seq.current) setErr(humanizeError(e));
    } finally { if (my === seq.current) setBusy(false); }
  }, [api, q, geo]);
  useEffect(() => { if (online) void load(1); }, [online, load]);

  return (
    <div className="space-y-3 p-3">
      <h1 className="text-lg font-bold">Hardware stores</h1>
      <div>
        <label htmlFor="store-q" className="sr-only">Search stores</label>
        <input id="store-q" type="search" value={text} onChange={(e) => setText(e.target.value)} placeholder="Store name, city or barangay" className={inputCls} />
      </div>
      <LocationToggle compact />
      {!online ? <CachedStoreList q={q} /> : err && !pg ? <ErrorState message={err} onRetry={() => void load(1)} /> : !pg ? <LoadingState label="Finding stores…" /> : pg.items.length === 0 ? (
        <EmptyState title="No stores found" action={q ? <Btn variant="secondary" onClick={() => setText("")}>Clear search</Btn> : undefined}>{q ? `No registered store matches “${q}”.` : "No stores are on Hardwhere yet."}</EmptyState>
      ) : (
        <ul className="space-y-2" aria-label="Stores" aria-live="polite">
          <li className="text-xs text-ink-muted">{pg.total} {pg.total === 1 ? "store" : "stores"}{geo ? " · nearest first" : ""} · Updated {formatAge(pg.at, now)}</li>
          {err && <li><Notice tone="warn">Couldn't load more: {err}</Notice></li>}
          {pg.items.map((s) => <li key={s.id}><StoreRow s={s} /></li>)}
          {pg.page < pg.last && <li><Btn variant="secondary" block busy={busy} onClick={() => void load(pg.page + 1)}>Load more</Btn></li>}
        </ul>
      )}
    </div>
  );
}

function StoreRow({ s, cached }: { s: PublicStoreDto; cached?: boolean }) {
  return (
    <Link to={`/store/${s.id}`} className="block space-y-1 rounded-card border border-line bg-surface p-3 shadow-sm active:bg-canvas">
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="truncate text-sm font-bold">{s.business_name ?? s.name}</p>
          <p className="truncate text-sm text-ink">{s.name}</p>
        </div>
        {s.distance_km != null && <span className="shrink-0 rounded-full bg-info-soft px-2 py-0.5 text-xs font-medium text-info-ink">{formatKm(s.distance_km)}</span>}
      </div>
      <p className="truncate text-xs text-ink-muted">{address(s)}</p>
      <div className="flex flex-wrap gap-1.5 text-xs">
        {s.listing_count != null && <span className={cx("rounded-full px-2 py-0.5 font-medium", s.listing_count ? "bg-success-soft text-success-ink" : "bg-surface-2 text-ink-muted")}>{s.listing_count ? `${s.listing_count} ${s.listing_count === 1 ? "product" : "products"} for sale` : "Nothing listed yet"}</span>}
        {s.supports_pickup && <span className="rounded-full bg-surface-2 px-2 py-0.5">Pickup</span>}
        {s.supports_delivery && <span className="rounded-full bg-surface-2 px-2 py-0.5">Delivery</span>}
        {cached && <span className="rounded-full bg-warn-soft px-2 py-0.5 text-warn-ink">Saved copy</span>}
      </div>
    </Link>
  );
}

function CachedStoreList({ q }: { q: string }) {
  const sync = useSyncStatus();
  const now = useNow(15_000);
  const { rows, loading } = useCacheList<PublicStoreDto>("store", { limit: 500 });
  const needle = q.toLowerCase();
  const list = rows.filter((s) => !needle || [s.name, s.business_name, s.city, s.barangay, s.province].some((x) => x?.toLowerCase().includes(needle)));
  return (
    <section className="space-y-2" aria-label="Saved stores">
      <Notice tone="warn">You're offline. Showing saved stores{sync?.lastSyncedAt ? ` from ${formatAge(sync.lastSyncedAt, now)}` : ""}.</Notice>
      {loading ? <LoadingState /> : list.length === 0 ? <EmptyState title="No saved stores">Connect to the internet to see stores.</EmptyState> : list.map((s) => <StoreRow key={s.id} s={s} cached />)}
    </section>
  );
}

// ───────────────────────── one store ─────────────────────────
export function StoreScreen() {
  const id = Number(useParams().id);
  const nav = useNavigate();
  const online = useOnline();
  const { api } = useServices();
  const now = useNow(15_000);
  const storeQ = useOnlineQuery((a) => a.publicStore(id), [id], { enabled: online });
  const cached = useCacheItem<PublicStoreDto>("store", id);
  const store = storeQ.data?.data ?? cached.item;
  const [actionErr, setActionErr] = useState<string | null>(null);
  const [text, setText] = useState("");
  const q = useDebounced(text);
  const [pg, setPg] = useState<{ items: PublicListingDto[]; page: number; last: number; total: number; at: number } | null>(null);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  const seq = useRef(0);

  const load = useCallback(async (page: number) => {
    const my = ++seq.current;
    setBusy(true);
    try {
      const r = await api.publicStoreListings(id, page, q || undefined);
      if (my !== seq.current) return;
      setErr(null);
      setPg((p) => ({ items: page === 1 || !p ? r.data : [...p.items, ...r.data.filter((l) => !p.items.some((x) => x.id === l.id))], page: r.meta.current_page, last: r.meta.last_page, total: r.meta.total, at: Date.now() }));
    } catch (e) {
      if (my === seq.current) setErr(humanizeError(e));
    } finally { if (my === seq.current) setBusy(false); }
  }, [api, id, q]);
  useEffect(() => { if (online) void load(1); }, [online, load]);

  if (!store) {
    if (storeQ.loading || cached.loading) return <LoadingState label="Loading store…" />;
    return <ErrorState title="Store not available" message={storeQ.message ?? (online ? "This store isn't on Hardwhere any more." : "This store isn't saved on this device. Connect to the internet to view it.")} onRetry={online ? () => void storeQ.refetch() : undefined} />;
  }
  const safely = (fn: () => void) => { try { setActionErr(null); fn(); } catch (e) { setActionErr(humanizeError(e)); } };
  const hours = formatHours(store.hours);

  return (
    <div className="space-y-3 p-3">
      <PageTitle title={store.business_name ?? store.name} back={() => nav(-1)} />
      <Card className="space-y-2">
        <div>
          <p className="text-base font-bold">{store.name}</p>
          <p className="text-sm text-ink-muted">{address(store)}</p>
        </div>
        {hours && <p className="text-sm"><span className="text-ink-muted">Hours:</span> {hours}</p>}
        {store.phone && <p className="text-sm"><span className="text-ink-muted">Phone:</span> {store.phone}</p>}
        <div className="flex flex-wrap gap-1.5 text-xs">
          {store.supports_pickup && <span className="rounded-full bg-surface-2 px-2 py-0.5 font-medium">Pickup</span>}
          {store.supports_delivery && <span className="rounded-full bg-surface-2 px-2 py-0.5 font-medium">Delivery</span>}
        </div>
        {!storeQ.data && <Notice tone="warn">{online && storeQ.message ? `${storeQ.message} ` : "You're offline. "}Showing a saved copy.</Notice>}
        {actionErr && <Notice tone="error">{actionErr}</Notice>}
        <div className="grid grid-cols-2 gap-2">
          <Btn variant="secondary" onClick={() => safely(() => openDirections(store.directions_url))}>Directions</Btn>
          <Btn variant="secondary" disabled={!store.phone} onClick={() => safely(() => callPhone(store.phone!))}>Call store</Btn>
        </div>
      </Card>

      <section aria-label="Products at this store" className="space-y-2">
        <h2 className="text-base font-bold">Products at this store</h2>
        <label htmlFor="store-items-q" className="sr-only">Search this store</label>
        <input id="store-items-q" type="search" value={text} onChange={(e) => setText(e.target.value)} placeholder="Search this store" className={inputCls} />
        {!online ? <CachedStoreListings storeId={id} q={q} /> : err && !pg ? <ErrorState message={err} onRetry={() => void load(1)} /> : !pg ? <LoadingState label="Loading products…" /> : pg.items.length === 0 ? (
          <EmptyState title={q ? "Not sold here" : "Nothing listed yet"} action={q ? <Btn variant="secondary" onClick={() => setText("")}>Clear search</Btn> : undefined}>
            {q ? `This store isn't selling anything that matches “${q}”. Search all stores to find who sells it.` : "This store hasn't put any products on Hardwhere yet."}
          </EmptyState>
        ) : (
          <ul className="space-y-2" aria-live="polite">
            <li className="text-xs text-ink-muted">{pg.total} {pg.total === 1 ? "product" : "products"} · prices and stock as of {formatAge(pg.at, now)}</li>
            {err && <li><Notice tone="warn">Couldn't load more: {err}</Notice></li>}
            {pg.items.map((l) => <li key={l.id}><StoreListing l={l} /></li>)}
            {pg.page < pg.last && <li><Btn variant="secondary" block busy={busy} onClick={() => void load(pg.page + 1)}>Load more</Btn></li>}
          </ul>
        )}
        {q && pg && pg.items.length === 0 && online && <Btn block variant="ghost" onClick={() => nav(`/?q=${encodeURIComponent(q)}`)}>Search all stores for “{q}”</Btn>}
      </section>
    </div>
  );
}

function StoreListing({ l, stale }: { l: PublicListingDto; stale?: boolean }) {
  const auth = useAuth();
  const cart = useCart();
  const nav = useNavigate();
  const toast = useToast();
  const out = l.availability.status === "out_of_stock";
  const onSale = l.sale_price_cents != null && l.sale_price_cents < l.price_cents;
  const inCart = cart.lines.find((x) => x.listing_id === l.id)?.quantity;
  const add = () => {
    if (auth.status !== "signed_in" && auth.status !== "expired") { nav(`/login?next=${encodeURIComponent(`/store/${l.store?.id ?? ""}`)}`); return; }
    cart.add(l, l.min_order_qty);
    toast.success(`Added to cart${l.min_order_qty > 1 ? ` (minimum ${l.min_order_qty})` : ""}`);
  };
  return (
    <Card className="space-y-2">
      <div className="flex gap-3">
        <Link to={`/product/${l.product_id}`} aria-label={`View ${l.title ?? "product"}`}><PhotoThumb src={l.image_url} alt="" size={64} /></Link>
        <div className="min-w-0 flex-1">
          <Link to={`/product/${l.product_id}`} className="line-clamp-2 text-sm font-semibold text-ink">{l.title ?? l.variant?.name}</Link>
          <p className="text-xs text-ink-muted">SKU {l.sku}{l.uom ? ` · per ${l.uom}` : ""}</p>
          <div className="mt-1 flex items-baseline gap-2">
            <Money cents={effectivePrice(l)} className="text-base font-extrabold text-brand-ink" />
            {onSale && <Money cents={l.price_cents} strike className="text-xs" />}
          </div>
        </div>
      </div>
      <div className="flex flex-wrap items-center gap-1.5">
        <StockBadge status={l.availability.status} quantity={l.availability.quantity} cachedAsOf={stale ? "an earlier sync" : null} />
        {l.pickup_available && <span className="rounded-full bg-surface-2 px-2 py-0.5 text-xs font-medium">Pickup</span>}
        {l.delivery_available && <span className="rounded-full bg-surface-2 px-2 py-0.5 text-xs font-medium">Delivery</span>}
      </div>
      <div className="flex items-center justify-between gap-2">
        {inCart ? <p className="text-xs font-medium text-success-ink">{inCart} in cart · <Link to="/cart" className="underline">View</Link></p> : <span />}
        <Btn className="px-3" disabled={out || stale} onClick={add} aria-label={`Add ${l.title ?? "item"} to cart`}>{out ? "Sold out" : "Add to cart"}</Btn>
      </div>
    </Card>
  );
}

function CachedStoreListings({ storeId, q }: { storeId: number; q: string }) {
  const { rows, loading } = useCacheList<PublicListingDto>("listing", { limit: 1000 });
  const needle = q.toLowerCase();
  const list = rows.filter((l) => (l.store?.id ?? (l as { store_id?: number }).store_id) === storeId && (!needle || [l.title, l.sku, l.variant?.name].some((x) => x?.toLowerCase().includes(needle))));
  if (loading) return <LoadingState />;
  return (
    <div className="space-y-2">
      <Notice tone="warn">Offline: prices and stock may be out of date. Reconnect to order.</Notice>
      {list.length === 0 ? <EmptyState title="No saved products">This store's products aren't saved on this device.</EmptyState> : list.map((l) => <StoreListing key={l.id} l={l} stale />)}
    </div>
  );
}
