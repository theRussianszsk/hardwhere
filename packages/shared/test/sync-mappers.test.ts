import { describe, expect, it } from "vitest";
import { DEFAULT_MAPPERS, categoryMapper, inventoryItemMapper, listingMapper, orderMapper, productMapper, storeMapper } from "../src/sync/mappers";
import { fx } from "./support/fixtures";

describe("default entity mappers (real server DTOs)", () => {
  it("product: search covers brand, name, model, synonyms, category and variants; keys are barcodes + model numbers", () => {
    const p = fx.product()[0]!; // Makita Hammer Drill HP1630
    const row = productMapper(p);
    expect(row.id).toBe(1);
    expect(row.payload).toBe(p); // the DTO is cached untouched
    for (const word of ["makita", "hammer drill 16mm", "hp1630", "rotary hammer", "impact drill", p.category.name.toLowerCase()]) expect(row.search).toContain(word);
    expect(row.search).toBe(row.search.toLowerCase());
    expect(row.keys).toEqual(expect.arrayContaining([p.variants[1].barcodes[0].code, "HP1630", "HP1630K"]));
    expect(row.search).toContain("hp1630k hammer drill 16mm with carrying case");
    expect(row.search).toContain(p.variants[1].barcodes[0].code);
    expect(row.rowVersion).toBe(p.row_version);
  });

  it("vendor listing: sku/title/product/barcodes searchable, keys = sku + barcodes + model, filter by store_id", () => {
    const l = fx.vendorListing()[1]!;
    const row = listingMapper(l);
    expect(row.search).toContain(l.sku.toLowerCase());
    expect(row.search).toContain(l.title.toLowerCase());
    expect(row.search).toContain(l.product.name.toLowerCase());
    expect(row.search).toContain(l.variant.barcodes[0].code);
    expect(row.keys).toEqual(expect.arrayContaining([l.sku, l.variant.barcodes[0].code, l.variant.model_number]));
    expect(row.filters).toEqual({ store_id: l.store_id });
  });

  it("public listing (PublicListingDto): store name is searchable and store_id comes from the embedded store", () => {
    const l = fx.publicListing()[0]!;
    const row = listingMapper(l);
    expect(row.search).toContain(l.store.name.toLowerCase());
    expect(row.search).toContain(l.sku.toLowerCase());
    expect(row.keys).toContain(l.sku);
    expect(row.filters).toEqual({ store_id: l.store.id });
  });

  it("inventory item / store / order / category", () => {
    const i = fx.inventory()[0]!;
    expect(inventoryItemMapper(i)).toMatchObject({ keys: [i.sku, i.barcodes[0]] });
    expect(inventoryItemMapper(i).search).toContain(i.product_name.toLowerCase());
    const s = fx.publicStore()[0]!;
    expect(storeMapper(s).search).toBe([s.name, s.city, s.province, s.business_name].join(" ").toLowerCase());
    const o = fx.vendorOrder()[0]!;
    expect(orderMapper(o)).toMatchObject({ keys: [o.order_no], search: [o.order_no, o.status, o.contact_name].join(" ").toLowerCase() });
    const c = fx.category()[0]!;
    expect(categoryMapper(c)).toMatchObject({ search: "power tools", keys: [], rowVersion: null });
  });

  it("is defensive about missing fields (no throw on sparse DTOs)", () => {
    for (const m of Object.values(DEFAULT_MAPPERS)) {
      const row = m({ id: 7 });
      expect(row.id).toBe(7);
      expect(row.search).toBe("");
      expect(row.keys).toEqual([]);
    }
    expect(productMapper({ id: 1, variants: [null, { barcodes: [null, { code: "1" }, "2"] }] }).keys).toEqual(["1", "2"]);
  });
});
