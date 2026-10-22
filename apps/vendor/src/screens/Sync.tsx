import { useEffect, useState } from "react";
import { Modal, ModalBody, ModalHeader } from "flowbite-react";
import { formatAge, formatManilaDateTime, humanizeError, UnsentWorkError, useActive, useAuth, useConnectivity, useNow, useOutbox, useServices, useSyncStatus, type OutboxItem, type PhotoMeta } from "@hardwhere/shared";
import { lastErrorText } from "../lib/CommandStatus";
import { useOnline } from "../lib/hooks";
import { Screen } from "../shell";
import { Alert, Btn, Card, ConfirmDialog, EmptyState, Pill, StatusPill } from "../ui";

export function SyncScreen() {
  const { session, profile, status } = useAuth();
  const { connectivity } = useServices();
  const active = useActive();
  const sync = useSyncStatus();
  const c = useConnectivity();
  const { online } = useOnline();
  const { items, unsent } = useOutbox();
  const now = useNow(15_000);
  const [photos, setPhotos] = useState<PhotoMeta[]>([]);
  const [busy, setBusy] = useState<string | null>(null);
  const [discarding, setDiscarding] = useState<OutboxItem | null>(null);
  const [logoutAsk, setLogoutAsk] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!active) return;
    let alive = true;
    const load = () => active.store.photos.list().then((p) => alive && setPhotos(p), () => undefined);
    void load();
    const off = active.store.onChange((e) => { if (e.area === "photos" || e.area === "outbox") void load(); });
    return () => { alive = false; off(); };
  }, [active]);

  const run = async (key: string, fn: () => Promise<unknown>) => { setBusy(key); setError(null); try { await fn(); } catch (e) { setError(humanizeError(e)); } finally { setBusy(null); } };
  const retry = (it: OutboxItem) => run(`retry-${it.id}`, async () => { await active?.store.outbox.retryNow(it.id); await active?.kickOutbox(); });

  const logout = async (keepUnsent?: boolean) => {
    setError(null); setBusy("logout");
    try { await session.logout(keepUnsent === undefined ? undefined : { keepUnsent }); setLogoutAsk(null); }
    catch (e) { if (e instanceof UnsentWorkError) setLogoutAsk(e.count); else setError(humanizeError(e)); }
    finally { setBusy(null); }
  };

  const visible = items.filter((i) => i.state !== "confirmed" || Date.now() - Date.parse(i.updatedAt) < 3_600_000);
  const unsentItems = visible.filter((i) => i.state !== "confirmed");
  const doneItems = visible.filter((i) => i.state === "confirmed");

  return (
    <Screen title="Sync &amp; unsent" back="/">
      {error && <Alert tone="error">{error}</Alert>}

      <Card className="space-y-2">
        <h2 className="text-base font-semibold">Catalogue &amp; stock data</h2>
        <dl className="grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
          <dt className="text-slate-600">Last synced</dt><dd>{sync?.lastSyncedAt ? `${formatAge(sync.lastSyncedAt, now)} (${formatManilaDateTime(sync.lastSyncedAt)})` : "never"}</dd>
          <dt className="text-slate-600">Downloaded fully</dt><dd>{sync?.snapshotComplete ? "Yes" : "Not yet"}</dd>
          <dt className="text-slate-600">State</dt><dd aria-live="polite">{sync?.phase === "syncing" ? "Syncing…" : sync?.error ? "Problem" : sync?.stale ? "Out of date" : "Up to date"}</dd>
        </dl>
        {sync?.error && <Alert tone="warn">{sync.error.message}</Alert>}
        <div className="flex gap-2">
          <Btn className="flex-1" busy={busy === "sync" || sync?.phase === "syncing"} disabled={!online} onClick={() => void run("sync", async () => { await active?.syncNow(); await active?.kickOutbox(); })}>Sync now</Btn>
          <Btn className="flex-1" variant="secondary" disabled={!online || sync?.phase === "syncing"} onClick={() => void run("full", () => active!.syncNow(true))}>Re-download all</Btn>
        </div>
      </Card>

      <Card className="space-y-2">
        <h2 className="text-base font-semibold">Connection</h2>
        <ul className="space-y-1 text-sm">
          <li className="flex justify-between"><span>This device</span><Pill tone={c.deviceOnline ? "green" : "red"}>{c.deviceOnline ? "Online" : "Offline"}</Pill></li>
          <li className="flex justify-between"><span>Hardwhere server</span><Pill tone={c.serverReachable === null ? "slate" : c.serverReachable ? "green" : "red"}>{c.serverReachable === null ? "Not checked" : c.serverReachable ? "Reachable" : "Not reachable"}</Pill></li>
          <li className="text-xs text-slate-600">Last check {c.lastProbeAt ? formatAge(c.lastProbeAt, now) : "never"}. Being online isn't proof the server is up — checkout needs both.</li>
        </ul>
        <Btn variant="secondary" block busy={busy === "probe"} onClick={() => void run("probe", () => connectivity.probe())}>Check connection</Btn>
      </Card>

      <section aria-label="Unsent changes" className="space-y-2">
        <h2 className="text-base font-semibold">Unsent changes ({unsent})</h2>
        {unsentItems.length === 0 ? <EmptyState title="Nothing waiting">Every change on this phone has been confirmed by the server.</EmptyState> : unsentItems.map((it) => (
          <Card key={it.id} className="space-y-1">
            <div className="flex items-start justify-between gap-2">
              <p className="font-semibold text-slate-900">{it.label || it.kind}</p>
              <StatusPill state={it.state} />
            </div>
            <p className="text-xs text-slate-600">{it.kind} · tries {it.attempts} · created {formatAge(it.createdAt, now)}{it.nextAttemptAt && it.state === "queued" ? ` · next try ${formatAge(it.nextAttemptAt, now).replace("ago", "").trim() === "just now" ? "now" : "soon"}` : ""}</p>
            {it.lastError && <p className="text-sm text-red-800">{lastErrorText(it.lastError)} <span className="text-xs text-slate-600">({it.lastError.code})</span></p>}
            <div className="flex gap-2 pt-1">
              {it.state !== "sending" && <Btn variant="secondary" className="flex-1" disabled={!online} busy={busy === `retry-${it.id}`} onClick={() => void retry(it)}>{it.state === "needs_review" ? "Check again" : "Retry"}</Btn>}
              {it.state !== "sending" && <Btn variant="ghost" className="flex-1" onClick={() => setDiscarding(it)}>Discard</Btn>}
            </div>
          </Card>
        ))}
        {doneItems.length > 0 && (
          <details className="rounded-lg border border-slate-200 bg-white p-3">
            <summary className="min-h-11 cursor-pointer py-2 text-sm font-semibold">Recently confirmed ({doneItems.length})</summary>
            <ul className="space-y-1 text-sm">{doneItems.map((it) => <li key={it.id} className="flex justify-between gap-2"><span className="truncate">{it.label || it.kind}</span><StatusPill state="confirmed" /></li>)}</ul>
          </details>
        )}
      </section>

      {photos.length > 0 && (
        <Card className="space-y-1">
          <h2 className="text-base font-semibold">Photos waiting to upload ({photos.length})</h2>
          <ul className="text-sm text-slate-700">{photos.map((p) => <li key={p.id}>{p.purpose} · {(p.size / 1024).toFixed(0)} KB{p.hint ? ` · demo: ${p.hint}` : ""}</li>)}</ul>
        </Card>
      )}

      <Card className="space-y-2">
        <h2 className="text-base font-semibold">Account</h2>
        <p className="text-sm text-slate-700">{profile?.user.name} · {profile?.user.email}<br />{profile?.tenant?.name} · {profile?.role?.replace(/_/g, " ")}{status === "expired" ? " · session expired" : ""}</p>
        <Btn variant="danger" block busy={busy === "logout"} onClick={() => void logout()}>Sign out</Btn>
      </Card>

      <ConfirmDialog open={!!discarding} title="Discard this change?" danger confirmLabel="Discard" onCancel={() => setDiscarding(null)}
        onConfirm={() => void run("discard", async () => { if (discarding) await active?.store.outbox.discard(discarding.id); setDiscarding(null); })}>
        <p>“{discarding?.label || discarding?.kind}” will be removed from this phone and <strong>never sent</strong>.</p>
      </ConfirmDialog>

      <Modal show={logoutAsk !== null} onClose={() => setLogoutAsk(null)} size="md" position="center">
        <ModalHeader>You have unsent work</ModalHeader>
        <ModalBody>
          <p className="mb-3 text-slate-800">{logoutAsk} item{logoutAsk === 1 ? " is" : "s are"} still unsent (queued changes, or a saved cart/draft). What should happen to {logoutAsk === 1 ? "it" : "them"}?</p>
          <div className="space-y-2">
            <Btn block onClick={() => void logout(true)} busy={busy === "logout"}>Keep unsent items and sign out</Btn>
            <p className="text-xs text-slate-600">They stay on this phone and are picked up when you sign back in as the same user.</p>
            <Btn block variant="danger" onClick={() => void logout(false)} disabled={busy === "logout"}>Discard them and sign out</Btn>
            <Btn block variant="secondary" onClick={() => setLogoutAsk(null)}>Cancel</Btn>
          </div>
        </ModalBody>
      </Modal>
    </Screen>
  );
}
