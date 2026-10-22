<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Enums\OrderStatus;
use App\Http\Controllers\Api\Concerns\ApiHelpers;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\TransitionRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    use ApiHelpers;

    public function __construct(private OrderService $orders) {}

    public function index(Request $request)
    {
        $ctx = $this->ctx();
        $q = Order::query()->forTenant($ctx)->with(['items', 'reservation'])->orderByDesc('placed_at')
            ->when($ctx->storeId, fn ($w) => $w->where('store_id', $ctx->storeId))
            ->when($request->query('status'), fn ($w, $s) => $w->where('status', $s));

        return OrderResource::collection($q->paginate($this->perPage($request)));
    }

    public function show(int $order): OrderResource
    {
        $ctx = $this->ctx();
        $o = Order::query()->forTenant($ctx)->with(['items', 'reservation'])->findOrFail($order);
        $ctx->assertStore($o->store_id);

        return new OrderResource($o);
    }

    public function transition(TransitionRequest $request, int $order): OrderResource
    {
        $ctx = $this->ctx();
        $o = Order::query()->forTenant($ctx)->findOrFail($order);
        $ctx->assertStore($o->store_id);
        $updated = $this->orders->vendorTransition($o->id, OrderStatus::from($request->validated('to')), $request->validated('reason'), $ctx->userId);

        return new OrderResource($updated->load(['items', 'reservation']));
    }
}
