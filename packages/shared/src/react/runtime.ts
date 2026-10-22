/**
 * Non-React application runtime: opens the encrypted local DB, builds the API client + session, and keeps ONE active
 * scope (LocalStore + SyncEngine + OutboxProcessor) in step with the signed-in account. React only subscribes to it.
 */
import { createApiClient, type ApiClient } from "../api/client";
import { createChangeBus, makeScope, type ChangeBus, type CommandHandler, type Connectivity, type LocalStore, type OutboxProcessor, type PhotoStore, type ScopeInfo, type SessionManager, type SqlPort, type SyncEngine, type SyncResult } from "../contracts";
import { countUnsent, createLocalStore, createMetaStore, listPhotoRefs, migrate, openLocalDatabase, wipeScope } from "../db";
import { createConnectivity, createPhotoStore } from "../platform";
import { createSecureTokenStore, createSessionManager } from "../session";
import { createOutboxProcessor, createSyncEngine } from "../sync";
import { singleFlight } from "./singleFlight";

export interface HandlerDeps { api: ApiClient; photos: PhotoStore; store: LocalStore }
export interface AppConfig {
  appKind: "vendor" | "customer";
  apiBaseUrl: string;
  dbName: string;
  keychainPrefix: string;
  /** Builds this app's outbox command handlers (kind -> handler). */
  commandHandlers: (deps: HandlerDeps) => Record<string, CommandHandler>;
  /** Customer app: browse the public catalogue (anonymous audience) while signed out. */
  anonymousBrowse?: boolean;
  syncIntervalMs?: number;
}

export interface ActiveScope {
  scope: ScopeInfo;
  store: LocalStore;
  sync: SyncEngine;
  /** Resolves once this scope's persisted sync state has been read. */
  ready: Promise<void>;
  outbox: OutboxProcessor;
  syncNow: (forceSnapshot?: boolean) => Promise<SyncResult>;
  kickOutbox: () => Promise<void>;
  dispose: () => void;
}

export interface AppServices {
  config: AppConfig;
  api: ApiClient;
  session: SessionManager;
  connectivity: Connectivity;
  photos: PhotoStore;
  port: SqlPort;
  bus: ChangeBus;
  active(): ActiveScope | null;
  subscribeActive(listener: () => void): () => void;
  /** Re-run sync + outbox now (pull-to-refresh, "Retry" buttons). */
  refresh(): Promise<void>;
  dispose(): void;
}

let booting: Promise<AppServices> | null = null;
/** Single boot per page load (React StrictMode mounts twice in dev; a second connection to the same DB would fail). */
export function bootApp(config: AppConfig): Promise<AppServices> {
  return (booting ??= doBoot(config));
}

async function doBoot(config: AppConfig): Promise<AppServices> {
  const port = await openLocalDatabase({ name: config.dbName, keychainPrefix: config.keychainPrefix, allowUnencryptedWeb: true });
  await migrate(port);
  const meta = createMetaStore(port);
  const bus = createChangeBus();
  const tokens = createSecureTokenStore({ keyPrefix: config.keychainPrefix });
  const photos = createPhotoStore();

  // The API client needs the token; the session needs the client to log in: break the cycle with a late-bound reference.
  let sessionRef: SessionManager;
  const api = createApiClient({
    baseUrl: config.apiBaseUrl,
    getToken: () => sessionRef.getToken(),
    onUnauthenticated: () => void sessionRef.markExpired(),
    onAccessRevoked: () => void sessionRef.handleAccessRevoked(),
  });
  const session = (sessionRef = createSessionManager({
    api,
    tokens,
    meta,
    appKind: config.appKind,
    countUnsent: (scopeKey) => countUnsent(port, scopeKey),
    wipe: async (scopeKey, o) => {
      const refs = o.keepUnsent ? [] : await listPhotoRefs(port, scopeKey); // collect before the rows disappear
      await wipeScope(port, scopeKey, o);
      await Promise.allSettled(refs.map((r) => photos.remove(r)));
      bus.emit({ area: "session" });
    },
  }));
  const connectivity = createConnectivity({ probe: () => api.health() });

  // ───────── active scope management ─────────
  let current: ActiveScope | null = null;
  const listeners = new Set<() => void>();
  const notify = () => listeners.forEach((l) => l());

  const desiredScope = (): ScopeInfo | null => {
    const st = session.state();
    if ((st.status === "signed_in" || st.status === "expired") && st.profile) return st.profile.scope;
    if (st.status === "signed_out" && config.anonymousBrowse) return makeScope(0, null, "public");
    return null;
  };

  const build = (scope: ScopeInfo): ActiveScope => {
    const store = createLocalStore(port, scope, bus);
    const sync = createSyncEngine({
      api, port, scope, bus,
      onUnauthenticated: () => void session.markExpired(),
      onAccessRevoked: () => void session.handleAccessRevoked(),
    });
    const outbox = createOutboxProcessor({
      port, scope, bus, handlers: config.commandHandlers({ api, photos, store }),
      onPhotosReleased: async (refs) => void (await Promise.allSettled(refs.map((r) => photos.remove(r)))), // confirmed uploads: delete the private file
    });
    let wake: ReturnType<typeof setTimeout> | undefined;
    let dead = false;

    const syncNow = (forceSnapshot = false) => sync.syncNow({ forceSnapshot });
    const kickOutbox = singleFlight(async () => {
      if (dead || session.state().status !== "signed_in" || !connectivity.snapshot().deviceOnline) return;
      const r = await outbox.processDue();
      if (r.authRequired) await session.markExpired();
      if (r.confirmed > 0) void syncNow(); // refresh the cache so screens show the server's version of what was just accepted
      if (r.nextWakeAt) {
        clearTimeout(wake);
        wake = setTimeout(() => void kickOutbox(), Math.max(1000, r.nextWakeAt - Date.now()));
      }
    });

    let debounce: ReturnType<typeof setTimeout> | undefined;
    const offBus = bus.subscribe((e) => {
      if (e.area === "outbox") { clearTimeout(debounce); debounce = setTimeout(() => void kickOutbox(), 250); }
    });
    return {
      scope, store, sync, outbox, syncNow, kickOutbox, ready: sync.ready(),
      dispose: () => { dead = true; offBus(); clearTimeout(wake); clearTimeout(debounce); },
    };
  };

  const reconcile = async () => {
    const want = desiredScope();
    if (current?.scope.key === want?.key) return;
    current?.dispose();
    current = want ? build(want) : null;
    notify();
    if (current) {
      const c = current;
      await c.ready; // persisted sync state is read: first-launch / stale labels are accurate from the first render
      notify();
      await c.outbox.recoverInterrupted();
      void c.syncNow().then(() => c.kickOutbox());
    }
  };

  const offSession = session.subscribe(() => void reconcile());
  const refresh = async () => {
    if (!current) return;
    await Promise.allSettled([current.syncNow(), current.kickOutbox(), session.renewIfNeeded()]);
  };
  const offResume = connectivity.onResumeOrReconnect(() => void refresh());
  const interval = setInterval(() => {
    if (typeof document === "undefined" || document.visibilityState === "visible") void refresh();
  }, config.syncIntervalMs ?? 180_000);

  await session.restore();
  await reconcile();
  void connectivity.probe();

  return {
    config, api, session, connectivity, photos, port, bus,
    active: () => current,
    subscribeActive: (l) => { listeners.add(l); return () => listeners.delete(l); },
    refresh,
    dispose: () => { offSession(); offResume(); clearInterval(interval); current?.dispose(); connectivity.dispose(); void port.close(); booting = null; },
  };
}
