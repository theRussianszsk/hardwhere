<?php

namespace App\Enums;

enum MatchClassification: string
{
    case Exact = 'exact_match';
    case LikelySame = 'likely_same_product';
    case LikelyVariant = 'likely_variant';
    case PossibleDuplicate = 'possible_duplicate';
    case NewCandidate = 'new_product_candidate';
    case InsufficientConfidence = 'insufficient_confidence';

    /**
     * Only an exact identifier match may skip the human review screen.
     */
    public function requiresConfirmation(): bool
    {
        return $this !== self::Exact;
    }
}
