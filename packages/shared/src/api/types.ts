/**
 * Wire types for the Laravel API (/api/v1). Money is always integer centavos.
 * These are checked against real server responses: packages/shared/contract/check.ts type-checks the fixtures
 * that the PHP feature tests write, so a server change that is not mirrored here fails `tsc`.
 */
export type Cents = number;
export type IsoDateTime = string;
export type Attrs = Partial<Record<string, string | number | boolean>>;

export interface PageMeta {
  current_page: number;
  from: number | null;
  last_page: number;
  per_page: number;
  to: number | null;
  total: number;
  path: string;
  links: { url: string | null; label: string; page: number | null; active: boolean }[];
}
export interface Paginated<T> {
  data: T[];
  links: { first: string | null; last: string | null; prev: string | null; next: string | null };
  meta: PageMeta;
}
export interface Wrapped<T> {
  data: T;
  replayed?: boolean;
}

export interface ApiErrorBody {
  error: { code: string; message: string; details?: Record<string, unknown>; fields?: Record<string, string[]> };
}

// ---- auth
export interface UserDto {
  id: number;
  name: string;
  email: string;
  phone: string | null;
}
export interface TenantDto {
  id: number;
  slug: string;
  name: string;
  vat_rate_bps: number;
}
export type AppKind = "vendor" | "customer" | "admin";
export type RoleName = "owner" | "store_manager" | "inventory_manager" | "cashier" | "marketplace_manager" | "staff";
export interface SessionDto {
  token: string;
  expires_at: string | null;
  user: UserDto;
  app: AppKind;
  tenant: TenantDto | null;
  role: RoleName | null;
  permissions: string[];
  store_id: number | null;
}
export type MeDto = Omit<SessionDto, "token" | "expires_at">;
export interface LoginInput {
  email: string;
  password: string;
  app: AppKind;
  tenant?: string;
  device_name?: string;
}
export interface RegisterInput {
  name: string;
  email: string;
  password: string;
  phone?: string;
  device_name?: string;
}

// ---- catalog
export interface CategoryDto {
  id: number;
  parent_id: number | null;
  slug: string;
  name: string;
  sort: number;
  updated_at: IsoDateTime | null;
}
export interface BarcodeDto {
  code: string;
  type: string;
}
export interface VariantDto {
  id: number;
  product_id: number;
  name: string;
  model_number: string | null;
  part_number: string | null;
  attributes: Attrs;
  uom: string;
  pack_quantity: number;
  is_default: boolean;
  status: string;
  barcodes?: BarcodeDto[];
}
export interface ProductDto {
  id: number;
  category?: { id: number; slug: string; name: string };
  category_id: number;
  brand: string | null;
  manufacturer: string | null;
  name: string;
  model_number: string | null;
  product_type: string | null;
  description: string | null;
  attributes: Attrs;
  compatibility: unknown[];
  included_components: unknown[];
  safety_info: string | null;
  warranty: string | null;
  ph_specs: Partial<Record<string, string>>;
  synonyms: string[];
  status: string;
  images?: { url: string; alt: string | null; is_primary: boolean }[];
  variants?: VariantDto[];
  min_price_cents?: Cents;
  max_price_cents?: Cents;
  store_count?: number;
  /** Search with a location only: km to the closest store that sells it. */
  nearest_km?: number;
  relevance?: number;
  row_version: number;
  updated_at: IsoDateTime | null;
}

// ---- search
export type SearchSort = "relevance" | "nearest" | "price_asc" | "price_desc";
/** Traditional search uses q/category/sort/in_stock; the detailed search adds the explicit filters. */
export interface PublicSearchQuery {
  q?: string;
  brand?: string;
  model?: string;
  category?: string;
  sort?: SearchSort;
  in_stock?: boolean;
  lat?: number;
  lng?: number;
  max_km?: number;
  min_price_cents?: Cents;
  max_price_cents?: Cents;
  fulfillment?: "pickup" | "delivery";
  /** attribute key -> exact value, e.g. { voltage_v: "20" } */
  spec?: Record<string, string>;
  page?: number;
  per_page?: number;
}
export interface SearchMetaExtra {
  server_time: string;
  /** When nothing is on sale: catalogue products that match but no registered store sells yet. */
  unlisted_matches?: { id: number; name: string; brand: string | null; model_number: string | null }[];
}
export interface SearchFiltersDto {
  brands: string[];
  specs: { key: string; label: string; unit: string | null; values: string[] }[];
}

