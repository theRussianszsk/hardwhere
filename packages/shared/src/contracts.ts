/**
 * INTEGRATION CONTRACT between the shared-library modules. Types-only (plus two tiny helpers).
 * Implemented by:  db/, sync/        -> "data layer" (SQLite port, migrations, repos, local store, sync engine, outbox)
 *                  session/, platform/, ui/ -> "platform & session layer"
 * Consumed by:     react/ (app glue) and both apps' screens.
 *
 * Rules that every implementation must honour (they come from the product spec):
 *  - The server (Laravel/PostgreSQL) is authoritative. Local SQLite is a scoped cache + draft store + durable outbox.
 *  - Every private local row is bound to a ScopeInfo.key (account + tenant + audience). Device tags are NOT authorization.
 *  - API tokens never touch SQLite or Capacitor Preferences (see TokenStore). Photos are files, not BLOB columns.
 *  - A sync cursor is persisted only after the batch it covers was applied in ONE local transaction.
 *  - A queued command is never "done" because the network is up; only a 2xx response (or a replayed idempotent response) confirms it.
 */
import type { ApiClient } from "./api/client";
import type { AppKind, LoginInput, RegisterInput, RoleName, SyncKind, TenantDto, UserDto } from "./api/types";

// ───────────────────────────── SQL port ─────────────────────────────
export type SqlValue = string | number | null;

/** Statement execution. Implemented by both a SqlPort (auto-commit) and the tx handle passed to transaction(). */
export interface SqlExec {
  /** One or more statements, no parameters (DDL, PRAGMA). */
  exec(sql: string): Promise<void>;
  run(sql: string, params?: SqlValue[]): Promise<{ changes: number }>;
  all<T = Record<string, unknown>>(sql: string, params?: SqlValue[]): Promise<T[]>;
}

export interface SqlPort extends SqlExec {
  /** Serialised: only one transaction at a time per port; plain run/all/exec calls wait for it. Rolls back if fn throws. */
  transaction<T>(fn: (tx: SqlExec) => Promise<T>): Promise<T>;
  close(): Promise<void>;
  /** "capacitor-native" is SQLCipher-encrypted; "capacitor-web" (jeep-sqlite) and "node-test" are NOT encrypted. */
  readonly kind: "capacitor-native" | "capacitor-web" | "node-test";
  readonly encrypted: boolean;
}

// ───────────────────────────── scope ─────────────────────────────
export interface ScopeInfo {
  accountId: number;
  /** null for customers and for the anonymous public audience. */
  tenantId: number | null;
  kind: SyncKind;
  /** Stable partition key used in every table: `${kind}:${accountId}:${tenantId ?? 0}`. Anonymous public browsing uses accountId 0. */
  key: string;
}
export const makeScope = (accountId: number, tenantId: number | null, kind: SyncKind): ScopeInfo => ({ accountId, tenantId, kind, key: `${kind}:${accountId}:${tenantId ?? 0}` });

// ───────────────────────────── change bus ─────────────────────────────
export type ChangeArea = "cache" | "outbox" | "drafts" | "sync" | "session" | "photos";
export interface ChangeEvent {
  area: ChangeArea;
  /** For area "cache": the entity type that changed ("listing", "product", ...). */
  entity?: string;
}
export interface ChangeBus {
  emit(e: ChangeEvent): void;
  subscribe(listener: (e: ChangeEvent) => void): () => void;
}
export function createChangeBus(): ChangeBus {
  const ls = new Set<(e: ChangeEvent) => void>();
  return {
    emit: (e) => ls.forEach((l) => l(e)),
    subscribe: (l) => { ls.add(l); return () => ls.delete(l); },
  };
}

