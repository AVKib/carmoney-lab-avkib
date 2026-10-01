<?php

declare(strict_types=1);

namespace CarMoneyLab\Tests\Unit;

use CarMoneyLab\Domain\DecisionEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MileageDecisionEngineTest extends TestCase
{
    #[DataProvider('mileageAndLtvValues')]
    public function testCapsOnlyApproveAboveMileageThreshold(float $ltv, int $mileage, string $expected): void
    {
        // Arrange: пороги — фикстура из intent и действующего rules.php.
        $engine = new DecisionEngine(['approve_max' => 60.0, 'review_max' => 85.0], 400000);

        // Act
        $decision = $engine->decide($ltv, $mileage);

        // Assert: AC-MILEAGE-01–07, AC-MILEAGE-12, AC-MILEAGE-17.
        self::assertSame($expected, $decision);
    }

    /** @return iterable<string,array{float,int,string}> */
    public static function mileageAndLtvValues(): iterable
    {
        $ltvValues = [
            [10.0, ['approve', 'approve', 'review']],
            [50.0, ['approve', 'approve', 'review']],
            [59.99, ['approve', 'approve', 'review']],
            [60.0, ['review', 'review', 'review']],
            [60.01, ['review', 'review', 'review']],
            [75.0, ['review', 'review', 'review']],
            [84.99, ['review', 'review', 'review']],
            [85.0, ['review', 'review', 'review']],
            [85.01, ['reject', 'reject', 'reject']],
            [95.0, ['reject', 'reject', 'reject']],
        ];

        foreach ([399999, 400000, 400001] as $index => $mileage) {
            foreach ($ltvValues as [$ltv, $decisions]) {
                yield sprintf('mileage=%d, ltv=%.2f', $mileage, $ltv) => [$ltv, $mileage, $decisions[$index]];
            }
        }
    }

    #[DataProvider('configuredThresholdValues')]
    public function testUsesInjectedMileageThreshold(int $mileage, string $expected): void
    {
        // Arrange: синтетический порог AC-MILEAGE-13; файл правил не меняется.
        $engine = new DecisionEngine(['approve_max' => 60.0, 'review_max' => 85.0], 100000);

        // Act
        $decision = $engine->decide(50.0, $mileage);

        // Assert
        self::assertSame($expected, $decision);
    }

    /** @return array<string,array{int,string}> */
    public static function configuredThresholdValues(): array
    {
        return [
            'на тестовом пороге' => [100000, DecisionEngine::APPROVE],
            'за тестовым порогом' => [100001, DecisionEngine::REVIEW],
        ];
    }
}
