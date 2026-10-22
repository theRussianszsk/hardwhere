import { afterEach, describe, expect, it, vi } from "vitest";
import { ApiError } from "../src/api/errors";
import type { SessionDto } from "../src/api/types";
import { UnsentWorkError, type MetaStore } from "../src/contracts";
import { createMemoryTokenStore, createSessionManager, SESSION_PROFILE_KEY } from "../src/session";

const T0 = Date.parse("2026-03-01T00:00:00Z");
const HOUR = 3_600_000;
const iso = (ms: number) => new Date(ms).toISOString();

const ana = { id: 7, name: "Ana Reyes", email: "ana@reyes.ph", phone: null };
const ben = { id: 8, name: "Ben Cruz", email: "ben@cruz.ph", phone: null };
const tenant3 = { id: 3, slug: "reyes-hardware", name: "Reyes Hardware", vat_rate_bps: 1200 };

const vendorDto = (over: Partial<SessionDto> = {}): SessionDto => ({
  token: "secret-token-AAA", expires_at: iso(T0 + 8 * HOUR), user: ana, app: "vendor", tenant: tenant3, role: "owner", permissions: ["inventory.view", "pos.sell"], store_id: 5, ...over,
});

function memoryMeta(): MetaStore & { dump: () => string } {
  const m = new Map<string, string>();
  return { get: async (k) => m.get(k) ?? null, set: async (k, v) => void m.set(k, v), delete: async (k) => void m.delete(k), dump: () => JSON.stringify([...m]) };
}

function setup(o: { appKind?: "vendor" | "customer"; unsent?: Record<string, number>; dto?: SessionDto } = {}) {
  let t = T0;
  const unsent = { ...(o.unsent ?? {}) };
  const api = {
    login: vi.fn(async () => o.dto ?? vendorDto()),
    register: vi.fn(async () => o.dto ?? vendorDto()),
    renew: vi.fn(async () => vendorDto({ token: "secret-token-BBB", expires_at: iso(t + 8 * HOUR) })),
    logout: vi.fn(async () => ({ ok: true })),
  };
  const tokens = createMemoryTokenStore();
  const meta = memoryMeta();
  const wipe = vi.fn(async () => undefined);
  const countUnsent = vi.fn(async (key: string) => unsent[key] ?? 0);
  const mgr = createSessionManager({ api, tokens, meta, appKind: o.appKind ?? "vendor", wipe, countUnsent, now: () => t, deviceName: "Test phone", logoutTimeoutMs: 50 });
  return { mgr, api, tokens, meta, wipe, countUnsent, unsent, advance: (ms: number) => { t += ms; } };
}
const creds = { email: "ana@reyes.ph", password: "pw", tenant: "reyes-hardware" };
const VENDOR_3 = "vendor:7:3";

afterEach(() => { vi.useRealTimers(); });

describe("restore", () => {
  it("is 'restoring' until restore() runs", () => {
    expect(setup().mgr.state()).toEqual({ status: "restoring", profile: null });
  });
  it("no token + no profile => signed_out", async () => {
    const { mgr } = setup();
    expect((await mgr.restore()).status).toBe("signed_out");
  });
  it("token without profile (iOS keychain outlived an uninstall) => signed_out and the orphan token is dropped", async () => {
    const { mgr, tokens } = setup();
    await tokens.set("orphan");
    expect((await mgr.restore()).status).toBe("signed_out");
    expect(await tokens.get()).toBeNull();
  });
  it("profile but no token => expired (profile kept)", async () => {
    const s = setup();
    await s.mgr.login(creds);
    await s.tokens.clear();
    const fresh = createSessionManager({ api: s.api, tokens: s.tokens, meta: s.meta, appKind: "vendor", wipe: s.wipe, countUnsent: s.countUnsent, now: () => T0 });
    const st = await fresh.restore();
    expect(st.status).toBe("expired");
    expect(st.profile?.scope.key).toBe(VENDOR_3);
    expect(await s.meta.get(SESSION_PROFILE_KEY)).not.toBeNull();
  });
  it("token + valid profile => signed_in without any network call", async () => {
    const s = setup();
    await s.mgr.login(creds);
    s.api.login.mockClear();
    const fresh = createSessionManager({ api: s.api, tokens: s.tokens, meta: s.meta, appKind: "vendor", wipe: s.wipe, countUnsent: s.countUnsent, now: () => T0 + HOUR });
    const st = await fresh.restore();
    expect(st.status).toBe("signed_in");
    expect(st.profile?.permissions).toContain("pos.sell");
    expect(s.api.login).not.toHaveBeenCalled();
    expect(s.api.renew).not.toHaveBeenCalled();
    expect(await fresh.getToken()).toBe("secret-token-AAA");
  });
  it("profile.expiresAt in the past => expired and the dead token is removed", async () => {
    const s = setup();
    await s.mgr.login(creds);
    const fresh = createSessionManager({ api: s.api, tokens: s.tokens, meta: s.meta, appKind: "vendor", wipe: s.wipe, countUnsent: s.countUnsent, now: () => T0 + 9 * HOUR });
    expect((await fresh.restore()).status).toBe("expired");
    expect(await s.tokens.get()).toBeNull();
  });
  it("corrupt or foreign profile JSON is ignored (signed_out), never thrown", async () => {
    const s = setup();
    await s.meta.set(SESSION_PROFILE_KEY, "{not json");
    expect((await s.mgr.restore()).status).toBe("signed_out");
    await s.meta.set(SESSION_PROFILE_KEY, JSON.stringify({ v: 1, appKind: "customer", user: ana, permissions: [] }));
    expect((await s.mgr.restore()).status).toBe("signed_out");
  });
});

