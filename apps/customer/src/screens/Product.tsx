import { useMemo, useState } from "react";
import { useToast } from "@hardwhere/shared/ui";
import { Link, useLocation, useNavigate, useParams } from "react-router-dom";
import {
  callPhone, formatAge, formatKm, humanizeError, openDirections, useAuth, useCacheItem, useCacheList, useNow, useOnlineQuery, useSyncStatus,
  type Attrs, type ProductDto, type PublicListingDto, type PublicStoreDto, type VariantDto,
} from "@hardwhere/shared";
import { useCart } from "../cart";
import { Btn, Card, EmptyState, ErrorState, LoadingState, Money, Notice, PhotoThumb, StockBadge, cx, inputCls } from "../components/ui";
import { useOnline } from "../hooks";
import { imgUrl } from "../config";
import { useShopperLocation, type Geo } from "../location";

const ATTR_LABEL: Record<string, string> = { voltage_v: "Voltage", wattage_w: "Power", chuck_mm: "Chuck size", weight_oz: "Weight", kit: "Kit", size: "Size", material: "Material", capacity_ml: "Capacity" };
const ATTR_UNIT: Record<string, string> = { voltage_v: "V", wattage_w: "W", chuck_mm: " mm", weight_oz: " oz", capacity_ml: " ml" };
const attrEntries = (a: Attrs | undefined) => Object.entries(a ?? {}).map(([k, v]) => [ATTR_LABEL[k] ?? k.replace(/_/g, " "), `${v}${ATTR_UNIT[k] ?? ""}`] as const);
const attrSummary = (a: Attrs | undefined) => attrEntries(a).map(([, v]) => v).join(" · ");

export const effectivePrice = (l: Pick<PublicListingDto, "price_cents" | "sale_price_cents">) => l.sale_price_cents != null && l.sale_price_cents < l.price_cents ? l.sale_price_cents : l.price_cents;

const DAY: Record<string, string> = { mon: "Mon", tue: "Tue", wed: "Wed", thu: "Thu", fri: "Fri", sat: "Sat", sun: "Sun", "mon-sat": "Mon–Sat", "mon-fri": "Mon–Fri" };
export const formatHours = (h: PublicStoreDto["hours"]) =>
  Object.entries(h ?? {}).filter(([, v]) => v && v.length >= 2).map(([d, v]) => `${DAY[d] ?? d} ${v![0]}–${v![1]}`).join(" · ");

export function ProductScreen() {
  const id = Number(useParams().id);
  const online = useOnline();
  const sync = useSyncStatus();
  const now = useNow(15_000);
  const prodQ = useOnlineQuery((api) => api.publicProduct(id), [id], { enabled: online });
  const cached = useCacheItem<ProductDto>("product", id);
  const product = prodQ.data?.data ?? cached.item;

  const { geo } = useShopperLocation();
  const listQ = useOnlineQuery((api) => api.publicProductListings(id, geo ?? undefined), [id, geo?.lat, geo?.lng], { enabled: online });

  if (!product) {
    if (prodQ.loading || cached.loading) return <LoadingState label="Loading product…" />;
    return <ErrorState title="Product not available" message={prodQ.message ?? (online ? "We couldn't find that product." : "This product isn't in your saved catalogue. Connect to the internet to view it.")} onRetry={online ? () => void prodQ.refetch() : undefined} />;
  }

  const live = online && listQ.data;
  return (
    <div className="space-y-3 p-3">
      <ProductHeader product={product} />
      <ProductBody
        product={product}
        geo={geo}
        listings={live ? listQ.data!.data : null}
        asOf={live ? listQ.data!.meta.server_time : null}
        loading={online && listQ.loading && !listQ.data}
        error={online && !listQ.data ? listQ.message : null}
        retry={() => void listQ.refetch()}
        cacheAge={sync?.lastSyncedAt ? formatAge(sync.lastSyncedAt, now) : null}
      />
    </div>
  );
}