// ---- stores & listings
export interface PublicStoreDto {
  id: number;
  business_name?: string;
  name: string;
  address_line: string;
  barangay: string | null;
  city: string;
  province: string;
  postal_code: string | null;
  lat: number | null;
  lng: number | null;
  phone: string | null;
  email: string | null;
  hours: Partial<Record<string, string[]>>;
  supports_pickup: boolean;
  supports_delivery: boolean;
  directions_url: string;
  updated_at: IsoDateTime | null;
  /** Store directory only: live marketplace listings at this store. */
  listing_count?: number;
  /** Store directory only, when a location was sent. */
  distance_km?: number;
}
export type AvailabilityStatus = "in_stock" | "low_stock" | "out_of_stock";
export interface Availability {
  status: AvailabilityStatus;
  /** Exact count only when the store opted in to showing exact stock; otherwise null. */
  quantity: number | null;
}
/** `details` of a 409 `stock_unavailable` from POST /customer/orders. `available` is sent only when the store shows exact stock. */
export interface OrderStockConflictDetails {
  listing_id: number;
  requested: number;
  availability: AvailabilityStatus;
  available?: number;
}
export interface PublicListingDto {
  id: number;
  product_id: number;
  variant_id: number;
  title: string | null;
  description: string | null;
  sku: string;
  price_cents: Cents;
  sale_price_cents: Cents | null;
  currency: string;
  uom: string;
  availability: Availability;
  pickup_available: boolean;
  delivery_available: boolean;
  min_order_qty: number;
  max_order_qty: number | null;
  lead_time_hours: number;
  store?: PublicStoreDto;
  variant?: VariantDto;
  /** Store listings only: the canonical product's primary image (API-relative). */
  image_url?: string | null;
  distance_km?: number;
  row_version: number;
  updated_at: IsoDateTime | null;
}
export interface StoreDto {
  id: number;
  name: string;
  code: string;
  address_line: string;
  barangay: string | null;
  city: string;
  province: string;
  postal_code: string | null;
  lat: number | null;
  lng: number | null;
  phone: string | null;
  email: string | null;
  hours: Partial<Record<string, string[]>>;
  supports_pickup: boolean;
  supports_delivery: boolean;
  is_active: boolean;
  row_version: number;
  updated_at: IsoDateTime | null;
}
export interface LocationDto {
  id: number;
  store_id: number;
  name: string;
  code: string;
  is_sellable: boolean;
}
export interface TenantListingDto {
  id: number;
  store_id: number;
  inventory_item_id: number;
  product_id: number;
  variant_id: number;
  sku: string;
  title: string | null;
  title_override: string | null;
  description: string | null;
  price_cents: Cents;
  sale_price_cents: Cents | null;
  currency: string;
  uom: string;
  visibility: "public" | "hidden";
  publication_status: "draft" | "published" | "unpublished";
  pickup_available: boolean;
  delivery_available: boolean;
  min_order_qty: number;
  max_order_qty: number | null;
  lead_time_hours: number;
  stock_buffer: number;
  show_exact_stock: boolean;
  /** Last known sellable quantity. Cached copies are NOT live stock. */
  available_quantity?: number;
  variant?: VariantDto;
  product?: { id: number; name: string; brand: string | null; category: string | null };
  row_version: number;
  updated_at: IsoDateTime | null;
  deleted_at: IsoDateTime | null;
}

