<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Api\Concerns\ApiHelpers;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AnalyzeImageRequest;
use App\Http\Requests\Api\ConfirmIntakeRequest;
use App\Http\Resources\AiAnalysisResource;
use App\Models\AiAnalysis;
use App\Services\AiIntakeService;
use Illuminate\Http\JsonResponse;

class AiController extends Controller
{
    use ApiHelpers;

    public function __construct(private AiIntakeService $ai) {}

    public function analyze(AnalyzeImageRequest $request): JsonResponse
    {
        $ctx = $this->ctx();
        $analysis = $this->ai->analyze($request->user(), $ctx, $request->file('image'), 'intake', $this->idempotencyKey($request), $request->validated('hint'));

        return (new AiAnalysisResource($analysis))->response()->setStatusCode(201);
    }

    public function show(int $analysis): AiAnalysisResource
    {
        $a = AiAnalysis::query()->where('tenant_id', $this->ctx()->tenantId)->with(['candidates.product', 'candidates.variant.barcodes', 'decision'])->findOrFail($analysis);

        return new AiAnalysisResource($a);
    }

    public function confirm(ConfirmIntakeRequest $request, int $analysis): JsonResponse
    {
        $decision = $this->ai->confirm($this->ctx(), $analysis, $request->validated(), $this->idempotencyKey($request));

        return response()->json(['data' => [
            'id' => $decision->id, 'decision' => $decision->decision, 'variant_id' => $decision->variant_id, 'inventory_item_id' => $decision->inventory_item_id,
            'ai_suggestion_preserved' => ! empty($decision->ai_suggestion), 'tenant_input_preserved' => ! empty($decision->tenant_input),
        ]], 201);
    }
}
