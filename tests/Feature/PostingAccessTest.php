<?php

namespace Tests\Feature;

use App\Models\PostingMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithBillingPosting;
use Tests\TestCase;

class PostingAccessTest extends TestCase
{
    use InteractsWithBillingPosting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideRoutes(): array
    {
        return [
            'show settings' => ['GET', '/api/mis/posting-settings'],
            'update settings' => ['PUT', '/api/mis/posting-settings'],
            'list unposted' => ['GET', '/api/mis/unposted-events'],
            'post pending' => ['POST', '/api/mis/unposted-events/post'],
        ];
    }

    #[DataProvider('provideRoutes')]
    public function test_guest_gets_401(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
    }

    #[DataProvider('provideRoutes')]
    public function test_registrar_cashier_and_teacher_get_403(string $method, string $uri): void
    {
        $mappingsBefore = DB::table('posting_mappings')->orderBy('id')->get()->all();

        foreach ([$this->createRegistrarToken(), $this->createCashierToken(), $this->createTeacherToken()] as $staffToken) {
            $this->sendAs($staffToken, $method, $uri, ['mappings' => ['ar_students' => 1]])->assertForbidden()->assertExactJson(['message' => 'Unauthorized']);
        }

        $this->assertEquals($mappingsBefore, DB::table('posting_mappings')->orderBy('id')->get()->all());
        $this->assertDatabaseCount('journal_entries', 0);
    }

    #[DataProvider('provideRoutes')]
    public function test_role_less_user_gets_403(string $method, string $uri): void
    {
        $this->sendAs($this->createRoleLessToken(), $method, $uri)->assertForbidden();
    }

    public function test_admin_uses_all_four_endpoints(): void
    {
        $this->sendAs($this->adminToken, 'GET', self::POSTING_SETTINGS_URL)->assertOk();
        $this->sendAs($this->adminToken, 'PUT', self::POSTING_SETTINGS_URL, ['mappings' => ['ar_students' => $this->findAccountByCode('1200')->id]])->assertOk();
        $this->fetchUnposted($this->adminToken)->assertOk();
        $this->postPending($this->adminToken)->assertOk();
    }

    public function test_accountant_uses_all_four_endpoints(): void
    {
        $accountantToken = $this->createAccountantToken();

        $this->sendAs($accountantToken, 'GET', self::POSTING_SETTINGS_URL)->assertOk()->assertJsonCount(12, 'posting_mappings');
        $this->sendAs($accountantToken, 'PUT', self::POSTING_SETTINGS_URL, ['mappings' => ['ar_students' => $this->findAccountByCode('1200')->id]])->assertOk();
        $this->fetchUnposted($accountantToken)->assertOk()->assertJsonPath('meta.per_page', 15);
        $this->postPending($accountantToken)->assertOk()->assertJsonPath('posted_count', 0);
        $this->assertSame($this->findAccountByCode('1200')->id, PostingMapping::where('purpose', 'ar_students')->value('account_id'));
    }

    public function test_missing_accept_header_still_returns_json_401(): void
    {
        $this->call('GET', self::POSTING_SETTINGS_URL, [], [], [], ['HTTP_ACCEPT' => ''])
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json');
    }
}
