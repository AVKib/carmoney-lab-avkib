<?php

declare(strict_types=1);

namespace CarMoneyLab\Tests\Unit;

use CarMoneyLab\Domain\ApplicationValidator;
use CarMoneyLab\Domain\AssessmentService;
use CarMoneyLab\Domain\DecisionEngine;
use CarMoneyLab\Domain\LtvCalculator;
use CarMoneyLab\Domain\ValidationException;
use CarMoneyLab\Domain\VehicleAge;
use CarMoneyLab\Domain\VinValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MileageAssessmentServiceTest extends TestCase
{
    private AssessmentService $service;

    protected function setUp(): void
    {
        $rules = require __DIR__ . '/../../backend/config/rules.php';
        $age = new VehicleAge((int) date('Y'));
        $this->service = new AssessmentService(
            new ApplicationValidator($rules, new VinValidator($rules['vin']), $age),
            new LtvCalculator(),
            new DecisionEngine($rules['ltv'], 400000),
            $age,
        );
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'vin' => 'XTA21099998765432',
            'year' => (int) date('Y') - 4,
            'mileage' => 400001,
            'market_value' => 900000,
            'requested_amount' => 450000,
            'term_months' => 24,
        ];
    }

    #[DataProvider('assessments')]
    public function testPassesPayloadMileageToDecisionAndSetsLimit(
        int $mileage,
        int $amount,
        float $expectedLtv,
        string $expectedDecision,
        int $expectedLimit,
    ): void {
        // Arrange: AC-MILEAGE-01–08, AC-MILEAGE-12, AC-MILEAGE-14–15.
        $payload = array_replace($this->payload(), ['mileage' => $mileage, 'requested_amount' => $amount]);

        // Act
        $result = $this->service->assess($payload);

        // Assert
        self::assertSame($mileage, $result['input']['mileage']);
        self::assertSame($expectedLtv, $result['ltv']);
        self::assertSame($expectedDecision, $result['decision']);
        self::assertSame($expectedLimit, $result['approved_limit']);
    }

    /** @return iterable<string,array{int,int,float,string,int}> */
    public static function assessments(): iterable
    {
        yield 'approve zone, mileage=399999' => [399999, 450000, 50.0, DecisionEngine::APPROVE, 450000];
        yield 'approve zone, mileage=400000' => [400000, 450000, 50.0, DecisionEngine::APPROVE, 450000];
        yield 'approve zone, mileage=400001' => [400001, 450000, 50.0, DecisionEngine::REVIEW, 0];
        yield 'review zone, mileage=399999' => [399999, 675000, 75.0, DecisionEngine::REVIEW, 0];
        yield 'review zone, mileage=400000' => [400000, 675000, 75.0, DecisionEngine::REVIEW, 0];
        yield 'review zone, mileage=400001' => [400001, 675000, 75.0, DecisionEngine::REVIEW, 0];
        yield 'reject zone, mileage=399999' => [399999, 855000, 95.0, DecisionEngine::REJECT, 0];
        yield 'reject zone, mileage=400000' => [400000, 855000, 95.0, DecisionEngine::REJECT, 0];
        yield 'reject zone, mileage=400001' => [400001, 855000, 95.0, DecisionEngine::REJECT, 0];
        yield 'низкий LTV не обходит правило' => [400001, 90000, 10.0, DecisionEngine::REVIEW, 0];
        yield 'верхняя граница ввода допустима' => [500000, 450000, 50.0, DecisionEngine::REVIEW, 0];
    }

    #[DataProvider('invalidMileageValues')]
    public function testRejectsMissingNullNegativeOrOutOfRangeMileage(bool $includeField, ?int $mileage): void
    {
        // Arrange: AC-MILEAGE-09–11.
        $payload = $this->payload();
        if ($includeField) {
            $payload['mileage'] = $mileage;
        } else {
            unset($payload['mileage']);
        }

        // Act
        try {
            $this->service->assess($payload);
            self::fail('Ожидали ошибку валидации пробега');
        } catch (ValidationException $exception) {
            // Assert
            self::assertSame(['mileage' => 'Пробег от 0 до 500000 км'], $exception->errors());
        }
    }

    /** @return array<string,array{bool,?int}> */
    public static function invalidMileageValues(): array
    {
        return [
            'поле отсутствует' => [false, null],
            'неизвестный пробег null' => [true, null],
            'отрицательный пробег' => [true, -1],
            'выше верхней границы ввода' => [true, 500001],
        ];
    }

    #[DataProvider('zeroMileageValues')]
    public function testPreservesZeroMileageAndExistingEmptyStringNormalisation(int|string $mileage): void
    {
        // Arrange: AC-MILEAGE-18.
        $payload = array_replace($this->payload(), ['mileage' => $mileage]);

        // Act
        $result = $this->service->assess($payload);

        // Assert
        self::assertSame(0, $result['input']['mileage']);
        self::assertSame(DecisionEngine::APPROVE, $result['decision']);
    }

    /** @return array<string,array{int|string}> */
    public static function zeroMileageValues(): array
    {
        return ['нулевой пробег' => [0], 'пустая строка' => ['']];
    }
}