// ───────────────────────────── local store (what screens read) ─────────────────────────────
export interface CacheQuery {
  /** Whitespace-separated tokens, ALL must match (case-insensitive substring) against the entity's search text. */
  search?: string;
  limit?: number;
  offset?: number;
  /** Default "id". "search" sorts alphabetically by search text (names). */
  orderBy?: "id" | "search";
  /** Optional equality filters on columns the mapper put in `filters` (e.g. { store_id: 3 }). */
  filters?: Record<string, string | number>;
}
/** Read-only, already scoped view of the cache. Rows come back as the server DTO that was cached. */
export interface LocalCache {
  get<T>(entity: string, id: number): Promise<T | null>;
  list<T>(entity: string, q?: CacheQuery): Promise<T[]>;
  count(entity: string, q?: CacheQuery): Promise<number>;
  /** Exact lookup by a mapper-provided key: barcode, SKU, model number, order number. Case-insensitive. */
  findByKey<T>(entity: string, key: string): Promise<T | null>;
  /** When this entity type was last confirmed by the server, for "as of" labels. */
  lastSyncedAt(): Promise<string | null>;
}

export type OutboxState = "draft" | "queued" | "sending" | "confirmed" | "rejected" | "needs_review";
export interface OutboxItem<P = unknown, R = unknown> {
  /** Also the Idempotency-Key sent to the server. Globally unique. */
  id: string;
  kind: string;
  payload: P;
  payloadVersion: number;
  state: OutboxState;
  attempts: number;
  nextAttemptAt: string | null;
  lastError: { code: string; message: string; details?: unknown } | null;
  response: R | null;
  /** Short human text for lists: "Adjust stock: Stanley hammer −2". */
  label: string;
  createdAt: string;
  updatedAt: string;
}
export interface OutboxApi {
  enqueue<P>(kind: string, payload: P, o?: { id?: string; label?: string; state?: "draft" | "queued"; payloadVersion?: number }): Promise<OutboxItem<P>>;
  list(filter?: { kinds?: string[]; states?: OutboxState[]; limit?: number }): Promise<OutboxItem[]>;
  get<P = unknown, R = unknown>(id: string): Promise<OutboxItem<P, R> | null>;
  /** draft -> queued */
  submitDraft(id: string): Promise<void>;
  /** user-triggered retry: queued/needs_review/rejected -> queued, due now, attempts reset */
  retryNow(id: string): Promise<void>;
  discard(id: string): Promise<void>;
  countsByState(): Promise<Record<OutboxState, number>>;
  /** draft + queued + sending + needs_review + rejected: things the user has not seen succeed. */
  unsentCount(): Promise<number>;
}

export interface DraftsApi {
  put<T>(kind: string, id: string, payload: T): Promise<void>;
  get<T>(kind: string, id: string): Promise<T | null>;
  list<T>(kind: string): Promise<{ id: string; payload: T; updatedAt: string }[]>;
  remove(kind: string, id: string): Promise<void>;
}

/** Metadata for photos waiting to upload. The image itself lives in app-private file storage (PhotoStore), never in SQLite. */
export interface PhotoMeta { id: string; fileRef: string; mime: string; size: number; purpose: string; hint: string | null; outboxId: string | null; createdAt: string }
export interface PhotosApi {
  add(m: Omit<PhotoMeta, "createdAt">): Promise<void>;
  list(): Promise<PhotoMeta[]>;
  remove(id: string): Promise<void>;
}

export interface LocalStore {
  readonly scope: ScopeInfo;
  readonly cache: LocalCache;
  readonly drafts: DraftsApi;
  readonly outbox: OutboxApi;
  readonly photos: PhotosApi;
  onChange(listener: (e: ChangeEvent) => void): () => void;
}

/** Non-secret key/value metadata that is not scope-bound (signed-in profile, last account, install id). */
export interface MetaStore {
  get(key: string): Promise<string | null>;
  set(key: string, value: string): Promise<void>;
  delete(key: string): Promise<void>;
}

