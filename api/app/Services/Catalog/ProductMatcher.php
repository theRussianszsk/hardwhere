<?php

namespace App\Services\Catalog;

use App\Enums\MatchClassification;
use App\Models\CatalogProduct;
use App\Services\Vision\ProductAnalysis;

/**
 * Ranks canonical catalog products against an AI analysis. Never merges anything:
 * every result is a suggestion that a person must confirm (except exact identifier hits).
 */
class ProductMatcher
{
    /** Attributes that make two products commercially different when they disagree. */
    private const VARIANT_ATTRIBUTES = [
        'voltage', 'wattage', 'amperage', 'pack_quantity', 'capacity', 'color', 'size', 'length', 'diameter', 'weight',
    ];

    private const MIN_IDENTITY_CONFIDENCE = 0.35;

    private const MAX_CANDIDATES = 5;

    private const MIN_CANDIDATE_SCORE = 0.3;

    /**
     * @return array{classification: MatchClassification, requires_confirmation: bool, warnings: list<string>, candidates: list<MatchCandidate>}
     */
    public function match(ProductAnalysis $analysis): array
    {
        $globalWarnings = [];

        if (! $analysis->imageSufficient) {
            $globalWarnings[] = 'Image insufficient for reliable identification';
        }

        if ($analysis->brand !== null && ($analysis->confidences['brand'] ?? 1.0) < 0.5) {
            $globalWarnings[] = 'Low-confidence brand';
        }

        if ($analysis->modelNumber !== null && ($analysis->confidences['model_number'] ?? 1.0) < 0.5) {
            $globalWarnings[] = 'Low-confidence model';
        }

        $ranked = CatalogProduct::query()
            ->get()
            ->map(fn (CatalogProduct $product) => $this->score($analysis, $product))
            ->filter(fn (MatchCandidate $candidate) => $candidate->score >= self::MIN_CANDIDATE_SCORE)
            ->sortByDesc(fn (MatchCandidate $candidate) => $candidate->score)
            ->take(self::MAX_CANDIDATES)
            ->values()
            ->all();

        $candidates = array_values($ranked);

        $classification = $this->overallClassification($analysis, $candidates);

        return [
            'classification' => $classification,
            'requires_confirmation' => $classification->requiresConfirmation(),
            'warnings' => $globalWarnings,
            'candidates' => $candidates,
        ];
    }

    private function score(ProductAnalysis $analysis, CatalogProduct $product): MatchCandidate
    {
        $matched = [];
        $warnings = [];

        $barcodeMatch = $analysis->barcode !== null && $analysis->barcode === $product->barcode;
        if ($barcodeMatch) {
            $matched[] = 'barcode';
        } elseif ($analysis->barcode !== null && $product->barcode !== null) {
            $warnings[] = 'Conflicting barcode';
        }

        $brandMatch = $this->same($analysis->brand, $product->brand);
        $modelMatch = $this->same($analysis->modelNumber, $product->model_number)
            || $this->matchesSkuAlias($analysis->modelNumber, $product);

        if ($brandMatch) {
            $matched[] = 'brand';
        }
        if ($modelMatch) {
            $matched[] = 'model_number';
        }

        $categoryMatch = $this->same($analysis->category, $product->category);
        if ($categoryMatch) {
            $matched[] = 'category';
        }

        $textSimilarity = $this->tokenSimilarity($analysis->name, $product->name);
        if ($textSimilarity >= 0.5) {
            $matched[] = 'name';
        }

        $conflicts = $this->attributeConflicts($analysis, $product);
        foreach ($conflicts as $attribute) {
            $warnings[] = 'Similar product with different '.str_replace('_', ' ', $attribute);
        }

        $agreeingAttributes = $this->agreeingAttributes($analysis, $product);
        if ($agreeingAttributes !== []) {
            $matched[] = 'attributes';
        }

        $score = ($barcodeMatch ? 0.6 : 0.0)
            + ($modelMatch ? ($brandMatch ? 0.45 : 0.3) : 0.0)
            + ($brandMatch ? 0.1 : 0.0)
            + ($categoryMatch ? 0.05 : 0.0)
            + (0.3 * $textSimilarity)
            + min(0.3, 0.1 * count($agreeingAttributes));
        $score = min(1.0, $score);

        $classification = $this->classify($score, $barcodeMatch, $modelMatch, $conflicts, $warnings);

        if (in_array($classification, [MatchClassification::LikelyVariant, MatchClassification::PossibleDuplicate], true)
            && $conflicts === []) {
            $warnings[] = $classification === MatchClassification::PossibleDuplicate
                ? 'Possible duplicate'
                : 'Possible product variant';
        }
        if ($classification === MatchClassification::LikelyVariant && $conflicts !== []) {
            $warnings[] = 'Possible product variant';
        }

        return new MatchCandidate($product, $score, $classification, $matched, array_values(array_unique($warnings)));
    }

