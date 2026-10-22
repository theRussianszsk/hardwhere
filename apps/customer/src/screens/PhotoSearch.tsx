import { useEffect, useRef, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import {
  ApiError, COMMAND, CancelledError, PermissionDeniedError, UnsupportedError, capturePhoto, downscaleImage, humanizeError, newId, useActive, useAuth, useOutbox, useServices,
  type AiAnalysisDto, type MatchCandidateDto,
} from "@hardwhere/shared";
import { Btn, Card, ConfidenceBadge, DemoAiBadge, EmptyState, MatchBadge, Notice, PhotoThumb, StatusPill, WarningList, cx } from "../components/ui";
import { useOnline } from "../hooks";
import { AuthForm } from "./Account";

const DEMO_PHOTOS: { hint: string; label: string }[] = [
  { hint: "makita-hp1630", label: "Makita HP1630 drill" },
  { hint: "barcode-visible", label: "Stanley hammer (barcode)" },
  { hint: "bosch-gsb13", label: "Bosch GSB 13 drill" },
  { hint: "ambiguous-cordless-drill", label: "Ambiguous cordless drill" },
  { hint: "unbarcoded-tek-screw", label: "Tek screws (no barcode)" },
  { hint: "blurry", label: "Blurry photo" },
];

/** Generated placeholder image, clearly labelled: the demo AI keys off the hint, not the pixels. */
function makeDemoPhoto(hint: string, label: string): Promise<Blob> {
  const c = document.createElement("canvas");
  c.width = 640; c.height = 480;
  const g = c.getContext("2d")!;
  g.fillStyle = "#f5e6c8"; g.fillRect(0, 0, 640, 480);
  g.fillStyle = "#b45309"; g.fillRect(0, 0, 640, 70);
  g.fillStyle = "#fff"; g.font = "bold 30px sans-serif"; g.fillText("DEMO PHOTO (not a real picture)", 24, 46);
  g.fillStyle = "#1c1917"; g.font = "bold 44px sans-serif"; g.fillText(label, 24, 250, 592);
  g.font = "28px monospace"; g.fillStyle = "#57534e"; g.fillText(hint, 24, 310);
  return new Promise((res, rej) => c.toBlob((b) => (b ? res(b) : rej(new Error("Could not create the demo photo."))), "image/jpeg", 0.85));
}

interface Chosen { blob: Blob; url: string; hint?: string; label: string; key: string }

export function PhotoSearchScreen() {
  const nav = useNavigate();
  const auth = useAuth();
  const online = useOnline();
  const active = useActive();
  const { api, photos } = useServices();
  const { items } = useOutbox({ kinds: [COMMAND.photoSearch] });
  const signedIn = auth.status === "signed_in";

  const [chosen, setChosen] = useState<Chosen | null>(null);
  const [busy, setBusy] = useState(false);
  const [analysis, setAnalysis] = useState<AiAnalysisDto | null>(null);
  const [queuedId, setQueuedId] = useState<string | null>(null);
  const [viewId, setViewId] = useState<string | null>(null);
  const [err, setErr] = useState<{ msg: string; retake?: boolean } | null>(null);
  const [note, setNote] = useState<string | null>(null);
  const started = useRef<string | null>(null);

  const reset = () => { setAnalysis(null); setQueuedId(null); setViewId(null); setErr(null); setNote(null); started.current = null; };

  const queue = async (c: Chosen) => {
    if (!active) return;
    const saved = await photos.save(c.blob);
    await active.store.photos.add({ id: newId(), fileRef: saved.ref, mime: saved.mime, size: saved.size, purpose: "photo_search", hint: c.hint ?? null, outboxId: c.key });
    await active.store.outbox.enqueue(COMMAND.photoSearch, { photoRef: saved.ref, hint: c.hint }, { id: c.key, label: `Photo search · ${c.label}` });
    setQueuedId(c.key);
  };

  const search = async (c: Chosen) => {
    reset();
    started.current = c.key;
    if (!online) { await queue(c); return; }
    setBusy(true);
    try {
      const r = await api.customerPhotoSearch(c.blob, c.key, c.hint);
      setAnalysis(r.data);
    } catch (e) {
      if (e instanceof ApiError && e.code === "image_insufficient") setErr({ msg: "That photo isn't clear enough to identify. Retake it closer, in good light, with the label or model number in focus.", retake: true });
      else if (e instanceof ApiError && (e.kind === "network" || e.kind === "timeout")) { await queue(c); setNote("We couldn't reach the server, so the photo was saved. It will be searched when you're back online."); }
      else if (e instanceof ApiError && e.kind === "unauthenticated") setErr({ msg: "Your session expired. Sign in again to search by photo." });
      else setErr({ msg: humanizeError(e) });
    } finally { setBusy(false); }
  };

  const choose = async (get: () => Promise<Blob>, hint: string | undefined, label: string) => {
    reset();
    try {
      const raw = await get();
      const blob = hint ? raw : await downscaleImage(raw, { maxEdge: 1280 });
      if (chosen) URL.revokeObjectURL(chosen.url);
      const c: Chosen = { blob, url: URL.createObjectURL(blob), hint, label, key: newId() };
      setChosen(c);
      if (signedIn) await search(c);
    } catch (e) {
      if (e instanceof CancelledError) return;
      if (e instanceof PermissionDeniedError) setErr({ msg: `${e.message} You can also choose a photo from your library or use a demo photo.` });
      else if (e instanceof UnsupportedError) setErr({ msg: "No camera is available here. Choose a photo from your library or use a demo photo." });
      else setErr({ msg: humanizeError(e) });
    }
  };

  // signed-out shopper picked a photo, then signed in inline: continue automatically
  useEffect(() => {
    if (signedIn && chosen && started.current !== chosen.key) void search(chosen);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [signedIn]);

  const watched = items.find((i) => i.id === (viewId ?? queuedId));
  const shownAnalysis = analysis ?? (watched?.state === "confirmed" ? (watched.response as AiAnalysisDto) : null);

  return (
    <div className="space-y-3 p-3">
      <button type="button" onClick={() => nav("/")} className="touch-target text-sm font-medium text-brand-ink">‹ Search</button>
      <h1 className="text-lg font-bold">Search by photo</h1>
      <p className="text-sm text-ink-muted">Snap the product or its label. We'll suggest matching products and the stores that sell them.</p>

      <div className="grid grid-cols-2 gap-2">
        <Btn disabled={busy} onClick={() => void choose(() => capturePhoto("camera"), undefined, "Camera photo")}>Take photo</Btn>
        <Btn variant="secondary" disabled={busy} onClick={() => void choose(() => capturePhoto("library"), undefined, "Library photo")}>Choose from library</Btn>
      </div>

      <details key={chosen ? "closed" : "open"} className="rounded-card border border-line bg-surface-2 p-3" open={!chosen}>
        <summary className="min-h-8 cursor-pointer text-sm font-semibold text-info-ink">Demo photos (no camera needed)</summary>
        <p className="mt-1 text-xs text-info-ink">Placeholder images sent with a scenario hint to the <em>demo AI</em>, which doesn't read the pixels.</p>
        <div className="mt-2 flex flex-wrap gap-2">
          {DEMO_PHOTOS.map((d) => (
            <button key={d.hint} type="button" disabled={busy} onClick={() => void choose(() => makeDemoPhoto(d.hint, d.label), d.hint, d.label)}
              className="touch-target rounded-control border border-line bg-surface px-3 text-sm font-medium text-info-ink disabled:opacity-50">{d.label}</button>
          ))}
        </div>
      </details>

      {chosen && (
        <Card className="flex items-center gap-3">
          <img src={chosen.url} alt="Your photo" className="h-20 w-20 rounded-control object-cover" />
          <div className="min-w-0 flex-1 text-sm"><p className="truncate font-semibold">{chosen.label}</p>
            {chosen.hint && <p className="text-xs text-info-ink">Demo photo · {chosen.hint}</p>}
            {busy && <p className="text-xs text-ink-muted" role="status">Analysing photo…</p>}</div>
        </Card>
      )}

      {chosen && !signedIn && (
        <Card className="space-y-2 border-warn bg-warn-soft p-4">
          <p className="text-sm font-semibold text-warn-ink">Sign in to search with this photo</p>
          <p className="text-xs text-warn-ink">Photo search uses AI, so it's limited to signed-in accounts. Your photo is kept while you sign in.</p>
          <AuthForm title="Sign in or create an account" />
        </Card>
      )}
      {!chosen && !signedIn && <Notice tone="info">Photo search needs an account. You can pick a photo first, then sign in.</Notice>}

      <div aria-live="polite" className="space-y-3">
        {!online && !queuedId && chosen && signedIn && null}
        {note && <Notice tone="warn">{note}</Notice>}
        {err && (
          <Notice tone="error">
            {err.msg}
            {chosen && !err.retake && <button type="button" className="mt-2 block font-semibold underline" onClick={() => void search(chosen)}>Try again</button>}
          </Notice>
        )}
        {watched && watched.state !== "confirmed" && <QueuedCard id={watched.id} />}
        {shownAnalysis && <AnalysisView a={shownAnalysis} />}
      </div>

      {items.length > 0 && (
        <section className="space-y-2" aria-label="Photo searches">
          <h2 className="text-sm font-bold">Your photo searches</h2>
          {items.slice(0, 6).map((i) => (
            <Card key={i.id} className="flex items-center justify-between gap-2">
              <div className="min-w-0"><p className="truncate text-sm">{i.label}</p>
                {i.lastError && <p className="text-xs text-danger-ink">{i.lastError.message}</p>}</div>
              <div className="flex items-center gap-1">
                <StatusPill status={i.state === "queued" || i.state === "sending" || i.state === "draft" ? "queued" : i.state} label={i.state === "confirmed" ? "Done" : i.state === "queued" || i.state === "sending" ? "Queued" : undefined} />
                {i.state === "confirmed" && <Btn variant="ghost" onClick={() => { setAnalysis(null); setViewId(i.id); }}>View</Btn>}
                {(i.state === "rejected" || i.state === "needs_review") && <Btn variant="ghost" onClick={() => { void active?.store.outbox.retryNow(i.id); }}>Retry</Btn>}
                {i.state !== "confirmed" && <Btn variant="ghost" onClick={() => void active?.store.outbox.discard(i.id)}>Discard</Btn>}
              </div>
            </Card>
          ))}
        </section>
      )}
    </div>
  );
}

function QueuedCard({ id }: { id: string }) {
  const { items } = useOutbox({ kinds: [COMMAND.photoSearch] });
  const i = items.find((x) => x.id === id);
  if (!i) return null;
  if (i.state === "rejected" || i.state === "needs_review") {
    return <Notice tone="error">The photo search didn't go through: {i.lastError?.message ?? "unknown problem"}. Use Retry below, or retake the photo.</Notice>;
  }
  return <Notice tone="info" role="status"><strong>Queued</strong> — will search when you're back online. Your photo is saved on this device{i.attempts > 0 ? ` (attempt ${i.attempts})` : ""}.</Notice>;
}

const FIELD = (f: string) => f.replace(/^attr:/, "").replace(/_/g, " ");

function Candidate({ c }: { c: MatchCandidateDto }) {
  return (
    <Link to={`/product/${c.product.id}`} className="block rounded-card border border-line bg-surface p-3 shadow-sm active:bg-canvas" aria-label={`Candidate ${c.rank}: ${c.product.name}`}>
      <div className="flex gap-3">
        <PhotoThumb src={c.product.images?.[0]?.url} alt="" size={56} />
        <div className="min-w-0 flex-1 space-y-1">
          <p className="text-xs font-semibold text-ink-muted">#{c.rank}</p>
          <p className="text-sm font-bold text-ink">{c.product.name}</p>
          <p className="text-xs text-ink-muted">{[c.product.brand, c.product.model_number].filter(Boolean).join(" · ")}</p>
          {c.variant && <p className="text-xs text-ink">Version: {c.variant.name}</p>}
        </div>
      </div>
      <div className="mt-2 flex flex-wrap gap-1.5"><MatchBadge cls={c.classification} /><ConfidenceBadge value={c.score} /></div>
      {(c.matched_fields.length > 0 || c.mismatched_fields.length > 0) && (
        <div className="mt-2 flex flex-wrap gap-1 text-xs">
          {c.matched_fields.map((f) => <span key={f} className="rounded bg-success-soft px-1.5 py-0.5 text-success-ink">✓ {FIELD(f)}</span>)}
          {c.mismatched_fields.map((f) => <span key={f} className="rounded bg-danger-soft px-1.5 py-0.5 text-danger-ink">✕ {FIELD(f)} differs</span>)}
        </div>
      )}
      <div className="mt-2"><WarningList warnings={c.warnings} /></div>
      <p className="mt-2 text-xs font-semibold text-brand-ink">Compare stores ›</p>
    </Link>
  );
}

export function AnalysisView({ a }: { a: AiAnalysisDto }) {
  const nav = useNavigate();
  const x = a.extracted;
  const cands = a.candidates ?? [];
  return (
    <section className="space-y-3" aria-label="Photo search results">
      <Card className="space-y-2">
        <div className="flex flex-wrap items-center gap-1.5">
          {a.is_mock ? <DemoAiBadge label={a.provider_label} /> : <span className={cx("rounded-full bg-surface-2 px-2 py-0.5 text-xs font-semibold")}>{a.provider_label}</span>}
        </div>
        <h2 className="text-sm font-bold">What we read from your photo</h2>
        <p className="text-sm">{[x.brand, x.name, x.model].filter(Boolean).join(" · ") || "Couldn't read a product name"}</p>
        {x.barcode_text && <p className="text-xs text-ink-muted">Barcode: {x.barcode_text}</p>}
        {x.category && <p className="text-xs text-ink-muted">Category: {x.category}</p>}
        <div className="flex flex-wrap gap-1.5"><ConfidenceBadge value={a.overall_confidence} /><MatchBadge cls={a.classification} /></div>
        {a.requires_confirmation && <Notice tone="warn">We're not certain. Check the details before you buy.</Notice>}
        <WarningList warnings={[...a.warnings, ...x.notes.filter((n) => !/Demo AI/i.test(n))]} />
      </Card>
      {cands.length === 0 ? (
        <EmptyState title="No matching products found" action={x.name ? <Btn variant="secondary" onClick={() => nav(`/?q=${encodeURIComponent(x.name ?? "")}`)}>Search “{x.name}”</Btn> : undefined}>
          No store currently lists a product that matches this photo. Try another angle or search by text.
        </EmptyState>
      ) : (
        <>
          <h2 className="text-sm font-bold">Possible matches</h2>
          {cands.map((c) => <Candidate key={`${c.rank}-${c.product.id}-${c.variant?.id ?? 0}`} c={c} />)}
        </>
      )}
    </section>
  );
}
