<?php

namespace Tests\Feature\Concerns;

use App\Models\Account;
use App\Models\User;
use Illuminate\Testing\TestResponse;

trait InteractsWithAccounts
{
    use InteractsWithFees;

    private const ACCOUNTS_URL = '/api/mis/accounts';

    private const ACCOUNT_KEYS = ['id', 'code', 'name', 'type', 'subtype', 'is_contra', 'normal_balance', 'description', 'is_active', 'is_used', 'created_at', 'updated_at'];

    private const SEEDED_ACCOUNT_COUNT = 33;

    private const NEW_ACCOUNT_CODE = '1999';

    private const NEW_ACCOUNT_NAME = 'Petty Cash Fund';

    private function createAccountantToken(): string
    {
        return $this->createTokenFor(User::factory()->accountant()->create(['email' => 'accountant@school.test']));
    }

    private function createCashierToken(): string
    {
        return $this->createTokenFor(User::factory()->cashier()->create(['email' => 'cashier@school.test']));
    }

    private function createTeacherToken(): string
    {
        return $this->createTokenFor(User::factory()->teacher()->create(['email' => 'teacher@school.test']));
    }

    private function createRoleLessToken(): string
    {
        return $this->createTokenFor(User::factory()->create(['email' => 'roleless@school.test']));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendAs(string $token, string $method, string $uri, array $payload = []): TestResponse
    {
        // a different token needs a fresh guard, which otherwise keeps the user of the previous request
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->json($method, $uri, $payload);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function buildAccountPayload(array $overrides = []): array
    {
        return [
            'code' => self::NEW_ACCOUNT_CODE,
            'name' => self::NEW_ACCOUNT_NAME,
            'type' => Account::TYPE_ASSET,
            'subtype' => Account::SUBTYPE_CURRENT_ASSET,
            'is_contra' => false,
            'description' => null,
            ...$overrides,
        ];
    }

    private function findSeededAccount(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    /**
     * @return list<string>
     */
    private function listCodes(TestResponse $listResponse): array
    {
        return array_column($listResponse->assertOk()->json('accounts'), 'code');
    }
}
