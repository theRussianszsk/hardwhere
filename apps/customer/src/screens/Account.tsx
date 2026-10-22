import { useState, type FormEvent } from "react";
import { Navigate, useNavigate, useSearchParams } from "react-router-dom";
import {
  ApiError, COMMAND, UnsentWorkError, formatAge, humanizeError, normalizePhMobile, useActive, useAuth, useConnectivity, useNow, useOutbox, useServices, useSyncStatus,
} from "@hardwhere/shared";
import { API_BASE } from "../config";
import { Btn, Card, ConfirmDialog, FormField, Notice, StatusPill, inputCls } from "../components/ui";

const DEMO = { email: "ana.customer@hardwhere.test", password: "password" };

export function AuthForm({ onDone, title }: { onDone?: () => void; title?: string }) {
  const { session } = useServices();
  const [mode, setMode] = useState<"login" | "register">("login");
  const [f, setF] = useState({ name: "", email: "", password: "", phone: "" });
  const [errs, setErrs] = useState<Record<string, string>>({});
  const [msg, setMsg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF((p) => ({ ...p, [k]: e.target.value }));

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    const er: Record<string, string> = {};
    if (!/^\S+@\S+\.\S+$/.test(f.email.trim())) er.email = "Enter a valid email address.";
    if (!f.password) er.password = "Enter your password.";
    if (mode === "register") {
      if (f.name.trim().length < 2) er.name = "Enter your name.";
      if (f.password && f.password.length < 8) er.password = "Use at least 8 characters.";
      if (f.phone.trim() && !normalizePhMobile(f.phone)) er.phone = "Enter a Philippine mobile number, like 0917 123 4567.";
    }
    setErrs(er); setMsg(null);
    if (Object.keys(er).length) return;
    setBusy(true);
    try {
      if (mode === "login") await session.login({ email: f.email.trim(), password: f.password });
      else await session.register({ name: f.name.trim(), email: f.email.trim(), password: f.password, phone: f.phone.trim() ? normalizePhMobile(f.phone)! : undefined });
      onDone?.();
    } catch (x) {
      if (x instanceof ApiError && Object.keys(x.fields).length) setErrs(Object.fromEntries(Object.entries(x.fields).map(([k, v]) => [k, v[0]])));
      else setMsg(humanizeError(x));
    } finally { setBusy(false); }
  };

  return (
    <form onSubmit={(e) => void submit(e)} noValidate className="space-y-3" aria-label={mode === "login" ? "Sign in" : "Create account"}>
      <h2 className="text-lg font-bold">{title ?? (mode === "login" ? "Sign in" : "Create your account")}</h2>
      {mode === "register" && <FormField label="Full name" error={errs.name}>{(p) => <input {...p} value={f.name} onChange={set("name")} autoComplete="name" className={inputCls} />}</FormField>}
      <FormField label="Email" error={errs.email}>{(p) => <input {...p} type="email" value={f.email} onChange={set("email")} autoComplete="email" inputMode="email" className={inputCls} />}</FormField>
      <FormField label="Password" error={errs.password} hint={mode === "register" ? "At least 8 characters." : undefined}>
        {(p) => <input {...p} type="password" value={f.password} onChange={set("password")} autoComplete={mode === "login" ? "current-password" : "new-password"} className={inputCls} />}
      </FormField>
      {mode === "register" && <FormField label="Mobile number (optional)" error={errs.phone} hint="Pre-fills your orders so the store can reach you.">{(p) => <input {...p} value={f.phone} onChange={set("phone")} inputMode="tel" autoComplete="tel" placeholder="0917 123 4567" className={inputCls} />}</FormField>}
      {msg && <Notice tone="error">{msg}</Notice>}
      <Btn type="submit" block busy={busy}>{mode === "login" ? "Sign in" : "Create account"}</Btn>
      <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
        <button type="button" className="touch-target font-semibold text-brand-ink underline" onClick={() => { setMode(mode === "login" ? "register" : "login"); setErrs({}); setMsg(null); }}>
          {mode === "login" ? "New here? Create an account" : "Have an account? Sign in"}
        </button>
        {mode === "login" && <button type="button" className="touch-target text-ink-muted underline" onClick={() => setF((p) => ({ ...p, ...DEMO }))}>Fill demo account</button>}
      </div>
    </form>
  );
}

export function LoginScreen() {
  const [p] = useSearchParams();
  const nav = useNavigate();
  const auth = useAuth();
  const next = p.get("next") && p.get("next")!.startsWith("/") ? p.get("next")! : "/";
  if (auth.status === "signed_in") return <Navigate to={next} replace />;
  return (
    <div className="p-4">
      <Card className="p-4"><AuthForm onDone={() => nav(next, { replace: true })} /></Card>
    </div>
  );
}

export function AccountScreen() {
  const auth = useAuth();
  const nav = useNavigate();
  if (auth.status === "restoring") return null;
  if (auth.status === "signed_out") {
    return (
      <div className="space-y-3 p-4">
        <p className="text-sm text-ink">Sign in to add items to your cart, reserve hardware, use photo search and track orders. You can still browse without an account.</p>
        <Card className="p-4"><AuthForm onDone={() => nav("/account", { replace: true })} /></Card>
      </div>
    );
  }
  return <SignedIn />;
}

