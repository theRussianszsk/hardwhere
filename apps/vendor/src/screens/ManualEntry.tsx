import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { humanizeError, newId, useActive, useCacheList, useServices, type ProductDto, type VariantDto } from "@hardwhere/shared";
import { CMD_INVENTORY_CREATE } from "../config";
import { CommandStatus } from "../lib/CommandStatus";
import { useDraft, useOnline } from "../lib/hooks";
import { EMPTY_INV, invProblems, suggestSku, TenantStockForm, type InvForm } from "../lib/TenantStockForm";
import { Screen } from "../shell";
import { Alert, Btn, Card, EmptyState, LoadingState, TextField } from "../ui";

interface Pick { productName: string; brand: string | null; variantId: number; variantName: string; model: string | null; attrs: string }
interface ManualDraft { pick: Pick | null; inv: InvForm }
interface Sent { id: string; title: string }
const EMPTY: ManualDraft = { pick: null, inv: EMPTY_INV };
/** Entries of each queued add, keyed by outbox id, kept until the server confirms it so a refused one (e.g. SKU taken) can be fixed and resent. */
const SENT_KIND = "intake-manual-sent";

const attrText = (a: VariantDto["attributes"]) => Object.entries(a ?? {}).map(([k, v]) => `${k.replace(/_/g, " ")}: ${v}`).join(", ");
const withBrand = (brand: string | null, name: string) => (brand && !name.toLowerCase().startsWith(brand.toLowerCase()) ? `${brand} ${name}` : name);
const toPick = (p: ProductDto, v: VariantDto): Pick => ({ productName: p.name, brand: p.brand, variantId: v.id, variantName: v.name, model: v.model_number ?? p.model_number, attrs: attrText(v.attributes) });

