import { type ReactNode } from "react";
import { Link, NavLink, useLocation, useNavigate } from "react-router-dom";
import { formatAge, useActive, useAuth, useConnectivity, useNow, useOutbox, useSyncStatus } from "@hardwhere/shared";
import { Alert, cx, EmptyState, Icon } from "./ui";

export function SyncChip() {
  const c = useConnectivity();
  const sync = useSyncStatus();
  const { unsent } = useOutbox();
  const now = useNow(30_000);
  let text: string; let tone: string;
  if (!c.deviceOnline) { text = unsent ? `Offline · ${unsent} unsent` : "Offline"; tone = "bg-slate-700 text-white"; }
  else if (c.serverReachable === false) { text = "Server down"; tone = "bg-red-700 text-white"; }
  else if (sync?.phase === "syncing") { text = "Syncing…"; tone = "bg-blue-100 text-blue-900"; }
  else if (sync?.error) { text = "Sync problem"; tone = "bg-red-100 text-red-900"; }
  else if (unsent > 0) { text = `${unsent} unsent`; tone = "bg-amber-100 text-amber-900"; }
  else if (sync?.stale) { text = `Synced ${formatAge(sync.lastSyncedAt, now)}`; tone = "bg-amber-100 text-amber-900"; }
  else { text = `Synced ${formatAge(sync?.lastSyncedAt, now)}`; tone = "bg-emerald-100 text-emerald-900"; }
  return (
    <Link to="/sync" aria-label={`Sync status: ${text}. Open sync and unsent items`} className={cx("inline-flex min-h-11 items-center gap-1.5 rounded-full px-3 text-xs font-semibold", tone)}>
      <Icon name="sync" className={cx("h-4 w-4", sync?.phase === "syncing" && "animate-spin")} />
      <span aria-live="polite">{text}</span>
    </Link>
  );
}

/** Stacked status notices at the top of every screen: expired session, offline, server down, first download, stale cache, unsent work. */
export function ConnectionBanner() {
  const c = useConnectivity();
  const sync = useSyncStatus();
  const { status } = useAuth();
  const { unsent } = useOutbox();
  const now = useNow(30_000);
  const loc = useLocation();
  const notes: ReactNode[] = [];

  if (status === "expired") {
    notes.push(
      <Alert key="exp" tone="error" live="assertive">
        <strong>Session expired — sign in again.</strong> Your saved data and unsent work are kept.{" "}
        <Link className="font-semibold underline" to={`/login?next=${encodeURIComponent(loc.pathname + loc.search)}`}>Sign in</Link>
      </Alert>,
    );
  }
  if (!c.deviceOnline) {
    notes.push(<Alert key="off" tone="warn">You're offline. Showing saved data{sync?.lastSyncedAt ? ` from ${formatAge(sync.lastSyncedAt, now)}` : ""}. Changes are queued and sent when you're back online.</Alert>);
  } else if (c.serverReachable === false) {
    notes.push(<Alert key="srv" tone="warn">The server isn't answering right now. Showing saved data; your changes are queued.</Alert>);
  } else if (sync?.error && sync.snapshotComplete) {
    notes.push(<Alert key="err" tone="warn">Couldn't refresh: {sync.error.message} Showing data from {formatAge(sync.lastSyncedAt, now)}.</Alert>);
  } else if (sync?.stale && sync.snapshotComplete && sync.phase !== "syncing") {
    notes.push(<Alert key="stale" tone="info">Your data was last synced {formatAge(sync.lastSyncedAt, now)}. Prices and stock may be out of date.</Alert>);
  }
  if (sync && !sync.snapshotComplete) {
    notes.push(
      sync.phase === "syncing" || (!sync.error && c.deviceOnline)
        ? <Alert key="first" tone="info">Downloading your catalogue… this only happens the first time.</Alert>
        : <Alert key="first" tone="warn">Your catalogue hasn't been downloaded yet. Connect to the internet to get started.</Alert>,
    );
  }
  if (unsent > 0) {
    notes.push(<Alert key="unsent" tone="info"><Link to="/sync" className="font-semibold underline">{unsent} change{unsent === 1 ? "" : "s"} waiting to send</Link> — not confirmed by the server yet.</Alert>);
  }
  if (!notes.length) return null;
  return <div className="space-y-2 px-4 pt-3">{notes}</div>;
}

export function Screen({ title, back, right, children }: { title: string; back?: string | true; right?: ReactNode; children: ReactNode }) {
  const nav = useNavigate();
  return (
    <>
      <header className="sticky top-0 z-20 flex items-center gap-2 border-b border-slate-200 bg-white px-3 py-2">
        {back && (
          <button type="button" aria-label="Go back" onClick={() => (back === true ? nav(-1) : nav(back))} className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg text-slate-800 hover:bg-slate-100">
            <Icon name="back" />
          </button>
        )}
        <h1 className="min-w-0 flex-1 truncate text-lg font-bold text-slate-900">{title}</h1>
        {right}
        <SyncChip />
      </header>
      <ConnectionBanner />
      <div className="space-y-4 p-4">{children}</div>
    </>
  );
}

export function Gate({ perm, anyOf, children }: { perm?: string; anyOf?: string[]; children: ReactNode }) {
  const { can } = useAuth();
  const ok = perm ? can(perm) : (anyOf ?? []).some(can);
  if (ok) return <>{children}</>;
  return (
    <Screen title="Not available">
      <EmptyState title="Your role can't use this screen">Ask the business owner if you need access.</EmptyState>
    </Screen>
  );
}

export function BottomNav() {
  const { can } = useAuth();
  const active = useActive();
  void active;
  const tabs = [
    { to: "/", label: "Home", icon: "home", show: true, end: true },
    { to: "/stock", label: "Stock", icon: "box", show: can("inventory.view"), end: false },
    { to: "/add", label: "Add", icon: "plus", show: can("ai.analyze") || can("inventory.create"), end: false },
    { to: "/pos", label: "POS", icon: "cart", show: can("pos.sell"), end: false },
    { to: "/orders", label: "Orders", icon: "list", show: can("orders.view"), end: false },
  ].filter((t) => t.show);
  return (
    <nav aria-label="Main" className="no-print fixed inset-x-0 bottom-0 z-30 mx-auto flex max-w-md border-t border-slate-200 bg-white pb-[env(safe-area-inset-bottom)]">
      {tabs.map((t) => (
        <NavLink key={t.to} to={t.to} end={t.end} className={({ isActive }) => cx("flex min-h-14 flex-1 flex-col items-center justify-center gap-0.5 text-xs font-semibold", isActive ? "text-orange-800" : "text-slate-600")}>
          <Icon name={t.icon} className="h-6 w-6" />
          {t.label}
        </NavLink>
      ))}
    </nav>
  );
}
