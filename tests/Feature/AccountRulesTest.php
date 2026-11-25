<?php

namespace Tests\Feature;

use App\Http\Requests\StoreAccountRequest;
use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithAccounts;
use Tests\TestCase;

class AccountRulesTest extends TestCase
{
    use InteractsWithAccounts;
    use RefreshDatabase;

    private const MISSING_FIELD = '__missing__';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function provideInvalidFields(): array
    {
        return [
            'code missing' => [['code' => self::MISSING_FIELD], 'code'],
            'code letters' => [['code' => 'AB12'], 'code'],
            'code dash' => [['code' => '10-1'], 'code'],
            'code space' => [['code' => '10 1'], 'code'],
            // a trailing newline never reaches the rule: TrimStrings strips it, so the row uses an embedded one
            'code embedded newline' => [['code' => "12\n34"], 'code'],
            'code 3 chars' => [['code' => '101'], 'code'],
            'code 11 chars' => [['code' => '12345678901'], 'code'],
            'code number' => [['code' => 1234], 'code'],
            'name missing' => [['name' => self::MISSING_FIELD], 'name'],
            'name 151 chars' => [['name' => str_repeat('n', 151)], 'name'],
            'name array' => [['name' => ['Petty']], 'name'],
            'type missing' => [['type' => self::MISSING_FIELD], 'type'],
            'type income' => [['type' => 'income'], 'type'],
            'type capitalised' => [['type' => 'Asset'], 'type'],
            'subtype missing' => [['subtype' => self::MISSING_FIELD], 'subtype'],
            'subtype cash' => [['subtype' => 'cash'], 'subtype'],
            'is_contra missing' => [['is_contra' => self::MISSING_FIELD], 'is_contra'],
            'is_contra yes' => [['is_contra' => 'yes'], 'is_contra'],
            'description 1001 chars' => [['description' => str_repeat('d', 1001)], 'description'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideMismatchedSubtypes(): array
    {
        return [
            'asset with operating revenue' => [Account::TYPE_ASSET, Account::SUBTYPE_OPERATING_REVENUE],
            'liability with current asset' => [Account::TYPE_LIABILITY, Account::SUBTYPE_CURRENT_ASSET],
            'equity with other income' => [Account::TYPE_EQUITY, Account::SUBTYPE_OTHER_INCOME],
            'revenue with operating expense' => [Account::TYPE_REVENUE, Account::SUBTYPE_OPERATING_EXPENSE],
            'expense with equity' => [Account::TYPE_EXPENSE, Account::SUBTYPE_EQUITY],
        ];
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function provideCatalogPairs(): array
    {
        $catalogPairs = [];
        $pairNumber = 0;

        foreach (Account::SUBTYPES_BY_TYPE as $accountType => $subtypes) {
            foreach ($subtypes as $subtype) {
                $pairNumber++;
                $catalogPairs[$accountType.' '.$subtype] = [$accountType, $subtype, '70'.str_pad((string) $pairNumber, 2, '0', STR_PAD_LEFT)];
            }
        }

        return $catalogPairs;
    }

    /**
     * @param  array<string, mixed>  $invalidFields
     */
    #[DataProvider('provideInvalidFields')]
    public function test_create_rejects_invalid_field(array $invalidFields, string $errorKey): void
    {
        $payload = $this->buildAccountPayload($invalidFields);
        $payload = array_filter($payload, fn ($value) => $value !== self::MISSING_FIELD);

        $this->withToken($this->adminToken)
            ->postJson(self::ACCOUNTS_URL, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$errorKey]);

        $this->assertSame(self::SEEDED_ACCOUNT_COUNT, Account::count());
    }

    public function test_code_accepts_4_and_10_digits_and_leading_zero(): void
    {
        foreach (['0100', '1234567890'] as $acceptedCode) {
            $this->withToken($this->adminToken)
                ->postJson(self::ACCOUNTS_URL, $this->buildAccountPayload(['code' => $acceptedCode, 'name' => 'Account '.$acceptedCode]))
                ->assertCreated()
                ->assertJsonPath('account.code', $acceptedCode);
        }
    }

    public function test_code_must_be_unique(): void
    {
        $this->withToken($this->adminToken)
            ->postJson(self::ACCOUNTS_URL, $this->buildAccountPayload(['code' => '1010']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code'])
            ->assertJsonPath('errors.code.0', 'The code has already been taken.');

        $this->assertSame(self::SEEDED_ACCOUNT_COUNT, Account::count());
    }

    public function test_update_rejects_code_of_other_account(): void
    {
        $bankAccount = $this->findSeededAccount('1020');

        $this->withToken($this->adminToken)
            ->putJson(self::ACCOUNTS_URL.'/'.$bankAccount->id, $this->buildAccountPayload(['code' => '1010']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);

        $this->assertSame('1020', $bankAccount->refresh()->code);
    }

    public function test_name_unique_ignoring_case(): void
    {
        $this->withToken($this->adminToken)
            ->postJson(self::ACCOUNTS_URL, $this->buildAccountPayload(['name' => 'CASH ON HAND']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', StoreAccountRequest::NAME_TAKEN_MESSAGE);

        $this->assertSame(self::SEEDED_ACCOUNT_COUNT, Account::count());
    }

    public function test_update_rejects_name_of_other_account_ignoring_case(): void
    {
        $bankAccount = $this->findSeededAccount('1020');

        $this->withToken($this->adminToken)
            ->putJson(self::ACCOUNTS_URL.'/'.$bankAccount->id, $this->buildAccountPayload(['code' => '1020', 'name' => 'cash on hand']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', StoreAccountRequest::NAME_TAKEN_MESSAGE);

        $this->assertSame('Cash in Bank', $bankAccount->refresh()->name);
    }

    public function test_name_of_150_characters_is_accepted(): void
    {
        $this->withToken($this->adminToken)
            ->postJson(self::ACCOUNTS_URL, $this->buildAccountPayload(['name' => str_repeat('n', 150)]))
            ->assertCreated();
    }

    #[DataProvider('provideMismatchedSubtypes')]
    public function test_subtype_mismatch_is_rejected(string $accountType, string $subtype): void
    {
        $this->withToken($this->adminToken)
            ->postJson(self::ACCOUNTS_URL, $this->buildAccountPayload(['type' => $accountType, 'subtype' => $subtype]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subtype']);
    }

    #[DataProvider('provideCatalogPairs')]
    public function test_every_catalog_pair_is_accepted(string $accountType, string $subtype, string $code): void
    {
        $this->withToken($this->adminToken)
            ->postJson(self::ACCOUNTS_URL, $this->buildAccountPayload(['code' => $code, 'name' => 'Pair '.$code, 'type' => $accountType, 'subtype' => $subtype]))
            ->assertCreated()
            ->assertJsonPath('account.type', $accountType)
            ->assertJsonPath('account.subtype', $subtype);
    }

    public function test_subtype_rejected_when_type_invalid(): void
    {
        $this->withToken($this->adminToken)
            ->postJson(self::ACCOUNTS_URL, $this->buildAccountPayload(['type' => [Account::TYPE_ASSET]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type', 'subtype']);
    }

    public function test_update_rejects_subtype_mismatch(): void
    {
        $cashAccount = $this->findSeededAccount('1010');

        $this->withToken($this->adminToken)
            ->putJson(self::ACCOUNTS_URL.'/'.$cashAccount->id, $this->buildAccountPayload(['code' => '1010', 'name' => 'Cash on Hand', 'subtype' => Account::SUBTYPE_OTHER_INCOME]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subtype']);

        $this->assertSame(Account::SUBTYPE_CURRENT_ASSET, $cashAccount->refresh()->subtype);
    }
}
