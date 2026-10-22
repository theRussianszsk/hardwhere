<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Api\Concerns\ApiHelpers;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PlaceOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\MarketplaceCart;
use App\Models\Order;
use App\Services\OrderService;
use App\Support\ApiException;
use App\Support\Text;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    use ApiHelpers;

    public function __construct(private OrderService $orders) {}

    public function store(PlaceOrderRequest $request): JsonResponse
    {
        $d = $request->validated();
        $d['contact_phone'] = Text::phone($d['contact_phone']) ?? throw ApiException::invalid('invalid_phone', 'Enter a Philippine mobile number, e.g. 0917 123 4567.');

        [$order, $replayed] = $this->orders->place($request->user(), $d, $this->idempotencyKey($request));
        MarketplaceCart::query()->where('user_id', $request->user()->id)->first()?->items()->whereIn('listing_id', $order->items->pluck('listing_id'))->delete();

        return (new OrderResource($order->load(['items', 'reservation', 'store.tenant'])))->additional(['replayed' => $replayed])->response()->setStatusCode($replayed ? 200 : 201);
    }

    public function index(Request $request)
    {
        return OrderResource::collection(Order::query()->withoutGlobalScopes()->where('customer_id', $request->user()->id)->with(['items', 'reservation', 'store.tenant'])->orderByDesc('placed_at')->paginate($this->perPage($request)));
    }

    public function show(Request $request, int $order): OrderResource
    {
        return new OrderResource(Order::query()->withoutGlobalScopes()->where('customer_id', $request->user()->id)->with(['items', 'reservation', 'store.tenant'])->findOrFail($order));
    }

    public function cancel(Request $request, int $order): OrderResource
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:200']]);
        $this->orders->customerCancel($request->user(), $order, $request->input('reason'));

        return $this->show($request, $order);
    }
}
