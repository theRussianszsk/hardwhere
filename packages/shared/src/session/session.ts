/**
 * Session manager: login / restore / logout / renew / 401 / 403 handling, and the account <-> local-data-scope binding.
 *
 * ── Wiring (circularity ApiClient <-> SessionManager) ──────────────────────────────────────────────────────────────────────
 * The manager takes an ALREADY-BUILT ApiClient (only login/register/renew/logout are used). The client in turn needs the manager
 * for its token and its 401/403 callbacks. Break the cycle with a late-bound reference; nothing is called during construction:
 *
 *   let session!: SessionManager;
 *   const api = createApiClient({
 *     baseUrl, getToken: () => session.getToken(),
 *     onUnauthenticated: () => void session.markExpired(),
 *     onAccessRevoked: () => void session.handleAccessRevoked(),
 *   });
 *   session = createSessionManager({ api, tokens, meta, appKind, countUnsent, wipe });
 *
 * Both callbacks are fire-and-forget on purpose: they queue behind whatever session operation is running (login, logout, renew ...)
 * and are no-ops when they arrive after the session is already gone, so a 401 caused by our own logout cannot re-open anything.
 *
 * ── Rules ───────────────────────────────────────────────────────────────────────────────────────────────────────────────
 *  - The token is only ever written to the TokenStore. The persisted profile (meta key "session.profile") holds no secrets.
 *  - restore() never touches the network; the first API call confirms the session (401 -> markExpired).
 *  - A different account/business signing in on this device first needs its predecessor's unsent work resolved (UnsentWorkError).
 */
import type { ApiClient } from "../api/client";
import { ApiError } from "../api/errors";
import type { AppKind, RoleName, SessionDto, TenantDto, UserDto } from "../api/types";
import {
  makeScope, UnsentWorkError,
  type AuthState, type MetaStore, type SessionManager, type SessionProfile, type TokenStore,
} from "../contracts";

export const SESSION_PROFILE_KEY = "session.profile";
/** Renew once more than this fraction of the token lifetime has elapsed. */
export const RENEW_AFTER_FRACTION = 0.75;

export type SessionApi = Pick<ApiClient, "login" | "register" | "renew" | "logout">;

export interface SessionManagerOptions {
  api: SessionApi;
  tokens: TokenStore;
  meta: MetaStore;
  appKind: "vendor" | "customer";
  /** Remove a scope's local data. keepUnsent=true keeps outbox/drafts/photos so the same account can resume. */
  wipe: (scopeKey: string, o: { keepUnsent: boolean }) => Promise<void>;
  countUnsent: (scopeKey: string) => Promise<number>;
  now?: () => number;
  deviceName?: string;
  /** Max time logout() waits for the server to revoke the token. Default 3000. */
  logoutTimeoutMs?: number;
}

/** Non-secret JSON persisted in `meta`. `issuedAt` (epoch ms) is when we received the current token: renewal needs it. */
interface StoredProfile {
  v: 1;
  user: UserDto;
  appKind: AppKind;
  tenant: TenantDto | null;
  role: RoleName | null;
  permissions: string[];
  storeId: number | null;
  expiresAt: string | null;
  issuedAt: number;
}

