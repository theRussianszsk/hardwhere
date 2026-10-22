import { useEffect, useRef, useState } from "react";
import { useActive } from "@hardwhere/shared";
import { useOnline, useOutboxItem } from "./hooks";
import { Alert, Btn, StatusPill } from "../ui";

export function lastErrorText(e: { code: string; message: string; details?: unknown } | null | undefined): string {
  if (!e) return "";
  const fields = (e.details as { fields?: Record<string, string[]> } | undefined)?.fields;
  const first = fields ? Object.values(fields).flat()[0] : undefined;
  return first ? `${e.message} ${first}` : e.message;
}

/**
 * The real state of one queued command. Never says "done" before the server confirmed it:
 * queued -> sending -> confirmed, or needs review / rejected with the server's reason.
 */
export function CommandStatus({ id, title, onConfirmed, onDismiss, confirmedText = "Confirmed by the server." }: {
  id: string; title: string; onConfirmed?: () => void; onDismiss?: () => void; confirmedText?: string;
}) {
  const { item } = useOutboxItem(id);
  const active = useActive();
  const { online } = useOnline();
  const fired = useRef(false);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (item?.state === "confirmed" && !fired.current) { fired.current = true; onConfirmed?.(); }
  }, [item?.state, onConfirmed]);

  if (!item) return null;
  const retry = async () => { setBusy(true); try { await active?.store.outbox.retryNow(id); await active?.kickOutbox(); } finally { setBusy(false); } };
  const discard = async () => { await active?.store.outbox.discard(id); onDismiss?.(); };
  const tone = item.state === "confirmed" ? "success" : item.state === "rejected" ? "error" : item.state === "needs_review" ? "warn" : "info";

  return (
    <Alert tone={tone} live="polite">
      <div className="flex items-start justify-between gap-2">
        <p className="font-semibold">{title}</p>
        <StatusPill state={item.state} />
      </div>
      {item.state === "queued" && <p className="mt-1">{online ? "Waiting to send…" : "Saved on this phone. It will be sent when you're back online."}{item.attempts > 0 && item.lastError ? ` Last try failed: ${item.lastError.message}` : ""}</p>}
      {item.state === "sending" && <p className="mt-1">Sending to the server…</p>}
      {item.state === "confirmed" && <p className="mt-1">{confirmedText}</p>}
      {(item.state === "needs_review" || item.state === "rejected") && <p className="mt-1">{item.state === "rejected" ? "The server refused this change: " : "Not confirmed: "}{lastErrorText(item.lastError)}</p>}
      <div className="mt-2 flex flex-wrap gap-2">
        {(item.state === "queued" || item.state === "needs_review") && <Btn variant="secondary" busy={busy} disabled={!online} onClick={() => void retry()}>{item.state === "queued" ? "Send now" : "Check again"}</Btn>}
        {(item.state === "queued" || item.state === "needs_review" || item.state === "rejected") && <Btn variant="ghost" onClick={() => void discard()}>Discard</Btn>}
        {item.state === "confirmed" && onDismiss && <Btn variant="ghost" onClick={onDismiss}>Dismiss</Btn>}
      </div>
    </Alert>
  );
}
