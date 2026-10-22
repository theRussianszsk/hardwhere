import { useCallback, useEffect, useRef, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { formatPeso, humanizeError, useCacheList, useNow, useOnlineQuery, useServices, useSyncStatus, formatAge, type CategoryDto, type ProductDto } from "@hardwhere/shared";
import { Btn, EmptyState, ErrorState, LoadingState, Notice, PhotoThumb, cx, inputCls } from "../components/ui";
import { useOnline } from "../hooks";

interface Page { items: ProductDto[]; page: number; last: number; total: number; serverTime: string | null }

export function priceRange(p: ProductDto): string {
  if (p.min_price_cents == null) return "See store prices";
  return p.max_price_cents != null && p.max_price_cents !== p.min_price_cents ? `${formatPeso(p.min_price_cents)} – ${formatPeso(p.max_price_cents)}` : formatPeso(p.min_price_cents);
}

export function ProductRow({ p, cached }: { p: ProductDto; cached?: boolean }) {
  return (
    <Link to={`/product/${p.id}`} className="flex min-h-20 gap-3 rounded-card border border-line bg-surface p-2 shadow-sm active:bg-canvas">
      <PhotoThumb src={p.images?.[0]?.url} alt={p.images?.[0]?.alt ?? p.name} size={72} />
      <div className="min-w-0 flex-1">
        <p className="line-clamp-2 text-sm leading-snug font-semibold text-ink">{p.name}</p>
        <p className="truncate text-xs text-ink-muted">{[p.brand, p.model_number].filter(Boolean).join(" · ") || p.category?.name}</p>
        {cached && p.min_price_cents == null ? (
          <p className="mt-1 text-xs text-ink-muted">Price and stores shown when online</p>
        ) : (
          <p className="mt-1 text-sm font-bold text-brand-ink">{priceRange(p)}</p>
        )}
        {p.store_count != null && <p className="text-xs text-ink-muted">{p.store_count} {p.store_count === 1 ? "store" : "stores"}</p>}
      </div>
    </Link>
  );
}

export function SearchScreen() {
  const online = useOnline();
  const [params, setParams] = useSearchParams();
  const initial = params.get("q") ?? "";
  const [text, setText] = useState(initial);
  const [q, setQ] = useState(initial);
  const [category, setCategory] = useState(params.get("cat") ?? "");
  const [sort, setSort] = useState("relevance");
  const [inStock, setInStock] = useState(false);
  const now = useNow(15_000);

  const cats = useOnlineQuery((api) => api.publicCategories(), [], { enabled: online });
  const cachedCats = useCacheList<CategoryDto>("category", { orderBy: "id", limit: 100 });
  const categories = (cats.data?.data ?? cachedCats.rows).filter((c) => c.parent_id == null).sort((a, b) => a.sort - b.sort);

  // debounce typing; keep the URL in sync so back-navigation restores the search
  useEffect(() => {
    const t = setTimeout(() => {
      setQ(text.trim());
      setParams((p) => { const n = new URLSearchParams(p); text.trim() ? n.set("q", text.trim()) : n.delete("q"); category ? n.set("cat", category) : n.delete("cat"); return n; }, { replace: true });
    }, 350);
    return () => clearTimeout(t);
  }, [text, category, setParams]);

  const [res, setRes] = useState<Page | null>(null);
  const [loading, setLoading] = useState(true);
  const [more, setMore] = useState(false);
  const [failed, setFailed] = useState<string | null>(null);
  const seq = useRef(0);

  const { api } = useServices();
  const load = useCallback(async (page: number) => {
    const my = ++seq.current;
    if (page === 1) setLoading(true); else setMore(true);
    try {
      const r = await api.publicSearch({ q: q || undefined, category: category || undefined, sort: sort === "relevance" ? undefined : sort, in_stock: inStock || undefined, page, per_page: 20 });
      if (my !== seq.current) return;
      setFailed(null);
      setRes((prev) => ({
        items: page === 1 || !prev ? r.data : [...prev.items, ...r.data.filter((d) => !prev.items.some((x) => x.id === d.id))],
        page: r.meta.current_page, last: r.meta.last_page, total: r.meta.total, serverTime: r.meta_extra?.server_time ?? new Date().toISOString(),
      }));
    } catch (e) {
      if (my !== seq.current) return;
      setFailed(humanizeError(e));
      if (page === 1) setRes(null);
    } finally {
      if (my === seq.current) { setLoading(false); setMore(false); }
    }
  }, [api, q, category, sort, inStock]);

  useEffect(() => {
    if (!online) { seq.current++; setFailed("offline"); setLoading(false); return; }
    void load(1);
  }, [load, online]);

  const showCache = failed !== null && (!res || !online);

  return (
    <div className="space-y-3 p-3">
      <form role="search" onSubmit={(e) => { e.preventDefault(); setQ(text.trim()); }} className="flex gap-2">
        <label htmlFor="q" className="sr-only">Search hardware</label>
        <input id="q" type="search" enterKeyHint="search" value={text} onChange={(e) => setText(e.target.value)} placeholder='Try "hammer" or "20V drill under 5000"' className={cx(inputCls, "flex-1")} />
        <Link to="/photo" aria-label="Search by photo" className="grid touch-target w-12 place-items-center rounded-control bg-ink text-canvas">
          <svg aria-hidden viewBox="0 0 24 24" className="h-6 w-6" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d="M4 8h3l2-3h6l2 3h3v11H4zM12 17a4 4 0 100-8 4 4 0 000 8z" /></svg>
        </Link>
      </form>

      <div className="-mx-3 flex gap-2 overflow-x-auto px-3 pb-1" role="group" aria-label="Categories">
        {[{ slug: "", name: "All" }, ...categories].map((c) => (
          <button key={c.slug} type="button" aria-pressed={category === c.slug} onClick={() => setCategory(c.slug)}
            className={cx("touch-target shrink-0 rounded-full border px-4 text-sm font-medium", category === c.slug ? "border-primary-600 bg-primary-600 text-white" : "border-line bg-surface text-ink")}>
            {c.name}
          </button>
        ))}
      </div>

      <div className="flex items-center gap-3 text-sm">
        <label className="flex touch-target items-center gap-2"><input type="checkbox" checked={inStock} onChange={(e) => setInStock(e.target.checked)} className="h-5 w-5 rounded" /> In stock only</label>
        <label className="ml-auto flex items-center gap-2">
          <span className="text-ink-muted">Sort</span>
          <select value={sort} onChange={(e) => setSort(e.target.value)} className="touch-target rounded-control border border-line bg-surface px-2 text-sm">
            <option value="relevance">Best match</option>
            <option value="price_asc">Price: low to high</option>
            <option value="price_desc">Price: high to low</option>
          </select>
        </label>
      </div>

      {showCache ? (
        <CachedResults q={q} category={category} offline={!online} reason={failed !== "offline" ? failed : null} />
      ) : loading && !res ? (
        <LoadingState label="Searching…" />
      ) : failed && !res ? (
        <ErrorState message={failed} onRetry={() => void load(1)} />
      ) : res && res.items.length === 0 ? (
        <EmptyState title="No matches" action={<Btn variant="secondary" onClick={() => { setText(""); setCategory(""); setInStock(false); }}>Clear search</Btn>}>
          Nothing found{q ? ` for “${q}”` : ""}. Try a brand, model number or a simpler word like “drill”.
        </EmptyState>
      ) : res ? (
        <section aria-live="polite" aria-label="Search results" className="space-y-2">
          <p className="text-xs text-ink-muted">
            {res.total} {res.total === 1 ? "result" : "results"} · Updated {res.serverTime ? formatAge(res.serverTime, now) : "just now"}
          </p>
          {failed && <Notice tone="warn">Couldn't load more: {failed}</Notice>}
          {res.items.map((p) => <ProductRow key={p.id} p={p} />)}
          {res.page < res.last && <Btn variant="secondary" block busy={more} onClick={() => void load(res.page + 1)}>Load more</Btn>}
        </section>
      ) : null}
    </div>
  );
}

function CachedResults({ q, category, offline, reason }: { q: string; category: string; offline: boolean; reason: string | null }) {
  const sync = useSyncStatus();
  const now = useNow(15_000);
  const { rows, loading } = useCacheList<ProductDto>("product", { search: q || undefined, orderBy: "search", limit: 60 });
  const list = category ? rows.filter((p) => p.category?.slug === category) : rows;
  return (
    <section aria-live="polite" aria-label="Cached results" className="space-y-2">
      <Notice tone="warn">
        {offline ? "You're offline. " : reason ? `${reason} ` : ""}Showing cached results{sync?.lastSyncedAt ? ` (cached ${formatAge(sync.lastSyncedAt, now)})` : ""}. Prices and stock may be out of date.
      </Notice>
      {loading ? <LoadingState /> : !list.length ? (
        sync && !sync.snapshotComplete
          ? <EmptyState title="No saved catalogue yet">The catalogue hasn't finished downloading. Connect to the internet and open the app once to save it for offline use.</EmptyState>
          : <EmptyState title="No cached matches">Nothing in the saved catalogue matches{q ? ` “${q}”` : ""}.</EmptyState>
      ) : list.map((p) => <ProductRow key={p.id} p={p} cached />)}
    </section>
  );
}