export function createSessionManager(opts: SessionManagerOptions): SessionManager {
  const { api, tokens, meta, appKind, wipe, countUnsent } = opts;
  const now = opts.now ?? Date.now;
  const deviceName = opts.deviceName ?? `Hardwhere ${appKind} app`;
  const logoutTimeoutMs = opts.logoutTimeoutMs ?? 3000;

  let state: AuthState = { status: "restoring", profile: null };
  let issuedAt = 0;
  let cachedToken: string | null | undefined; // undefined = not read yet; saves a Keychain round trip per API call
  const listeners = new Set<() => void>();

  const setState = (status: AuthState["status"], profile: SessionProfile | null) => {
    state = { status, profile };
    for (const l of [...listeners]) { try { l(); } catch (e) { console.error("session listener failed", e); } }
  };
  const putToken = async (t: string) => { await tokens.set(t); cachedToken = t; };
  const dropToken = async () => { cachedToken = null; await tokens.clear(); };

  // All state-changing operations run one at a time (a 401 storm, or a 403 during renew, must not interleave with login/logout).
  let tail: Promise<unknown> = Promise.resolve();
  const serial = <T>(fn: () => Promise<T>): Promise<T> => {
    const run = tail.then(fn);
    tail = run.catch(() => undefined);
    return run;
  };

  // ───────── profile <-> meta ─────────
  const scopeFor = (user: UserDto, tenant: TenantDto | null) =>
    appKind === "vendor" ? makeScope(user.id, tenant!.id, "vendor") : makeScope(user.id, null, "customer");

  function fromDto(res: SessionDto): SessionProfile {
    if (appKind === "vendor" && !res.tenant) throw new Error("The server did not say which business this sign-in belongs to.");
    return {
      user: res.user, appKind, tenant: appKind === "vendor" ? res.tenant : null, role: res.role, permissions: [...res.permissions],
      storeId: res.store_id, expiresAt: res.expires_at, scope: scopeFor(res.user, res.tenant),
    };
  }

  async function persist(p: SessionProfile) {
    const s: StoredProfile = { v: 1, user: p.user, appKind: p.appKind, tenant: p.tenant, role: p.role, permissions: p.permissions, storeId: p.storeId, expiresAt: p.expiresAt, issuedAt };
    await meta.set(SESSION_PROFILE_KEY, JSON.stringify(s));
  }

  /** Stored profile or null when absent / corrupt / written by another app kind (never throws). */
  async function readProfile(): Promise<{ profile: SessionProfile; issuedAt: number } | null> {
    try {
      const raw = await meta.get(SESSION_PROFILE_KEY);
      if (!raw) return null;
      const s = JSON.parse(raw) as Partial<StoredProfile>;
      if (s.v !== 1 || s.appKind !== appKind || typeof s.user?.id !== "number" || !Array.isArray(s.permissions)) return null;
      if (appKind === "vendor" && typeof s.tenant?.id !== "number") return null;
      const tenant = s.tenant ?? null;
      return {
        issuedAt: typeof s.issuedAt === "number" ? s.issuedAt : 0,
        profile: { user: s.user, appKind, tenant, role: s.role ?? null, permissions: s.permissions, storeId: s.storeId ?? null, expiresAt: s.expiresAt ?? null, scope: scopeFor(s.user, tenant) },
      };
    } catch { return null; }
  }

  const isPast = (iso: string | null) => iso !== null && Date.parse(iso) <= now();

  // ───────── operations ─────────
  async function guardUnsent(scopeKey: string) {
    const n = await countUnsent(scopeKey);
    if (n > 0) throw new UnsentWorkError(n);
  }

  /** Shared by login() and register(). */
  async function establish(call: () => Promise<SessionDto>, email: string, tenantSlug?: string): Promise<SessionProfile> {
    const prev = (await readProfile())?.profile ?? null;
    // Cheap pre-check so the common "someone else signs in on this phone" case is refused before any server call / token minting.
    const looksDifferent = prev && (email.trim().toLowerCase() !== prev.user.email.toLowerCase() || (!!tenantSlug && !!prev.tenant && tenantSlug !== prev.tenant.slug));
    if (prev && looksDifferent) await guardUnsent(prev.scope.key);

    const res = await call();
    const next = fromDto(res);
    if (prev && prev.scope.key !== next.scope.key) {
      await guardUnsent(prev.scope.key); // authoritative check: now that the server told us who this really is
      await wipe(prev.scope.key, { keepUnsent: false }); // account switch: the previous account's private cache goes first
    }
    issuedAt = now();
    await putToken(res.token);
    await persist(next);
    setState("signed_in", next);
    return next;
  }

  async function expire() {
    if (state.status !== "signed_in") return; // idempotent; late 401s after logout/revoke land here
    const p = state.profile;
    await dropToken();
    setState("expired", p);
  }

  async function settleWithin(p: Promise<unknown>, ms: number) {
    let t: ReturnType<typeof setTimeout> | undefined;
    try { await Promise.race([p.catch(() => undefined), new Promise<void>((r) => { t = setTimeout(r, ms); })]); } finally { clearTimeout(t); }
  }

  return {
    state: () => state,
    subscribe(l) { listeners.add(l); return () => { listeners.delete(l); }; },

    restore: () => serial(async () => {
      const [token, stored] = await Promise.all([tokens.get(), readProfile()]);
      if (!stored) {
        if (token) await dropToken(); // orphan (e.g. iOS keychain outlived an uninstall): we cannot tell whose it is
        setState("signed_out", null);
        return state;
      }
      issuedAt = stored.issuedAt;
      if (!token || isPast(stored.profile.expiresAt)) {
        if (token) await dropToken();
        setState("expired", stored.profile); // profile kept: signing in again resumes the SAME scope
      } else {
        cachedToken = token;
        setState("signed_in", stored.profile);
      }
      return state;
    }),

    login: (input) => serial(() => establish(() => api.login({ ...input, app: appKind, device_name: deviceName }), input.email, input.tenant)),
    register: (input) => serial(() => establish(() => api.register({ ...input, device_name: deviceName }), input.email)),

    logout: (o = {}) => serial(async () => {
      const p = state.profile;
      if (p) {
        const unsent = await countUnsent(p.scope.key);
        if (unsent > 0 && o.keepUnsent === undefined) throw new UnsentWorkError(unsent); // nothing has changed yet, not even server-side
      }
      if (state.status === "signed_in") await settleWithin(Promise.resolve().then(() => api.logout()), logoutTimeoutMs);
      if (p) await wipe(p.scope.key, { keepUnsent: o.keepUnsent ?? false });
      await dropToken();
      await meta.delete(SESSION_PROFILE_KEY);
      setState("signed_out", null);
    }),

    markExpired: () => serial(expire),

    handleAccessRevoked: () => serial(async () => {
      const p = state.profile ?? (await readProfile())?.profile;
      if (!p) return;
      await dropToken(); // stop authenticating immediately, then remove everything the revoked member could still see
      await wipe(p.scope.key, { keepUnsent: false }); // unsent items can no longer be authorised: they would only fail
      await meta.delete(SESSION_PROFILE_KEY);
      setState("signed_out", null);
    }),

    renewIfNeeded: () => serial(async () => {
      const p = state.profile;
      if (state.status !== "signed_in" || !p?.expiresAt || issuedAt <= 0) return;
      const expiresAt = Date.parse(p.expiresAt);
      const life = expiresAt - issuedAt;
      if (!(life > 0) || now() - issuedAt <= RENEW_AFTER_FRACTION * life) return;
      if (now() >= expiresAt) return expire(); // the server cannot renew a dead token

      let res: SessionDto;
      try {
        res = await api.renew();
      } catch (e) {
        if (!(e instanceof ApiError)) throw e;
        if (e.kind === "unauthenticated") await expire();
        return; // offline / timeout / 5xx: try again next time
      }
      try {
        const next = fromDto(res);
        if (next.scope.key !== p.scope.key) throw new Error("renewed token belongs to a different account");
        issuedAt = now();
        await putToken(res.token); // the server already deleted the old token: this write must not be skipped
        await persist(next);
        setState("signed_in", next);
      } catch (e) {
        await expire().catch(() => undefined);
        throw e;
      }
    }),

    async getToken() {
      if (state.status === "expired" || state.status === "signed_out") return null;
      return (cachedToken ??= await tokens.get());
    },

    can: (permission) => state.status === "signed_in" && !!state.profile?.permissions.includes(permission),
    unsentCount: async () => (state.profile ? countUnsent(state.profile.scope.key) : 0),
  };
}
