<?php

namespace App\Services;

use App\Models\TenantListing;
use App\Support\Text;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Cashier/staff lookup restricted to what THIS tenant sells at the given store. */
class PosSearch
{
    /**
     * @param  array{q?: ?string, barcode?: ?string, category?: ?string, per_page?: int}  $f
     * @return LengthAwarePaginator<int, TenantListing>
     */
    public function listings(TenantContext $ctx, int $storeId, array $f): LengthAwarePaginator
    {
        $ctx->assertStore($storeId);
        $q = StockService::withAvailability(TenantListing::query()->forTenant($ctx))->with(['variant.barcodes', 'product.category'])
            ->where('tenant_listings.store_id', $storeId);

        if (! empty($f['barcode'])) {
            $q->whereHas('variant.barcodes', fn ($b) => $b->where('code', $f['barcode']));
        }
        if (! empty($f['category'])) {
            $q->whereHas('product.category', fn ($c) => $c->where('slug', $f['category'])->orWhereHas('parent', fn ($p) => $p->where('slug', $f['category'])));
        }
        if (! empty($f['q'])) {
            $text = Text::normalize($f['q']);
            $key = Text::key($f['q']);
            $q->where(function ($w) use ($f, $text, $key) {
                $w->whereRaw('lower(tenant_listings.tenant_sku) LIKE ?', [mb_strtolower($f['q']).'%'])
                    ->orWhereHas('variant', fn ($v) => $v->whereRaw("regexp_replace(lower(coalesce(model_number,'')), '[^a-z0-9]', '', 'g') LIKE ?", [$key.'%'])
                        ->orWhereHas('barcodes', fn ($b) => $b->where('code', $f['q'])))
                    ->orWhereHas('product', function ($p) use ($text) {
                        foreach (explode(' ', $text) as $t) {
                            $p->whereRaw('products.search_text ILIKE ?', ['%'.$t.'%']);
                        }
                    })
                    ->orWhereRaw('lower(coalesce(tenant_listings.title_override, \'\')) LIKE ?', ['%'.mb_strtolower($f['q']).'%']);
            });
        }

        return $q->orderBy('tenant_listings.tenant_sku')->paginate($f['per_page'] ?? 30);
    }
}
