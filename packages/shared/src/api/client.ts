import manifest from "../../contract/endpoints.json";
import { ApiError } from "./errors";
import type * as T from "./types";

type EndpointName = (typeof manifest.endpoints)[number]["name"];
interface Endpoint { name: string; method: string; path: string; auth: string; app: string }
const ENDPOINTS = new Map<string, Endpoint>((manifest.endpoints as Endpoint[]).map((e) => [e.name, e]));

export interface ApiClientOptions {
  baseUrl: string;
  /** Returns the bearer token for authenticated endpoints (from protected storage, never from SQLite/Preferences). */
  getToken: () => string | null | Promise<string | null>;
  /** Called on any 401 from an authenticated endpoint so the session layer can clear credentials. */
  onUnauthenticated?: () => void;
  /** Called when the server says the membership/tenant was revoked. */
  onAccessRevoked?: () => void;
  fetchImpl?: typeof fetch;
  timeoutMs?: number;
}

export interface CallOptions {
  params?: Record<string, string | number>;
  query?: Record<string, string | number | boolean | undefined | null>;
  body?: unknown;
  form?: FormData;
  /** Client-generated key; the server guarantees one business effect per key. */
  idempotencyKey?: string;
  timeoutMs?: number;
  signal?: AbortSignal;
}

export type ApiClient = ReturnType<typeof createApiClient>;

