import { useEffect, useState, type FormEvent } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import { ApiError, humanizeError, UnsentWorkError, useAuth } from "@hardwhere/shared";
import { Alert, Btn, Card, TextField } from "../ui";

const DEMO_ACCOUNTS = [
  ["owner.a@hardwhere.test", "Owner"],
  ["manager.a@hardwhere.test", "Store manager (Kamuning)"],
  ["inventory.a@hardwhere.test", "Inventory"],
  ["cashier.a@hardwhere.test", "Cashier (Kamuning)"],
  ["market.a@hardwhere.test", "Marketplace"],
  ["staff.a@hardwhere.test", "Staff"],
] as const;

export function Login() {
  const { session, status, profile } = useAuth();
  const nav = useNavigate();
  const [params] = useSearchParams();
  const next = params.get("next") && params.get("next")!.startsWith("/") ? params.get("next")! : "/";
  const resuming = status === "expired" && profile;

  const [email, setEmail] = useState(profile?.user.email ?? "");
  const [password, setPassword] = useState("");
  const [tenant, setTenant] = useState<string | undefined>(resuming ? profile.tenant?.slug : undefined);
  const [tenants, setTenants] = useState<{ slug: string; name: string }[] | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fields, setFields] = useState<Record<string, string[]>>({});

  useEffect(() => { if (status === "signed_in") nav(next, { replace: true }); }, [status, next, nav]);

  const submit = async (e?: FormEvent) => {
    e?.preventDefault();
    setError(null); setFields({}); setBusy(true);
    try {
      const sameAccount = resuming && email.trim().toLowerCase() === profile.user.email.toLowerCase();
      await session.login({ email: email.trim(), password, tenant: tenants ? tenant : sameAccount ? tenant : undefined });
    } catch (err) {
      if (err instanceof UnsentWorkError) {
        setError(`This phone still has ${err.count} unsent change${err.count === 1 ? "" : "s"} for another account. Sign in with that account first to send or discard them.`);
      } else if (err instanceof ApiError && err.code === "tenant_required") {
        const list = (err.details.tenants as { slug: string; name: string }[] | undefined) ?? [];
        setTenants(list); setTenant(list[0]?.slug);
        setError("This account belongs to more than one business. Choose which one to open.");
      } else {
        setError(humanizeError(err));
        if (err instanceof ApiError) setFields(err.fields);
      }
    } finally { setBusy(false); }
  };

  return (
    <main className="mx-auto min-h-dvh max-w-md space-y-4 bg-slate-50 p-4 pt-10">
      <div className="text-center">
        <p className="text-sm font-semibold uppercase tracking-wide text-orange-800">Hardwhere</p>
        <h1 className="text-2xl font-bold text-slate-900">Store sign in</h1>
        <p className="text-sm text-slate-600">inventOS stock · POSsible point of sale</p>
      </div>

      {resuming && <Alert tone="warn" live="assertive">Your session expired. Sign in as <strong>{profile.user.email}</strong> to pick up where you left off — your saved data and unsent work are kept.</Alert>}

      <Card>
        <form onSubmit={submit} className="space-y-3" noValidate>
          <TextField label="Email" type="email" autoComplete="username" inputMode="email" value={email} onChange={(e) => setEmail(e.target.value)} error={fields.email?.[0]} required />
          <TextField label="Password" type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} error={fields.password?.[0]} required />

          {tenants && (
            <fieldset className="space-y-2">
              <legend className="text-sm font-medium text-slate-800">Business</legend>
              {tenants.map((t) => (
                <label key={t.slug} className="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg border border-slate-300 bg-white px-3">
                  <input type="radio" name="tenant" className="h-5 w-5" checked={tenant === t.slug} onChange={() => setTenant(t.slug)} />
                  <span><span className="font-semibold">{t.name}</span> <span className="text-sm text-slate-600">({t.slug})</span></span>
                </label>
              ))}
            </fieldset>
          )}

          {error && <Alert tone="error" live="assertive">{error}</Alert>}
          <Btn type="submit" block busy={busy} disabled={!email || !password}>{tenants ? "Open business" : "Sign in"}</Btn>
        </form>
      </Card>

      <details className="rounded-lg border border-slate-300 bg-white p-3">
        <summary className="min-h-11 cursor-pointer py-2 text-sm font-semibold text-slate-800">Demo accounts (password: password)</summary>
        <div className="mt-1 grid grid-cols-1 gap-2">
          {DEMO_ACCOUNTS.map(([em, label]) => (
            <Btn key={em} variant="secondary" onClick={() => { setEmail(em); setPassword("password"); setTenants(null); setTenant(undefined); setError(null); }}>
              {label} <span className="font-normal text-slate-600">· {em.split("@")[0]}</span>
            </Btn>
          ))}
          <p className="text-xs text-slate-600">Other businesses: owner.b@ (mabini-builders), owner.c@ (visayas-tools) at hardwhere.test.</p>
        </div>
      </details>
    </main>
  );
}