function ProductHeader({ product }: { product: ProductDto }) {
  const nav = useNavigate();
  const imgs = product.images ?? [];
  const [sel, setSel] = useState(0);
  return (
    <>
      <button type="button" onClick={() => nav(-1)} className="touch-target text-sm font-medium text-brand-ink">‹ Back to results</button>
      <div className="overflow-hidden rounded-card bg-surface shadow-sm">
        <div className="flex h-48 items-center justify-center overflow-hidden bg-surface-2">
          {imgs[sel] ? <img src={imgUrl(imgs[sel].url)!} alt={imgs[sel].alt ?? product.name} className="h-48 w-full object-contain" /> : <span aria-hidden className="text-5xl">🔧</span>}
        </div>
        {imgs.length > 1 && (
          <div className="flex gap-2 p-2">
            {imgs.map((im, i) => (
              <button key={i} type="button" aria-label={`Show image ${i + 1}`} aria-pressed={i === sel} onClick={() => setSel(i)} className={cx("rounded-control border-2", i === sel ? "border-primary-600" : "border-transparent")}>
                <PhotoThumb src={im.url} alt="" size={44} />
              </button>
            ))}
          </div>
        )}
        <div className="space-y-1 p-3">
          <p className="text-xs tracking-wide text-ink-muted uppercase">{[product.category?.name, product.brand].filter(Boolean).join(" · ")}</p>
          <h1 className="text-xl leading-tight font-bold text-ink">{product.name}</h1>
          {product.model_number && <p className="text-sm text-ink-muted">Model {product.model_number}</p>}
        </div>
      </div>
    </>
  );
}

function ProductBody(props: {
  product: ProductDto; geo: Geo | null;
  listings: PublicListingDto[] | null; asOf: string | null; loading: boolean; error: string | null; retry: () => void; cacheAge: string | null;
}) {
  const { product } = props;
  const now = useNow(15_000);
  // Offline / failed: pull this product's listings from the local cache (labelled stale).
  const useCacheFallback = props.listings == null && !props.loading;
  return (
    <>
      {useCacheFallback ? (
        <CachedStores {...props} />
      ) : props.loading ? (
        <LoadingState label="Comparing stores…" />
      ) : (
        <Stores product={product} listings={props.listings!} geo={props.geo}
          stamp={<p className="text-xs text-ink-muted" aria-live="polite">Prices and stock as of {props.asOf ? formatAge(props.asOf, now) : "just now"} · {formatTimeShort(props.asOf)}</p>} />
      )}
      <Specs product={product} />
    </>
  );
}

const formatTimeShort = (iso: string | null) => (iso ? new Date(iso).toLocaleTimeString("en-PH", { hour: "numeric", minute: "2-digit", timeZone: "Asia/Manila" }) : "");

function CachedStores(p: { product: ProductDto; error: string | null; retry: () => void; cacheAge: string | null }) {
  const { rows, loading } = useCacheList<PublicListingDto>("listing", { limit: 1000 });
  const mine = useMemo(() => rows.filter((l) => l.product_id === p.product.id), [rows, p.product.id]);
  if (loading) return <LoadingState />;
  return (
    <>
      <Notice tone="warn">
        {p.error ? `${p.error} ` : "You're offline. "}Showing a saved copy{p.cacheAge ? ` from ${p.cacheAge}` : ""}. Prices and stock may be out of date; confirm online before ordering.
        {p.error && <button type="button" className="ml-2 font-semibold underline" onClick={p.retry}>Retry</button>}
      </Notice>
      {mine.length === 0 ? <EmptyState title="No saved store prices">No saved copy shows a store selling this product. Connect to check which registered stores sell it now.</EmptyState>
        : <Stores product={p.product} listings={mine} geo={null} stale staleAge={p.cacheAge} stamp={null} />}
    </>
  );
}

function variantsOf(product: ProductDto, listings: PublicListingDto[]): VariantDto[] {
  const byId = new Map<number, VariantDto>();
  (product.variants ?? []).forEach((v) => byId.set(v.id, v));
  listings.forEach((l) => l.variant && !byId.has(l.variant.id) && byId.set(l.variant.id, l.variant));
  return [...byId.values()];
}