// ---- inventory
export interface InventoryItemDto {
  id: number;
  variant_id: number;
  sku: string;
  name?: string;
  brand?: string | null;
  product_name?: string;
  barcodes?: string[];
  reorder_threshold: number;
  track_stock: boolean;
  default_location_id: number | null;
  on_hand: number | null;
  reserved: number | null;
  available: number | null;
  low_stock: boolean | null;
  locations?: { location_id: number; on_hand: number; reserved: number }[];
  /** Present only for roles with inventory.cost.view. */
  cost_cents?: Cents;
  supplier_id?: number | null;
  row_version: number;
  updated_at: IsoDateTime | null;
  deleted_at: IsoDateTime | null;
}
export type MovementType =
  | "opening_balance" | "purchase_receipt" | "manual_adjustment" | "sale" | "return" | "reservation" | "reservation_release"
  | "order_fulfillment" | "transfer_in" | "transfer_out" | "damage" | "loss" | "correction";
export interface LedgerEntryDto {
  id: number;
  inventory_item_id: number;
  location_id: number;
  movement_type: MovementType;
  quantity_delta: number;
  before_quantity: number;
  after_quantity: number;
  reserved_delta: number;
  before_reserved: number;
  after_reserved: number;
  reference_type: string | null;
  reference_id: number | null;
  actor_type: "user" | "system";
  actor_id: number | null;
  note: string | null;
  occurred_at: IsoDateTime;
}
export interface SupplierDto {
  id: number;
  name: string;
  contact_name: string | null;
  phone: string | null;
  email: string | null;
  lead_time_days: number | null;
  notes: string | null;
}
export interface StaffDto {
  id: number;
  user_id: number;
  name: string;
  email: string;
  role: RoleName;
  store_id: number | null;
}
export interface DashboardDto {
  sales_today_count: number;
  sales_today_cents: Cents;
  low_stock_count: number;
  open_orders: number | null;
  published_listings: number;
  inventory_items: number;
}
export interface RegisterDto {
  id: number;
  store_id: number;
  name: string;
  is_active: boolean;
}
export interface PosSessionDto {
  id: number;
  register_id: number;
  opened_at: IsoDateTime;
  closed_at: IsoDateTime | null;
  opening_float_cents: Cents;
  closing_cash_cents: Cents | null;
}

// ---- sales
export type PaymentMethod = "cash" | "gcash" | "maya" | "card" | "bank_transfer" | "other";
export interface SaleItemDto {
  id: number;
  listing_id: number;
  sku: string;
  name: string;
  quantity: number;
  unit_price_cents: Cents;
  discount_cents: Cents;
  vat_cents: Cents;
  line_total_cents: Cents;
  returned_quantity: number;
}
export interface SaleDto {
  id: number;
  receipt_no: string | null;
  store_id: number;
  status: "completed" | "voided";
  cashier_id: number;
  subtotal_cents: Cents;
  discount_cents: Cents;
  vat_cents: Cents;
  total_cents: Cents;
  payment_method: PaymentMethod;
  payment_reference: string | null;
  tendered_cents: Cents | null;
  change_cents: Cents;
  customer_name: string | null;
  customer_phone: string | null;
  completed_at: IsoDateTime;
  voided_at: IsoDateTime | null;
  void_reason: string | null;
  items?: SaleItemDto[];
  low_stock?: { inventory_item_id: number; sku: string; remaining: number; reorder_threshold: number }[];
}
export interface CheckoutInput {
  store_id?: number;
  pos_session_id?: number;
  items: { listing_id: number; quantity: number; discount_cents?: Cents; expected_unit_price_cents?: Cents }[];
  payment: { method: PaymentMethod; reference?: string; tendered_cents?: Cents };
  customer?: { name?: string; phone?: string };
}

// ---- orders
export type OrderStatus =
  | "pending" | "reserved" | "confirmed" | "preparing" | "ready_for_pickup" | "fulfilled" | "cancelled" | "expired" | "failed";
