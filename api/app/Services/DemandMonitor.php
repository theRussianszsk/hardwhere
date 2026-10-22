<?php

namespace App\Services;

use App\Mail\DemandAlertMail;
use App\Mail\ProspectDemandMail;
use App\Models\AiAnalysis;
use App\Models\Category;
use App\Models\DemandAlert;
use App\Models\DemandAlertDelivery;
use App\Models\DemandSignal;
use App\Models\Product;
use App\Models\ProspectVendor;
use App\Models\Tenant;
use App\Support\Text;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Turns customer searches into demand alerts. Every search leaves a signal; when enough distinct people search for the same
 * thing inside the window, member tenants are told to prepare stock and matching non-member vendors are invited to join.
 * Only counts ever leave this class: no searcher identity, query history or per-customer detail is emailed.
 */
class DemandMonitor
{
    /** Salted, so the stored key can't be reversed into a user id or IP. */
    public static function searcherKey(Request $request): string
    {
        return hash_hmac('sha256', $request->user() ? 'u:'.$request->user()->id : 'ip:'.$request->ip(), (string) config('app.key'));
    }

    /**
     * @param  array{q?: ?string, brand?: ?string, category?: ?string}  $filters
     * @param  LengthAwarePaginator<int, Product>  $results
     */
    public function recordTextSearch(string $searcherKey, array $filters, LengthAwarePaginator $results): void
    {
        $words = array_unique(array_filter(explode(' ', QueryParser::parse($filters['q'] ?? '')['text'])));
        if (! $words || $results->currentPage() > 1) {
            return; // browsing without a query, or the next page of a search already counted
        }
        sort($words);
        $top = $results->items()[0] ?? null;
        $product = $results->total() === 1 ? $top : null;
        $categoryId = $product->category_id
            ?? (! empty($filters['category']) ? Category::query()->where('slug', $filters['category'])->value('id') : null)
            ?? $top?->category_id;

        $this->signal($searcherKey, 'text_search', $product, $product ? null : implode(' ', $words), Text::normalize($filters['q'] ?? ''),
            $categoryId, $product->brand ?? ($filters['brand'] ?? null), $results->total());
    }

    public function recordPhotoSearch(string $searcherKey, AiAnalysis $analysis): void
    {
        $top = $analysis->candidates->sortBy('rank')->first();
        $product = $top && $top->score >= config('hardwhere.demand.photo_match_min_score') ? Product::query()->find($top->product_id) : null;
        $x = $analysis->extracted;
        $label = Text::normalize(trim(($x['brand'] ?? '').' '.($x['model'] ?? $x['name'] ?? '')));
        if (! $product && $label === '') {
            return;
        }
        $categoryId = $product->category_id ?? (! empty($x['category'])
            ? Category::query()->where('slug', Str::slug($x['category']))->orWhereRaw('lower(name) = ?', [mb_strtolower($x['category'])])->value('id')
            : null);

        $this->signal($searcherKey, 'photo_search', $product, $label, $label, $categoryId, $product->brand ?? ($x['brand'] ?? null), $analysis->candidates->count());
    }

    private function signal(string $searcherKey, string $source, ?Product $product, ?string $words, string $label, ?int $categoryId, ?string $brand, int $resultCount): void
    {
        DemandSignal::create([
            'source' => $source,
            'subject_key' => mb_substr($product ? 'p:'.$product->id : 'q:'.$words, 0, 160),
            'subject_label' => mb_substr($product ? trim($product->brand.' '.$product->name) : $label, 0, 160),
            'product_id' => $product?->id, 'category_id' => $categoryId, 'brand' => $brand ? mb_substr($brand, 0, 80) : null,
            'result_count' => $resultCount, 'searcher_key' => $searcherKey,
        ]);
    }

    /**
     * Raise an alert for every subject that crossed the threshold and has not been alerted within the cooldown, then email it out.
     *
     * @return EloquentCollection<int, DemandAlert>
     */
    public function detect(): EloquentCollection
    {
        $cfg = config('hardwhere.demand');
        $hot = DB::table('demand_signals')
            ->where('created_at', '>=', now()->subHours($cfg['window_hours']))
            ->groupBy('subject_key')
            ->havingRaw('COUNT(DISTINCT searcher_key) >= ?', [$cfg['min_searchers']])
            ->select('subject_key')
            // Ordered-set aggregates skip NULLs, so mode() picks the most common non-null hint.
            ->selectRaw('COUNT(DISTINCT searcher_key) AS searchers, COUNT(*) AS searches, MAX(result_count) AS max_results, MODE() WITHIN GROUP (ORDER BY subject_label) AS label')
            ->selectRaw('MAX(product_id) AS product_id, MODE() WITHIN GROUP (ORDER BY category_id) AS category_id, MODE() WITHIN GROUP (ORDER BY brand) AS brand')
            ->orderByDesc('searchers')
            ->get();

        $alerts = new EloquentCollection;
        foreach ($hot as $row) {
            // Per-subject lock + re-check, so an overlapping manual run and scheduler tick can't both alert the same subject.
            $alert = DB::transaction(function () use ($row, $cfg) {
                DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['demand:'.$row->subject_key]);
                if (DemandAlert::query()->where('subject_key', $row->subject_key)->where('created_at', '>=', now()->subHours($cfg['cooldown_hours']))->exists()) {
                    return null;
                }

                return DemandAlert::create([
                    'subject_key' => $row->subject_key, 'subject_label' => $row->label, 'product_id' => $row->product_id, 'category_id' => $row->category_id,
                    'brand' => $row->brand, 'searcher_count' => $row->searchers, 'search_count' => $row->searches, 'window_hours' => $cfg['window_hours'],
                    'unserved' => (int) $row->max_results === 0,
                ]);
            });
            if ($alert) {
                $this->notify($alert);
                $alerts->push($alert);
            }
        }

