import { useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { COMMAND, downscaleImage, formatAge, humanizeError, newId, useActive, useAuth, useNow, useOutbox, useServices, type AiAnalyzePayload } from "@hardwhere/shared";
import { useOnline } from "../lib/hooks";
import { PhotoPicker } from "../lib/PhotoPicker";
import { Screen } from "../shell";
import { Alert, Btn, Card, StatusPill } from "../ui";

export function IntakeStart() {
  const { can } = useAuth();
  const active = useActive();
  const { photos } = useServices();
  const { online } = useOnline();
  const nav = useNavigate();
  const now = useNow(30_000);
  const recent = useOutbox({ kinds: [COMMAND.aiAnalyze] }).items.slice(0, 6);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Photo -> app-private file -> outbox command (so it survives an app kill) -> wait for the analysis on the review screen.
  const start = async (blob: Blob, hint?: string) => {
    if (!active) return;
    setBusy(true); setError(null);
    try {
      const small = await downscaleImage(blob, { maxEdge: 1600, quality: 0.85 });
      const saved = await photos.save(small);
      const id = newId();
      const payload: AiAnalyzePayload = { photoRef: saved.ref, ...(hint ? { hint } : {}) };
      await active.store.outbox.enqueue(COMMAND.aiAnalyze, payload, { id, label: hint ? `Photo analysis (demo: ${hint})` : "Photo analysis" });
      await active.store.photos.add({ id: newId(), fileRef: saved.ref, mime: saved.mime, size: saved.size, purpose: "ai.analyze", hint: hint ?? null, outboxId: id });
      void active.kickOutbox();
      nav(`/add/review/${id}`);
    } catch (e) { setError(humanizeError(e)); } finally { setBusy(false); }
  };

  return (
    <Screen title="Add item">
      <p className="text-slate-700">Take a photo of the product. The AI suggests what it is and checks your catalogue — <strong>you decide</strong> before anything is added to stock.</p>
      {!online && <Alert tone="warn">You're offline. You can still take the photo — it will be analysed when you're back online — or enter the item manually.</Alert>}
      {can("ai.analyze") && <Card><PhotoPicker onPhoto={start} busy={busy} /></Card>}
      {error && <Alert tone="error">{error}</Alert>}

      {can("inventory.create") && (
        <Link to="/add/manual" className="block"><Btn block variant="secondary">Enter an item manually (no photo)</Btn></Link>
      )}

      {recent.length > 0 && (
        <section aria-label="Recent photos" className="space-y-2">
          <h2 className="text-base font-semibold text-slate-900">Recent photos</h2>
          <ul className="space-y-2">
            {recent.map((it) => (
              <li key={it.id}>
                <Link to={`/add/review/${it.id}`} className="block">
                  <Card className="flex items-center justify-between gap-2 p-3">
                    <div className="min-w-0"><p className="truncate font-medium">{it.label}</p><p className="text-xs text-slate-600">{formatAge(it.createdAt, now)}{it.state === "confirmed" ? " · ready to review" : ""}</p></div>
                    <StatusPill state={it.state} />
                  </Card>
                </Link>
              </li>
            ))}
          </ul>
        </section>
      )}
    </Screen>
  );
}
