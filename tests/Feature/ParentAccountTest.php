<?php

namespace Tests\Feature;

use App\Http\Controllers\ParentAccountController;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Support\MobileNumberNormaliser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\Feature\Concerns\InteractsWithRooms;
use Tests\TestCase;

class ParentAccountTest extends TestCase
{
    use InteractsWithRooms;
    use RefreshDatabase;

    private const PARENTS_URL = '/api/mis/parents';

    private const MOBILE_LOGIN_URL = '/api/mis/mobile/login';

    private const MOBILE_ME_URL = '/api/mis/mobile/me';

    private const PASSWORD_ALPHABET_PATTERN = '/^[ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789]{10}$/';

    private const PARENT_KEYS = ['id', 'name', 'mobile_number', 'email', 'status', 'must_change_password', 'is_active', 'children', 'created_at', 'updated_at'];

    private const PARENT_CHILD_KEYS = ['id', 'lrn', 'first_name', 'middle_name', 'last_name', 'relationship'];

    private const PAGE_META_KEYS = ['current_page', 'last_page', 'per_page', 'total'];

    private const NUMBER = '09170000001';

    private const OTHER_NUMBER = '09170000002';

    private const PARENT_NAME = 'Ana Cruz';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function buildPayload(array $overrides = []): array
    {
        return ['name' => self::PARENT_NAME, 'mobile_number' => self::NUMBER, ...$overrides];
    }

