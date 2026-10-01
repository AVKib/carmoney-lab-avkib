<?php

declare(strict_types=1);

namespace CarMoneyLab\Domain;

/**
 * Решение по заявке на основании LTV и пробега из заявки.
 *
 *   LTV < approve_max              -> approve
 *   approve_max <= LTV <= review_max -> review
 *   LTV > review_max                -> reject
 *
 * Пробег выше настроенного порога ограничивает только approve до review.
 * Решения review и reject по LTV сохраняются.
 */
final class DecisionEngine
{
    public const APPROVE = 'approve';
    public const REVIEW = 'review';
    public const REJECT = 'reject';

    private float $approveMax;
    private float $reviewMax;

    /** @param array{approve_max:float,review_max:float} $thresholds */
    public function __construct(
        array $thresholds,
        private readonly int $reviewMaxMileage,
    ) {
        $this->approveMax = $thresholds['approve_max'];
        $this->reviewMax = $thresholds['review_max'];
    }

    public function decide(float $ltv, int $mileage = 0): string
    {
        if ($ltv < $this->approveMax) {
            return $mileage > $this->reviewMaxMileage ? self::REVIEW : self::APPROVE;
        }

        if ($ltv <= $this->reviewMax) {
            return self::REVIEW;
        }

        return self::REJECT;
    }
}
