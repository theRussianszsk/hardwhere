<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Api\Concerns\ApiHelpers;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CartRequest;
use App\Http\Resources\PublicListingResource;
use App\Models\MarketplaceCart;
use App\Services\MarketplaceSearch;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Server copy of the customer's cart (so a second device can see it). Holds NO stock; only checkout reserves. */
class CartController extends Controller
{
    use ApiHelpers;

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->payload($request->user()->id)]);
    }

    public function replace(CartRequest $request): JsonResponse
    {
        $userId = $request->user()->id;
        $live = MarketplaceSearch::liveListings()->whereIn('tenant_listings.id', collect($request->validated('items'))->pluck('listing_id'))->pluck('tenant_listings.id');

        DB::transaction(function () use ($request, $userId, $live) {
            $cart = MarketplaceCart::query()->firstOrCreate(['user_id' => $userId]);
            $cart->items()->delete();
            foreach ($request->validated('items') as $line) {
                $live->contains($line['listing_id']) && $cart->items()->create(['listing_id' => $line['listing_id'], 'quantity' => $line['quantity']]);
            }
            $cart->touch();
        });

        return response()->json(['data' => $this->payload($userId)]);
    }

    public function clear(Request $request): JsonResponse
    {
        MarketplaceCart::query()->where('user_id', $request->user()->id)->first()?->items()->delete();

        return response()->json(['data' => ['items' => []]]);
    }

    /** @return array<string, mixed> */
    private function payload(int $userId): array
    {
        $cart = MarketplaceCart::query()->with('items')->where('user_id', $userId)->first();
        $ids = $cart?->items->pluck('listing_id') ?? collect();
        $listings = StockService::withAvailability(MarketplaceSearch::liveListings())->with(['store.tenant', 'variant.barcodes'])->whereIn('tenant_listings.id', $ids)->get()->keyBy('id');

        return ['items' => ($cart?->items ?? collect())->map(fn ($i) => [
            'listing_id' => $i->listing_id, 'quantity' => $i->quantity,
            'listing' => isset($listings[$i->listing_id]) ? (new PublicListingResource($listings[$i->listing_id]))->resolve() : null, // null = no longer available
        ])->values()->all(), 'updated_at' => $cart?->updated_at?->toIso8601String()];
    }
}
