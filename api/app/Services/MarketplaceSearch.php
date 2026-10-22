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
    /** An attribute's value as text: numbers lose trailing zeros (20.0000 -> "20"). */
    private const ATTR_VALUE_SQL = 'COALESCE(pa.value_text, trim_scale(pa.value_number)::text)';

    /** Public listing filter shared by every customer-facing query: only moderated (approved) catalog products are ever sold. @return Builder<TenantListing> */
    public static function liveListings(): Builder
    {
        return TenantListing::query()->withoutGlobalScopes()
            ->where('tenant_listings.publication_status', 'published')->where('tenant_listings.visibility', 'public')->whereNull('tenant_listings.deleted_at')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('product_variants as lv')->join('products as lp', 'lp.id', '=', 'lv.product_id')->whereColumn('lv.id', 'tenant_listings.variant_id')
                ->where('lv.status', 'approved')->whereNull('lv.deleted_at')->where('lp.status', 'approved')->whereNull('lp.deleted_at'))
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('stores')->whereColumn('stores.id', 'tenant_listings.store_id')->where('stores.is_active', true)->whereNull('stores.deleted_at'))
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('tenants')->whereColumn('tenants.id', 'tenant_listings.tenant_id')->where('tenants.status', 'active')->whereNull('tenants.deleted_at'));
    }

    /**
     * With `lat`/`lng` each product carries `nearest_km` (closest store that sells it) and `sort=nearest` orders by it.
     * Detailed search adds explicit filters; listing-level ones (price, stock, fulfilment, radius) must all hold for the SAME listing.
     *
     * @param  array{q?: ?string, brand?: ?string, model?: ?string, category?: ?string, in_stock?: bool, sort?: ?string, per_page?: int, lat?: ?float, lng?: ?float,
     *     max_km?: ?float, min_price_cents?: ?int, max_price_cents?: ?int, fulfillment?: ?string, spec?: array<string, string>}  $f
     * @return LengthAwarePaginator<int, Product>
     */
    public function products(array $f): LengthAwarePaginator
    {
        $parsed = QueryParser::parse($f['q'] ?? '');
        $text = $parsed['text'];
        $key = Text::key($f['q'] ?? '');
        $price = 'COALESCE(l.sale_price_cents, l.price_cents)';

        $live = fn ($q) => $q->from('tenant_listings as l')->join('product_variants as v', 'v.id', '=', 'l.variant_id')
            ->whereColumn('v.product_id', 'products.id')->where('v.status', 'approved')->whereNull('v.deleted_at')->where('l.publication_status', 'published')->where('l.visibility', 'public')->whereNull('l.deleted_at')
            ->whereExists(fn ($s) => $s->select(DB::raw(1))->from('stores as s')->whereColumn('s.id', 'l.store_id')->where('s.is_active', true)->whereNull('s.deleted_at'))
            ->whereExists(fn ($t) => $t->select(DB::raw(1))->from('tenants as t')->whereColumn('t.id', 'l.tenant_id')->where('t.status', 'active')->whereNull('t.deleted_at'));

        // Listing-level filters must all hold for the SAME listing, and the price range, store count and nearest distance shown come from those listings only.
        $geo = isset($f['lat'], $f['lng']);
        $minPrice = $f['min_price_cents'] ?? $parsed['min_price_cents'];
        $maxPrice = $f['max_price_cents'] ?? $parsed['max_price_cents'];
        $radius = $geo && ! empty($f['max_km']) ? (float) $f['max_km'] : null;
        $fulfil = in_array($f['fulfillment'] ?? null, ['pickup', 'delivery'], true) ? $f['fulfillment'] : null;
        $matching = fn ($q) => $live($q)
            ->when($maxPrice, fn ($w, $v) => $w->whereRaw("$price <= ?", [$v]))
            ->when($minPrice, fn ($w, $v) => $w->whereRaw("$price >= ?", [$v]))
            ->when(! empty($f['in_stock']), fn ($w) => $w->whereRaw(StockService::availableSql('l').' - l.stock_buffer > 0'))
            ->when($fulfil, fn ($w, $type) => $w->where("l.{$type}_available", true)
                ->whereExists(fn ($s) => $s->select(DB::raw(1))->from('stores as fs')->whereColumn('fs.id', 'l.store_id')->where("fs.supports_$type", true)))
            ->when($radius, fn ($w, $km) => $w->whereExists(fn ($s) => $s->select(DB::raw(1))->from('stores as rs')->whereColumn('rs.id', 'l.store_id')
                ->whereNotNull('rs.lat')->whereRaw(self::haversineSql('rs').' <= ?', [$f['lat'], $f['lat'], $f['lng'], $km])));
        $exists = fn ($q) => $matching($q)->selectRaw('1');

        $query = Product::query()->where('products.status', 'approved')->with(['category', 'images' => fn ($q) => $q->whereNull('tenant_id')->orderByDesc('is_primary')])
            ->whereExists($exists)
            ->addSelect('products.*')
            ->selectRaw($text !== '' ? 'similarity(products.search_text, ?) AS relevance' : '0 AS relevance', $text !== '' ? [$text] : [])
            ->selectSub(fn ($q) => $matching($q)->selectRaw("MIN($price)"), 'min_price_cents')
            ->selectSub(fn ($q) => $matching($q)->selectRaw("MAX($price)"), 'max_price_cents')
            ->selectSub(fn ($q) => $matching($q)->selectRaw('COUNT(DISTINCT l.store_id)'), 'store_count');
        if ($geo) {
            $query->selectRaw($text !== '' ? 'round(similarity(products.search_text, ?)::numeric, 1) AS relevance_band' : '0 AS relevance_band', $text !== '' ? [$text] : []);
            $query->selectSub(fn ($q) => $matching($q)->join('stores as ns', 'ns.id', '=', 'l.store_id')->selectRaw('MIN('.self::haversineSql('ns').')', [$f['lat'], $f['lat'], $f['lng']]), 'nearest_km');
        }

        self::matchText($query, $f['q'] ?? '', $text, $key);
        foreach ($parsed['attributes'] as $attr => $value) {
            $query->whereExists(fn ($q) => $q->select(DB::raw(1))->from('product_variants as v')->join('product_attributes as pa', 'pa.variant_id', '=', 'v.id')
                ->whereColumn('v.product_id', 'products.id')->where('pa.key', $attr)->where('pa.value_number', $value));
        }
        foreach ($f['spec'] ?? [] as $attr => $value) {
            $query->whereExists(fn ($q) => $q->select(DB::raw(1))->from('product_variants as v')->join('product_attributes as pa', 'pa.variant_id', '=', 'v.id')
                ->whereColumn('v.product_id', 'products.id')->whereNull('v.deleted_at')->where('pa.key', $attr)->whereRaw(self::ATTR_VALUE_SQL.' = ?', [(string) $value]));
        }
        if (! empty($f['brand'])) {
            $query->whereRaw('lower(products.brand) = ?', [mb_strtolower($f['brand'])]);
        }
        if (($model = Text::key($f['model'] ?? '')) !== '') {
            $norm = fn (string $col) => "regexp_replace(lower(coalesce($col, '')), '[^a-z0-9]', '', 'g') LIKE ?";
            $query->where(fn ($w) => $w->whereRaw($norm('products.model_number'), ["%$model%"])
                ->orWhereExists(fn ($q) => $q->select(DB::raw(1))->from('product_variants as mv')->whereColumn('mv.product_id', 'products.id')->whereNull('mv.deleted_at')
                    ->where(fn ($x) => $x->whereRaw($norm('mv.model_number'), ["%$model%"])->orWhereRaw($norm('mv.part_number'), ["%$model%"]))));
        }
        if (! empty($f['category'])) {
            $query->whereHas('category', fn ($c) => $c->where('slug', $f['category'])->orWhereHas('parent', fn ($p) => $p->where('slug', $f['category'])));
        }

        match ($f['sort'] ?? 'relevance') {
            'nearest' => $geo ? $query->orderByRaw('nearest_km ASC NULLS LAST')->orderBy('min_price_cents')->orderBy('products.id') : $query->orderByDesc('relevance')->orderBy('products.name')->orderBy('products.id'),
            // best match first; among similarly good matches (same 0.1 relevance band) the closest seller wins
            'relevance' => $geo ? $query->orderByRaw('relevance_band DESC')->orderByRaw('nearest_km ASC NULLS LAST')->orderByDesc('relevance')->orderBy('products.id')
                : $query->orderByDesc('relevance')->orderBy('products.name')->orderBy('products.id'),
            'price_asc' => $query->orderBy('min_price_cents')->orderBy('products.id'),
            'price_desc' => $query->orderByDesc('min_price_cents')->orderBy('products.id'),
            default => $query->orderByDesc('relevance')->orderBy('products.name')->orderBy('products.id'),
        };

        return $query->paginate($f['per_page'] ?? 20);
    }

    /** Free-text match on a product query: every token, trigram similarity, barcode, alias, or a store's exact SKU. */
    private static function matchText(Builder $query, string $raw, string $text, string $key): void
    {
        if ($text === '') {
            return;
        }
        $tokens = explode(' ', $text);
        $query->where(function ($w) use ($raw, $text, $tokens, $key) {
            $w->where(function ($all) use ($tokens) {
                foreach ($tokens as $t) {
                    $all->whereRaw('products.search_text ILIKE ?', ['%'.$t.'%']);
                }
            })->orWhereRaw('similarity(products.search_text, ?) > 0.3', [$text])
                ->orWhereExists(fn ($q) => $q->select(DB::raw(1))->from('product_variants as v')->join('product_barcodes as b', 'b.variant_id', '=', 'v.id')->whereColumn('v.product_id', 'products.id')->where('b.code', $key))
                ->orWhereExists(fn ($q) => $q->select(DB::raw(1))->from('product_aliases as a')->whereColumn('a.product_id', 'products.id')->whereRaw("replace(a.normalized,' ','') = ?", [$key]))
                ->orWhereExists(fn ($q) => $q->select(DB::raw(1))->from('tenant_listings as l')->join('product_variants as v', 'v.id', '=', 'l.variant_id')->whereColumn('v.product_id', 'products.id')->whereRaw('lower(l.tenant_sku) = ?', [mb_strtolower($raw)]));
        });
    }

    /**
     * Approved catalog products that match the text but that no registered store currently sells, so the app can say so
     * instead of a bare "no results".
     *
     * @return Collection<int, Product>
     */
    public function unlistedMatches(string $q, ?string $category = null, int $limit = 5)
    {
        $text = QueryParser::parse($q)['text'];
        if ($text === '') {
            return new Collection;
        }
        $query = Product::query()->where('products.status', 'approved')->select('products.id', 'products.name', 'products.brand', 'products.model_number')
            ->whereNotExists(self::liveListings()->getQuery()->select(DB::raw(1))->whereColumn('tenant_listings.product_id', 'products.id'))
            ->when($category, fn ($w, $c) => $w->whereHas('category', fn ($x) => $x->where('slug', $c)->orWhereHas('parent', fn ($p) => $p->where('slug', $c))));
        self::matchText($query, $q, $text, Text::key($q));

        return $query->orderBy('products.name')->limit($limit)->get();
    }

    /**
     * Options for the detailed search form, taken from what stores actually sell: brands, and spec values per attribute.
     *
     * @return array{brands: list<string>, specs: list<array{key: string, label: string, unit: ?string, values: list<string>}>}
     */
    public function filters(?string $category = null): array
    {
        $listed = fn ($q) => $q->whereExists(self::liveListings()->getQuery()->select(DB::raw(1))->whereColumn('tenant_listings.product_id', 'products.id'))
            ->where('products.status', 'approved')
            ->when($category, fn ($w, $c) => $w->whereExists(fn ($x) => $x->select(DB::raw(1))->from('categories as c')->leftJoin('categories as cp', 'cp.id', '=', 'c.parent_id')
                ->whereColumn('c.id', 'products.category_id')->where(fn ($y) => $y->where('c.slug', $c)->orWhere('cp.slug', $c))));

        $brands = $listed(DB::table('products'))->whereNotNull('brand')->where('brand', '<>', '')->distinct()->orderBy('brand')->pluck('brand')->all();

        $rows = $listed(DB::table('product_attributes as pa')->join('product_variants as v', 'v.id', '=', 'pa.variant_id')->join('products', 'products.id', '=', 'v.product_id'))
            ->whereNull('v.deleted_at')->whereExists(self::liveListings()->getQuery()->select(DB::raw(1))->whereColumn('tenant_listings.variant_id', 'v.id'))
            ->leftJoin('attribute_definitions as ad', fn ($j) => $j->on('ad.key', '=', 'pa.key')->whereNull('ad.category_id'))
            ->selectRaw('pa.key, ad.label, COALESCE(pa.unit, ad.unit) AS unit, '.self::ATTR_VALUE_SQL.' AS value, MIN(pa.value_number) AS sort_number')
            ->groupByRaw('pa.key, ad.label, COALESCE(pa.unit, ad.unit), '.self::ATTR_VALUE_SQL)->orderBy('pa.key')->orderByRaw('sort_number NULLS LAST, value')->get();

        $specs = $rows->groupBy('key')->map(fn ($g, $key) => ['key' => (string) $key, 'label' => $g->first()->label ?? ucfirst(str_replace('_', ' ', (string) $key)),
            // a pack of 1 is every product's default, not a choice worth offering
            'unit' => $g->first()->unit, 'values' => $g->pluck('value')->filter(fn ($v) => $v !== null && $v !== '' && ! ($key === 'pack_quantity' && $v === '1'))->unique()->values()->all()])
            ->filter(fn ($s) => $s['values'] !== [])->values()->all();

        return ['brands' => $brands, 'specs' => $specs];
    }

    /** Great-circle km from (?, ?) [lat, lat, lng bindings] to the given stores alias. */
    public static function haversineSql(string $alias): string
    {
        return "6371 * 2 * asin(sqrt(power(sin(radians($alias.lat - ?) / 2), 2) + cos(radians(?)) * cos(radians($alias.lat)) * power(sin(radians($alias.lng - ?) / 2), 2)))";
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
