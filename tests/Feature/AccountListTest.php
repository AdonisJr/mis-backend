<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithAccounts;
use Tests\TestCase;

class AccountListTest extends TestCase
{
    use InteractsWithAccounts;
    use RefreshDatabase;

    private const PER_PAGE = 15;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function provideInvalidFilters(): array
    {
        return [
            'type income' => [['type' => 'income']],
            'status all' => [['status' => 'all']],
            'search 256 chars' => [['search' => str_repeat('s', 256)]],
        ];
    }

    /**
     * @param  array<string, mixed>  $queryParams
     */
    private function fetchAccountList(array $queryParams = []): TestResponse
    {
        return $this->withToken($this->adminToken)->getJson(self::ACCOUNTS_URL.'?'.http_build_query($queryParams));
    }

    public function test_list_returns_chart_sorted_by_code_paginated(): void
    {
        $firstPage = $this->fetchAccountList()->assertOk();

        $this->assertSame(self::SEEDED_ACCOUNT_COUNT, $firstPage->json('meta.total'));
        $this->assertSame(self::PER_PAGE, $firstPage->json('meta.per_page'));
        $this->assertSame(3, $firstPage->json('meta.last_page'));
        $this->assertSame(1, $firstPage->json('meta.current_page'));
        $this->assertCount(self::PER_PAGE, $firstPage->json('accounts'));
        $this->assertSame('1010', $firstPage->json('accounts.0.code'));

        $lastPageCodes = $this->listCodes($this->fetchAccountList(['page' => 3]));
        $this->assertSame('5100', end($lastPageCodes));
        $this->assertCount(3, $lastPageCodes);
    }

    public function test_list_rows_have_account_keys(): void
    {
        $listResponse = $this->fetchAccountList()->assertOk();

        $this->assertSame(self::ACCOUNT_KEYS, array_keys($listResponse->json('accounts.0')));
    }

    public function test_search_matches_code_substring(): void
    {
        $this->assertSame(['1590'], $this->listCodes($this->fetchAccountList(['search' => '159'])));
    }

    public function test_search_matches_name_case_insensitively(): void
    {
        $this->assertSame(['1010', '1020', '4095'], $this->listCodes($this->fetchAccountList(['search' => 'CaSh'])));
    }

    public function test_search_requires_every_word(): void
    {
        $this->assertSame(['1020'], $this->listCodes($this->fetchAccountList(['search' => 'cash bank'])));
    }

    public function test_type_filter(): void
    {
        $this->assertSame(['3010', '3020'], $this->listCodes($this->fetchAccountList(['type' => 'equity'])));
    }

    public function test_status_filter_active_and_inactive(): void
    {
        $prepaidAccount = $this->findSeededAccount('1200');
        $this->withToken($this->adminToken)->putJson(self::ACCOUNTS_URL.'/'.$prepaidAccount->id.'/deactivate')->assertOk();

        $this->assertSame(['1200'], $this->listCodes($this->fetchAccountList(['status' => 'inactive'])));
        $this->assertSame(self::SEEDED_ACCOUNT_COUNT - 1, $this->fetchAccountList(['status' => 'active'])->json('meta.total'));
    }

    public function test_filters_combine(): void
    {
        $filteredCodes = $this->listCodes($this->fetchAccountList(['search' => 'receivable', 'type' => 'asset', 'status' => 'active']));

        $this->assertSame(['1100', '1110'], $filteredCodes);
    }

    /**
     * @param  array<string, mixed>  $invalidFilter
     */
    #[DataProvider('provideInvalidFilters')]
    public function test_list_rejects_invalid_filter(array $invalidFilter): void
    {
        $this->fetchAccountList($invalidFilter)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(array_keys($invalidFilter));
    }
}
