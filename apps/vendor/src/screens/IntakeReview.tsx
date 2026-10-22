import { useEffect, useMemo, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import {
  ApiError, humanizeError, newId, useActive, useAuth, useOnlineQuery, useServices,
  useNow, type AiAnalysisDto, type AiAnalyzePayload, type ConfirmIntakeInput, type ExtractedProduct, type MatchCandidateDto,
} from "@hardwhere/shared";
import { PhotoThumb } from "@hardwhere/shared/ui";
import { lastErrorText } from "../lib/CommandStatus";
import { fieldError, useDraft, useOnline, useOutboxItem, useStoredPhoto } from "../lib/hooks";
import { EMPTY_INV, invProblems, suggestSku, TenantStockForm, type InvForm } from "../lib/TenantStockForm";
import { Screen } from "../shell";
import {
  Alert, Btn, Card, ConfidenceBadge, ConfirmDialog, DemoAiBadge, EmptyState, ErrorState, LoadingState, MatchBadge, Money, Pill, QuantityStepper, SelectField, TextField, WarningList, cx,
} from "../ui";

type Mode = "none" | "existing" | "new" | "reject";
interface NewProd { categoryId: number | null; name: string; brand: string; model: string; pack: number; barcode: string }
interface IntakeDraft { key: string; mode: Mode; variantId: number | null; decision: "confirmed_existing" | "selected_variant"; candidateRank: number | null; np: NewProd; inv: InvForm }
interface Done { kind: "added" | "rejected"; inventoryItemId: number | null; name: string; qty: number }

const pretty = (f: string) => f.replace(/^attr:/, "").replace(/_/g, " ");
const attrList = (a: Record<string, unknown> | undefined | null) => Object.entries(a ?? {}).map(([k, v]) => `${pretty(k)}: ${String(v)}`);
/** Barcode type by digit count. Must stay within the server's ProductBarcode::TYPES; the catalog only stores 8-14 digit codes. */
const barcodeType = (c: string) => ({ 8: "EAN8", 12: "UPC", 13: "EAN13", 14: "GTIN14" } as Record<number, string>)[c.length] ?? "OTHER";
const barcodeProblem = (c: string) => (c === "" || /^\d{8,14}$/.test(c) ? null : "A barcode is 8 to 14 digits. Leave it empty if you can't read it.");

function initialDraft(a: AiAnalysisDto): IntakeDraft {
  const x = a.extracted;
  return {
    key: newId(), mode: "none", variantId: null, decision: "selected_variant", candidateRank: null,
    np: { categoryId: null, name: x.name ?? "", brand: x.brand ?? "", model: x.model ?? "", pack: x.pack_quantity && x.pack_quantity > 0 ? x.pack_quantity : 1, barcode: x.barcode_text ?? "" },
    inv: { ...EMPTY_INV, sku: suggestSku(x.brand, x.model, x.name) },
  };
}

export function IntakeReview() {
  const id = useParams().id!;
  const { item, loading } = useOutboxItem<AiAnalysisDto, AiAnalyzePayload>(id);
  const active = useActive();
  const { online } = useOnline();
  const [done, setDone] = useState<Done | null>(null);
  const [busy, setBusy] = useState(false);
  const now = useNow(1000);
  const photo = useStoredPhoto(item?.payload?.photoRef);

  if (done) return <DoneView done={done} />;
  if (loading) return <Screen title="Review" back="/add"><LoadingState /></Screen>;
  if (!item) return <Screen title="Review" back="/add"><EmptyState title="This photo isn't on this phone" action={<Link to="/add"><Btn>Add another item</Btn></Link>}>It may have been finished or discarded already.</EmptyState></Screen>;

  if (item.state !== "confirmed" || !item.response) {
    const retry = async () => { setBusy(true); try { await active?.store.outbox.retryNow(id); await active?.kickOutbox(); } finally { setBusy(false); } };
    return (
      <Screen title="Analysing photo" back="/add">
        {photo && <PhotoThumb source={photo} alt="Your product photo" size={120} />}
        <div aria-live="polite" className="space-y-3">
          {item.state === "sending" && <LoadingState label={`The AI is reading your photo… ${Math.max(0, Math.round((now - new Date(item.updatedAt).getTime()) / 1000))}s (usually 3–10 s)`} />}
          {item.state === "queued" && (online ? <LoadingState label={item.attempts > 0 ? `Retrying (attempt ${item.attempts + 1})${item.nextAttemptAt ? ` in ${Math.max(0, Math.ceil((new Date(item.nextAttemptAt).getTime() - now) / 1000))}s` : ""}… tap Try again to send now` : "Sending your photo…"} /> : (
            <Alert tone="warn" live="assertive"><strong>Saved — it will be analysed when you're back online.</strong> You can leave this screen; the photo stays queued on this phone. Or enter the item yourself now.</Alert>
          ))}
          {(item.state === "needs_review" || item.state === "rejected") && (
            <Alert tone="error" live="assertive"><strong>The photo couldn't be analysed.</strong> {lastErrorText(item.lastError)}</Alert>
          )}
        </div>
        <div className="grid grid-cols-1 gap-2">
          {(item.state === "needs_review" || item.state === "rejected" || (item.state === "queued" && online)) && <Btn busy={busy} onClick={() => void retry()}>Try again</Btn>}
          <Link to="/add/manual"><Btn block variant="secondary">Enter the item manually instead</Btn></Link>
          <Btn variant="ghost" onClick={async () => { await active?.store.outbox.discard(id); window.location.hash = "#/add"; }}>Discard this photo</Btn>
        </div>
      </Screen>
    );
  }

  return <ReviewBody analysis={item.response} outboxId={id} photoRef={item.payload?.photoRef} photo={photo} onDone={setDone} />;
}

function DoneView({ done }: { done: Done }) {
  return (
    <Screen title={done.kind === "added" ? "Added to stock" : "Photo rejected"}>
      {done.kind === "added" ? (
        <>
          <Alert tone="success" live="assertive"><strong>{done.name}</strong> was added to stock — {done.qty} unit{done.qty === 1 ? "" : "s"}, confirmed by the server. It starts as a hidden draft listing until you publish it.</Alert>
          <div className="grid gap-2">
            {done.inventoryItemId && <Link to={`/stock/${done.inventoryItemId}`}><Btn block>View the item</Btn></Link>}
            <Link to="/add"><Btn block variant="secondary">Add another item</Btn></Link>
          </div>
        </>
      ) : (
        <>
          <Alert tone="info">Nothing was added. Your decision to reject the AI suggestion was recorded.</Alert>
          <Link to="/add"><Btn block>Add another item</Btn></Link>
        </>
      )}
    </Screen>
  );
}

function ReviewBody({ analysis, outboxId, photoRef, photo, onDone }: { analysis: AiAnalysisDto; outboxId: string; photoRef?: string; photo: Blob | null; onDone: (d: Done) => void }) {
  const { api, photos } = useServices();
  const active = useActive();
  const { profile } = useAuth();
  const { online } = useOnline();
  const nav = useNavigate();
  const initial = useMemo(() => initialDraft(analysis), [analysis]);
  const draft = useDraft<IntakeDraft>("intake-confirm", outboxId, initial);
  const d = draft.value;
  const [confirm, setConfirm] = useState(false);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<unknown>(null);

  const x = analysis.extracted;
  const cands = analysis.candidates ?? [];
  const chosen = cands.find((c) => c.rank === d.candidateRank) ?? null;
  const categories = useOnlineQuery((a) => a.catalogCategories(), [], { enabled: d.mode === "new" });
  const catList = categories.data?.data ?? [];

  // Pre-select the category the AI named, when the catalogue has one with that name.
  useEffect(() => {
    if (d.mode !== "new" || d.np.categoryId !== null || !x.category || !catList.length) return;
    const m = catList.find((c) => c.name.toLowerCase() === x.category!.toLowerCase());
    if (m) draft.set({ ...d, np: { ...d.np, categoryId: m.id } });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [catList.length, d.mode]);

  const set = (p: Partial<IntakeDraft>) => draft.set({ ...d, ...p });
  const chooseVariant = (c: MatchCandidateDto) => {
    if (!c.variant) return;
    const exact = c.classification === "exact_match" || c.classification === "likely_same_product";
    set({ mode: "existing", variantId: c.variant.id, decision: exact ? "confirmed_existing" : "selected_variant", candidateRank: c.rank, inv: { ...d.inv, sku: d.inv.sku || suggestSku(c.product.brand, c.variant.model_number ?? c.product.model_number, c.product.name) } });
  };

  const problems = invProblems(d.inv);
  const npProblems: string[] = [];
  if (d.mode === "new") { if (d.np.categoryId === null) npProblems.push("category"); if (!d.np.name.trim()) npProblems.push("name"); if (barcodeProblem(d.np.barcode)) npProblems.push("barcode"); }
  const canSubmit = d.mode === "reject" || (d.mode !== "none" && Object.keys(problems).length === 0 && npProblems.length === 0);
  const displayName = d.mode === "new" ? d.np.name || "this product" : chosen ? (chosen.variant?.name ?? chosen.product.name) : "this product";

  const body = (): ConfirmIntakeInput => {
    if (d.mode === "reject") return { decision: "rejected" };
    const i = d.inv;
    const inventory = {
      tenant_sku: i.sku.trim(), cost_cents: i.cost ?? 0, price_cents: i.price ?? 0, quantity: i.qty, reorder_threshold: i.reorder,
      store_id: i.storeId!, ...(i.locationId ? { location_id: i.locationId } : {}),
    };
    if (d.mode === "existing") return { decision: d.decision, variant_id: d.variantId!, inventory };
    const np = d.np;
    const attrs = Object.keys(x.attributes ?? {}).length ? x.attributes : undefined;
    return {
      decision: "created_candidate", inventory,
      new_product: {
        category_id: np.categoryId!, name: np.name.trim(), ...(np.brand.trim() ? { brand: np.brand.trim() } : {}), ...(np.model.trim() ? { model_number: np.model.trim() } : {}), ...(attrs ? { attributes: attrs } : {}),
        variants: [{ name: np.name.trim(), ...(np.model.trim() ? { model_number: np.model.trim() } : {}), ...(attrs ? { attributes: attrs } : {}), pack_quantity: np.pack, uom: "pc", ...(np.barcode.trim() ? { barcodes: { [np.barcode.trim()]: barcodeType(np.barcode.trim()) } } : {}) }],
      },
    };
  };

  const submit = async () => {
    if (!online) { setConfirm(false); setErr(new ApiError({ kind: "network", message: "Can't reach the server." })); return; }
    setBusy(true); setErr(null);
    try {
      // The idempotency key is generated once per review (kept in the draft), so a retry after a lost response cannot add the stock twice.
      const r = await api.aiConfirm(analysis.id, body(), d.key);
      const result: Done = r.data.decision === "rejected"
        ? { kind: "rejected", inventoryItemId: null, name: displayName, qty: 0 }
        : { kind: "added", inventoryItemId: r.data.inventory_item_id, name: displayName, qty: d.inv.qty };
      await draft.clear();
      await active?.store.outbox.discard(outboxId);
      if (photoRef) void photos.remove(photoRef).catch(() => undefined);
      void active?.syncNow();
      onDone(result);
    } catch (e) { setErr(e); setConfirm(false); } finally { setBusy(false); }
  };

  const apiErr = err instanceof ApiError ? err : null;
  const code = apiErr?.code;
  const serverFields = {
    sku: code === "sku_taken" ? apiErr!.message : fieldError(err, "inventory.tenant_sku"),
    cost: fieldError(err, "inventory.cost_cents"), price: fieldError(err, "inventory.price_cents"), qty: fieldError(err, "inventory.quantity"), storeId: fieldError(err, "inventory.store_id"),
  };

  if (!draft.ready) return <Screen title="Review" back="/add"><LoadingState /></Screen>;

  const confirmWarnings = [...new Set([...(chosen?.warnings ?? []), ...(chosen?.mismatched_fields.length ? [`Differs from the photo: ${chosen.mismatched_fields.map(pretty).join(", ")}`] : [])])];

  return (
    <Screen title="Review suggestion" back="/add">
      {/* ───────── what the AI saw ───────── */}
      <Card className="space-y-3">
        <div className="flex gap-3">
          {photo && <PhotoThumb source={photo} alt="Your product photo" size={88} />}
          <div className="min-w-0 space-y-1.5">
            {analysis.is_mock && <DemoAiBadge label={analysis.provider_label} />}
            {!analysis.is_mock && <p className="text-xs text-slate-600">{analysis.provider_label}</p>}
            <div className="flex flex-wrap items-center gap-1.5"><MatchBadge value={analysis.classification} /></div>
            <div className="flex items-center gap-1.5 text-sm text-slate-700">Overall <ConfidenceBadge value={analysis.overall_confidence} /></div>
          </div>
        </div>
        {!x.image_sufficient && <Alert tone="warn" live="assertive"><strong>The photo isn't clear enough.</strong> Retake it with the product label in focus, or enter the details yourself. <Link to="/add" className="font-semibold underline">Retake photo</Link></Alert>}
        <ExtractedTable x={x} />
        <WarningList warnings={analysis.warnings} title="Check before you continue" />
        {x.notes.length > 0 && <ul className="list-disc pl-5 text-xs text-slate-600">{x.notes.map((n) => <li key={n}>{n}</li>)}</ul>}
      </Card>

      {analysis.decided && <Alert tone="warn">This photo has already been reviewed. Remove it from your list from the Add screen.</Alert>}

      {/* ───────── candidates ───────── */}
      <section aria-label="Catalogue matches" className="space-y-2">
        <h2 className="text-base font-semibold text-slate-900">{cands.length ? "Possible matches in the catalogue" : "No catalogue match"}</h2>
        {analysis.requires_confirmation && <p className="text-sm text-slate-700">You must choose what to do — nothing is added automatically.</p>}
        {cands.length >= 2 && <VariantCompare cands={cands} x={x} />}
        {cands.map((c) => (
          <Card key={c.rank} className={cx("space-y-2", d.candidateRank === c.rank && d.mode === "existing" && "ring-2 ring-orange-700")}>
            <div className="flex items-start justify-between gap-2">
              <div className="min-w-0">
                <p className="text-xs font-semibold text-slate-600">#{c.rank} · match score {Math.round(c.score * 100)}%</p>
                <p className="font-semibold text-slate-900">{c.product.brand ? `${c.product.brand} ` : ""}{c.product.name}</p>
                <p className="text-sm text-slate-700">{c.variant ? c.variant.name : "No specific variant"}</p>
              </div>
              <MatchBadge value={c.classification} />
            </div>
            {c.variant && <p className="text-xs text-slate-600">{[c.variant.model_number && `Model ${c.variant.model_number}`, `Pack of ${c.variant.pack_quantity}`, ...attrList(c.variant.attributes), c.variant.barcodes?.length ? `Barcode ${c.variant.barcodes.map((b) => b.code).join(", ")}` : ""].filter(Boolean).join(" · ")}</p>}
            <div className="flex flex-wrap gap-1.5">
              {c.matched_fields.map((f) => <Pill key={`m-${f}`} tone="green">✓ {pretty(f)}</Pill>)}
              {c.mismatched_fields.map((f) => <Pill key={`x-${f}`} tone="red">✗ differs: {pretty(f)}</Pill>)}
            </div>
            {c.warnings.length > 0 && <WarningList warnings={c.warnings} title="About this match" />}
            <Btn block variant={d.mode === "existing" && d.candidateRank === c.rank ? "success" : c.rank === 1 ? "primary" : "secondary"} disabled={!c.variant || analysis.decided} onClick={() => chooseVariant(c)}>
              {d.mode === "existing" && d.candidateRank === c.rank ? "✓ Using this variant" : c.variant ? "Yes, use this variant" : "No variant to use"}
            </Btn>
          </Card>
        ))}
        <div className="grid grid-cols-1 gap-2 pt-1">
          <Btn variant={d.mode === "new" ? "success" : "secondary"} disabled={analysis.decided} onClick={() => set({ mode: "new", candidateRank: null, variantId: null })}>{d.mode === "new" ? "✓ Creating a new catalogue product" : "None of these — create a new catalogue product"}</Btn>
          <Btn variant={d.mode === "reject" ? "danger" : "ghost"} disabled={analysis.decided} onClick={() => set({ mode: "reject" })}>{d.mode === "reject" ? "✓ Rejecting this suggestion" : "Reject — this is wrong, don't add anything"}</Btn>
        </div>
      </section>

      {/* ───────── new catalogue product ───────── */}
      {d.mode === "new" && (
        <Card className="space-y-3">
          <h2 className="text-base font-semibold">New catalogue product</h2>
          <p className="text-sm text-slate-700">Submitted for review by Hardwhere; you can stock it right away. Check the AI's guesses.</p>
          {categories.error && !categories.data ? <ErrorState title="Couldn't load categories" message={categories.message ?? ""} onRetry={() => void categories.refetch()} />
            : (
              <SelectField label="Category" value={d.np.categoryId ?? ""} onChange={(e) => set({ np: { ...d.np, categoryId: e.target.value ? Number(e.target.value) : null } })} error={fieldError(err, "new_product.category_id") ?? (d.np.categoryId === null && catList.length ? "Choose a category." : null)} hint={x.category ? `The AI read: ${x.category}` : undefined}>
                <option value="">{categories.loading && !catList.length ? "Loading…" : "Choose a category…"}</option>
                {catList.filter((c) => c.parent_id === null).map((p) => (
                  <optgroup key={p.id} label={p.name}>
                    <option value={p.id}>{p.name}</option>
                    {catList.filter((c) => c.parent_id === p.id).map((c) => <option key={c.id} value={c.id}>{p.name} › {c.name}</option>)}
                  </optgroup>
                ))}
              </SelectField>
            )}
          <TextField label="Product name" value={d.np.name} onChange={(e) => set({ np: { ...d.np, name: e.target.value } })} error={fieldError(err, "new_product.name") ?? (!d.np.name.trim() ? "Enter a name." : null)} maxLength={160} />
          <div className="grid grid-cols-2 gap-3">
            <TextField label="Brand" value={d.np.brand} onChange={(e) => set({ np: { ...d.np, brand: e.target.value } })} maxLength={80} />
            <TextField label="Model" value={d.np.model} onChange={(e) => set({ np: { ...d.np, model: e.target.value } })} maxLength={80} />
          </div>
          <div className="space-y-1"><p className="text-sm font-medium text-slate-800">Pack quantity</p><QuantityStepper label="Pack quantity" value={d.np.pack} min={1} max={100000} onChange={(n) => set({ np: { ...d.np, pack: n } })} /></div>
          <TextField label="Barcode (optional)" inputMode="numeric" value={d.np.barcode} onChange={(e) => set({ np: { ...d.np, barcode: e.target.value.replace(/\s/g, "") } })} error={barcodeProblem(d.np.barcode) ?? (code === "barcode_conflict" ? apiErr!.message : null)} hint="Only if you can read it on the package." />
        </Card>
      )}

      {/* ───────── your stock ───────── */}
      {(d.mode === "existing" || d.mode === "new") && (
        <Card className="space-y-3">
          <h2 className="text-base font-semibold">Your stock details</h2>
          <TenantStockForm value={d.inv} onChange={(v) => set({ inv: v })} errors={problems} serverFields={serverFields} />
        </Card>
      )}

      {/* ───────── errors ───────── */}
      {err != null && (
        <div className="space-y-2" aria-live="assertive">
          {code === "already_stocked" ? (
            <Alert tone="warn"><strong>This store already stocks this product.</strong> Adding it again would duplicate it.{" "}
              {typeof apiErr!.details.inventory_item_id === "number" && <Link className="font-semibold underline" to={`/stock/${apiErr!.details.inventory_item_id}`}>Adjust its stock instead</Link>}</Alert>
          ) : code === "already_decided" ? (
            <Alert tone="warn"><strong>This photo was already reviewed.</strong> Nothing more was changed. Check your <Link className="font-semibold underline" to="/stock">stock list</Link>.
              <Btn variant="secondary" className="mt-2" onClick={async () => { await draft.clear(); await active?.store.outbox.discard(outboxId); nav("/add"); }}>Remove this photo</Btn></Alert>
          ) : code === "sku_taken" ? (
            <Alert tone="error">That SKU is already used by another item. Change the SKU above and try again.</Alert>
          ) : (
            <Alert tone="error">{humanizeError(err)}{apiErr?.kind === "network" || apiErr?.kind === "timeout" ? " Your entries are saved; trying again is safe — it can't add the stock twice." : ""}</Alert>
          )}
        </div>
      )}

      {d.mode !== "none" && (
        <Btn block className="min-h-14 text-base" variant={d.mode === "reject" ? "danger" : "primary"} disabled={!canSubmit || !!analysis.decided || !online} onClick={() => setConfirm(true)}>
          {d.mode === "reject" ? "Reject suggestion" : `Add ${d.inv.qty} unit${d.inv.qty === 1 ? "" : "s"} to stock`}
        </Btn>
      )}
      {!online && d.mode !== "none" && <Alert tone="warn">Confirming needs a connection. Your choices are saved on this phone.</Alert>}
      {d.mode !== "none" && !canSubmit && d.mode !== "reject" && <p className="text-sm text-slate-600">Fill in the highlighted details to continue.</p>}

      <ConfirmDialog open={confirm} title={d.mode === "reject" ? "Reject this suggestion?" : "Add to stock?"} danger={d.mode === "reject"} confirmLabel={d.mode === "reject" ? "Reject" : `Add ${d.inv.qty} unit${d.inv.qty === 1 ? "" : "s"}`} busy={busy} onCancel={() => setConfirm(false)} onConfirm={() => void submit()}>
        {d.mode === "reject" ? <p>No stock is added. The AI suggestion is recorded as rejected.</p> : (
          <>
            <p><strong>This adds {d.inv.qty} unit{d.inv.qty === 1 ? "" : "s"} to stock.</strong></p>
            <p>{displayName} · SKU {d.inv.sku.trim()} · sells at <Money cents={d.inv.price} />, cost <Money cents={d.inv.cost} /></p>
            <p className="text-sm text-slate-700">{d.mode === "new" ? "A new catalogue product will be created and submitted for review." : d.decision === "confirmed_existing" ? "You are confirming it is the same product as the existing catalogue entry." : "You are choosing this variant of an existing catalogue product."}</p>
            {confirmWarnings.length > 0 && <WarningList warnings={confirmWarnings} title="You were warned about" />}
          </>
        )}
      </ConfirmDialog>
      <span className="sr-only">{profile?.tenant?.name}</span>
    </Screen>
  );
}

function ExtractedTable({ x }: { x: ExtractedProduct }) {
  const c = x.confidence ?? {};
  const rows: [string, string | null, number | undefined][] = [
    ["Name", x.name, c.name], ["Brand", x.brand, c.brand], ["Model", x.model, c.model], ["Part number", x.part_number, c.part_number], ["Category", x.category, c.category],
    ["Attributes", attrList(x.attributes).join(", ") || null, c.attributes], ["Pack quantity", x.pack_quantity ? String(x.pack_quantity) : null, c.pack_quantity], ["Barcode read", x.barcode_text, c.barcode],
  ];
  return (
    <dl className="divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm" aria-label="What the AI read from the photo">
      {rows.map(([label, value, conf]) => (
        <div key={label} className="flex items-center justify-between gap-2 px-3 py-2">
          <dt className="w-24 shrink-0 text-slate-600">{label}</dt>
          <dd className="min-w-0 flex-1 break-words font-medium text-slate-900">{value ?? <span className="font-normal text-slate-500">not detected</span>}</dd>
          {value && conf !== undefined && <ConfidenceBadge value={conf} />}
        </div>
      ))}
    </dl>
  );
}

/** Variants side by side: rows where the candidates (or the photo) disagree are highlighted so the difference can't be missed. */
function VariantCompare({ cands, x }: { cands: MatchCandidateDto[]; x: ExtractedProduct }) {
  const withVariant = cands.filter((c) => c.variant).slice(0, 4);
  if (withVariant.length < 2) return null;
  const keys = [...new Set(withVariant.flatMap((c) => Object.keys(c.variant!.attributes ?? {})))];
  type Row = { label: string; photo: string; vals: string[] };
  const rows: Row[] = [
    { label: "Variant", photo: x.name ?? "—", vals: withVariant.map((c) => c.variant!.name) },
    { label: "Model", photo: x.model ?? "—", vals: withVariant.map((c) => c.variant!.model_number ?? "—") },
    { label: "Pack of", photo: x.pack_quantity ? String(x.pack_quantity) : "—", vals: withVariant.map((c) => `${c.variant!.pack_quantity} ${c.variant!.uom}`) },
    ...keys.map((k) => ({ label: pretty(k), photo: x.attributes?.[k] !== undefined ? String(x.attributes[k]) : "—", vals: withVariant.map((c) => String(c.variant!.attributes?.[k] ?? "—")) })),
    { label: "Barcode", photo: x.barcode_text ?? "—", vals: withVariant.map((c) => c.variant!.barcodes?.map((b) => b.code).join(", ") || "—") },
  ];
  return (
    <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white" role="region" aria-label="Variants side by side" tabIndex={0}>
      <table className="w-full min-w-[440px] border-collapse text-left text-xs">
        <caption className="px-3 py-2 text-left text-sm font-semibold text-slate-900">Compare the variants</caption>
        <thead>
          <tr className="bg-slate-50">
            <th scope="col" className="px-2 py-1.5"><span className="sr-only">Field</span></th>
            <th scope="col" className="px-2 py-1.5 text-violet-900">From photo</th>
            {withVariant.map((c) => <th scope="col" key={c.rank} className="px-2 py-1.5">#{c.rank} {Math.round(c.score * 100)}%</th>)}
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => {
            const differs = new Set(r.vals).size > 1;
            return (
              <tr key={r.label} className={cx("border-t border-slate-100", differs && "bg-amber-50")}>
                <th scope="row" className="px-2 py-1.5 font-medium text-slate-700">{r.label}{differs && <span className="sr-only"> (differs)</span>}</th>
                <td className="px-2 py-1.5 text-violet-900">{r.photo}</td>
                {r.vals.map((v, i) => <td key={i} className={cx("px-2 py-1.5", differs && "font-semibold")}>{v}</td>)}
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}
