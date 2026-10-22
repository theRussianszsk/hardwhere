import syncCategory from "../../contract/fixtures/sync.vendor.snapshot.category.json";
import syncInventory from "../../contract/fixtures/sync.vendor.snapshot.inventory_item.json";
import syncVendorListing from "../../contract/fixtures/sync.vendor.snapshot.listing.json";
import syncVendorOrder from "../../contract/fixtures/sync.vendor.snapshot.order.json";
import syncVendorProduct from "../../contract/fixtures/sync.vendor.snapshot.product.json";
import syncVendorStore from "../../contract/fixtures/sync.vendor.snapshot.store.json";
import syncPublicListing from "../../contract/fixtures/sync.public.snapshot.listing.json";
import syncPublicStore from "../../contract/fixtures/sync.public.snapshot.store.json";
import syncCustomerOrder from "../../contract/fixtures/sync.customer.snapshot.order.json";
import syncPublicChanges from "../../contract/fixtures/sync.public.changes.json";

/**
 * The checked-in server fixtures are normalised (every id is 1), so tests give each item a distinct id 1..n.
 * Everything else is the real server DTO.
 */
export const reid = <T extends { id: number }>(items: readonly T[], start = 1): T[] => items.map((it, i) => ({ ...structuredClone(it), id: start + i }));

export const fx = {
  category: () => reid(syncCategory.items as any[]),
  product: () => reid(syncVendorProduct.items as any[]),
  store: () => reid(syncVendorStore.items as any[]),
  vendorListing: () => reid(syncVendorListing.items as any[]),
  publicListing: () => reid(syncPublicListing.items as any[]),
  publicStore: () => reid(syncPublicStore.items as any[]),
  inventory: () => reid(syncInventory.items as any[]),
  vendorOrder: () => reid(syncVendorOrder.items as any[]),
  customerOrder: () => reid(syncCustomerOrder.items as any[]),
  publicChanges: () => structuredClone(syncPublicChanges) as { changes: { entity: string; id: number; op: "upsert" | "delete"; data: any }[]; cursor: string; has_more: boolean; server_time: string },
};

/** A full vendor data set as the server would serve it. */
export const vendorData = () => ({
  category: fx.category(), product: fx.product(), store: fx.store(), listing: fx.vendorListing(), inventory_item: fx.inventory(), order: fx.vendorOrder(),
});
export const publicData = () => ({ category: fx.category(), product: fx.product(), store: fx.publicStore(), listing: fx.publicListing() });