    private function buildParentUrl(int $id, string $suffix = ''): string
    {
        return self::PARENTS_URL.'/'.$id.$suffix;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createParentUser(array $overrides = []): User
    {
        return User::factory()->parentAccount()->create($overrides);
    }

    private function loginAsParent(string $mobileNumber, string $password): string
    {
        return $this->postJson(self::MOBILE_LOGIN_URL, ['username' => $mobileNumber, 'password' => $password, 'device_name' => 'test-phone'])->assertOk()->json('token');
    }

    public function test_create_returns_the_parent_and_a_one_time_temporary_password(): void
    {
        $createResponse = $this->withToken($this->adminToken)->postJson(self::PARENTS_URL, $this->buildPayload())->assertCreated();

        $this->assertSame(['parent', 'temporary_password'], array_keys($createResponse->json()));
        $this->assertSame(self::PARENT_KEYS, array_keys($createResponse->json('parent')));
        $this->assertSame(Student::ACCOUNT_STATUS_MUST_CHANGE_PASSWORD, $createResponse->json('parent.status'));
        $this->assertTrue($createResponse->json('parent.is_active'));
        $this->assertTrue($createResponse->json('parent.must_change_password'));
        $this->assertSame([], $createResponse->json('parent.children'));
        $this->assertNull($createResponse->json('parent.email'));
        $this->assertMatchesRegularExpression(self::PASSWORD_ALPHABET_PATTERN, $createResponse->json('temporary_password'));
        $parentUser = User::where('mobile_number', self::NUMBER)->firstOrFail();
        $this->assertSame([Role::PARENT], $parentUser->roles()->pluck('name')->all());
        $this->assertTrue(Hash::check($createResponse->json('temporary_password'), $parentUser->password));
        $this->assertNotSame($createResponse->json('temporary_password'), $parentUser->password);
    }

    public function test_create_stores_a_plus_63_number_as_09(): void
    {
        $this->withToken($this->adminToken)->postJson(self::PARENTS_URL, $this->buildPayload(['mobile_number' => '+63 917-000-0001']))
            ->assertCreated()
            ->assertJsonPath('parent.mobile_number', self::NUMBER);

        $this->assertSame(1, User::where('mobile_number', self::NUMBER)->count());
    }

    public function test_create_with_a_student_links_the_first_child(): void
    {
        $student = Student::factory()->create();

        $createResponse = $this->withToken($this->adminToken)
            ->postJson(self::PARENTS_URL, $this->buildPayload(['student_id' => $student->id, 'relationship' => 'Mother']))
            ->assertCreated();

        $this->assertCount(1, $createResponse->json('parent.children'));
        $this->assertSame(self::PARENT_CHILD_KEYS, array_keys($createResponse->json('parent.children.0')));
        $this->assertSame('Mother', $createResponse->json('parent.children.0.relationship'));
        $this->assertSame($student->id, $createResponse->json('parent.children.0.id'));
    }

    public function test_create_rejects_invalid_fields(): void
    {
        $student = Student::factory()->create();

        $this->withToken($this->adminToken)->postJson(self::PARENTS_URL, [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'mobile_number']);
        $this->withToken($this->adminToken)->postJson(self::PARENTS_URL, $this->buildPayload(['mobile_number' => '08170000001']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mobile_number' => MobileNumberNormaliser::FORMAT_MESSAGE]);
        $this->withToken($this->adminToken)->postJson(self::PARENTS_URL, $this->buildPayload(['email' => 'not-an-email']))->assertUnprocessable()->assertJsonValidationErrors(['email']);
        $this->withToken($this->adminToken)->postJson(self::PARENTS_URL, $this->buildPayload(['student_id' => $student->id, 'relationship' => 'Aunt']))->assertUnprocessable()->assertJsonValidationErrors(['relationship']);
        $this->withToken($this->adminToken)->postJson(self::PARENTS_URL, $this->buildPayload(['student_id' => $student->id]))->assertUnprocessable()->assertJsonValidationErrors(['relationship']);
        $this->withToken($this->adminToken)->postJson(self::PARENTS_URL, $this->buildPayload(['relationship' => 'Mother']))->assertUnprocessable()->assertJsonValidationErrors(['student_id']);
        $this->withToken($this->adminToken)->postJson(self::PARENTS_URL, $this->buildPayload(['student_id' => self::MISSING_ID, 'relationship' => 'Mother']))->assertUnprocessable()->assertJsonValidationErrors(['student_id']);

        $this->assertSame(0, User::parentAccounts()->count());
    }

    public function test_create_rejects_a_taken_mobile_number(): void
    {
        $this->withToken($this->adminToken)->postJson(self::PARENTS_URL, $this->buildPayload())->assertCreated();

        $this->withToken($this->adminToken)->postJson(self::PARENTS_URL, $this->buildPayload(['name' => 'Second Click']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mobile_number' => ParentAccountController::MOBILE_TAKEN_MESSAGE]);

        $this->assertSame(1, User::parentAccounts()->count());
    }

    public function test_a_staff_contact_number_does_not_block_a_parent(): void
    {
        User::factory()->registrar()->create(['email' => 'staff@school.test', 'contact' => self::NUMBER]);

        $this->withToken($this->adminToken)->postJson(self::PARENTS_URL, $this->buildPayload())->assertCreated();
    }

    public function test_created_account_logs_in_on_the_mobile_login(): void
    {
        $temporaryPassword = $this->withToken($this->adminToken)->postJson(self::PARENTS_URL, $this->buildPayload())->assertCreated()->json('temporary_password');

        $this->assertNotEmpty($this->loginAsParent(self::NUMBER, $temporaryPassword));
    }

    public function test_create_rolls_back_the_account_when_the_link_fails(): void
    {
        $student = Student::factory()->create();
        $userCountBefore = User::count();
        $payload = $this->buildPayload(['student_id' => $student->id, 'relationship' => 'Mother']);
        Student::retrieved(fn () => throw new RuntimeException('forced'));

        $this->withToken($this->adminToken)->postJson(self::PARENTS_URL, $payload)->assertStatus(500);

        $this->assertSame($userCountBefore, User::count());
        $this->assertSame(0, DB::table('parent_student')->count());
    }

    public function test_double_submit_index_violation_returns_422_on_the_mobile_number(): void
    {
        $this->insertRawUserWhenCreating(['mobile_number' => self::NUMBER]);

        $this->withToken($this->adminToken)->postJson(self::PARENTS_URL, $this->buildPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mobile_number' => ParentAccountController::MOBILE_TAKEN_MESSAGE]);

        // the competing row was inserted inside the rolled back transaction, so nothing remains
        $this->assertSame(0, User::parentAccounts()->count());
        $this->assertSame(0, DB::table('parent_student')->count());
    }

    public function test_double_submit_index_violation_returns_422_on_the_email(): void
    {
        $this->insertRawUserWhenCreating(['email' => 'ana@family.test', 'mobile_number' => self::OTHER_NUMBER]);

        $this->withToken($this->adminToken)->postJson(self::PARENTS_URL, $this->buildPayload(['email' => 'ana@family.test']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => ParentAccountController::EMAIL_TAKEN_MESSAGE])
            ->assertJsonMissingValidationErrors(['mobile_number']);

        $this->assertSame(0, User::parentAccounts()->count());
    }

    /**
     * @param  array<string, string>  $competingFields
     */
    private function insertRawUserWhenCreating(array $competingFields): void
    {
        // the unique rule already passed, so this row stands in for a second request that committed in between
        User::creating(fn () => DB::table('users')->insert([
            'name' => 'Competing Request',
            'password' => 'unused',
            'created_at' => now(),
            'updated_at' => now(),
            ...$competingFields,
        ]));
    }

    public function test_reset_replaces_the_password_and_revokes_every_token(): void
    {
        $parentUser = $this->createParentUser(['mobile_number' => self::NUMBER, 'password' => 'temp-pass-1']);
        $oldToken = $this->loginAsParent(self::NUMBER, 'temp-pass-1');
        $parentUser->forceFill(['must_change_password' => false])->save();

        $resetResponse = $this->withToken($this->adminToken)->putJson($this->buildParentUrl($parentUser->id, '/password'))
            ->assertOk()
            ->assertJsonPath('parent.status', Student::ACCOUNT_STATUS_MUST_CHANGE_PASSWORD);
        $newPassword = $resetResponse->json('temporary_password');

        $this->assertSame(['parent', 'temporary_password'], array_keys($resetResponse->json()));
        $this->assertSame(self::PARENT_KEYS, array_keys($resetResponse->json('parent')));
        $this->assertMatchesRegularExpression(self::PASSWORD_ALPHABET_PATTERN, $newPassword);
        $parentUser->refresh();
        $this->assertTrue(Hash::check($newPassword, $parentUser->password));
        $this->assertFalse(Hash::check('temp-pass-1', $parentUser->password));
        $this->assertTrue($parentUser->must_change_password);
        $this->assertSame(0, $parentUser->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($oldToken)->getJson(self::MOBILE_ME_URL)->assertUnauthorized();
        $this->postJson(self::MOBILE_LOGIN_URL, ['username' => self::NUMBER, 'password' => 'temp-pass-1', 'device_name' => 'test-phone'])->assertUnprocessable();
        $this->assertNotEmpty($this->loginAsParent(self::NUMBER, $newPassword));
    }

    public function test_deactivate_revokes_tokens_and_reactivate_restores_the_flag_based_status(): void
    {
        $parentUser = $this->createParentUser(['mobile_number' => self::NUMBER, 'password' => 'temp-pass-1']);
        $oldToken = $this->loginAsParent(self::NUMBER, 'temp-pass-1');
        $parentUser->forceFill(['must_change_password' => true])->save();

        $this->withToken($this->adminToken)->putJson($this->buildParentUrl($parentUser->id, '/deactivate'))
            ->assertOk()
            ->assertJsonPath('parent.status', Student::ACCOUNT_STATUS_INACTIVE)
            ->assertJsonPath('parent.is_active', false);
        $this->assertSame(0, $parentUser->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($oldToken)->getJson(self::MOBILE_ME_URL)->assertUnauthorized();
        $this->withToken($this->adminToken)->putJson($this->buildParentUrl($parentUser->id, '/deactivate'))->assertOk()->assertJsonPath('parent.status', Student::ACCOUNT_STATUS_INACTIVE);

        $this->withToken($this->adminToken)->putJson($this->buildParentUrl($parentUser->id, '/reactivate'))
            ->assertOk()
            ->assertJsonPath('parent.status', Student::ACCOUNT_STATUS_MUST_CHANGE_PASSWORD);
    }

    public function test_actions_return_404_for_a_missing_or_non_parent_user(): void
    {
        $adminUser = User::where('email', 'acting@admin.test')->firstOrFail();
        $studentUser = User::factory()->studentAccount()->create();
        $originalAdminPassword = $adminUser->password;

        foreach ([self::MISSING_ID, $adminUser->id, $studentUser->id] as $targetId) {
            $this->withToken($this->adminToken)->putJson($this->buildParentUrl($targetId, '/password'))->assertNotFound();
            $this->withToken($this->adminToken)->putJson($this->buildParentUrl($targetId, '/deactivate'))->assertNotFound();
            $this->withToken($this->adminToken)->putJson($this->buildParentUrl($targetId, '/reactivate'))->assertNotFound();
        }

        $this->assertSame($originalAdminPassword, $adminUser->refresh()->password);
        $this->assertTrue($adminUser->is_active);
        $this->assertTrue($studentUser->refresh()->is_active);
    }

    public function test_list_returns_parents_newest_first_with_meta_and_no_other_users(): void
    {
        $olderParent = $this->createParentUser(['created_at' => now()->subDay()]);
        $newerParent = $this->createParentUser(['created_at' => now()]);
        User::factory()->studentAccount()->create();
        User::factory()->registrar()->create(['email' => 'staff@school.test']);

        $listResponse = $this->withToken($this->adminToken)->getJson(self::PARENTS_URL)->assertOk();

        $this->assertSame(['parents', 'meta'], array_keys($listResponse->json()));
        $this->assertSame(self::PAGE_META_KEYS, array_keys($listResponse->json('meta')));
        $this->assertSame([$newerParent->id, $olderParent->id], array_column($listResponse->json('parents'), 'id'));
        $this->assertSame(self::PARENT_KEYS, array_keys($listResponse->json('parents.0')));
        $this->assertSame(2, $listResponse->json('meta.total'));
    }

    public function test_list_searches_by_name_number_part_and_email(): void
    {
        $ana = $this->createParentUser(['name' => 'Ana Cruz', 'mobile_number' => self::NUMBER, 'email' => 'ana@family.test']);
        $ben = $this->createParentUser(['name' => 'Ben Reyes', 'mobile_number' => '09991112222', 'email' => null]);

        $this->assertSame([$ana->id], $this->listParentIds(['search' => 'cruz']));
        $this->assertSame([$ana->id], $this->listParentIds(['search' => '0917000']));
        $this->assertSame([$ana->id], $this->listParentIds(['search' => 'ANA@family']));
        $this->assertSame([$ben->id], $this->listParentIds(['search' => 'ben 0999']));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<int>
     */
    private function listParentIds(array $query): array
    {
        return array_column($this->withToken($this->adminToken)->getJson(self::PARENTS_URL.'?'.http_build_query($query))->assertOk()->json('parents'), 'id');
    }

    public function test_list_filters_by_each_status(): void
    {
        $activeParent = $this->createParentUser();
        $pendingParent = $this->createParentUser(['must_change_password' => true]);
        $inactiveParent = $this->createParentUser(['is_active' => false, 'must_change_password' => true]);

        $this->assertSame([$activeParent->id], $this->listParentIds(['status' => Student::ACCOUNT_STATUS_ACTIVE]));
        $this->assertSame([$pendingParent->id], $this->listParentIds(['status' => Student::ACCOUNT_STATUS_MUST_CHANGE_PASSWORD]));
        $this->assertSame([$inactiveParent->id], $this->listParentIds(['status' => Student::ACCOUNT_STATUS_INACTIVE]));
    }

    public function test_list_rejects_an_invalid_status_and_a_long_search(): void
    {
        $this->withToken($this->adminToken)->getJson(self::PARENTS_URL.'?status=bogus')->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->withToken($this->adminToken)->getJson(self::PARENTS_URL.'?search='.str_repeat('a', 256))->assertUnprocessable()->assertJsonValidationErrors(['search']);
    }

    public function test_list_paginates_fifteen_per_page(): void
    {
        User::factory()->parentAccount()->count(16)->create();

        $pageResponse = $this->withToken($this->adminToken)->getJson(self::PARENTS_URL.'?page=2')->assertOk();

        $this->assertCount(1, $pageResponse->json('parents'));
        $this->assertSame(15, $pageResponse->json('meta.per_page'));
        $this->assertSame(16, $pageResponse->json('meta.total'));
    }
}
