<?php

declare(strict_types=1);

namespace CarMoneyLab\Tests\Feature;

use CarMoneyLab\AppFactory;
use CarMoneyLab\Repository\ApplicationRepository;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

final class MileageApiTest extends TestCase
{
    private PDO $pdo;
    private App $app;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        // Только синтетическая БД теста; схема MySQL и миграции не меняются.
        $this->pdo->exec('CREATE TABLE applications (
            id INTEGER PRIMARY KEY AUTOINCREMENT, applicant_ref TEXT, requested_amount INTEGER,
            term_months INTEGER, status TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )');
        $this->pdo->exec('CREATE TABLE vehicles (
            application_id INTEGER, vin TEXT, production_year INTEGER, mileage_km INTEGER, market_value INTEGER
        )');
        $this->pdo->exec('CREATE TABLE decisions (
            application_id INTEGER, ltv REAL, decision TEXT, approved_limit INTEGER
        )');
        $this->app = AppFactory::create($this->pdo);
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'applicant_ref' => 'CL-MILEAGE-TEST',
            'vin' => 'XTA21099998765432',
            'year' => (int) date('Y') - 4,
            'mileage' => 400001,
            'market_value' => 900000,
            'requested_amount' => 450000,
            'term_months' => 24,
        ];
    }

    /** @param array<string,mixed>|null $payload */
    private function request(string $method, string $path, ?array $payload = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);
        if ($payload !== null) {
            $request = $request->withHeader('Content-Type', 'application/json')
                ->withBody((new StreamFactory())->createStream(json_encode($payload, JSON_THROW_ON_ERROR)));
        }

        return $this->app->handle($request);
    }

    /** @return array<string,mixed> */
    private function body(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    #[DataProvider('applicationValues')]
    public function testCreatesApplicationWithConfiguredMileageRule(
        int $mileage,
        int $amount,
        string $expectedDecision,
        int $expectedLimit,
    ): void {
        // Arrange: AC-MILEAGE-01–03, AC-MILEAGE-05–08, AC-MILEAGE-12, AC-MILEAGE-14–15.
        $payload = array_replace($this->payload(), ['mileage' => $mileage, 'requested_amount' => $amount]);

        // Act
        $response = $this->request('POST', '/api/applications', $payload);
        $body = $this->body($response);
        $saved = (new ApplicationRepository($this->pdo))->find((int) ($body['id'] ?? 0));

        // Assert: проверяется также production wiring AppFactory и сохранение результата.
        self::assertSame(201, $response->getStatusCode());
        self::assertSame($expectedDecision, $body['decision']);
        self::assertSame($expectedLimit, $body['approved_limit']);
        self::assertSame($mileage, $saved['mileage_km']);
        self::assertSame($expectedDecision, $saved['decision']);
    }

    /** @return iterable<string,array{int,int,string,int}> */
    public static function applicationValues(): iterable
    {
        yield 'approve zone, mileage=399999' => [399999, 450000, 'approve', 450000];
        yield 'approve zone, mileage=400000' => [400000, 450000, 'approve', 450000];
        yield 'approve zone, mileage=400001' => [400001, 450000, 'review', 0];
        yield 'review zone, mileage=399999' => [399999, 675000, 'review', 0];
        yield 'review zone, mileage=400000' => [400000, 675000, 'review', 0];
        yield 'review zone, mileage=400001' => [400001, 675000, 'review', 0];
        yield 'reject zone, mileage=399999' => [399999, 855000, 'reject', 0];
        yield 'reject zone, mileage=400000' => [400000, 855000, 'reject', 0];
        yield 'reject zone, mileage=400001' => [400001, 855000, 'reject', 0];
        yield 'верхняя граница допустимого пробега' => [500000, 450000, 'review', 0];
    }

    public function testCalculationOnlyUsesMileageWithoutSavingApplication(): void
    {
        // Arrange: тот же контракт решения для POST /api/ltv.
        $payload = $this->payload();

        // Act
        $response = $this->request('POST', '/api/ltv', $payload);
        $body = $this->body($response);
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM applications')->fetchColumn();

        // Assert
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(50.0, $body['ltv']);
        self::assertSame('review', $body['decision']);
        self::assertSame(0, $body['approved_limit']);
        self::assertSame(0, $count);
    }

    #[DataProvider('invalidRequests')]
    public function testReturnsExisting422MileageErrorWithoutSavingApplication(
        string $path,
        bool $includeField,
        ?int $mileage,
    ): void {
        // Arrange: AC-MILEAGE-09–11 для обоих HTTP-входов.
        $payload = $this->payload();
        if ($includeField) {
            $payload['mileage'] = $mileage;
        } else {
            unset($payload['mileage']);
        }

        // Act
        $response = $this->request('POST', $path, $payload);
        $body = $this->body($response);
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM applications')->fetchColumn();

        // Assert
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['errors' => ['mileage' => 'Пробег от 0 до 500000 км']], $body);
        self::assertSame(0, $count);
    }

    /** @return iterable<string,array{string,bool,?int}> */
    public static function invalidRequests(): iterable
    {
        foreach (['/api/applications', '/api/ltv'] as $path) {
            yield "$path: отсутствует" => [$path, false, null];
            yield "$path: null" => [$path, true, null];
            yield "$path: отрицательный" => [$path, true, -1];
            yield "$path: превышен диапазон ввода" => [$path, true, 500001];
        }
    }

    #[DataProvider('configuredThresholdValues')]
    public function testAppFactoryReadsMileageThresholdFromConfiguration(int $mileage, string $expected): void
    {
        // Arrange: AC-MILEAGE-13, конфигурация меняется только в памяти теста.
        $rules = require __DIR__ . '/../../backend/config/rules.php';
        $rules['vehicle']['review_max_mileage_km'] = 100000;
        $this->app = AppFactory::create($this->pdo, $rules);
        $payload = array_replace($this->payload(), ['mileage' => $mileage]);

        // Act
        $response = $this->request('POST', '/api/ltv', $payload);
        $body = $this->body($response);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($expected, $body['decision']);
    }

    /** @return array<string,array{int,string}> */
    public static function configuredThresholdValues(): array
    {
        return ['на тестовом пороге' => [100000, 'approve'], 'за тестовым порогом' => [100001, 'review']];
    }

    public function testReadingPreviouslySavedApproveDoesNotReassessMileage(): void
    {
        // Arrange: AC-MILEAGE-16, запись до введения правила с большим пробегом.
        $repository = new ApplicationRepository($this->pdo);
        $id = $repository->save('CL-MILEAGE-OLD', $this->payload(), [
            'ltv' => 50.0, 'decision' => 'approve', 'approved_limit' => 450000,
        ]);

        // Act
        $response = $this->request('GET', "/api/applications/$id");
        $body = $this->body($response);
        $saved = $repository->find($id);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(400001, $body['mileage_km']);
        self::assertSame('approve', $body['decision']);
        self::assertSame(450000, $body['approved_limit']);
        self::assertSame('approve', $saved['decision']);
    }
}