    /**
     * @param  list<string>  $conflicts
     * @param  list<string>  $warnings
     */
    private function classify(float $score, bool $barcodeMatch, bool $modelMatch, array $conflicts, array $warnings): MatchClassification
    {
        $barcodeConflict = in_array('Conflicting barcode', $warnings, true);

        if ($barcodeMatch && $conflicts === []) {
            return MatchClassification::Exact;
        }

        if ($conflicts !== [] && ($modelMatch || $barcodeMatch || $score >= 0.6)) {
            return MatchClassification::LikelyVariant;
        }

        if ($score >= 0.8 && ! $barcodeConflict) {
            return MatchClassification::LikelySame;
        }

        if ($score >= 0.45) {
            return MatchClassification::PossibleDuplicate;
        }

        return MatchClassification::NewCandidate;
    }

    /**
     * @param  list<MatchCandidate>  $candidates
     */
    private function overallClassification(ProductAnalysis $analysis, array $candidates): MatchClassification
    {
        $best = $candidates === [] ? null : $candidates[0];

        // A printed, matching barcode is trustworthy even when the rest of the photo is poor.
        if ($best?->classification === MatchClassification::Exact) {
            return MatchClassification::Exact;
        }

        if (! $analysis->imageSufficient || $analysis->identityConfidence() < self::MIN_IDENTITY_CONFIDENCE) {
            return MatchClassification::InsufficientConfidence;
        }

        return $best === null ? MatchClassification::NewCandidate : $best->classification;
    }

    /**
     * @return list<string>
     */
    private function attributeConflicts(ProductAnalysis $analysis, CatalogProduct $product): array
    {
        $conflicts = [];
        $productAttributes = $product->attributes ?? [];

        foreach (self::VARIANT_ATTRIBUTES as $key) {
            if (isset($analysis->attributes[$key], $productAttributes[$key])
                && $this->normalize((string) $analysis->attributes[$key]) !== $this->normalize((string) $productAttributes[$key])) {
                $conflicts[] = $key;
            }
        }

        return $conflicts;
    }

    /**
     * @return list<string>
     */
    private function agreeingAttributes(ProductAnalysis $analysis, CatalogProduct $product): array
    {
        $productAttributes = $product->attributes ?? [];

        return array_values(array_filter(
            self::VARIANT_ATTRIBUTES,
            fn (string $key) => isset($analysis->attributes[$key], $productAttributes[$key])
                && $this->normalize((string) $analysis->attributes[$key]) === $this->normalize((string) $productAttributes[$key]),
        ));
    }

    private function matchesSkuAlias(?string $value, CatalogProduct $product): bool
    {
        if ($value === null) {
            return false;
        }

        foreach ($product->sku_aliases ?? [] as $alias) {
            if ($this->normalize($alias) === $this->normalize($value)) {
                return true;
            }
        }

        return false;
    }

    private function same(?string $a, ?string $b): bool
    {
        return $a !== null && $b !== null && $this->normalize($a) === $this->normalize($b);
    }

    private function normalize(string $value): string
    {
        return preg_replace('/[^a-z0-9.]/', '', strtolower($value)) ?? '';
    }

    /**
     * Jaccard similarity over word tokens.
     */
    private function tokenSimilarity(?string $a, ?string $b): float
    {
        if ($a === null || $b === null) {
            return 0.0;
        }

        $tokens = fn (string $value): array => array_unique(
            preg_split('/[^a-z0-9.]+/', strtolower($value), -1, PREG_SPLIT_NO_EMPTY) ?: [],
        );

        [$left, $right] = [$tokens($a), $tokens($b)];
        $union = count(array_unique([...$left, ...$right]));

        return $union === 0 ? 0.0 : count(array_intersect($left, $right)) / $union;
    }
}