function Stores({ product, listings, geo, stale, staleAge, stamp }: {
  product: ProductDto; listings: PublicListingDto[]; geo: Geo | null; stale?: boolean; staleAge?: string | null; stamp: React.ReactNode;
}) {
  const loc = useShopperLocation();
  const variants = variantsOf(product, listings);
  const withListings = variants.filter((v) => listings.some((l) => l.variant_id === v.id));
  const [variantId, setVariantId] = useState<number | null>(null);
  const selected = variantId ?? withListings[0]?.id ?? null;
  const [area, setArea] = useState("");

  const shown = useMemo(() => {
    const a = area.trim().toLowerCase();
    return listings
      .filter((l) => l.variant_id === selected)
      .filter((l) => !a || [l.store?.city, l.store?.province, l.store?.barangay, l.store?.name, l.store?.business_name, l.store?.address_line].some((s) => s?.toLowerCase().includes(a)))
      .sort((x, y) => Number(x.availability.status === "out_of_stock") - Number(y.availability.status === "out_of_stock") || (x.distance_km ?? 1e9) - (y.distance_km ?? 1e9) || effectivePrice(x) - effectivePrice(y));
  }, [listings, selected, area]);
  const cheapest = useMemo(() => {
    const inStock = shown.filter((l) => l.availability.status !== "out_of_stock");
    return inStock.length ? inStock.reduce((m, l) => (effectivePrice(l) < effectivePrice(m) ? l : m)).id : null;
  }, [shown]);

  const sel = variants.find((v) => v.id === selected);
  // closest registered store that has this exact version in stock (any version, if none of the selected one is in stock)
  const nearest = useMemo(() => {
    if (!geo || stale) return null;
    const pick = (ls: PublicListingDto[]) => ls.filter((l) => l.availability.status !== "out_of_stock" && l.distance_km != null).sort((a, b) => a.distance_km! - b.distance_km!)[0] ?? null;
    return pick(listings.filter((l) => l.variant_id === selected)) ?? pick(listings);
  }, [geo, stale, listings, selected]);

  if (listings.length === 0) {
    return (
      <section aria-label="Compare stores" className="space-y-3">
        <h2 className="text-base font-bold text-ink">Compare stores</h2>
        {stamp}
        <EmptyState title="No store is selling this yet">No registered store on Hardwhere is selling this product right now. Check back later, or search for a similar product.</EmptyState>
      </section>
    );
  }

  return (
    <section aria-label="Compare stores" className="space-y-3">
      <h2 className="text-base font-bold text-ink">Compare stores</h2>
      <p className="text-sm text-ink-muted">{new Set(listings.map((l) => l.store?.id)).size} registered {new Set(listings.map((l) => l.store?.id)).size === 1 ? "store sells" : "stores sell"} this product.</p>
      {nearest && (
        <Card className="space-y-1 border-info bg-info-soft">
          <p className="text-xs font-semibold tracking-wide text-info-ink uppercase">Nearest store with it in stock</p>
          <p className="text-sm font-bold text-ink"><Link to={`/store/${nearest.store?.id}`} className="underline">{nearest.store?.business_name} · {nearest.store?.name}</Link></p>
          <p className="text-sm text-ink">{formatKm(nearest.distance_km)} away · <Money cents={effectivePrice(nearest)} className="font-bold" />{nearest.variant_id !== selected ? ` · ${nearest.variant?.model_number ?? nearest.title ?? "other version"}` : ""}</p>
        </Card>
      )}
      {geo && !stale && !nearest && <Notice tone="warn">No store near you has this in stock right now.</Notice>}

      {variants.length > 1 && (
        <div role="radiogroup" aria-label="Choose a version" className="space-y-2">
          <p className="text-xs text-ink-muted">This product comes in different versions. Prices differ, so pick the exact one you need.</p>
          <div className="flex flex-wrap gap-2">
            {variants.map((v) => {
              const n = listings.filter((l) => l.variant_id === v.id).length;
              return (
                <button key={v.id} type="button" role="radio" aria-checked={selected === v.id} disabled={n === 0} onClick={() => setVariantId(v.id)}
                  className={cx("touch-target rounded-control border px-3 py-1 text-left text-sm", selected === v.id ? "border-primary-600 bg-warn-soft font-semibold text-warn-ink" : "border-line bg-surface text-ink", n === 0 && "opacity-50")}>
                  {v.model_number ?? v.name}
                  <span className="block text-xs font-normal text-ink-muted">{attrSummary(v.attributes)}{v.pack_quantity > 1 ? ` · pack of ${v.pack_quantity}` : ""} · {n === 0 ? "not listed" : `${n} ${n === 1 ? "store" : "stores"}`}</span>
                </button>
              );
            })}
          </div>
        </div>
      )}
      {sel && <p className="text-sm font-medium text-ink">{sel.name}</p>}

      <div className="flex flex-wrap items-end gap-2">
        {!stale && (
          <Btn variant="secondary" busy={loc.busy} onClick={() => void loc.locate()}>
            {geo ? "Location on · refresh" : "Use my location"}
          </Btn>
        )}
        <div className="min-w-36 flex-1">
          <label htmlFor="area" className="sr-only">Filter by city or area</label>
          <input id="area" value={area} onChange={(e) => setArea(e.target.value)} placeholder="Filter by city or area" className={inputCls} />
        </div>
      </div>
      {loc.error && <Notice tone="warn">{loc.error}</Notice>}
      {geo && !stale && <p className="text-xs text-ink-muted">Sorted by distance from your approximate location.</p>}
      {stamp}

      {shown.length === 0 ? (
        <EmptyState title={area ? "No stores in that area" : "No store sells this version"}>{area ? "No registered store in that area sells this. Try a different city or clear the filter." : "No registered store is selling this exact version right now. Try another version above."}</EmptyState>
      ) : shown.map((l) => <StoreCard key={l.id} l={l} cheapest={l.id === cheapest && shown.length > 1} stale={stale} staleAge={staleAge} />)}
    </section>
  );
}

