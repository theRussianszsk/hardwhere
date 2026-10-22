import { useMemo, useState, type FormEvent } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import { formatPeso, parsePesoToCents, useOnlineQuery, type PublicSearchQuery, type SearchSort } from "@hardwhere/shared";
import { Btn, Card, FormField, Notice, PageTitle, cx, inputCls } from "../components/ui";
import { useOnline } from "../hooks";
import { useShopperLocation } from "../location";
import { LocationToggle, ResultsList, useCategories, useProductSearch } from "./Search";

const DISTANCES = [2, 5, 10, 25, 50];
const SPEC = "spec.";

interface Form { q: string; category: string; brand: string; model: string; min: string; max: string; spec: Record<string, string>; inStock: boolean; fulfillment: "" | "pickup" | "delivery"; km: string; sort: SearchSort }

const fromParams = (p: URLSearchParams): Form => ({
  q: p.get("q") ?? "", category: p.get("cat") ?? "", brand: p.get("brand") ?? "", model: p.get("model") ?? "", min: p.get("min") ?? "", max: p.get("max") ?? "",
  spec: Object.fromEntries([...p.entries()].filter(([k, v]) => k.startsWith(SPEC) && v).map(([k, v]) => [k.slice(SPEC.length), v])),
  inStock: p.get("stock") === "1", fulfillment: (["pickup", "delivery"].includes(p.get("ful") ?? "") ? p.get("ful") : "") as Form["fulfillment"],
  km: p.get("km") ?? "", sort: (p.get("sort") as SearchSort) || "relevance",
});

const toParams = (f: Form): URLSearchParams => {
  const n = new URLSearchParams({ run: "1" });
  const put = (k: string, v: string) => v.trim() && n.set(k, v.trim());
  put("q", f.q); put("cat", f.category); put("brand", f.brand); put("model", f.model); put("min", f.min); put("max", f.max); put("km", f.km);
  if (f.inStock) n.set("stock", "1");
  if (f.fulfillment) n.set("ful", f.fulfillment);
  if (f.sort !== "relevance") n.set("sort", f.sort);
  Object.entries(f.spec).forEach(([k, v]) => v && n.set(SPEC + k, v));
  return n;
};

