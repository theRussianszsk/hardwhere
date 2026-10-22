<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAlias;
use App\Models\ProductAttribute;
use App\Models\ProductBarcode;
use App\Models\ProductVariant;
use App\Models\TenantListing;
use App\Support\ApiException;
use App\Support\Text;
use Illuminate\Support\Facades\DB;

/** Writes to the shared canonical catalog. Tenants can only add 'candidate' products, never edit approved ones. */
class CatalogService
{
    public function __construct(private SyncRecorder $sync) {}

    /**
     * @param  array<string, mixed>  $data  product fields plus `variants` (each: name, model_number, part_number, attributes, pack_quantity, uom, barcodes[code=>type])
     */
    public function createProduct(array $data, ?int $submittedByTenantId = null): Product
    {
        return DB::transaction(function () use ($data, $submittedByTenantId) {
            $category = Category::query()->find($data['category_id']) ?? throw ApiException::invalid('unknown_category', 'Unknown category.');
            $status = $submittedByTenantId ? 'candidate' : ($data['status'] ?? 'approved');

            $product = Product::create([
                'category_id' => $category->id, 'brand' => $data['brand'] ?? null, 'manufacturer' => $data['manufacturer'] ?? ($data['brand'] ?? null),
                'name' => $data['name'], 'model_number' => $data['model_number'] ?? null, 'product_type' => $data['product_type'] ?? null,
                'description' => $data['description'] ?? null, 'attributes' => $data['attributes'] ?? [], 'compatibility' => $data['compatibility'] ?? [],
                'included_components' => $data['included_components'] ?? [], 'safety_info' => $data['safety_info'] ?? null, 'warranty' => $data['warranty'] ?? null,
                'ph_specs' => $data['ph_specs'] ?? [], 'synonyms' => $data['synonyms'] ?? [], 'status' => $status, 'submitted_by_tenant_id' => $submittedByTenantId,
            ]);
            $product->update(['search_text' => $this->searchText($product, $category)]);

            foreach ($data['synonyms'] ?? [] as $syn) {
                ProductAlias::create(['product_id' => $product->id, 'type' => 'synonym', 'alias' => $syn, 'normalized' => Text::normalize($syn)]);
            }

            foreach ($data['variants'] ?? [['name' => $data['name']]] as $i => $v) {
                $this->createVariant($product, $v + ['is_default' => $i === 0], $status);
            }

            $this->recordSync($product);

            return $product->load('variants.barcodes');
        });
    }

    /** @param  array<string, mixed>  $v */
    public function createVariant(Product $product, array $v, string $status = 'approved'): ProductVariant
    {
        $attrs = $v['attributes'] ?? [];
        $pack = (int) ($v['pack_quantity'] ?? 1);

        $variant = ProductVariant::create([
            'product_id' => $product->id, 'name' => $v['name'], 'model_number' => $v['model_number'] ?? null, 'part_number' => $v['part_number'] ?? null,
            'attributes' => $attrs, 'uom' => $v['uom'] ?? 'pc', 'pack_quantity' => $pack,
            'variant_key' => Text::variantKey($attrs + ['pack_quantity' => $pack] + ($v['model_number'] ?? null ? ['model' => $v['model_number']] : [])),
            'is_default' => $v['is_default'] ?? false, 'status' => $status,
        ]);

        foreach ($attrs + ['pack_quantity' => $pack] as $key => $value) {
            ProductAttribute::create([
                'variant_id' => $variant->id, 'key' => $key,
                'value_text' => is_numeric($value) ? null : (string) $value, 'value_number' => is_numeric($value) ? $value : null,
            ]);
        }
        foreach ($v['barcodes'] ?? [] as $code => $type) {
            preg_match('/^\d{8,14}$/', (string) $code) || throw ApiException::invalid('invalid_barcode', "Barcode $code must be 8 to 14 digits.");
            ProductBarcode::query()->where('code', (string) $code)->exists()
                && throw ApiException::conflict('barcode_conflict', "Barcode $code already belongs to another catalog product.", ['barcode' => (string) $code]);
            ProductBarcode::create(['variant_id' => $variant->id, 'code' => (string) $code, 'type' => $type]);
        }
        foreach (array_filter([$v['model_number'] ?? null, $v['part_number'] ?? null]) as $sku) {
            ProductAlias::create(['product_id' => $product->id, 'variant_id' => $variant->id, 'type' => 'sku', 'alias' => $sku, 'normalized' => Text::normalize($sku)]);
        }

        return $variant;
    }

    public function approve(Product $product): Product
    {
        DB::transaction(function () use ($product) {
            $product->update(['status' => 'approved', 'row_version' => $product->row_version + 1]);
            $product->variants()->update(['status' => 'approved']);
            $this->recordSync($product);
            // a listing published while this was a candidate (possible before publishing was gated) was withheld from the marketplace; re-emit so public caches pick it up
            TenantListing::query()->withoutGlobalScopes()->where('product_id', $product->id)->get(['id', 'tenant_id'])->each(fn (TenantListing $l) => $this->sync->listing($l->tenant_id, $l->id));
        });

        return $product;
    }

    public function searchText(Product $p, ?Category $category = null): string
    {
        $category ??= $p->category;

        return Text::normalize(implode(' ', array_filter([
            $p->brand, $p->manufacturer, $p->name, $p->model_number, $p->product_type, $category?->name, ...($p->synonyms ?? []),
        ])));
    }

    private function recordSync(Product $product): void
    {
        $product->status === 'approved'
            ? $this->sync->catalogEntity('product', $product->id)
            : $this->sync->tenantEntity((int) $product->submitted_by_tenant_id, 'product', $product->id);
    }
}
