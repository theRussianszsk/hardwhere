<?php

namespace App\Services;

use App\Ai\AiProviderException;
use App\Ai\VisionProvider;
use App\Models\AiAnalysis;
use App\Models\AiDecision;
use App\Models\InventoryItem;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\ApiException;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/** Photo -> extraction -> catalog candidates -> (human decision) -> tenant inventory. Nothing is persisted as inventory before confirm(). */
class AiIntakeService
{
    public function __construct(
        private VisionProvider $vision,
        private ImageIntake $images,
        private CatalogMatcher $matcher,
        private CatalogService $catalog,
        private InventoryService $inventory,
        private Idempotency $idempotency,
        private AuditLogger $audit,
    ) {}

    /**
     * Analyse a photo. Vendors keep the (sanitised) image; customer searches do not.
     *
     * @param  Builder<ProductVariant>|null  $restrictTo  constrain candidates (POS: carried by the tenant; marketplace: currently listed)
     */
    public function analyze(User $user, ?TenantContext $ctx, UploadedFile $file, string $purpose, string $idempotencyKey, ?string $hint = null, ?Builder $restrictTo = null): AiAnalysis
    {
        $clean = $this->images->sanitize($file);
        $scope = $ctx ? "t{$ctx->tenantId}:u{$user->id}" : "u{$user->id}";

        $existing = DB::table('idempotency_keys')->where('scope', $scope)->where('key', $idempotencyKey)->where('operation', "ai.$purpose")->whereNotNull('resource_id')->first();
        if ($existing && $existing->request_hash === hash('sha256', $clean['sha256'].($hint ?? ''))) {
            return AiAnalysis::query()->findOrFail($existing->resource_id)->load('candidates.product', 'candidates.variant.barcodes'); // a retried upload must not pay for a second provider call
        }

        // The slow provider call happens outside the DB transaction; the idempotent persist below makes retries replay one analysis.
        $started = microtime(true);
        try {
            $result = $this->vision->analyze($this->images->forModel($clean['bytes']), $clean['mime'], ['hint' => $hint, 'filename' => $file->getClientOriginalName()]);
        } catch (AiProviderException $e) {
            throw new ApiException('ai_unavailable', 'Photo analysis is unavailable right now. You can enter the product manually.', 503, ['reason' => $e->getMessage()]);
        }
        $ms = (int) ((microtime(true) - $started) * 1000);
        $match = $this->matcher->match($result->extraction, $ctx?->tenantId, $restrictTo);

        [$analysis] = $this->idempotency->run($scope, $idempotencyKey, "ai.$purpose", hash('sha256', $clean['sha256'].($hint ?? '')), AiAnalysis::class, function () use ($user, $ctx, $purpose, $clean, $result, $match, $ms) {
            $analysis = AiAnalysis::create([
                'tenant_id' => $ctx?->tenantId, 'user_id' => $user->id, 'purpose' => $purpose, 'provider' => $result->provider, 'model' => $result->model,
                'is_mock' => $result->isMock, 'status' => 'completed',
                'image_path' => $ctx && $purpose === 'intake' ? $this->images->store($clean['bytes'], $clean['sha256'], $ctx->tenantId) : null,
                'image_sha256' => $clean['sha256'], 'image_mime' => $clean['mime'], 'extracted' => $result->extraction->toArray(),
                'warnings' => $match['warnings'], 'raw_response' => $result->raw, 'overall_confidence' => $result->extraction->overallConfidence,
                'classification' => $match['classification'], 'requires_confirmation' => $match['requires_confirmation'], 'duration_ms' => $ms,
            ]);
            foreach ($match['candidates'] as $rank => $c) {
                $analysis->candidates()->create([
                    'product_id' => $c['product_id'], 'variant_id' => $c['variant_id'], 'classification' => $c['classification'], 'score' => $c['score'],
                    'matched_fields' => $c['matched_fields'], 'mismatched_fields' => $c['mismatched_fields'], 'warnings' => $c['warnings'], 'rank' => $rank + 1,
                ]);
            }

            return $analysis;
        });

        return $analysis->load('candidates.product', 'candidates.variant.barcodes');
    }

    /**
     * Record the human decision and, unless rejected, create the tenant inventory. Preserves the AI suggestion and the raw tenant input.
     *
     * @param  array<string, mixed>  $d  validated by ConfirmIntakeRequest
     */
    public function confirm(TenantContext $ctx, int $analysisId, array $d, string $idempotencyKey): AiDecision
    {
        $ctx->can('ai.analyze') || throw ApiException::forbidden();

        [$decision] = $this->idempotency->run("t{$ctx->tenantId}:u{$ctx->userId}", $idempotencyKey, 'ai.confirm', Idempotency::hash($d + ['analysis' => $analysisId]), AiDecision::class, function () use ($ctx, $analysisId, $d) {
            $analysis = AiAnalysis::query()->where('tenant_id', $ctx->tenantId)->where('purpose', 'intake')->lockForUpdate()->find($analysisId) ?? throw ApiException::notFound('Analysis');
            if ($analysis->decision()->exists()) {
                throw ApiException::conflict('already_decided', 'This photo was already reviewed.');
            }

            $variantId = null;
            $item = null;
            if ($d['decision'] === 'created_candidate') {
                $product = $this->catalog->createProduct($d['new_product'], $ctx->tenantId);
                $variantId = $product->variants->first()->id;
            } elseif ($d['decision'] !== 'rejected') {
                $variantId = (int) $d['variant_id'];
            }

            if ($variantId) {
                $confidence = $analysis->candidates()->where('variant_id', $variantId)->value('score');
                $item = $this->inventory->createItem($ctx, $variantId, $d['inventory'] + ['source' => 'ai_intake', 'ai_analysis_id' => $analysis->id, 'confidence' => $confidence]);
            }

            $decision = AiDecision::create([
                'tenant_id' => $ctx->tenantId, 'ai_analysis_id' => $analysis->id, 'user_id' => $ctx->userId, 'decision' => $d['decision'],
                'variant_id' => $variantId, 'inventory_item_id' => $item?->id,
                'tenant_input' => ['inventory' => $d['inventory'] ?? null, 'new_product' => $d['new_product'] ?? null],
                'ai_suggestion' => ['extracted' => $analysis->extracted, 'classification' => $analysis->classification, 'warnings' => $analysis->warnings,
                    'candidates' => $analysis->candidates()->get(['variant_id', 'classification', 'score'])->toArray()],
            ]);
            $this->audit->record('ai.intake.decided', $ctx, $decision, ['decision' => $d['decision'], 'analysis_id' => $analysis->id]);

            return $decision;
        });

        return $decision;
    }

    /** Variants this tenant actually carries (has an inventory item for). @return Builder<ProductVariant> */
    public function carriedBy(int $tenantId): Builder
    {
        return ProductVariant::query()->whereIn('product_variants.id', InventoryItem::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->select('variant_id'));
    }

    /** Variants with at least one live public listing. @return Builder<ProductVariant> */
    public function publiclyListed(): Builder
    {
        return ProductVariant::query()->whereIn('product_variants.id', MarketplaceSearch::liveListings()->select('tenant_listings.variant_id'));
    }
}