function SignedIn() {
  const auth = useAuth();
  const { session, connectivity } = useServices();
  const conn = useConnectivity();
  const sync = useSyncStatus();
  const active = useActive();
  const now = useNow(15_000);
  const { items, unsent, outbox } = useOutbox();
  const [busy, setBusy] = useState(false);
  const [probing, setProbing] = useState(false);
  const [askLogout, setAskLogout] = useState<number | null>(null);
  const [err, setErr] = useState<string | null>(null);
  const nav = useNavigate();
  const user = auth.profile!.user;
  const shown = items.filter((i) => i.state !== "confirmed" && i.kind !== COMMAND.cartReplace);

  const syncNow = async () => { setBusy(true); try { await active?.syncNow(); } finally { setBusy(false); } };
  const logout = async (keepUnsent?: boolean) => {
    setErr(null);
    try { await session.logout(keepUnsent === undefined ? undefined : { keepUnsent }); setAskLogout(null); nav("/", { replace: true }); }
    catch (e) { if (e instanceof UnsentWorkError) setAskLogout(e.count); else setErr(humanizeError(e)); }
  };

  return (
    <div className="space-y-3 p-3">
      <h1 className="text-lg font-bold">Account</h1>
      {auth.status === "expired" && <Notice tone="warn">Your session expired. Sign in again to keep ordering. Your cart and unsent items are kept.</Notice>}
      <Card className="space-y-0.5">
        <p className="text-base font-bold">{user.name}</p>
        <p className="text-sm text-ink-muted">{user.email}</p>
        {user.phone && <p className="text-sm text-ink-muted">{user.phone}</p>}
      </Card>
      {auth.status === "expired" && <Card className="p-4"><AuthForm title="Sign in again" onDone={() => undefined} /></Card>}

      <Card className="space-y-2" >
        <h2 className="text-sm font-bold">Sync and connection</h2>
        <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
          <dt className="text-ink-muted">Last synced</dt><dd className="text-right" aria-live="polite">{sync?.lastSyncedAt ? formatAge(sync.lastSyncedAt, now) : "never"}{sync?.stale ? " (stale)" : ""}</dd>
          <dt className="text-ink-muted">Sync status</dt><dd className="text-right">{sync?.phase === "syncing" ? "Syncing…" : sync?.phase === "error" ? "Failed" : sync?.snapshotComplete ? "Up to date" : "Not downloaded yet"}</dd>
          <dt className="text-ink-muted">Device network</dt><dd className="text-right">{conn.deviceOnline ? "Online" : "Offline"}</dd>
          <dt className="text-ink-muted">Hardwhere server</dt><dd className="text-right">{conn.serverReachable === null ? "Not checked" : conn.serverReachable ? "Reachable" : "Unreachable"}{conn.lastProbeAt ? ` · ${formatAge(conn.lastProbeAt, now)}` : ""}</dd>
        </dl>
        {sync?.error && <Notice tone="error">Sync problem: {sync.error.message}</Notice>}
        <div className="grid grid-cols-2 gap-2">
          <Btn busy={busy || sync?.phase === "syncing"} onClick={() => void syncNow()}>Sync now</Btn>
          <Btn variant="secondary" busy={probing} onClick={() => { setProbing(true); void connectivity.probe().finally(() => setProbing(false)); }}>Check connection</Btn>
        </div>
      </Card>

      <Card className="space-y-2">
        <h2 className="text-sm font-bold">Unsent items {shown.length ? `(${shown.length})` : ""}</h2>
        {shown.length === 0 ? <p className="text-sm text-ink-muted">Nothing waiting. Everything you did has reached the server.</p> : (
          <ul className="divide-y divide-line">
            {shown.map((i) => (
              <li key={i.id} className="space-y-1 py-2">
                <div className="flex items-center justify-between gap-2"><span className="min-w-0 truncate text-sm">{i.label}</span><StatusPill status={i.state === "sending" || i.state === "queued" ? "sending" : i.state} label={i.state === "sending" || i.state === "queued" || i.state === "draft" ? "Submitted" : undefined} /></div>
                {i.lastError && <p className="text-xs text-danger-ink">{i.lastError.message}</p>}
                <div className="flex gap-2">
                  {i.kind === COMMAND.orderPlace && <Btn variant="ghost" onClick={() => nav(`/attempt/${i.id}`)}>View</Btn>}
                  <Btn variant="secondary" onClick={() => void outbox?.retryNow(i.id)}>Retry</Btn>
                  <Btn variant="ghost" onClick={() => void outbox?.discard(i.id)}>Discard</Btn>
                </div>
              </li>
            ))}
          </ul>
        )}
        {unsent > 0 && <p className="text-xs text-ink-muted">{unsent} item(s) not yet confirmed by the server.</p>}
      </Card>

      {err && <Notice tone="error">{err}</Notice>}
      <Btn variant="secondary" block onClick={() => void logout()}>Sign out</Btn>
      <p className="text-center text-xs text-ink-muted">Hardwhere customer · API {API_BASE}</p>

      <ConfirmDialog open={askLogout !== null} title="You have unsent work" confirmLabel="Keep it and sign out" onConfirm={() => void logout(true)} onCancel={() => setAskLogout(null)} cancelLabel="Stay signed in"
        extra={<Btn variant="danger" onClick={() => void logout(false)}>Discard it and sign out</Btn>}>
        {askLogout} item(s) (such as your cart, queued photo searches or orders not yet confirmed) haven't finished. <strong>Keep</strong> saves them on this device for when you sign back in to the same account; <strong>discard</strong> deletes them.
      </ConfirmDialog>
    </div>
  );
}