describe("login", () => {
  it("vendor happy path: app kind + device name sent, token stored, profile persisted, scope bound to account+tenant", async () => {
    const s = setup();
    const seen = vi.fn();
    s.mgr.subscribe(seen);
    const p = await s.mgr.login(creds);
    expect(s.api.login).toHaveBeenCalledWith({ ...creds, app: "vendor", device_name: "Test phone" });
    expect(p.scope).toEqual({ accountId: 7, tenantId: 3, kind: "vendor", key: VENDOR_3 });
    expect(await s.tokens.get()).toBe("secret-token-AAA");
    expect(await s.mgr.getToken()).toBe("secret-token-AAA");
    expect(s.mgr.state().status).toBe("signed_in");
    expect(seen).toHaveBeenCalled();
    expect(s.mgr.can("pos.sell")).toBe(true);
    expect(s.mgr.can("staff.manage")).toBe(false);
    const stored = JSON.parse((await s.meta.get(SESSION_PROFILE_KEY))!);
    expect(stored).toMatchObject({ appKind: "vendor", role: "owner", storeId: 5, permissions: ["inventory.view", "pos.sell"], tenant: { id: 3 }, user: { id: 7 } });
    expect(s.wipe).not.toHaveBeenCalled();
  });
  it("the token is never written into meta", async () => {
    const s = setup();
    await s.mgr.login(creds);
    await s.mgr.renewIfNeeded();
    expect(s.meta.dump()).not.toContain("secret-token");
  });
  it("customer register + login use customer scope (tenant 0)", async () => {
    const dto = vendorDto({ app: "customer", tenant: null, role: null, permissions: [], store_id: null });
    const s = setup({ appKind: "customer", dto });
    const p = await s.mgr.register({ name: "Ana", email: "ana@reyes.ph", password: "pw" });
    expect(s.api.register).toHaveBeenCalledWith({ name: "Ana", email: "ana@reyes.ph", password: "pw", device_name: "Test phone" });
    expect(p.scope.key).toBe("customer:7:0");
    await s.mgr.login({ email: "ana@reyes.ph", password: "pw" });
    expect(s.api.login).toHaveBeenCalledWith({ email: "ana@reyes.ph", password: "pw", app: "customer", device_name: "Test phone" });
  });
  it("vendor sign-in without a tenant in the response is refused and nothing is stored", async () => {
    const s = setup({ dto: vendorDto({ tenant: null }) });
    await expect(s.mgr.login(creds)).rejects.toThrow(/business/);
    expect(await s.tokens.get()).toBeNull();
  });
  it("failed login changes nothing (state, token, profile)", async () => {
    const s = setup();
    await s.mgr.login(creds);
    s.api.login.mockRejectedValueOnce(new ApiError({ kind: "unauthenticated", status: 401, code: "invalid_credentials", message: "Email or password is incorrect." }));
    await expect(s.mgr.login({ ...creds, password: "bad" })).rejects.toBeInstanceOf(ApiError);
    expect(s.mgr.state().status).toBe("signed_in");
    expect(await s.tokens.get()).toBe("secret-token-AAA");
  });
});