/** Advanced search: every filter is explicit. Listing-level filters (price, stock, pickup/delivery, distance) must all hold at the same store. */
export function DetailedSearchScreen() {
  const nav = useNavigate();
  const online = useOnline();
  const [params, setParams] = useSearchParams();
  const applied = useMemo(() => fromParams(params), [params]);
  const [f, setF] = useState<Form>(applied);
  const [errs, setErrs] = useState<Record<string, string>>({});
  const { geo } = useShopperLocation();
  const { all: categories } = useCategories();
  const filters = useOnlineQuery((api) => api.publicSearchFilters(f.category || undefined), [f.category], { enabled: online });
  const brands = filters.data?.data.brands ?? [];
  const specs = filters.data?.data.specs ?? [];
  const set = <K extends keyof Form>(k: K, v: Form[K]) => setF((p) => ({ ...p, [k]: v }));

  const tops = categories.filter((c) => c.parent_id == null).sort((a, b) => a.sort - b.sort);
  const childrenOf = (id: number) => categories.filter((c) => c.parent_id === id).sort((a, b) => a.sort - b.sort);

  const query = useMemo<PublicSearchQuery>(() => {
    const minC = applied.min ? parsePesoToCents(applied.min) : null;
    const maxC = applied.max ? parsePesoToCents(applied.max) : null;
    const km = Number(applied.km);
    return {
      q: applied.q || undefined, category: applied.category || undefined, brand: applied.brand || undefined, model: applied.model || undefined,
      min_price_cents: minC ?? undefined, max_price_cents: maxC ?? undefined, in_stock: applied.inStock || undefined, fulfillment: applied.fulfillment || undefined,
      spec: Object.keys(applied.spec).length ? applied.spec : undefined,
      sort: applied.sort === "nearest" && !geo ? undefined : applied.sort === "relevance" ? undefined : applied.sort,
      ...(geo ? { lat: geo.lat, lng: geo.lng, ...(km > 0 ? { max_km: km } : {}) } : {}),
    };
  }, [applied, geo]);
  const ran = params.get("run") === "1";
  const search = useProductSearch(query, ran);

  const submit = (e: FormEvent) => {
    e.preventDefault();
    const er: Record<string, string> = {};
    const minC = f.min.trim() ? parsePesoToCents(f.min) : null;
    const maxC = f.max.trim() ? parsePesoToCents(f.max) : null;
    if (f.min.trim() && minC == null) er.min = "Enter an amount, like 500.";
    if (f.max.trim() && maxC == null) er.max = "Enter an amount, like 5,000.";
    if (minC != null && maxC != null && maxC < minC) er.max = "The maximum must be at least the minimum.";
    setErrs(er);
    if (Object.keys(er).length) return;
    setParams(toParams(f), { replace: false });
  };
  const reset = () => { const blank = fromParams(new URLSearchParams()); setF(blank); setErrs({}); setParams(new URLSearchParams(), { replace: true }); };

  const chips = [
    applied.q && `“${applied.q}”`, applied.category && categories.find((c) => c.slug === applied.category)?.name, applied.brand, applied.model && `Model ${applied.model}`,
    (applied.min || applied.max) && `${applied.min ? formatPeso(parsePesoToCents(applied.min) ?? 0) : "₱0"} – ${applied.max ? formatPeso(parsePesoToCents(applied.max) ?? 0) : "any"}`,
    ...Object.entries(applied.spec).map(([k, v]) => { const s = specs.find((x) => x.key === k); return `${s?.label ?? k}: ${v}${s?.unit ? ` ${s.unit}` : ""}`; }),
    applied.inStock && "In stock", applied.fulfillment && (applied.fulfillment === "pickup" ? "Pickup" : "Delivery"), geo && applied.km && `Within ${applied.km} km`,
  ].filter(Boolean) as string[];

  return (
    <div className="space-y-3 p-3">
      <PageTitle title="Detailed search" back={() => nav(`/${applied.q ? `?q=${encodeURIComponent(applied.q)}` : ""}`)} />
      {!online && <Notice tone="warn">Detailed search needs a connection. The basic search works offline with saved results.</Notice>}
      <form onSubmit={submit} noValidate aria-label="Detailed search" className="space-y-3">
        <Card className="space-y-3">
          <FormField label="Keywords">{(p) => <input {...p} type="search" value={f.q} onChange={(e) => set("q", e.target.value)} placeholder="e.g. cordless drill" className={inputCls} />}</FormField>
          <FormField label="Category">
            {(p) => (
              <select {...p} value={f.category} onChange={(e) => setF((x) => ({ ...x, category: e.target.value, brand: "", spec: {} }))} className={inputCls}>
                <option value="">Any category</option>
                {tops.map((c) => [
                  <option key={c.slug} value={c.slug}>{c.name}</option>,
                  ...childrenOf(c.id).map((s) => <option key={s.slug} value={s.slug}>{"  "}{s.name}</option>),
                ])}
              </select>
            )}
          </FormField>
          <div className="grid grid-cols-2 gap-2">
            <FormField label="Brand">
              {(p) => (
                <select {...p} value={f.brand} onChange={(e) => set("brand", e.target.value)} className={inputCls}>
                  <option value="">Any brand</option>
                  {f.brand && !brands.includes(f.brand) && <option value={f.brand}>{f.brand}</option>}
                  {brands.map((b) => <option key={b} value={b}>{b}</option>)}
                </select>
              )}
            </FormField>
            <FormField label="Model / part no.">{(p) => <input {...p} value={f.model} onChange={(e) => set("model", e.target.value)} placeholder="e.g. HP1630" autoCapitalize="characters" className={inputCls} />}</FormField>
          </div>
          <div className="grid grid-cols-2 gap-2">
            <FormField label="Min price (₱)" error={errs.min}>{(p) => <input {...p} value={f.min} onChange={(e) => set("min", e.target.value)} inputMode="decimal" placeholder="0" className={inputCls} />}</FormField>
            <FormField label="Max price (₱)" error={errs.max}>{(p) => <input {...p} value={f.max} onChange={(e) => set("max", e.target.value)} inputMode="decimal" placeholder="Any" className={inputCls} />}</FormField>
          </div>
        </Card>

        {specs.length > 0 && (
          <Card className="space-y-2">
            <h2 className="text-sm font-bold">Specifications</h2>
            <p className="text-xs text-ink-muted">Only values that stores currently sell are listed.</p>
            <div className="grid grid-cols-2 gap-2">
              {specs.map((s) => (
                <FormField key={s.key} label={`${s.label}${s.unit ? ` (${s.unit})` : ""}`}>
                  {(p) => (
                    <select {...p} value={f.spec[s.key] ?? ""} onChange={(e) => setF((x) => ({ ...x, spec: { ...x.spec, [s.key]: e.target.value } }))} className={inputCls}>
                      <option value="">Any</option>
                      {s.values.map((v) => <option key={v} value={v}>{v}{s.unit ? ` ${s.unit}` : ""}</option>)}
                    </select>
                  )}
                </FormField>
              ))}
            </div>
          </Card>
        )}

        <Card className="space-y-3">
          <h2 className="text-sm font-bold">Availability and distance</h2>
          <label className="flex touch-target items-center gap-2 text-sm"><input type="checkbox" checked={f.inStock} onChange={(e) => set("inStock", e.target.checked)} className="h-5 w-5 rounded" /> In stock only</label>
          <fieldset className="space-y-1">
            <legend className="text-sm text-ink-muted">How you'll get it</legend>
            <div className="grid grid-cols-3 gap-2">
              {([["", "Any"], ["pickup", "Pickup"], ["delivery", "Delivery"]] as const).map(([v, label]) => (
                <label key={v} className={cx("flex touch-target cursor-pointer items-center justify-center rounded-control border text-sm font-medium", f.fulfillment === v ? "border-primary-600 bg-warn-soft text-warn-ink" : "border-line bg-surface")}>
                  <input type="radio" name="ful" className="sr-only" checked={f.fulfillment === v} onChange={() => set("fulfillment", v)} />{label}
                </label>
              ))}
            </div>
          </fieldset>
          <LocationToggle compact />
          <div className="grid grid-cols-2 gap-2">
            <FormField label="Distance" hint={geo ? undefined : "Turn on location to use this."}>
              {(p) => (
                <select {...p} value={geo ? f.km : ""} disabled={!geo} onChange={(e) => set("km", e.target.value)} className={inputCls}>
                  <option value="">Any distance</option>
                  {DISTANCES.map((d) => <option key={d} value={d}>Within {d} km</option>)}
                </select>
              )}
            </FormField>
            <FormField label="Sort by">
              {(p) => (
                <select {...p} value={f.sort === "nearest" && !geo ? "relevance" : f.sort} onChange={(e) => set("sort", e.target.value as SearchSort)} className={inputCls}>
                  <option value="relevance">{geo ? "Best match, nearest" : "Best match"}</option>
                  {geo && <option value="nearest">Nearest store</option>}
                  <option value="price_asc">Price: low to high</option>
                  <option value="price_desc">Price: high to low</option>
                </select>
              )}
            </FormField>
          </div>
        </Card>

        <div className="grid grid-cols-[1fr_2fr] gap-2">
          <Btn variant="secondary" onClick={reset}>Reset</Btn>
          <Btn type="submit" disabled={!online}>Search</Btn>
        </div>
      </form>

      {ran && (
        <section aria-label="Detailed search results" className="space-y-2 border-t border-line pt-3">
          {chips.length > 0 && (
            <ul className="flex flex-wrap gap-1.5" aria-label="Applied filters">
              {chips.map((c) => <li key={c} className="rounded-full bg-surface-2 px-2 py-0.5 text-xs font-medium">{c}</li>)}
            </ul>
          )}
          <ResultsList s={search} q={applied.q} onClear={reset} filtered={chips.length > (applied.q ? 1 : 0)} />
        </section>
      )}
    </div>
  );
}
