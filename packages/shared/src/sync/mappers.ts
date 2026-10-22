import type { CacheRow, EntityMapper } from "../contracts";

/**
 * Default DTO -> CacheRow mappers. Input is `any` on purpose: vendor and public DTOs differ and the server may add/omit
 * fields, so every access is defensive. `payload` is always the unmodified server DTO (nothing is "freshened" locally).
 */
const str = (v: unknown): string => (typeof v === "string" ? v : typeof v === "number" ? String(v) : "");
const arr = (v: unknown): any[] => (Array.isArray(v) ? v : []);
const clean = (parts: unknown[]): string => parts.map(str).filter(Boolean).join(" ").toLowerCase().replace(/\s+/g, " ").trim();
const uniq = (xs: unknown[]): string[] => [...new Set(xs.map(str).map((s) => s.trim()).filter(Boolean))];
const rv = (d: any): number | null => (Number.isFinite(d?.row_version) ? Number(d.row_version) : null);

/** Barcodes appear as [{code,type}] on variants and as plain strings on inventory items. */
const barcodes = (v: any): string[] => arr(v?.barcodes).map((b) => (typeof b === "string" ? b : b?.code)).filter(Boolean);

export const productMapper: EntityMapper = (d): CacheRow => {
  const variants = arr(d?.variants);
  const codes = variants.flatMap(barcodes);
  return {
    id: Number(d.id),
    payload: d,
    search: clean([d?.brand, d?.name, d?.model_number, ...arr(d?.synonyms), d?.category?.name, ...variants.flatMap((v) => [v?.name, v?.model_number, ...barcodes(v)])]),
    keys: uniq([...codes, d?.model_number, ...variants.map((v) => v?.model_number)]),
    rowVersion: rv(d),
  };
};

/** Tenant (vendor) listing and PublicListingDto share one mapper; fields that do not exist on one side are simply absent. */
export const listingMapper: EntityMapper = (d): CacheRow => {
  const codes = barcodes(d?.variant);
  return {
    id: Number(d.id),
    payload: d,
    search: clean([d?.sku, d?.title, d?.title_override, d?.product?.name, d?.product?.brand, d?.store?.name, ...codes]),
    keys: uniq([d?.sku, ...codes, d?.variant?.model_number]),
    rowVersion: rv(d),
    filters: Number.isFinite(d?.store_id ?? d?.store?.id) ? { store_id: Number(d.store_id ?? d.store.id) } : undefined,
  };
};

export const inventoryItemMapper: EntityMapper = (d): CacheRow => {
  const codes = arr(d?.barcodes).map(str);
  return {
    id: Number(d.id),
    payload: d,
    search: clean([d?.sku, d?.name, d?.brand, d?.product_name, ...codes]),
    keys: uniq([d?.sku, ...codes]),
    rowVersion: rv(d),
  };
};

export const storeMapper: EntityMapper = (d): CacheRow => ({
  id: Number(d.id), payload: d, search: clean([d?.name, d?.city, d?.province, d?.business_name]), keys: [], rowVersion: rv(d),
});

export const orderMapper: EntityMapper = (d): CacheRow => ({
  id: Number(d.id), payload: d, search: clean([d?.order_no, d?.status, d?.contact_name]), keys: uniq([d?.order_no]), rowVersion: rv(d),
});

export const categoryMapper: EntityMapper = (d): CacheRow => ({
  id: Number(d.id), payload: d, search: clean([d?.name]), keys: [], rowVersion: null,
});

export const DEFAULT_MAPPERS: Readonly<Record<string, EntityMapper>> = {
  category: categoryMapper,
  product: productMapper,
  store: storeMapper,
  listing: listingMapper,
  inventory_item: inventoryItemMapper,
  order: orderMapper,
};
