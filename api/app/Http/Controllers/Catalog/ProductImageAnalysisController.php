<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\AnalyzeProductImageRequest;
use App\Services\Catalog\MatchCandidate;
use App\Services\Catalog\ProductMatcher;
use App\Services\Vision\ProductVisionProvider;
use App\Services\Vision\VisionProviderException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ProductImageAnalysisController extends Controller
{
    /**
     * Analyse a product photo and suggest canonical catalog matches.
     * Read-only: nothing is created or merged until a person confirms.
     */
    public function __invoke(
        AnalyzeProductImageRequest $request,
        ProductVisionProvider $vision,
        ProductMatcher $matcher,
    ): JsonResponse {
        $image = $request->file('image');

        try {
            $analysis = $vision->analyze(
                (string) file_get_contents($image->getRealPath()),
                (string) $image->getMimeType(),
                $image->getClientOriginalName(),
            );
        } catch (VisionProviderException $e) {
            Log::warning('Product image analysis failed', ['message' => $e->getMessage()]);

            return response()->json([
                'message' => 'Image analysis is unavailable right now. Enter the product manually.',
            ], 503);
        }

        $result = $matcher->match($analysis);

        return response()->json([
            'analysis' => $analysis->toArray(),
            'classification' => $result['classification']->value,
            'requires_confirmation' => $result['requires_confirmation'],
            'warnings' => $result['warnings'],
            'candidates' => array_map(fn (MatchCandidate $candidate) => $candidate->toArray(), $result['candidates']),
        ]);
    }
}
