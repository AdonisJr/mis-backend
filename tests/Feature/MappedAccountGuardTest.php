<?php

namespace Tests\Feature;

use App\Http\Controllers\AccountController;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithBillingPosting;
use Tests\TestCase;

class MappedAccountGuardTest extends TestCase
{
    use InteractsWithBillingPosting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
    }

    public function test_deactivating_a_mapped_account_is_refused(): void
    {
        $cashAccount = $this->findAccountByCode('1010');

        $this->sendAs($this->adminToken, 'PUT', self::ACCOUNTS_URL.'/'.$cashAccount->id.'/deactivate')
            ->assertUnprocessable()
            ->assertExactJson(['message' => sprintf(AccountController::MAPPED_MESSAGE_FORMAT, 'Cash – Cash, Cash – Check')]);

        $this->assertTrue($cashAccount->refresh()->is_active);
    }

    public function test_deleting_a_mapped_account_is_refused(): void
    {
        $receivableAccount = $this->findAccountByCode('1100');

        $this->sendAs($this->adminToken, 'DELETE', self::ACCOUNTS_URL.'/'.$receivableAccount->id)
            ->assertUnprocessable()
            ->assertExactJson(['message' => sprintf(AccountController::MAPPED_MESSAGE_FORMAT, 'AR – Students')]);

        $this->assertDatabaseHas('accounts', ['id' => $receivableAccount->id]);
    }

    public function test_mapped_message_wins_over_used_message_on_delete(): void
    {
        $governmentAccount = $this->findAccountByCode('1110');
        JournalLine::factory()->for(JournalEntry::factory(), 'entry')->for($governmentAccount)->create();

        $this->sendAs($this->adminToken, 'DELETE', self::ACCOUNTS_URL.'/'.$governmentAccount->id)
            ->assertUnprocessable()
            ->assertExactJson(['message' => sprintf(AccountController::MAPPED_MESSAGE_FORMAT, 'Receivable – Government')]);
    }

    public function test_remapping_frees_the_account_to_be_deactivated(): void
    {
        $receivableAccount = $this->findAccountByCode('1100');
        $this->sendAs($this->adminToken, 'PUT', self::POSTING_SETTINGS_URL, ['mappings' => ['ar_students' => $this->findAccountByCode('1200')->id]])->assertOk();

        $this->sendAs($this->adminToken, 'PUT', self::ACCOUNTS_URL.'/'.$receivableAccount->id.'/deactivate')
            ->assertOk()
            ->assertJsonPath('account.is_active', false);
    }

    public function test_missing_account_returns_404_before_the_guard(): void
    {
        $this->sendAs($this->adminToken, 'PUT', self::ACCOUNTS_URL.'/'.self::MISSING_ID.'/deactivate')->assertNotFound();
        $this->sendAs($this->adminToken, 'DELETE', self::ACCOUNTS_URL.'/'.self::MISSING_ID)->assertNotFound();
    }
}