// ───────────────────────────── sync ─────────────────────────────
export type SyncPhase = "idle" | "syncing" | "error";
export interface SyncStatus {
  phase: SyncPhase;
  /** False until a full snapshot completed for this scope. Screens show "first launch / empty cache" states from this. */
  snapshotComplete: boolean;
  lastSyncedAt: string | null;
  lastAttemptAt: string | null;
  error: { kind: string; code: string; message: string } | null;
  /** lastSyncedAt older than staleAfterMs (default 15 min) or never synced. */
  stale: boolean;
}
export interface SyncResult { mode: "snapshot" | "delta" | "none"; applied: number; deleted: number; cursor: string | null; pages: number }
export interface SyncEngine {
  /** Never throws for network/server failures: they land in status().error. Throws only on programmer errors. Calls are coalesced. */
  syncNow(opts?: { forceSnapshot?: boolean }): Promise<SyncResult>;
  status(): SyncStatus;
  subscribe(listener: () => void): () => void;
}
/** What a mapper turns a server DTO into before it is cached. */
export interface CacheRow {
  id: number;
  payload: unknown;
  /** lowercase text used by CacheQuery.search */
  search: string;
  /** exact-lookup keys (barcodes, SKU, model numbers, order no.) */
  keys: string[];
  rowVersion: number | null;
  filters?: Record<string, string | number>;
}
export type EntityMapper = (dto: any) => CacheRow;
export interface SyncEngineOptions {
  api: Pick<ApiClient, "syncSnapshot" | "syncChanges">;
  port: SqlPort;
  scope: ScopeInfo;
  bus?: ChangeBus;
  now?: () => number;
  staleAfterMs?: number;
  mappers?: Partial<Record<string, EntityMapper>>;
  /** 403 membership_revoked / no_business_access seen while syncing. */
  onAccessRevoked?: () => void;
  /** 401 seen while syncing. */
  onUnauthenticated?: () => void;
}

// ───────────────────────────── outbox processing ─────────────────────────────
export interface CommandHandler<P = any, R = any> {
  /**
   * false for commands a human should confirm before they are re-sent after an interruption (e.g. a POS sale whose response
   * was lost): those end in "needs_review" instead of being retried in the background.
   */
  autoRetry: boolean;
  /** Must send ctx.idempotencyKey as the Idempotency-Key header. Resolve only on a 2xx/replayed response. */
  run(payload: P, ctx: { idempotencyKey: string; attempt: number }): Promise<R>;
}
export interface BackoffOptions { baseMs: number; capMs: number; /** 0.2 = ±20% */ jitter: number }
export interface OutboxProcessorOptions {
  port: SqlPort;
  scope: ScopeInfo;
  handlers: Record<string, CommandHandler>;
  bus?: ChangeBus;
  now?: () => number;
  random?: () => number;
  maxAttempts?: number;
  backoff?: BackoffOptions;
}
export interface OutboxProcessor {
  /** Sends every due command once, oldest first. Stops early (authRequired) on the first 401. */
  processDue(): Promise<{ processed: number; confirmed: number; authRequired: boolean; nextWakeAt: number | null }>;
  /** App start: commands left in "sending" by a crash/kill go back to "queued" (same id => same idempotency key). */
  recoverInterrupted(): Promise<number>;
}

// ───────────────────────────── data-layer factory signatures ─────────────────────────────
export type OpenLocalDatabase = (o: { name: string; keychainPrefix: string; allowUnencryptedWeb?: boolean }) => Promise<SqlPort>;
export type Migrate = (port: SqlPort) => Promise<{ from: number; to: number }>;
export type CreateMetaStore = (port: SqlPort) => MetaStore;
export type CreateLocalStore = (port: SqlPort, scope: ScopeInfo, bus: ChangeBus) => LocalStore;
export type CreateSyncEngine = (o: SyncEngineOptions) => SyncEngine;
export type CreateOutboxProcessor = (o: OutboxProcessorOptions) => OutboxProcessor;
/** Remove every cache/sync row for a scope. keepUnsent=true keeps outbox/drafts/photos rows (same account may resume); false deletes them too. */
export type WipeScope = (port: SqlPort, scopeKey: string, o: { keepUnsent: boolean }) => Promise<void>;
/** Count of items in the scope the user has not seen succeed (see OutboxApi.unsentCount) + drafts. */
export type CountUnsent = (port: SqlPort, scopeKey: string) => Promise<number>;

