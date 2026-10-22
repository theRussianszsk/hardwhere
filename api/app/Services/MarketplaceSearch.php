<?php

namespace App\Services;

use App\Models\Product;
use App\Models\TenantListing;
use App\Support\Text;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Customer discovery. The canonical catalog is searched first; tenant listings are joined afterwards and only the
 * public projection of a listing (price, availability bucket, store contact) ever leaves this class.
 */
class MarketplaceSearch
{
    /** Public listing filter shared by every customer-facing query. @return Builder<TenantListing> */
    public static function liveListings(): Builder
    {
        return TenantListing::query()->withoutGlobalScopes()
            ->where('tenant_listings.publication_status', 'published')->where('tenant_listings.visibility', 'public')->whereNull('tenant_listings.deleted_at')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('stores')->whereColumn('stores.id', 'tenant_listings.store_id')->where('stores.is_active', true)->whereNull('stores.deleted_at'))
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('tenants')->whereColumn('tenants.id', 'tenant_listings.tenant_id')->where('tenants.status', 'active')->whereNull('tenants.deleted_at'));
    }

    /**
     * @param  array{q?: ?string, brand?: ?string, category?: ?string, in_stock?: bool, sort?: ?string, per_page?: int}  $f
     * @return LengthAwarePaginator<int, Product>
     */
    public function products(array $f): LengthAwarePaginator
    {
        $parsed = QueryParser::parse($f['q'] ?? '');
        $text = $parsed['text'];
        $key = Text::key($f['q'] ?? '');
        $price = 'COALESCE(l.sale_price_cents, l.price_cents)';

        $live = fn ($q) => $q->from('tenant_listings as l')->join('product_variants as v', 'v.id', '=', 'l.variant_id')
            ->whereColumn('v.product_id', 'products.id')->where('l.publication_status', 'published')->where('l.visibility', 'public')->whereNull('l.deleted_at')
            ->whereExists(fn ($s) => $s->select(DB::raw(1))->from('stores as s')->whereColumn('s.id', 'l.store_id')->where('s.is_active', true)->whereNull('s.deleted_at'))
            ->whereExists(fn ($t) => $t->select(DB::raw(1))->from('tenants as t')->whereColumn('t.id', 'l.tenant_id')->where('t.status', 'active')->whereNull('t.deleted_at'));

        $exists = fn ($q) => $live($q)->selectRaw('1');

        $query = Product::query()->where('products.status', 'approved')->with(['category', 'images' => fn ($q) => $q->whereNull('tenant_id')->orderByDesc('is_primary')])
            ->whereExists($exists)
            ->addSelect('products.*')
            ->selectRaw($text !== '' ? 'similarity(products.search_text, ?) AS relevance' : '0 AS relevance', $text !== '' ? [$text] : [])
            ->selectSub(fn ($q) => $live($q)->selectRaw("MIN($price)"), 'min_price_cents')
            ->selectSub(fn ($q) => $live($q)->selectRaw("MAX($price)"), 'max_price_cents')
            ->selectSub(fn ($q) => $live($q)->selectRaw('COUNT(DISTINCT l.store_id)'), 'store_count');

        if ($text !== '') {
            $tokens = explode(' ', $text);
            $query->where(function ($w) use ($f, $text, $tokens, $key) {
                $w->where(function ($all) use ($tokens) {
                    foreach ($tokens as $t) {
                        $all->whereRaw('products.search_text ILIKE ?', ['%'.$t.'%']);
                    }
                })->orWhereRaw('similarity(products.search_text, ?) > 0.3', [$text])
                    ->orWhereExists(fn ($q) => $q->select(DB::raw(1))->from('product_variants as v')->join('product_barcodes as b', 'b.variant_id', '=', 'v.id')->whereColumn('v.product_id', 'products.id')->where('b.code', $key))
                    ->orWhereExists(fn ($q) => $q->select(DB::raw(1))->from('product_aliases as a')->whereColumn('a.product_id', 'products.id')->whereRaw("replace(a.normalized,' ','') = ?", [$key]))
                    ->orWhereExists(fn ($q) => $q->select(DB::raw(1))->from('tenant_listings as l')->join('product_variants as v', 'v.id', '=', 'l.variant_id')->whereColumn('v.product_id', 'products.id')->whereRaw('lower(l.tenant_sku) = ?', [mb_strtolower($f['q'] ?? '')]));
            });
        }
        foreach ($parsed['attributes'] as $attr => $value) {
            $query->whereExists(fn ($q) => $q->select(DB::raw(1))->from('product_variants as v')->join('product_attributes as pa', 'pa.variant_id', '=', 'v.id')
                ->whereColumn('v.product_id', 'products.id')->where('pa.key', $attr)->where('pa.value_number', $value));
        }
        if ($parsed['max_price_cents'] || $parsed['min_price_cents']) {
            $query->whereExists(fn ($q) => $exists($q)
                ->when($parsed['max_price_cents'], fn ($w, $v) => $w->whereRaw("$price <= ?", [$v]))
                ->when($parsed['min_price_cents'], fn ($w, $v) => $w->whereRaw("$price >= ?", [$v])));
        }
        if (! empty($f['brand'])) {
            $query->whereRaw('lower(products.brand) = ?', [mb_strtolower($f['brand'])]);
        }
        if (! empty($f['category'])) {
            $query->whereHas('category', fn ($c) => $c->where('slug', $f['category'])->orWhereHas('parent', fn ($p) => $p->where('slug', $f['category'])));
        }
        if (! empty($f['in_stock'])) {
            $query->whereExists(fn ($q) => $exists($q)->whereRaw(StockService::availableSql('l').' - l.stock_buffer > 0'));
        }

        match ($f['sort'] ?? 'relevance') {
            'price_asc' => $query->orderBy('min_price_cents')->orderBy('products.id'),
            'price_desc' => $query->orderByDesc('min_price_cents')->orderBy('products.id'),
            default => $query->orderByDesc('relevance')->orderBy('products.name')->orderBy('products.id'),
        };

        return $query->paginate($f['per_page'] ?? 20);
    }

    /**
     * Every live listing of a product, joined with store and availability, optionally ordered by distance — the store comparison view.
     *
     * @return Collection<int, TenantListing>
     */
    public function listingsForProduct(int $productId, ?float $lat = null, ?float $lng = null)
    {
        $q = StockService::withAvailability(self::liveListings())->with(['store.tenant', 'variant.barcodes'])
            ->whereIn('tenant_listings.variant_id', DB::table('product_variants')->where('product_id', $productId)->whereNull('deleted_at')->select('id'));

        $listings = $q->get();
        if ($lat !== null && $lng !== null) {
            $listings->each(fn (TenantListing $l) => $l->setAttribute('distance_km', $l->store->lat === null ? null : round(self::haversine($lat, $lng, (float) $l->store->lat, (float) $l->store->lng), 1)));
        }

        return $listings->sortBy([['distance_km', 'asc'], ['price_cents', 'asc']])->values();
    }

    public static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371 * 2 * asin(min(1, sqrt($a)));
    }

    /** Customer-facing stock bucket; exact counts only when the store opted in. @return array{status: string, quantity: ?int} */
    public static function availability(int $available, bool $showExact): array
    {
        $status = $available <= 0 ? 'out_of_stock' : ($available <= config('hardwhere.marketplace.low_stock_threshold') ? 'low_stock' : 'in_stock');

        return ['status' => $status, 'quantity' => $showExact || $status === 'low_stock' ? $available : null];
    }
}