export function createApiClient(opts: ApiClientOptions) {
  const doFetch = opts.fetchImpl ?? ((...a: Parameters<typeof fetch>) => fetch(...a));
  const base = opts.baseUrl.replace(/\/+$/, "") + manifest.basePath;

  async function call<R>(name: EndpointName, o: CallOptions = {}): Promise<R> {
    const ep = ENDPOINTS.get(name);
    if (!ep) throw new Error(`Unknown endpoint "${name}" (not in contract/endpoints.json)`);

    const path = ep.path.replace(/\{(\w+)\}/g, (_, k: string) => {
      const v = o.params?.[k];
      if (v === undefined) throw new Error(`Missing path param "${k}" for ${name}`);
      return encodeURIComponent(String(v));
    });
    const qs = o.query ? Object.entries(o.query).filter(([, v]) => v !== undefined && v !== null && v !== "").map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`).join("&") : "";
    const headers: Record<string, string> = { Accept: "application/json" };
    if (ep.auth !== "none") {
      const token = await opts.getToken();
      if (token) headers.Authorization = `Bearer ${token}`;
      else if (ep.auth !== "any") throw new ApiError({ kind: "unauthenticated", status: 401, message: "Please sign in." });
    }
    if (o.idempotencyKey) headers["Idempotency-Key"] = o.idempotencyKey;
    let body: BodyInit | undefined;
    if (o.form) body = o.form; // the browser sets the multipart boundary
    else if (o.body !== undefined) { headers["Content-Type"] = "application/json"; body = JSON.stringify(o.body); }

    const ctl = new AbortController();
    const timeout = setTimeout(() => ctl.abort(new DOMException("timeout", "TimeoutError")), o.timeoutMs ?? (o.form ? 60_000 : (opts.timeoutMs ?? 15_000)));
    o.signal?.addEventListener("abort", () => ctl.abort());

    let res: Response;
    try {
      res = await doFetch(`${base}/${path}${qs ? `?${qs}` : ""}`, { method: ep.method, headers, body, signal: ctl.signal });
    } catch (e) {
      const timedOut = (e as { name?: string })?.name === "TimeoutError" || (e as { name?: string })?.name === "AbortError" && !o.signal?.aborted;
      throw new ApiError({ kind: timedOut ? "timeout" : "network", message: timedOut ? "The server took too long to answer." : "Can't reach the server." });
    } finally {
      clearTimeout(timeout);
    }

    const text = await res.text();
    let json: unknown = null;
    try { json = text ? JSON.parse(text) : null; } catch { /* non-JSON (proxy error page) */ }

    if (!res.ok) {
      const err = ApiError.fromStatus(res.status, json, res.headers.get("Retry-After"));
      if (err.kind === "unauthenticated" && ep.auth !== "none") opts.onUnauthenticated?.();
      // A failed sign-in (auth: none) says nothing about the CURRENT session, so only authenticated calls can mean "access revoked".
      if (ep.auth !== "none" && (err.code === "membership_revoked" || err.code === "no_business_access")) opts.onAccessRevoked?.();
      throw err;
    }
    if (json === null && res.status !== 204) throw new ApiError({ kind: "server", status: res.status, message: "The server sent an unreadable response." });

    return json as R;
  }

  const q = (query?: CallOptions["query"]) => ({ query });

  return {
    call,
    // ---- auth
    login: (input: T.LoginInput) => call<T.SessionDto>("login", { body: input }),
    register: (input: T.RegisterInput) => call<T.SessionDto>("register", { body: input }),
    me: () => call<T.MeDto>("me"),
    renew: () => call<T.SessionDto>("renew", { body: {} }),
    logout: () => call<{ ok: boolean }>("logout", { body: {} }),
    health: () => fetchHealth(base, doFetch),

    // ---- public / customer
    publicCategories: () => call<{ data: T.CategoryDto[] }>("publicCategories"),
    publicSearch: ({ spec, ...query }: T.PublicSearchQuery) =>
      call<T.Paginated<T.ProductDto> & { meta_extra?: T.SearchMetaExtra }>("publicSearch", q({ ...query, ...Object.fromEntries(Object.entries(spec ?? {}).map(([k, v]) => [`spec[${k}]`, v])) })),
    publicSearchFilters: (category?: string) => call<{ data: T.SearchFiltersDto; meta: { server_time: string } }>("publicSearchFilters", q({ category })),
    publicProduct: (product: number) => call<T.Wrapped<T.ProductDto>>("publicProduct", { params: { product } }),
    publicProductListings: (product: number, geo?: { lat?: number; lng?: number }) =>
      call<{ data: T.PublicListingDto[]; meta: { server_time: string; count: number } }>("publicProductListings", { params: { product }, ...q(geo) }),
    publicListing: (listing: number) => call<T.Wrapped<T.PublicListingDto>>("publicListing", { params: { listing } }),
    publicStores: (query: { q?: string; lat?: number; lng?: number; page?: number; per_page?: number } = {}) =>
      call<T.Paginated<T.PublicStoreDto> & { meta_extra?: { server_time: string } }>("publicStores", q(query)),
    publicStore: (store: number) => call<T.Wrapped<T.PublicStoreDto>>("publicStore", { params: { store } }),
    publicStoreListings: (store: number, page = 1, search?: string) => call<T.Paginated<T.PublicListingDto>>("publicStoreListings", { params: { store }, ...q({ page, q: search || undefined }) }),
    customerPhotoSearch: (image: Blob, idempotencyKey: string, hint?: string) =>
      call<{ data: T.AiAnalysisDto; meta: { server_time: string } }>("customerPhotoSearch", { form: photoForm(image, { hint }), idempotencyKey }),
    cartGet: () => call<T.Wrapped<T.CartDto>>("cartGet"),
    cartReplace: (items: { listing_id: number; quantity: number }[]) => call<T.Wrapped<T.CartDto>>("cartReplace", { body: { items } }),
    cartClear: () => call<unknown>("cartClear"),
    ordersList: (page = 1) => call<T.Paginated<T.OrderDto>>("ordersList", q({ page })),
    ordersPlace: (input: T.PlaceOrderInput, idempotencyKey: string) => call<T.Wrapped<T.OrderDto>>("ordersPlace", { body: input, idempotencyKey }),
    orderGet: (order: number) => call<T.Wrapped<T.OrderDto>>("orderGet", { params: { order } }),
    orderCancel: (order: number, reason?: string) => call<T.Wrapped<T.OrderDto>>("orderCancel", { params: { order }, body: { reason } }),

    // ---- vendor
    dashboard: () => call<T.Wrapped<T.DashboardDto>>("dashboard"),
    stores: () => call<{ data: T.StoreDto[] }>("stores"),
    locations: () => call<{ data: T.LocationDto[] }>("locations"),
    createStore: (input: Partial<T.StoreDto>) => call<T.Wrapped<T.StoreDto>>("createStore", { body: input }),
    updateStore: (store: number, input: Partial<T.StoreDto>) => call<T.Wrapped<T.StoreDto>>("updateStore", { params: { store }, body: input }),
    staffList: () => call<{ data: T.StaffDto[] }>("staffList"),
    staffAdd: (input: { email: string; name?: string; password?: string; role: string; store_id?: number | null }) => call<unknown>("staffAdd", { body: input }),
    staffUpdate: (membership: number, input: { role: string; store_id?: number | null }) => call<unknown>("staffUpdate", { params: { membership }, body: input }),
    staffRemove: (membership: number) => call<unknown>("staffRemove", { params: { membership } }),
    suppliers: () => call<{ data: T.SupplierDto[] }>("suppliers"),
    supplierCreate: (input: Partial<T.SupplierDto>) => call<T.Wrapped<T.SupplierDto>>("supplierCreate", { body: input }),
    inventoryList: (query: { q?: string; low_stock?: boolean; sort?: string; page?: number; per_page?: number }) => call<T.Paginated<T.InventoryItemDto>>("inventoryList", q(query)),
    inventoryCreate: (input: Record<string, unknown>, idempotencyKey: string) => call<T.Wrapped<T.InventoryItemDto>>("inventoryCreate", { body: input, idempotencyKey }),
    inventoryGet: (item: number) => call<T.Wrapped<T.InventoryItemDto>>("inventoryGet", { params: { item } }),
    inventoryLedger: (item: number, page = 1) => call<T.Paginated<T.LedgerEntryDto>>("inventoryLedger", { params: { item }, ...q({ page }) }),
    stockAdjust: (item: number, input: { location_id: number; movement_type: string; quantity_delta: number; note?: string }, idempotencyKey: string) =>
      call<T.Wrapped<T.LedgerEntryDto>>("stockAdjust", { params: { item }, body: input, idempotencyKey }),
    purchaseReceive: (input: Record<string, unknown>, idempotencyKey: string) => call<unknown>("purchaseReceive", { body: input, idempotencyKey }),
    catalogCategories: () => call<{ data: { id: number; parent_id: number | null; slug: string; name: string }[] }>("catalogCategories"),
    catalogSearch: (q_: string) => call<{ data: { product: T.ProductDto; variant: T.VariantDto }[] }>("catalogSearch", q({ q: q_ })),
    catalogProduct: (product: number) => call<T.Wrapped<T.ProductDto>>("catalogProduct", { params: { product } }),
    catalogCandidate: (input: Record<string, unknown>) => call<T.Wrapped<T.ProductDto>>("catalogCandidate", { body: input }),
    listingsList: (query: { q?: string; status?: string; store_id?: number; page?: number }) => call<T.Paginated<T.TenantListingDto>>("listingsList", q(query)),
    listingUpdate: (listing: number, input: Record<string, unknown>) => call<T.Wrapped<T.TenantListingDto>>("listingUpdate", { params: { listing }, body: input }),
    aiAnalyze: (image: Blob, idempotencyKey: string, hint?: string) => call<T.Wrapped<T.AiAnalysisDto>>("aiAnalyze", { form: photoForm(image, { hint }), idempotencyKey }),
    aiGet: (analysis: number) => call<T.Wrapped<T.AiAnalysisDto>>("aiGet", { params: { analysis } }),
    aiConfirm: (analysis: number, input: T.ConfirmIntakeInput, idempotencyKey: string) =>
      call<{ data: { id: number; decision: string; variant_id: number | null; inventory_item_id: number | null } }>("aiConfirm", { params: { analysis }, body: input, idempotencyKey }),
    posSearch: (query: { store_id?: number; q?: string; barcode?: string; category?: string; page?: number }) => call<T.Paginated<T.TenantListingDto>>("posSearch", q(query)),
    posPhotoSearch: (image: Blob, idempotencyKey: string, storeId?: number, hint?: string) =>
      call<T.PosPhotoSearchResult>("posPhotoSearch", { form: photoForm(image, { hint, store_id: storeId }), idempotencyKey }),
    posCheckout: (input: T.CheckoutInput, idempotencyKey: string) => call<T.Wrapped<T.SaleDto>>("posCheckout", { body: input, idempotencyKey }),
    /** Read-only: the sale this cashier's checkout with `key` created, or a 404 ApiError. Never re-sends the sale. */
    posCheckoutStatus: (key: string) => call<T.Wrapped<T.SaleDto>>("posCheckoutStatus", { params: { key } }),
    posRegisters: () => call<{ data: T.RegisterDto[] }>("posRegisters"),
    posSessionCurrent: () => call<{ data: T.PosSessionDto | null }>("posSessionCurrent"),
    posSessionOpen: (register_id: number, opening_float_cents = 0) => call<T.Wrapped<T.PosSessionDto>>("posSessionOpen", { body: { register_id, opening_float_cents } }),
    posSessionClose: (session: number, closing_cash_cents: number) => call<T.Wrapped<T.PosSessionDto>>("posSessionClose", { params: { session }, body: { closing_cash_cents } }),
    salesList: (query: { status?: string; page?: number }) => call<T.Paginated<T.SaleDto>>("salesList", q(query)),
    saleGet: (sale: number) => call<T.Wrapped<T.SaleDto>>("saleGet", { params: { sale } }),
    saleVoid: (sale: number, reason: string) => call<T.Wrapped<T.SaleDto>>("saleVoid", { params: { sale }, body: { reason } }),
    vendorOrders: (query: { status?: string; page?: number }) => call<T.Paginated<T.OrderDto>>("vendorOrders", q(query)),
    vendorOrderGet: (order: number) => call<T.Wrapped<T.OrderDto>>("vendorOrderGet", { params: { order } }),
    vendorOrderTransition: (order: number, to: string, reason?: string) => call<T.Wrapped<T.OrderDto>>("vendorOrderTransition", { params: { order }, body: { to, reason } }),

    // ---- sync (one pair per audience; the engine picks by kind)
    syncSnapshot: (kind: T.SyncKind, entity: string, after: number, limit = 200) =>
      call<T.SnapshotPage>(kind === "vendor" ? "vendorSyncSnapshot" : kind === "customer" ? "customerSyncSnapshot" : "publicSyncSnapshot", q({ entity, after, limit })),
    syncChanges: (kind: T.SyncKind, cursor: string, limit = 200) =>
      call<T.ChangesResponse>(kind === "vendor" ? "vendorSyncChanges" : kind === "customer" ? "customerSyncChanges" : "publicSyncChanges", q({ cursor, limit })),
  };
}

function photoForm(image: Blob, extra: Record<string, string | number | undefined>): FormData {
  const f = new FormData();
  f.append("image", image, image instanceof File && image.name ? image.name : "photo.jpg"); // the name is only a demo-AI hint; live models ignore it
  for (const [k, v] of Object.entries(extra)) if (v !== undefined && v !== "") f.append(k, String(v));
  return f;
}

/** The health route is outside the manifest on purpose: it is a reachability probe, not an app feature. */
async function fetchHealth(base: string, doFetch: typeof fetch): Promise<boolean> {
  const ctl = new AbortController();
  const t = setTimeout(() => ctl.abort(), 4000);
  try { return (await doFetch(`${base}/health`, { signal: ctl.signal, headers: { Accept: "application/json" } })).ok; } catch { return false; } finally { clearTimeout(t); }
}

export const endpointNames = (): string[] => [...ENDPOINTS.keys()];
