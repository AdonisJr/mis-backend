<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithReports;
use Tests\TestCase;

class ReportAccessTest extends TestCase
{
    use InteractsWithReports;
    use RefreshDatabase;

    private const MALFORMED_QUERY = '?as_of=bad&from=bad&to=bad';

    private const BAD_DATE = '2030-02-30';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAssessmentFixtures();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideReportPaths(): array
    {
        return [
            'trial balance' => ['trial-balance'],
            'income statement' => ['income-statement'],
            'balance sheet' => ['balance-sheet'],
            'ar aging' => ['ar-aging'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideMalformedDates(): array
    {
        return [
            'trial balance as_of' => ['trial-balance', 'as_of'],
            'income statement from' => ['income-statement', 'from'],
            'income statement to' => ['income-statement', 'to'],
            'balance sheet as_of' => ['balance-sheet', 'as_of'],
            'ar aging as_of' => ['ar-aging', 'as_of'],
        ];
    }

    #[DataProvider('provideReportPaths')]
    public function test_guest_gets_401(string $reportPath): void
    {
        $this->getJson(self::REPORTS_URL.'/'.$reportPath.self::MALFORMED_QUERY)->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
    }

    #[DataProvider('provideReportPaths')]
    public function test_admin_gets_200(string $reportPath): void
    {
        $this->sendAs($this->adminToken, 'GET', self::REPORTS_URL.'/'.$reportPath)->assertOk();
    }

    #[DataProvider('provideReportPaths')]
    public function test_accountant_gets_200(string $reportPath): void
    {
        $this->sendAs($this->createAccountantToken(), 'GET', self::REPORTS_URL.'/'.$reportPath)->assertOk();
    }

    #[DataProvider('provideReportPaths')]
    public function test_registrar_gets_403_before_validation(string $reportPath): void
    {
        $this->sendAs($this->createRegistrarToken(), 'GET', self::REPORTS_URL.'/'.$reportPath.self::MALFORMED_QUERY)->assertForbidden()->assertExactJson(['message' => 'Unauthorized']);
    }

    #[DataProvider('provideReportPaths')]
    public function test_cashier_gets_403_before_validation(string $reportPath): void
    {
        $this->sendAs($this->createCashierToken(), 'GET', self::REPORTS_URL.'/'.$reportPath.self::MALFORMED_QUERY)->assertForbidden()->assertExactJson(['message' => 'Unauthorized']);
    }

    #[DataProvider('provideReportPaths')]
    public function test_teacher_gets_403_before_validation(string $reportPath): void
    {
        $this->sendAs($this->createTeacherToken(), 'GET', self::REPORTS_URL.'/'.$reportPath.self::MALFORMED_QUERY)->assertForbidden()->assertExactJson(['message' => 'Unauthorized']);
    }

    #[DataProvider('provideMalformedDates')]
    public function test_impossible_calendar_date_is_rejected_on_its_key(string $reportPath, string $dateKey): void
    {
        $this->fetchReport($reportPath, $dateKey.'='.self::BAD_DATE)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$dateKey]);
    }
}