        return $alerts;
    }

    private function notify(DemandAlert $alert): void
    {
        $alert->load('product', 'category.parent');
        $listedBy = $alert->product_id ? $this->tenantsListing(fn ($q) => $q->where('v.product_id', $alert->product_id)) : collect();

        foreach ($this->memberRecipients($alert, $listedBy) as $r) {
            $this->deliver($alert, $r->email, new DemandAlertMail($alert, $r->tenant_name, $r->user_name, $listedBy->contains($r->tenant_id)), ['audience' => 'member', 'tenant_id' => $r->tenant_id]);
        }
        foreach ($this->prospectRecipients($alert) as $p) {
            $this->deliver($alert, $p->email, new ProspectDemandMail($alert, $p), ['audience' => 'prospect', 'prospect_vendor_id' => $p->id]);
        }
    }

    /** One failed address must not stop the rest; only sent mail is recorded. @param array<string, mixed> $who */
    private function deliver(DemandAlert $alert, string $email, Mailable $mail, array $who): void
    {
        if (DemandAlertDelivery::query()->where('demand_alert_id', $alert->id)->where('email', $email)->exists()) {
            return;
        }
        try {
            Mail::to($email)->send($mail);
            DemandAlertDelivery::create(['demand_alert_id' => $alert->id, 'email' => $email] + $who);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Owners and inventory managers of the tenants that should prepare: those already listing the product plus those selling in its
     * category, else (no hint matched anyone) every active tenant.
     *
     * @param  Collection<int, int>  $listedBy
     * @return Collection<int, \stdClass> rows of email, user_name, tenant_id, tenant_name
     */
    private function memberRecipients(DemandAlert $alert, Collection $listedBy): Collection
    {
        $tenantIds = $listedBy;
        if ($alert->category_id) {
            $tenantIds = $tenantIds->merge($this->tenantsListing(fn ($q) => $q->join('products as p', 'p.id', '=', 'v.product_id')->whereIn('p.category_id', $this->categoryFamily($alert))))->unique();
        }
        if ($tenantIds->isEmpty()) {
            $tenantIds = Tenant::query()->where('status', 'active')->pluck('id');
        }

        return DB::table('tenant_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')->join('tenants as t', 't.id', '=', 'm.tenant_id')
            ->whereIn('m.tenant_id', $tenantIds)->whereNull('m.deleted_at')->whereIn('m.role', ['owner', 'inventory_manager'])
            ->where('t.status', 'active')->whereNull('t.deleted_at')
            ->orderBy('m.tenant_id')->orderBy('m.id')
            ->get(['u.email', 'u.name as user_name', 't.id as tenant_id', 't.name as tenant_name'])
            ->unique(fn ($r) => mb_strtolower($r->email))->values();
    }

    /**
     * Non-members whose stated categories or brands match the demand (all of them when the demand carries neither hint).
     * Anyone already on the platform, as a member user or a store address, is skipped.
     *
     * @return Collection<int, ProspectVendor>
     */
    private function prospectRecipients(DemandAlert $alert): Collection
    {
        $slugs = Category::query()->whereIn('id', $this->categoryFamily($alert))->pluck('slug')->all();
        $brand = $alert->brand ? mb_strtolower($alert->brand) : null;
        $known = DB::table('users')->whereExists(fn ($q) => $q->select(DB::raw(1))->from('tenant_memberships as m')->whereColumn('m.user_id', 'users.id')->whereNull('m.deleted_at'))
            ->selectRaw('lower(email) AS email')
            ->union(DB::table('stores')->whereNotNull('email')->selectRaw('lower(email) AS email'))
            ->pluck('email')->flip();

        return ProspectVendor::query()->whereNull('unsubscribed_at')->orderBy('id')->get()
            ->reject(fn (ProspectVendor $p) => $known->has(mb_strtolower($p->email)))
            ->filter(fn (ProspectVendor $p) => (! $slugs && ! $brand)
                || array_intersect($slugs, $p->categories ?? [])
                || ($brand && in_array($brand, array_map('mb_strtolower', $p->brands ?? []), true)))
            ->values();
    }

    /** The alert's category plus its parent and children, so "drills" demand reaches "power-tools" sellers and vice versa. @return list<int> */
    private function categoryFamily(DemandAlert $alert): array
    {
        if (! $alert->category_id) {
            return [];
        }

        return Category::query()->where('id', $alert->category_id)->orWhere('parent_id', $alert->category_id)
            ->when($alert->category?->parent_id, fn ($q, $parent) => $q->orWhere('id', $parent))
            ->pluck('id')->all();
    }

    /** @return Collection<int, int> tenant ids with a live public listing matching $where (which sees `v` = product_variants) */
    private function tenantsListing(\Closure $where): Collection
    {
        return MarketplaceSearch::liveListings()->join('product_variants as v', 'v.id', '=', 'tenant_listings.variant_id')
            ->tap($where)->distinct()->pluck('tenant_listings.tenant_id');
    }
}
