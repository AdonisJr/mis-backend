<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\PostingMapping;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountSeedMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const SEEDED_ACCOUNT_COUNT = 33;

    private const CONTRA_CODES = ['1190', '1590', '4090', '4095'];

    private Migration $seedMigration;

    protected function setUp(): void
    {
        parent::setUp();

        // the mapping seed points at seeded accounts; this test covers the chart seed alone
        PostingMapping::query()->delete();
        // a fresh instance, like a real re-run after a rollback
        $this->seedMigration = require glob(database_path('migrations/*_seed_starter_chart_of_accounts.php'))[0];
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function provideEditedFields(): array
    {
        return [
            'code' => [['code' => '1011']],
            'name' => [['name' => 'Petty Cash']],
            'name case only' => [['name' => 'cash on hand']],
            'type and subtype' => [['type' => Account::TYPE_LIABILITY, 'subtype' => Account::SUBTYPE_CURRENT_LIABILITY]],
            'is_contra' => [['is_contra' => true]],
            'description' => [['description' => 'A note']],
            'deactivated' => [['is_active' => false]],
        ];
    }

    public function test_seed_inserts_33_accounts_with_counts_per_type(): void
    {
        $this->assertSame(self::SEEDED_ACCOUNT_COUNT, Account::count());
        $this->assertSame(
            [Account::TYPE_ASSET => 9, Account::TYPE_LIABILITY => 5, Account::TYPE_EQUITY => 2, Account::TYPE_REVENUE => 7, Account::TYPE_EXPENSE => 10],
            Account::query()->get()->countBy('type')->only(Account::TYPES)->all(),
        );
    }

    public function test_seed_marks_exactly_the_four_contra_accounts(): void
    {
        $contraCodes = Account::where('is_contra', true)->orderBy('code')->pluck('code')->all();

        $this->assertSame(self::CONTRA_CODES, $contraCodes);
    }

    public function test_seed_assigns_default_subtypes(): void
    {
        $codesBySubtype = [
            Account::SUBTYPE_CURRENT_ASSET => ['1010', '1020', '1100', '1110', '1190', '1200', '1300'],
            Account::SUBTYPE_NON_CURRENT_ASSET => ['1500', '1590'],
            Account::SUBTYPE_CURRENT_LIABILITY => ['2010', '2020', '2030', '2100', '2110'],
            Account::SUBTYPE_EQUITY => ['3010', '3020'],
            Account::SUBTYPE_OPERATING_REVENUE => ['4010', '4020', '4030', '4040', '4090', '4095'],
            Account::SUBTYPE_OTHER_INCOME => ['4100'],
            Account::SUBTYPE_OPERATING_EXPENSE => ['5010', '5020', '5030', '5040', '5050', '5060', '5070', '5080', '5090', '5100'],
        ];
        $actualSubtypes = Account::orderBy('code')->pluck('subtype', 'code')->all();

        $this->assertCount(self::SEEDED_ACCOUNT_COUNT, $actualSubtypes);

        foreach ($codesBySubtype as $subtype => $codes) {
            $this->assertSame(array_fill_keys($codes, $subtype), array_intersect_key($actualSubtypes, array_flip($codes)));
        }
    }

    public function test_rerun_inserts_nothing_when_chart_is_complete(): void
    {
        $this->seedMigration->up();
        $this->seedMigration->up();

        $this->assertSame(self::SEEDED_ACCOUNT_COUNT, Account::count());
    }

    public function test_rerun_keeps_edited_row_and_restores_deleted_row(): void
    {
        Account::where('code', '1010')->firstOrFail()->update(['name' => 'Renamed Cash']);
        Account::where('code', '1020')->delete();

        $this->seedMigration->up();

        $this->assertSame('Renamed Cash', Account::where('code', '1010')->firstOrFail()->name);
        $this->assertSame('Cash in Bank', Account::where('code', '1020')->firstOrFail()->name);
        $this->assertSame(self::SEEDED_ACCOUNT_COUNT, Account::count());
    }

    public function test_rerun_skips_seed_row_whose_name_is_taken_ignoring_case(): void
    {
        Account::where('code', '1010')->delete();
        Account::factory()->create(['code' => '9999', 'name' => 'CASH ON HAND']);

        $this->seedMigration->up();

        $this->assertFalse(Account::where('code', '1010')->exists());
        $this->assertSame(self::SEEDED_ACCOUNT_COUNT, Account::count());
    }

    public function test_rollback_removes_every_untouched_seeded_row(): void
    {
        $this->seedMigration->down();

        $this->assertSame(0, Account::count());
    }

    /**
     * @param  array<string, mixed>  $editedFields
     */
    #[DataProvider('provideEditedFields')]
    public function test_rollback_keeps_an_edited_seeded_row(array $editedFields): void
    {
        $editedAccount = Account::where('code', '1010')->firstOrFail();
        $editedAccount->forceFill($editedFields)->save();

        $this->seedMigration->down();

        $this->assertTrue(Account::whereKey($editedAccount->id)->exists());
        $this->assertSame(1, Account::count());
    }

    public function test_rollback_removes_a_deactivated_then_reactivated_row(): void
    {
        $cashAccount = Account::where('code', '1010')->firstOrFail();
        $cashAccount->forceFill(['is_active' => false])->save();
        $cashAccount->forceFill(['is_active' => true])->save();

        $this->seedMigration->down();

        $this->assertSame(0, Account::count());
    }

    public function test_rollback_keeps_user_created_accounts(): void
    {
        $userAccount = Account::factory()->create();

        $this->seedMigration->down();

        $this->assertSame([$userAccount->id], Account::pluck('id')->all());
    }
}