/** No photo: pick an existing catalogue variant (live search online, saved catalogue offline), fill the stock form, queue `inventory.create`. */
export function ManualEntry() {
  const active = useActive();
  const { online } = useOnline();
  const draft = useDraft<ManualDraft>("intake-manual", "current", EMPTY);
  const { pick, inv } = draft.value;
  const [q, setQ] = useState("");
  const [liveHits, setLiveHits] = useState<Pick[] | null>(null);
  const [searching, setSearching] = useState(false);
  const [searchErr, setSearchErr] = useState<string | null>(null);
  const [sent, setSent] = useState<Sent | null>(null);
  const [failed, setFailed] = useState<Sent[]>([]);
  const [submitErr, setSubmitErr] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const { api } = useServices();

  // Offline path: the saved catalogue (the `product` cache entity carries its variants).
  const cachedProducts = useCacheList<ProductDto>("product", { search: q, orderBy: "search", limit: 25 });
  const cachedHits: Pick[] = cachedProducts.rows.flatMap((p) => (p.variants ?? []).map((v) => toPick(p, v))).slice(0, 25);

  useEffect(() => {
    setSearchErr(null);
    if (!online || q.trim().length < 2) { setLiveHits(null); return; }
    let alive = true;
    const t = setTimeout(async () => {
      setSearching(true);
      try { const r = await api.catalogSearch(q.trim()); if (alive) setLiveHits(r.data.map((x) => toPick(x.product, x.variant))); }
      catch (e) { if (alive) { setLiveHits(null); setSearchErr(humanizeError(e)); } }
      finally { if (alive) setSearching(false); }
    }, 300);
    return () => { alive = false; clearTimeout(t); };
  }, [q, online, api]);

  // Drop saved entries once their add is confirmed (or discarded); surface the ones the server refused.
  useEffect(() => {
    if (!active) return;
    let alive = true;
    const scan = async () => {
      const keep: Sent[] = [];
      for (const r of await active.store.drafts.list<Sent & ManualDraft>(SENT_KIND)) {
        const o = await active.store.outbox.get(r.id);
        if (!o || o.state === "confirmed") await active.store.drafts.remove(SENT_KIND, r.id);
        else if (o.state === "needs_review" || o.state === "rejected") keep.push({ id: r.id, title: r.payload.title });
      }
      if (alive) setFailed(keep);
    };
    void scan();
    const off = active.store.onChange((e) => { if (e.area === "outbox") void scan(); });
    return () => { alive = false; off(); };
  }, [active]);

  const editAgain = async (id: string) => {
    if (!active) return;
    const saved = await active.store.drafts.get<Sent & ManualDraft>(SENT_KIND, id);
    if (saved) draft.set({ pick: saved.pick, inv: saved.inv });
    await active.store.outbox.discard(id);
    await active.store.drafts.remove(SENT_KIND, id);
    setSent(null);
  };

  const hits = liveHits ?? (q.trim().length >= 1 ? cachedHits : []);
  const problems = invProblems(inv);
  const ok = Object.keys(problems).length === 0 && pick !== null;

  const choose = (p: Pick) => draft.set({ pick: p, inv: { ...inv, sku: inv.sku || suggestSku(p.brand, p.model, p.productName) } });

  const save = async () => {
    if (!active || !pick || !ok) return;
    setBusy(true); setSubmitErr(null);
    try {
      const title = `Add to stock: ${withBrand(pick.brand, pick.variantName)} ×${inv.qty}`;
      const row = await active.store.outbox.enqueue(CMD_INVENTORY_CREATE, {
        variant_id: pick.variantId, tenant_sku: inv.sku.trim(), cost_cents: inv.cost, price_cents: inv.price, quantity: inv.qty, reorder_threshold: inv.reorder,
        store_id: inv.storeId, ...(inv.locationId ? { location_id: inv.locationId } : {}),
      }, { id: newId(), label: title });
      await active.store.drafts.put(SENT_KIND, row.id, { id: row.id, title, pick, inv });
      void active.kickOutbox();
      setSent({ id: row.id, title });
      await draft.clear();
    } catch (e) { setSubmitErr(humanizeError(e)); } finally { setBusy(false); }
  };

  if (!draft.ready) return <Screen title="Manual entry" back="/add"><LoadingState /></Screen>;

  return (
    <Screen title="Manual entry" back="/add">
      {sent ? (
        <>
          <CommandStatus id={sent.id} title={sent.title} confirmedText="Added to stock — the server confirmed it." onConfirmed={() => void active?.syncNow()} onDismiss={() => setSent(null)} />
          {failed.some((f) => f.id === sent.id) && <Btn block onClick={() => void editAgain(sent.id)}>Fix the entries and send again</Btn>}
          <div className="grid grid-cols-2 gap-2"><Btn variant="secondary" onClick={() => setSent(null)}>Add another</Btn><Link to="/stock"><Btn block>Go to stock</Btn></Link></div>
        </>
      ) : (
        <>
          {failed.map((f) => (
            <div key={f.id} className="space-y-2">
              <CommandStatus id={f.id} title={f.title} />
              <Btn block variant="secondary" onClick={() => void editAgain(f.id)}>Fix the entries and send again</Btn>
            </div>
          ))}
          <Alert tone="info">{online ? "Search the Hardwhere catalogue for the exact product." : "You're offline: searching the catalogue saved on this phone. The item is queued and added when you're back online."} Your entries are saved as a draft.</Alert>

          {!pick ? (
            <>
              <TextField label="Search the catalogue" type="search" placeholder="Brand, name or model" value={q} onChange={(e) => setQ(e.target.value)} />
              {searching && <LoadingState label="Searching…" />}
              {searchErr && <Alert tone="warn">{searchErr} Showing saved catalogue results.</Alert>}
              {q.trim() && hits.length === 0 && !searching && <EmptyState title="Nothing found">Try fewer words or the model number. A brand-new product needs the photo flow ("create a new catalogue product"), which needs a connection.</EmptyState>}
              <ul className="space-y-2">
                {hits.map((h) => (
                  <li key={h.variantId}>
                    <button type="button" onClick={() => choose(h)} className="block min-h-14 w-full rounded-xl border border-slate-200 bg-white p-3 text-left shadow-sm hover:bg-slate-50">
                      <p className="font-semibold text-slate-900">{withBrand(h.brand, h.productName)}</p>
                      <p className="text-sm text-slate-700">{h.variantName}</p>
                      <p className="text-xs text-slate-600">{[h.model, h.attrs].filter(Boolean).join(" · ")}</p>
                    </button>
                  </li>
                ))}
              </ul>
            </>
          ) : (
            <>
              <Card className="flex items-start justify-between gap-2">
                <div><p className="font-semibold">{withBrand(pick.brand, pick.productName)}</p><p className="text-sm text-slate-700">{pick.variantName}</p><p className="text-xs text-slate-600">{[pick.model, pick.attrs].filter(Boolean).join(" · ")}</p></div>
                <Btn variant="ghost" onClick={() => draft.set({ ...draft.value, pick: null })}>Change</Btn>
              </Card>
              <TenantStockForm value={inv} onChange={(v) => draft.set({ pick, inv: v })} errors={problems} />
              {submitErr && <Alert tone="error">{submitErr}</Alert>}
              <Btn block busy={busy} disabled={!ok} onClick={() => void save()}>{online ? "Add to stock" : "Save — add when online"}</Btn>
              <p className="text-xs text-slate-600">Queued first, then confirmed by the server. If this store already stocks this product the server will say so and nothing is duplicated.</p>
            </>
          )}
        </>
      )}
    </Screen>
  );
}
