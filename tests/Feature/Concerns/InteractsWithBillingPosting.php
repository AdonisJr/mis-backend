<?php

namespace Tests\Feature\Concerns;

use App\Models\Account;
use App\Models\Assessment;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\PostingMapping;
use App\Models\User;
use Illuminate\Testing\TestResponse;

trait InteractsWithBillingPosting
{
    // InteractsWithAccounts also defines sendAs and createCashierToken, so the two traits collide; the account helpers are repeated here
    use InteractsWithPayments;

    private const ACCOUNTS_URL = '/api/mis/accounts';

    private const JOURNAL_ENTRIES_URL = '/api/mis/journal-entries';

    private const POSTING_SETTINGS_URL = '/api/mis/posting-settings';

    private const UNPOSTED_EVENTS_URL = '/api/mis/unposted-events';

    private const MAPPING_KEYS = ['purpose', 'label', 'group', 'account_type', 'account'];

    private const EVENT_KEYS = ['type', 'source_id', 'date', 'student', 'amount', 'reason', 'reason_message'];

    private const TUITION_AMOUNT = '5000.00';

    private const PAYMENT_AMOUNT = '1000.00';

    private function createAccountantToken(): string
    {
        return $this->createTokenFor(User::factory()->accountant()->create(['email' => 'accountant@school.test']));
    }

    private function createTeacherToken(): string
    {
        return $this->createTokenFor(User::factory()->teacher()->create(['email' => 'teacher@school.test']));
    }

    private function createRoleLessToken(): string
    {
        return $this->createTokenFor(User::factory()->create(['email' => 'roleless@school.test']));
    }

    private function findAccountByCode(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    private function findOriginalEntry(Assessment|Payment $record): ?JournalEntry
    {
        return JournalEntry::originalsFor(JournalEntry::buildForSource($record))->first();
    }

    /**
     * @return array<string, array{string, string}> account code => [debit, credit]
     */
    private function mapLinesByCode(JournalEntry $entry): array
    {
        $linesByCode = [];

        foreach ($entry->lines()->with('account')->get() as $line) {
            $linesByCode[$line->account->code] = [$line->debit, $line->credit];
        }

        return $linesByCode;
    }

    private function unmapPurpose(string $purpose): void
    {
        PostingMapping::where('purpose', $purpose)->delete();
    }

    private function deactivateSeeded(string $code): void
    {
        $this->findAccountByCode($code)->forceFill(['is_active' => false])->save();
    }

    private function fetchUnposted(string $token, int $page = 1): TestResponse
    {
        return $this->sendAs($token, 'GET', self::UNPOSTED_EVENTS_URL.'?page='.$page);
    }

    private function postPending(string $token): TestResponse
    {
        return $this->sendAs($token, 'POST', self::UNPOSTED_EVENTS_URL.'/post');
    }

    /** A current assessment made by the factory, so nothing posted it. */
    private function createBackfillAssessment(string $grossTuition, string $createdAt): Assessment
    {
        $assessment = Assessment::factory()->create();
        $assessment->forceFill(['gross_tuition' => $grossTuition, 'gross_total' => $grossTuition, 'net_total' => $grossTuition, 'created_at' => $createdAt])->save();

        return $assessment;
    }
}
