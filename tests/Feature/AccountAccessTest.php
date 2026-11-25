<?php

namespace Tests\Feature;

use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithAccounts;
use Tests\TestCase;

class AccountAccessTest extends TestCase
{
    use InteractsWithAccounts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideAccountRoutes(): array
    {
        return [
            'list' => ['GET', self::ACCOUNTS_URL],
            'show' => ['GET', self::ACCOUNTS_URL.'/{account}'],
            'create' => ['POST', self::ACCOUNTS_URL],
            'update' => ['PUT', self::ACCOUNTS_URL.'/{account}'],
            'deactivate' => ['PUT', self::ACCOUNTS_URL.'/{account}/deactivate'],
            'reactivate' => ['PUT', self::ACCOUNTS_URL.'/{account}/reactivate'],
            'delete' => ['DELETE', self::ACCOUNTS_URL.'/{account}'],
        ];
    }

    #[DataProvider('provideAccountRoutes')]
    public function test_guest_gets_401_on_account_routes(string $method, string $uriPattern): void
    {
        $snapshot = Account::all()->toArray();

        $this->json($method, $this->buildTargetUri($uriPattern), $this->buildAccountPayload(['name' => 'CHANGED']))
            ->assertUnauthorized()
            ->assertExactJson(self::UNAUTHENTICATED_BODY);

        $this->assertSame($snapshot, Account::all()->toArray());
    }

    #[DataProvider('provideAccountRoutes')]
    public function test_registrar_cashier_and_teacher_get_403_on_account_routes(string $method, string $uriPattern): void
    {
        $snapshot = Account::all()->toArray();
        $deniedTokens = [$this->createRegistrarToken(), $this->createCashierToken(), $this->createTeacherToken()];

        foreach ($deniedTokens as $deniedToken) {
            $this->sendAs($deniedToken, $method, $this->buildTargetUri($uriPattern), $this->buildAccountPayload(['name' => 'CHANGED']))
                ->assertForbidden()
                ->assertExactJson(self::FORBIDDEN_BODY);
        }

        $this->assertSame($snapshot, Account::all()->toArray());
    }

    #[DataProvider('provideAccountRoutes')]
    public function test_user_without_role_gets_403_on_account_routes(string $method, string $uriPattern): void
    {
        $snapshot = Account::all()->toArray();

        $this->sendAs($this->createRoleLessToken(), $method, $this->buildTargetUri($uriPattern), $this->buildAccountPayload(['name' => 'CHANGED']))
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->assertSame($snapshot, Account::all()->toArray());
    }

    public function test_admin_can_use_every_account_endpoint(): void
    {
        $this->assertEveryEndpointWorksFor($this->adminToken);
    }

    public function test_accountant_can_use_every_account_endpoint(): void
    {
        $this->assertEveryEndpointWorksFor($this->createAccountantToken());
    }

    public function test_account_route_without_accept_header_returns_401_json(): void
    {
        $this->get(self::ACCOUNTS_URL)
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(self::UNAUTHENTICATED_BODY);
    }

    private function buildTargetUri(string $uriPattern): string
    {
        return str_replace('{account}', (string) $this->findSeededAccount('1010')->id, $uriPattern);
    }

    private function assertEveryEndpointWorksFor(string $token): void
    {
        $createdAccountId = $this->sendAs($token, 'POST', self::ACCOUNTS_URL, $this->buildAccountPayload())
            ->assertCreated()
            ->json('account.id');
        $accountUrl = self::ACCOUNTS_URL.'/'.$createdAccountId;

        $this->sendAs($token, 'GET', self::ACCOUNTS_URL)->assertOk()->assertJsonPath('meta.total', self::SEEDED_ACCOUNT_COUNT + 1);
        $this->sendAs($token, 'GET', $accountUrl)->assertOk()->assertJsonPath('account.id', $createdAccountId);
        $this->sendAs($token, 'PUT', $accountUrl, $this->buildAccountPayload(['name' => 'Renamed Fund']))->assertOk()->assertJsonPath('account.name', 'Renamed Fund');
        $this->sendAs($token, 'PUT', $accountUrl.'/deactivate')->assertOk()->assertJsonPath('account.is_active', false);
        $this->sendAs($token, 'PUT', $accountUrl.'/reactivate')->assertOk()->assertJsonPath('account.is_active', true);
        $this->sendAs($token, 'DELETE', $accountUrl)->assertNoContent();
    }
}
