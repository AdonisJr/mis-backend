<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\PostingMapping;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithBillingPosting;
use Tests\TestCase;

class PostingMappingTest extends TestCase
{
    use InteractsWithBillingPosting;
    use RefreshDatabase;

    private const DEFAULT_CODES = [
        'ar_students' => '1100',
        'receivable_government' => '1110',
        'revenue_tuition' => '4010',
        'revenue_miscellaneous' => '4020',
        'revenue_other' => '4030',
        'installment_surcharge' => '4040',
        'school_discounts' => '4090',
        'cash_payment_discount' => '4095',
        'cash_cash' => '1010',
        'cash_check' => '1010',
        'cash_e_wallet' => '1020',
        'cash_bank_transfer' => '1020',
    ];

    private Migration $seedMigration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
        // a fresh instance, like a real re-run after a rollback
        $this->seedMigration = require glob(database_path('migrations/*_seed_default_posting_mappings.php'))[0];
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function provideBadAccountIds(): array
    {
        return ['missing id' => [999999], 'not a number' => ['abc'], 'null' => [null]];
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function provideWrongTypeAccounts(): array
    {
        return [
            'revenue account for ar_students' => ['ar_students', '4010', 'asset'],
            'asset account for revenue_tuition' => ['revenue_tuition', '1010', 'revenue'],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function provideBadBodies(): array
    {
        return ['empty body' => [[]], 'unknown purpose' => [['mappings' => ['rent' => 1]]]];
    }

    public function test_seed_inserts_twelve_rows_with_the_default_codes(): void
    {
        $this->assertSame(12, PostingMapping::count());

        foreach (self::DEFAULT_CODES as $purpose => $code) {
            $this->assertSame($code, PostingMapping::where('purpose', $purpose)->firstOrFail()->account->code);
        }
    }

    public function test_seed_rerun_inserts_nothing(): void
    {
        $this->seedMigration->up();

        $this->assertSame(12, PostingMapping::count());
    }

    public function test_seed_rerun_skips_a_purpose_whose_code_is_missing(): void
    {
        PostingMapping::query()->delete();
        $this->findAccountByCode('4040')->delete();

        $this->seedMigration->up();

        $this->assertSame(11, PostingMapping::count());
        $this->assertDatabaseMissing('posting_mappings', ['purpose' => 'installment_surcharge']);
    }

    public function test_seed_rerun_keeps_an_edited_mapping(): void
    {
        $editedAccount = $this->findAccountByCode('1200');
        PostingMapping::where('purpose', 'ar_students')->update(['account_id' => $editedAccount->id]);

        $this->seedMigration->up();

        $this->assertSame(12, PostingMapping::count());
        $this->assertSame($editedAccount->id, PostingMapping::where('purpose', 'ar_students')->value('account_id'));
    }

    public function test_rollback_removes_untouched_rows_and_keeps_edited(): void
    {
        $editedAccount = $this->findAccountByCode('1200');
        PostingMapping::where('purpose', 'ar_students')->update(['account_id' => $editedAccount->id]);

        $this->seedMigration->down();

        $this->assertSame(['ar_students'], PostingMapping::pluck('purpose')->all());
    }

    public function test_show_lists_twelve_rows_in_catalog_order(): void
    {
        $mappingRows = $this->sendAs($this->adminToken, 'GET', self::POSTING_SETTINGS_URL)->assertOk()->json('posting_mappings');

        $this->assertCount(12, $mappingRows);
        $this->assertSame(PostingMapping::PURPOSES, array_column($mappingRows, 'purpose'));

        foreach ($mappingRows as $mappingRow) {
            $catalogRow = PostingMapping::CATALOG[$mappingRow['purpose']];
            $this->assertSame(self::MAPPING_KEYS, array_keys($mappingRow));
            $this->assertSame([$catalogRow['label'], $catalogRow['group'], $catalogRow['account_type']], [$mappingRow['label'], $mappingRow['group'], $mappingRow['account_type']]);
            $this->assertSame(['id', 'code', 'name', 'is_active'], array_keys($mappingRow['account']));
            $this->assertSame(self::DEFAULT_CODES[$mappingRow['purpose']], $mappingRow['account']['code']);
        }
    }

    public function test_unmapped_purpose_shows_a_null_account(): void
    {
        $this->unmapPurpose('cash_check');

        $mappingRows = $this->sendAs($this->adminToken, 'GET', self::POSTING_SETTINGS_URL)->assertOk()->json('posting_mappings');

        $this->assertNull($mappingRows[9]['account']);
        $this->assertSame('cash_check', $mappingRows[9]['purpose']);
        $this->assertNotNull($mappingRows[8]['account']);
    }

    public function test_update_changes_one_purpose(): void
    {
        $newAccount = $this->findAccountByCode('1200');

        $this->sendAs($this->adminToken, 'PUT', self::POSTING_SETTINGS_URL, ['mappings' => ['ar_students' => $newAccount->id]])
            ->assertOk()
            ->assertJsonPath('posting_mappings.0.account.id', $newAccount->id)
            ->assertJsonPath('posting_mappings.1.account.code', '1110');

        $this->assertSame($newAccount->id, PostingMapping::where('purpose', 'ar_students')->value('account_id'));
        $this->assertSame(12, PostingMapping::count());
    }

    public function test_update_changes_many_purposes_and_maps_an_unmapped_one(): void
    {
        $this->unmapPurpose('revenue_other');
        $assetAccount = $this->findAccountByCode('1200');
        $revenueAccount = $this->findAccountByCode('4100');

        $this->sendAs($this->adminToken, 'PUT', self::POSTING_SETTINGS_URL, ['mappings' => [
            'ar_students' => $assetAccount->id,
            'revenue_other' => $revenueAccount->id,
            'cash_cash' => $assetAccount->id,
        ]])->assertOk()->assertJsonPath('posting_mappings.4.account.code', '4100');

        $this->assertSame(12, PostingMapping::count());
        $this->assertSame($assetAccount->id, PostingMapping::where('purpose', 'cash_cash')->value('account_id'));
    }

    #[DataProvider('provideBadAccountIds')]
    public function test_update_rejects_a_bad_account_id(mixed $badId): void
    {
        $this->sendAs($this->adminToken, 'PUT', self::POSTING_SETTINGS_URL, ['mappings' => ['ar_students' => $badId]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mappings.ar_students']);
    }

    public function test_update_rejects_an_inactive_account(): void
    {
        $inactiveAccount = Account::factory()->inactive()->create();

        $errors = $this->sendAs($this->adminToken, 'PUT', self::POSTING_SETTINGS_URL, ['mappings' => ['ar_students' => $inactiveAccount->id]])
            ->assertUnprocessable()
            ->json('errors');

        $this->assertSame(['mappings.ar_students' => ['The selected account is inactive.']], $errors);
    }

    #[DataProvider('provideWrongTypeAccounts')]
    public function test_update_rejects_an_account_of_the_wrong_type(string $purpose, string $code, string $requiredType): void
    {
        $errors = $this->sendAs($this->adminToken, 'PUT', self::POSTING_SETTINGS_URL, ['mappings' => [$purpose => $this->findAccountByCode($code)->id]])
            ->assertUnprocessable()
            ->json('errors');

        $this->assertSame(['mappings.'.$purpose => [sprintf('The account must be of type %s for this purpose.', $requiredType)]], $errors);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('provideBadBodies')]
    public function test_update_rejects_a_bad_body(array $body): void
    {
        $this->sendAs($this->adminToken, 'PUT', self::POSTING_SETTINGS_URL, $body)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mappings']);
    }

    public function test_mixed_valid_and_invalid_saves_nothing(): void
    {
        $before = DB::table('posting_mappings')->orderBy('id')->get()->all();

        $this->sendAs($this->adminToken, 'PUT', self::POSTING_SETTINGS_URL, ['mappings' => [
            'ar_students' => $this->findAccountByCode('1200')->id,
            'revenue_tuition' => $this->findAccountByCode('1010')->id,
        ]])->assertUnprocessable()->assertJsonValidationErrors(['mappings.revenue_tuition']);

        $this->assertEquals($before, DB::table('posting_mappings')->orderBy('id')->get()->all());
    }
}