function StoreCard({ l, cheapest, stale, staleAge }: { l: PublicListingDto; cheapest: boolean; stale?: boolean; staleAge?: string | null }) {
  const s = l.store;
  const auth = useAuth();
  const cart = useCart();
  const nav = useNavigate();
  const loc = useLocation();
  const toast = useToast();
  const [err, setErr] = useState<string | null>(null);
  const onSale = l.sale_price_cents != null && l.sale_price_cents < l.price_cents;
  const inCart = cart.lines.find((x) => x.listing_id === l.id)?.quantity;
  const out = l.availability.status === "out_of_stock";

  const add = () => {
    if (auth.status !== "signed_in" && auth.status !== "expired") { nav(`/login?next=${encodeURIComponent(loc.pathname)}`); return; }
    cart.add(l, l.min_order_qty);
    toast.success(`Added to cart${l.min_order_qty > 1 ? ` (minimum ${l.min_order_qty})` : ""}`);
  };
  const safely = (fn: () => void) => { try { setErr(null); fn(); } catch (e) { setErr(humanizeError(e)); } };

  return (
    <Card className={cx("space-y-2", cheapest && "border-success ring-1 ring-success")}>
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          {s ? <Link to={`/store/${s.id}`} className="block truncate text-sm font-bold text-ink underline decoration-line underline-offset-2">{s.business_name ?? "Store"}</Link> : <p className="truncate text-sm font-bold text-ink">Store</p>}
          <p className="truncate text-sm text-ink">{s?.name}</p>
        </div>
        <div className="text-right">
          {onSale && <Money cents={l.price_cents} strike className="block text-xs" />}
          <Money cents={effectivePrice(l)} className="text-lg font-extrabold text-brand-ink" />
          {onSale && <span className="block text-xs font-semibold text-danger-ink">On sale</span>}
          {cheapest && !onSale && <span className="block text-xs font-semibold text-success-ink">Lowest price</span>}
        </div>
      </div>
      <div className="flex flex-wrap items-center gap-1.5">
        <StockBadge status={l.availability.status} quantity={l.availability.quantity} cachedAsOf={stale ? (staleAge ?? "an earlier sync") : null} />
        {l.pickup_available && <span className="rounded-full bg-surface-2 px-2 py-0.5 text-xs font-medium">Pickup</span>}
        {l.delivery_available && <span className="rounded-full bg-surface-2 px-2 py-0.5 text-xs font-medium">Delivery</span>}
        {(l.min_order_qty > 1 || l.max_order_qty) && <span className="rounded-full bg-surface-2 px-2 py-0.5 text-xs">{l.min_order_qty > 1 ? `Min ${l.min_order_qty}` : ""}{l.min_order_qty > 1 && l.max_order_qty ? " · " : ""}{l.max_order_qty ? `Max ${l.max_order_qty}` : ""}</span>}
        {l.lead_time_hours > 0 && <span className="rounded-full bg-surface-2 px-2 py-0.5 text-xs">Ready in {l.lead_time_hours} h</span>}
        {l.distance_km != null && <span className="rounded-full bg-info-soft px-2 py-0.5 text-xs font-medium text-info-ink">{formatKm(l.distance_km)} away</span>}
      </div>
      {s && (
        <div className="text-xs text-ink-muted">
          <p>{[s.address_line, s.barangay, s.city, s.province].filter(Boolean).join(", ")}</p>
          {formatHours(s.hours) && <p>Hours: {formatHours(s.hours)}</p>}
          {s.phone && <p>Phone: {s.phone}</p>}
        </div>
      )}
      {err && <Notice tone="error">{err}</Notice>}
      <div className="grid grid-cols-[1fr_0.8fr_1.2fr] gap-2">
        <Btn variant="secondary" className="px-2" disabled={!s?.directions_url} onClick={() => safely(() => openDirections(s!.directions_url))}>Directions</Btn>
        <Btn variant="secondary" className="px-2" disabled={!s?.phone} onClick={() => safely(() => callPhone(s!.phone!))}>Call</Btn>
        <Btn className="px-2 whitespace-nowrap" disabled={out || stale} onClick={add} aria-label={`Add ${l.title ?? "item"} from ${s?.name ?? "store"} to cart`}>{out ? "Sold out" : "Add to cart"}</Btn>
      </div>
      {stale && !out && <p className="text-xs text-ink-muted">Reconnect to add this to your cart.</p>}
      {inCart ? <p className="text-xs font-medium text-success-ink">{inCart} in your cart · <Link to="/cart" className="underline">View cart</Link></p> : null}
    </Card>
  );
}