describe("account switching", () => {
  it("different account with unsent work: UnsentWorkError BEFORE the server is called or anything changes", async () => {
    const s = setup({ unsent: { [VENDOR_3]: 4 } });
    await s.mgr.login(creds);
    s.api.login.mockClear();
    s.api.login.mockResolvedValue(vendorDto({ token: "ben-token", user: ben }));
    const err = await s.mgr.login({ email: "ben@cruz.ph", password: "pw", tenant: "reyes-hardware" }).catch((e) => e);
    expect(err).toBeInstanceOf(UnsentWorkError);
    expect(err.count).toBe(4);
    expect(s.api.login).not.toHaveBeenCalled();
    expect(s.wipe).not.toHaveBeenCalled();
    expect(await s.tokens.get()).toBe("secret-token-AAA");
    expect(s.mgr.state().profile?.user.id).toBe(7);
  });
  it("same email but the server says it is a different account (scope differs): still guarded, token not stored", async () => {
    const s = setup({ unsent: { [VENDOR_3]: 2 } });
    await s.mgr.login(creds);
    s.api.login.mockResolvedValue(vendorDto({ token: "other", user: ben })); // e-mail typed matches, identity returned differs
    await expect(s.mgr.login(creds)).rejects.toBeInstanceOf(UnsentWorkError);
    expect(s.wipe).not.toHaveBeenCalled();
    expect(await s.tokens.get()).toBe("secret-token-AAA");
  });
  it("different account, nothing unsent: previous scope is wiped (unsent included) then the new one is stored", async () => {
    const s = setup();
    await s.mgr.login(creds);
    s.api.login.mockResolvedValue(vendorDto({ token: "ben-token", user: ben }));
    const p = await s.mgr.login({ email: "ben@cruz.ph", password: "pw", tenant: "reyes-hardware" });
    expect(s.wipe).toHaveBeenCalledTimes(1);
    expect(s.wipe).toHaveBeenCalledWith(VENDOR_3, { keepUnsent: false });
    expect(p.scope.key).toBe("vendor:8:3");
    expect(await s.tokens.get()).toBe("ben-token");
  });
  it("same account signing in again keeps its cache (no wipe) even with unsent items", async () => {
    const s = setup({ unsent: { [VENDOR_3]: 9 } });
    await s.mgr.login(creds);
    await s.mgr.login({ ...creds, email: " ANA@reyes.ph " });
    expect(s.wipe).not.toHaveBeenCalled();
    expect(s.mgr.state().status).toBe("signed_in");
  });
  it("same user, different business = different scope", async () => {
    const s = setup();
    await s.mgr.login(creds);
    s.api.login.mockResolvedValue(vendorDto({ tenant: { ...tenant3, id: 4, slug: "second-branch" } }));
    const p = await s.mgr.login({ ...creds, tenant: "second-branch" });
    expect(s.wipe).toHaveBeenCalledWith(VENDOR_3, { keepUnsent: false });
    expect(p.scope.key).toBe("vendor:7:4");
  });
});