// ───────────────────────────── session / platform layer ─────────────────────────────
/** Holds the API bearer token in platform-protected storage (Keychain / Android Keystore-backed). */
export interface TokenStore {
  get(): Promise<string | null>;
  set(token: string): Promise<void>;
  clear(): Promise<void>;
  /** false for in-memory/dev stores: the UI shows "development storage" in settings. */
  readonly protected: boolean;
}

export interface SessionProfile {
  user: UserDto;
  appKind: AppKind;
  tenant: TenantDto | null;
  role: RoleName | null;
  permissions: string[];
  storeId: number | null;
  expiresAt: string | null;
  scope: ScopeInfo;
}
export type AuthStatus = "restoring" | "signed_out" | "signed_in" | "expired";
export interface AuthState { status: AuthStatus; profile: SessionProfile | null }

export interface SessionManager {
  state(): AuthState;
  subscribe(listener: () => void): () => void;
  /** App start: read protected token + stored profile; never trusts them as authorization (server confirms on first call). */
  restore(): Promise<AuthState>;
  login(input: Omit<LoginInput, "app" | "device_name">): Promise<SessionProfile>;
  register(input: Omit<RegisterInput, "device_name">): Promise<SessionProfile>;
  /** Wipes private cache + credentials. If unsent items exist and keepUnsent is undefined it throws UnsentWorkError so the UI can ask. */
  logout(o?: { keepUnsent?: boolean }): Promise<void>;
  unsentCount(): Promise<number>;
  can(permission: string): boolean;
  /** Rotates the token when more than ~75% of its life has passed. No-op if signed out or offline. */
  renewIfNeeded(): Promise<void>;
  /** 401 received: drop the token but keep the profile+cache so the user can sign back in to the SAME account and resume. */
  markExpired(): Promise<void>;
  /** 403 membership revoked: wipe everything private for that scope, unsent items included (they can no longer be authorized). */
  handleAccessRevoked(): Promise<void>;
  getToken(): Promise<string | null>;
}
export class UnsentWorkError extends Error {
  constructor(public readonly count: number) { super(`${count} unsent item(s) would be lost`); this.name = "UnsentWorkError"; }
}

/** App-private image files (never SQLite BLOBs). Native: Capacitor Filesystem Directory.Data. Web: IndexedDB. */
export interface PhotoStore {
  save(blob: Blob): Promise<{ ref: string; mime: string; size: number }>;
  load(ref: string): Promise<Blob>;
  remove(ref: string): Promise<void>;
}

export interface ConnectivitySnapshot {
  /** Device radio/Network plugin says it has a connection. NOT proof that our server is reachable. */
  deviceOnline: boolean;
  /** Result of the last real probe of the API health endpoint; null = never probed. */
  serverReachable: boolean | null;
  lastProbeAt: number | null;
}
export interface Connectivity {
  snapshot(): ConnectivitySnapshot;
  subscribe(listener: () => void): () => void;
  /** Probe the API now (4 s timeout). Updates serverReachable. */
  probe(): Promise<boolean>;
  /** Fire `fn` when the app returns to the foreground or the network comes back (debounced). Returns unsubscribe. */
  onResumeOrReconnect(fn: () => void): () => void;
  dispose(): void;
}

export class PermissionDeniedError extends Error {
  constructor(public readonly what: "camera" | "photos" | "location", message?: string) { super(message ?? `${what} permission was denied`); this.name = "PermissionDeniedError"; }
}
export class CancelledError extends Error { constructor() { super("cancelled"); this.name = "CancelledError"; } }
export class UnsupportedError extends Error { constructor(what: string) { super(`${what} is not supported on this device`); this.name = "UnsupportedError"; } }