function Specs({ product }: { product: ProductDto }) {
  const rows: [string, string][] = [
    ...attrEntries(product.attributes),
    ...Object.entries(product.ph_specs ?? {}).map(([k, v]) => [k.replace(/_/g, " ").replace(/^./, (c) => c.toUpperCase()), String(v)] as [string, string]),
    ...(product.warranty ? [["Warranty", product.warranty] as [string, string]] : []),
    ...(product.manufacturer && product.manufacturer !== product.brand ? [["Manufacturer", product.manufacturer] as [string, string]] : []),
  ].map(([k, v]) => [k.charAt(0).toUpperCase() + k.slice(1), v]);
  if (!rows.length && !product.safety_info && !product.description) return null;
  return (
    <section aria-label="Specifications" className="space-y-2">
      <h2 className="text-base font-bold text-ink">Details</h2>
      <Card>
        {product.description && <p className="mb-2 text-sm text-ink">{product.description}</p>}
        {rows.length > 0 && (
          <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
            {rows.map(([k, v]) => (<div key={k} className="contents"><dt className="text-ink-muted">{k}</dt><dd className="text-right font-medium">{v}</dd></div>))}
          </dl>
        )}
        {product.safety_info && <p className="mt-2 rounded-md bg-warn-soft p-2 text-xs text-warn-ink"><strong>Safety:</strong> {product.safety_info}</p>}
      </Card>
    </section>
  );
}
