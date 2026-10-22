/**
 * Compile-time contract check. Every fixture below is a REAL response captured by the PHP feature tests
 * (api/tests/Feature/ApiContractTest.php). If the server's JSON stops matching these DTOs, `tsc` fails.
 * Regenerate fixtures with:  scripts/dphp php artisan test tests/Feature/ApiContractTest.php
 */
import type * as T from "../src/api/types";

/** JSON imports widen string literals to `string`; compare against the same shape with literal unions widened. */
type Wide<V> = V extends string ? string : V extends (infer U)[] ? Wide<U>[] : V extends object ? { [K in keyof V]: Wide<V[K]> } : V;
import apiErr409 from "./fixtures/error.stock_unavailable.json";
import apiErr422 from "./fixtures/error.validation.json";
import apiErr401 from "./fixtures/error.unauthenticated.json";
import authCustomer from "./fixtures/auth.login.customer.json";
import authVendor from "./fixtures/auth.login.vendor.json";
import authMe from "./fixtures/auth.me.json";
import custCart from "./fixtures/customer.cart.json";
import custOrderPlace from "./fixtures/customer.orders.place.json";
import custOrders from "./fixtures/customer.orders.list.json";
import custPhoto from "./fixtures/customer.photoSearch.json";
import pubCategories from "./fixtures/public.categories.json";
import pubListing from "./fixtures/public.listing.json";
import pubProduct from "./fixtures/public.product.json";
import pubProductListings from "./fixtures/public.product.listings.json";
import pubSearch from "./fixtures/public.search.json";
import pubStore from "./fixtures/public.store.json";
import syncPubChanges from "./fixtures/sync.public.changes.json";
import syncPubListing from "./fixtures/sync.public.snapshot.listing.json";
import syncPubProduct from "./fixtures/sync.public.snapshot.product.json";
import syncPubStore from "./fixtures/sync.public.snapshot.store.json";
import syncCustOrder from "./fixtures/sync.customer.snapshot.order.json";
import syncVInv from "./fixtures/sync.vendor.snapshot.inventory_item.json";
import syncVListing from "./fixtures/sync.vendor.snapshot.listing.json";
import syncVOrder from "./fixtures/sync.vendor.snapshot.order.json";
import syncVStore from "./fixtures/sync.vendor.snapshot.store.json";
import syncVCategory from "./fixtures/sync.vendor.snapshot.category.json";
import syncVProduct from "./fixtures/sync.vendor.snapshot.product.json";
import vAiAnalysis from "./fixtures/vendor.ai.analysis.json";
import vDashboard from "./fixtures/vendor.dashboard.json";
import vInvGet from "./fixtures/vendor.inventory.get.json";
import vInvLedger from "./fixtures/vendor.inventory.ledger.json";
import vInvList from "./fixtures/vendor.inventory.list.json";
import vListings from "./fixtures/vendor.listings.list.json";
import vLocations from "./fixtures/vendor.locations.json";
import vOrders from "./fixtures/vendor.orders.list.json";
import vCheckout from "./fixtures/vendor.pos.checkout.json";
import vPosPhoto from "./fixtures/vendor.pos.photoSearch.json";
import vPosSearch from "./fixtures/vendor.pos.search.json";
import vSales from "./fixtures/vendor.sales.list.json";
import vStaff from "./fixtures/vendor.staff.json";
import vStores from "./fixtures/vendor.stores.json";
import vSuppliers from "./fixtures/vendor.suppliers.json";
import vCatalogSearch from "./fixtures/vendor.catalog.search.json";

export const contractChecks = [
  authVendor satisfies Wide<T.SessionDto>,
  authCustomer satisfies Wide<T.SessionDto>,
  authMe satisfies Wide<T.MeDto>,
  apiErr409 satisfies Wide<T.ApiErrorBody>,
  apiErr422 satisfies Wide<T.ApiErrorBody>,
  apiErr401 satisfies Wide<T.ApiErrorBody>,
  pubCategories satisfies Wide<{ data: T.CategoryDto[] }>,
  pubSearch satisfies Wide<T.Paginated<T.ProductDto>>,
  pubProduct satisfies Wide<T.Wrapped<T.ProductDto>>,
  pubProductListings satisfies Wide<{ data: T.PublicListingDto[]; meta: { server_time: string; count: number } }>,
  pubListing satisfies Wide<T.Wrapped<T.PublicListingDto>>,
  pubStore satisfies Wide<T.Wrapped<T.PublicStoreDto>>,
  custCart satisfies Wide<T.Wrapped<T.CartDto>>,
  custOrderPlace satisfies Wide<T.Wrapped<T.OrderDto>>,
  custOrders satisfies Wide<T.Paginated<T.OrderDto>>,
  custPhoto satisfies Wide<{ data: T.AiAnalysisDto; meta: { server_time: string } }>,
  vDashboard satisfies Wide<T.Wrapped<T.DashboardDto>>,
  vInvList satisfies Wide<T.Paginated<T.InventoryItemDto>>,
  vInvGet satisfies Wide<T.Wrapped<T.InventoryItemDto>>,
  vInvLedger satisfies Wide<T.Paginated<T.LedgerEntryDto>>,
  vListings satisfies Wide<T.Paginated<T.TenantListingDto>>,
  vLocations satisfies Wide<{ data: T.LocationDto[] }>,
  vStores satisfies Wide<{ data: T.StoreDto[] }>,
  vStaff satisfies Wide<{ data: T.StaffDto[] }>,
  vSuppliers satisfies Wide<{ data: T.SupplierDto[] }>,
  vOrders satisfies Wide<T.Paginated<T.OrderDto>>,
  vSales satisfies Wide<T.Paginated<T.SaleDto>>,
  vCheckout satisfies Wide<T.Wrapped<T.SaleDto>>,
  vPosSearch satisfies Wide<T.Paginated<T.TenantListingDto>>,
  vPosPhoto satisfies Wide<T.PosPhotoSearchResult>,
  vAiAnalysis satisfies Wide<T.Wrapped<T.AiAnalysisDto>>,
  vCatalogSearch satisfies Wide<{ data: { product: T.ProductDto; variant: T.VariantDto }[] }>,
  syncVInv satisfies Wide<T.SnapshotPage<T.InventoryItemDto>>,
  syncVListing satisfies Wide<T.SnapshotPage<T.TenantListingDto>>,
  syncVStore satisfies Wide<T.SnapshotPage<T.StoreDto>>,
  syncVOrder satisfies Wide<T.SnapshotPage<T.OrderDto>>,
  syncVCategory satisfies Wide<T.SnapshotPage<T.CategoryDto>>,
  syncVProduct satisfies Wide<T.SnapshotPage<T.ProductDto>>,
  syncPubListing satisfies Wide<T.SnapshotPage<T.PublicListingDto>>,
  syncPubStore satisfies Wide<T.SnapshotPage<T.PublicStoreDto>>,
  syncPubProduct satisfies Wide<T.SnapshotPage<T.ProductDto>>,
  syncCustOrder satisfies Wide<T.SnapshotPage<T.OrderDto>>,
  syncPubChanges satisfies Wide<T.ChangesResponse>,
];