describe("logout", () => {
  it("nothing unsent: revokes server-side, wipes the scope, clears token + profile", async () => {
    const s = setup();
    await s.mgr.login(creds);
    await s.mgr.logout();
    expect(s.api.logout).toHaveBeenCalledTimes(1);
    expect(s.wipe).toHaveBeenCalledWith(VENDOR_3, { keepUnsent: false });
    expect(await s.tokens.get()).toBeNull();
    expect(await s.meta.get(SESSION_PROFILE_KEY)).toBeNull();
    expect(s.mgr.state()).toEqual({ status: "signed_out", profile: null });
    expect(await s.mgr.getToken()).toBeNull();
  });
  it("unsent work + keepUnsent undefined: UnsentWorkError and truly nothing changed (not even the server token)", async () => {
    const s = setup({ unsent: { [VENDOR_3]: 3 } });
    await s.mgr.login(creds);
    const err = await s.mgr.logout().catch((e) => e);
    expect(err).toBeInstanceOf(UnsentWorkError);
    expect(err.count).toBe(3);
    expect(s.api.logout).not.toHaveBeenCalled();
    expect(s.wipe).not.toHaveBeenCalled();
    expect(s.mgr.state().status).toBe("signed_in");
    expect(await s.tokens.get()).toBe("secret-token-AAA");
  });
  it("keepUnsent:true / false are passed through to wipe", async () => {
    const keep = setup({ unsent: { [VENDOR_3]: 3 } });
    await keep.mgr.login(creds);
    await keep.mgr.logout({ keepUnsent: true });
    expect(keep.wipe).toHaveBeenCalledWith(VENDOR_3, { keepUnsent: true });
    const drop = setup({ unsent: { [VENDOR_3]: 3 } });
    await drop.mgr.login(creds);
    await drop.mgr.logout({ keepUnsent: false });
    expect(drop.wipe).toHaveBeenCalledWith(VENDOR_3, { keepUnsent: false });
  });
  it("server errors never block logout", async () => {
    const s = setup();
    await s.mgr.login(creds);
    s.api.logout.mockRejectedValue(new ApiError({ kind: "network", message: "offline" }));
    await s.mgr.logout();
    expect(s.mgr.state().status).toBe("signed_out");
  });
  it("a hanging server call is abandoned after logoutTimeoutMs", async () => {
    vi.useFakeTimers();
    const s = setup();
    await s.mgr.login(creds);
    s.api.logout.mockImplementation(() => new Promise(() => undefined));
    const done = s.mgr.logout();
    await vi.advanceTimersByTimeAsync(60);
    await done;
    expect(s.mgr.state().status).toBe("signed_out");
    expect(await s.tokens.get()).toBeNull();
  });
  it("logging out while expired skips the server call and can discard the old account's unsent work", async () => {
    const s = setup({ unsent: { [VENDOR_3]: 2 } });
    await s.mgr.login(creds);
    await s.mgr.markExpired();
    await expect(s.mgr.logout()).rejects.toBeInstanceOf(UnsentWorkError);
    await s.mgr.logout({ keepUnsent: false });
    expect(s.api.logout).not.toHaveBeenCalled();
    expect(s.wipe).toHaveBeenCalledWith(VENDOR_3, { keepUnsent: false });
  });
});

describe("401 / 403", () => {
  it("markExpired drops only the token; profile + cache stay; getToken is null", async () => {
    const s = setup();
    await s.mgr.login(creds);
    await s.mgr.markExpired();
    expect(s.mgr.state().status).toBe("expired");
    expect(s.mgr.state().profile?.scope.key).toBe(VENDOR_3);
    expect(await s.tokens.get()).toBeNull();
    expect(await s.mgr.getToken()).toBeNull();
    expect(await s.meta.get(SESSION_PROFILE_KEY)).not.toBeNull();
    expect(s.wipe).not.toHaveBeenCalled();
    expect(s.mgr.can("pos.sell")).toBe(false);
  });
  it("a burst of 401s (and a late one after logout) is harmless", async () => {
    const s = setup();
    await s.mgr.login(creds);
    const seen = vi.fn();
    s.mgr.subscribe(seen);
    await Promise.all([s.mgr.markExpired(), s.mgr.markExpired(), s.mgr.markExpired()]);
    expect(seen).toHaveBeenCalledTimes(1);
    await s.mgr.logout({ keepUnsent: false });
    await s.mgr.markExpired();
    expect(s.mgr.state().status).toBe("signed_out");
  });
  it("expired then signing in as the SAME account resumes the same scope without wiping", async () => {
    const s = setup({ unsent: { [VENDOR_3]: 5 } });
    await s.mgr.login(creds);
    await s.mgr.markExpired();
    s.api.login.mockResolvedValue(vendorDto({ token: "secret-token-NEW" }));
    const p = await s.mgr.login(creds);
    expect(p.scope.key).toBe(VENDOR_3);
    expect(s.wipe).not.toHaveBeenCalled();
    expect(s.mgr.state().status).toBe("signed_in");
    expect(await s.mgr.getToken()).toBe("secret-token-NEW");
  });
  it("handleAccessRevoked wipes the scope INCLUDING unsent, clears token + profile, signs out", async () => {
    const s = setup({ unsent: { [VENDOR_3]: 6 } });
    await s.mgr.login(creds);
    await s.mgr.handleAccessRevoked();
    expect(s.wipe).toHaveBeenCalledWith(VENDOR_3, { keepUnsent: false });
    expect(await s.tokens.get()).toBeNull();
    expect(await s.meta.get(SESSION_PROFILE_KEY)).toBeNull();
    expect(s.mgr.state()).toEqual({ status: "signed_out", profile: null });
    await s.mgr.handleAccessRevoked(); // nothing left: no-op
    expect(s.wipe).toHaveBeenCalledTimes(1);
  });
  it("handleAccessRevoked also works while expired", async () => {
    const s = setup();
    await s.mgr.login(creds);
    await s.mgr.markExpired();
    await s.mgr.handleAccessRevoked();
    expect(s.wipe).toHaveBeenCalledWith(VENDOR_3, { keepUnsent: false });
    expect(s.mgr.state().status).toBe("signed_out");
  });
});