export type FulfillmentType = "pickup" | "reservation" | "delivery";
export interface OrderItemDto {
  id: number;
  listing_id: number;
  variant_id: number;
  sku: string;
  name: string;
  quantity: number;
  unit_price_cents: Cents;
  line_total_cents: Cents;
}
export interface OrderDto {
  id: number;
  order_no: string | null;
  status: OrderStatus;
  fulfillment_type: FulfillmentType;
  store?: PublicStoreDto;
  store_id: number;
  subtotal_cents: Cents;
  delivery_fee_cents: Cents;
  total_cents: Cents;
  contact_name: string;
  contact_phone: string;
  delivery_address: string | null;
  notes: string | null;
  placed_at: IsoDateTime;
  confirmed_at: IsoDateTime | null;
  ready_at: IsoDateTime | null;
  fulfilled_at: IsoDateTime | null;
  cancelled_at: IsoDateTime | null;
  cancel_reason: string | null;
  reservation?: { status: "active" | "released" | "converted" | "expired" | "cancelled"; expires_at: IsoDateTime } | null;
  items?: OrderItemDto[];
  customer_can_cancel: boolean;
  next_statuses: OrderStatus[];
  row_version: number | null;
  updated_at: IsoDateTime | null;
}
export interface PlaceOrderInput {
  items: { listing_id: number; quantity: number; expected_unit_price_cents?: Cents }[];
  fulfillment_type: FulfillmentType;
  contact_name: string;
  contact_phone: string;
  delivery_address?: string;
  notes?: string;
}
export interface CartDto {
  items: { listing_id: number; quantity: number; listing: PublicListingDto | null }[];
  updated_at: IsoDateTime | null;
}

// ---- AI
export interface ExtractedProduct {
  name: string | null;
  brand: string | null;
  model: string | null;
  part_number: string | null;
  category: string | null;
  attributes: Attrs;
  pack_quantity: number | null;
  barcode_text: string | null;
  confidence: Record<string, number>;
  overall_confidence: number;
  image_sufficient: boolean;
  /** false: not something a hardware store sells; the analysis is then classified "not_hardware" with no candidates. */
  is_hardware: boolean;
  notes: string[];
}
export type MatchClass =
  | "exact_match" | "likely_same_product" | "likely_variant" | "possible_duplicate" | "new_product_candidate" | "insufficient_confidence";
/** Analysis-level classification: a match class, or "not_hardware" (never on a candidate). */
export type AnalysisClass = MatchClass | "not_hardware";
export interface MatchCandidateDto {
  rank: number;
  classification: MatchClass;
  score: number;
  matched_fields: string[];
  mismatched_fields: string[];
  warnings: string[];
  product: ProductDto;
  variant: VariantDto | null;
}
export interface AiAnalysisDto {
  id: number;
  purpose: "intake" | "pos_search" | "marketplace_search";
  is_mock: boolean;
  /** Says whether the result is a deterministic demo or live inference; the provider/model is never sent to clients. */
  provider_label: string;
  extracted: ExtractedProduct;
  classification: AnalysisClass;
  overall_confidence: number;
  requires_confirmation: boolean;
  warnings: string[];
  candidates?: MatchCandidateDto[];
  decided?: boolean;
  created_at: IsoDateTime;
}
export type IntakeDecision = "confirmed_existing" | "selected_variant" | "created_candidate" | "rejected";
export interface ConfirmIntakeInput {
  decision: IntakeDecision;
  variant_id?: number;
  new_product?: {
    category_id: number;
    name: string;
    brand?: string;
    model_number?: string;
    description?: string;
    attributes?: Attrs;
    variants?: { name: string; model_number?: string; attributes?: Attrs; pack_quantity?: number; uom?: string; barcodes?: Record<string, string> }[];
  };
  inventory?: {
    tenant_sku: string;
    cost_cents: Cents;
    price_cents: Cents;
    quantity: number;
    reorder_threshold?: number;
    supplier_id?: number;
    store_id: number;
    location_id?: number;
  };
}
export interface PosPhotoSearchResult {
  analysis: AiAnalysisDto;
  results: { rank: number; classification: MatchClass; score: number; warnings: string[]; listing: TenantListingDto | null }[];
}

// ---- sync
export interface SnapshotPage<T = unknown> {
  entity: string;
  items: T[];
  next_after: number | null;
  cursor: string;
  entities: string[];
  server_time: IsoDateTime;
}
export interface ChangeOp<T = unknown> {
  entity: string;
  id: number;
  op: "upsert" | "delete";
  data: T | null;
}
export interface ChangesResponse {
  changes: ChangeOp[];
  cursor: string;
  has_more: boolean;
  server_time: IsoDateTime;
}
export type SyncKind = "vendor" | "customer" | "public";