describe("renewIfNeeded", () => {
  it("does nothing before 75% of the lifetime has elapsed", async () => {
    const s = setup();
    await s.mgr.login(creds); // 8 h lifetime
    s.advance(6 * HOUR); // exactly 75%
    await s.mgr.renewIfNeeded();
    expect(s.api.renew).not.toHaveBeenCalled();
  });
  it("renews after 75%: stores the new token + expiry, keeps the scope", async () => {
    const s = setup();
    await s.mgr.login(creds);
    s.advance(6 * HOUR + 60_000);
    await s.mgr.renewIfNeeded();
    expect(s.api.renew).toHaveBeenCalledTimes(1);
    expect(await s.tokens.get()).toBe("secret-token-BBB");
    expect(s.mgr.state().profile?.expiresAt).toBe(iso(T0 + 6 * HOUR + 60_000 + 8 * HOUR));
    expect(s.mgr.state().profile?.scope.key).toBe(VENDOR_3);
    expect(JSON.parse((await s.meta.get(SESSION_PROFILE_KEY))!).expiresAt).toBe(s.mgr.state().profile?.expiresAt);
    expect(s.meta.dump()).not.toContain("secret-token");
    await s.mgr.renewIfNeeded(); // the lifetime restarted: no second renewal
    expect(s.api.renew).toHaveBeenCalledTimes(1);
  });
  it("renewal threshold survives an app restart (issue time is persisted)", async () => {
    const s = setup();
    await s.mgr.login(creds);
    const fresh = createSessionManager({ api: s.api, tokens: s.tokens, meta: s.meta, appKind: "vendor", wipe: s.wipe, countUnsent: s.countUnsent, now: () => T0 + 7 * HOUR });
    await fresh.restore();
    await fresh.renewIfNeeded();
    expect(s.api.renew).toHaveBeenCalledTimes(1);
  });
  it("swallows network / timeout / server errors and stays signed in", async () => {
    const s = setup();
    await s.mgr.login(creds);
    s.advance(7 * HOUR);
    for (const kind of ["network", "timeout", "server"] as const) {
      s.api.renew.mockRejectedValueOnce(new ApiError({ kind, message: kind }));
      await expect(s.mgr.renewIfNeeded()).resolves.toBeUndefined();
    }
    expect(s.mgr.state().status).toBe("signed_in");
    expect(await s.tokens.get()).toBe("secret-token-AAA");
  });
  it("a 401 on renew marks the session expired", async () => {
    const s = setup();
    await s.mgr.login(creds);
    s.advance(7 * HOUR);
    s.api.renew.mockRejectedValueOnce(new ApiError({ kind: "unauthenticated", status: 401, message: "Sign in again." }));
    await s.mgr.renewIfNeeded();
    expect(s.mgr.state().status).toBe("expired");
    expect(await s.tokens.get()).toBeNull();
  });
  it("is a no-op when signed out, expired, or the token has no expiry", async () => {
    const out = setup();
    await out.mgr.restore();
    await out.mgr.renewIfNeeded();
    const forever = setup({ dto: vendorDto({ expires_at: null }) });
    await forever.mgr.login(creds);
    forever.advance(100 * HOUR);
    await forever.mgr.renewIfNeeded();
    expect(out.api.renew).not.toHaveBeenCalled();
    expect(forever.api.renew).not.toHaveBeenCalled();
  });
  it("a token that is already past its expiry is not sent for renewal: session just expires", async () => {
    const s = setup();
    await s.mgr.login(creds);
    s.advance(9 * HOUR);
    await s.mgr.renewIfNeeded();
    expect(s.api.renew).not.toHaveBeenCalled();
    expect(s.mgr.state().status).toBe("expired");
  });
});
