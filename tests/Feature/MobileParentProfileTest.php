<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePasswordChanged;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithClassLists;
use Tests\TestCase;

class MobileParentProfileTest extends TestCase
{
    use InteractsWithClassLists;
    use RefreshDatabase;

    private const ME_URL = '/api/mis/mobile/me';

    private const PASSWORD_URL = '/api/mis/mobile/password';

    private const LOGOUT_URL = '/api/mis/mobile/logout';

    private const CHILDREN_URL = '/api/mis/mobile/children';

    private const LOGIN_URL = '/api/mis/mobile/login';

    private const NUMBER = '09170000001';

    private const OLD_PASSWORD = 'temp-pass-1';

    private const NEW_PASSWORD = 'new-secret-9';

    private const SESSION_KEYS = ['user', 'student', 'must_change_password', 'children'];

    private const MOBILE_CHILD_KEYS = ['id', 'lrn', 'first_name', 'middle_name', 'last_name', 'grade_level', 'section', 'school_year', 'relationship'];

    private User $parentUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClassListFixtures();
        $this->schoolYear->forceFill(['is_active' => true])->save();
        $this->parentUser = User::factory()->parentAccount()->create(['mobile_number' => self::NUMBER, 'password' => self::OLD_PASSWORD, 'name' => 'Ana Cruz']);
    }

    private function createLinkedChild(string $lastName, string $firstName, string $relationship = 'Mother'): Student
    {
        $student = $this->enrollStudent(['last_name' => $lastName, 'first_name' => $firstName], ['section_id' => $this->rizalSection->id])->student;
        $this->parentUser->children()->attach($student->id, ['relationship' => $relationship]);

        return $student;
    }

    /**
     * @return array<string, string>
     */
    private function buildPasswordPayload(): array
    {
        return ['current_password' => self::OLD_PASSWORD, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD];
    }

    public function test_me_returns_the_parent_shape(): void
    {
        $child = $this->createLinkedChild(self::AQUINO, self::JOSE, 'Father');

        $meResponse = $this->withToken($this->createTokenFor($this->parentUser))->getJson(self::ME_URL)->assertOk();

        $this->assertSame(self::SESSION_KEYS, array_keys($meResponse->json()));
        $meResponse
            ->assertJsonPath('user.id', $this->parentUser->id)
            ->assertJsonPath('user.name', 'Ana Cruz')
            ->assertJsonPath('user.email', null)
            ->assertJsonPath('user.roles', ['parent'])
            ->assertJsonPath('student', null)
            ->assertJsonPath('must_change_password', false)
            ->assertJsonPath('children.0.id', $child->id)
            ->assertJsonPath('children.0.relationship', 'Father');
        $this->assertSame(self::MOBILE_CHILD_KEYS, array_keys($meResponse->json('children.0')));
    }

    public function test_children_are_ordered_by_last_name_then_first_name(): void
    {
        $zoeAquino = $this->createLinkedChild(self::AQUINO, 'Zoe');
        $amyRamos = $this->createLinkedChild(self::RAMOS, 'Amy');
        $benAquino = $this->createLinkedChild(self::AQUINO, 'Ben');

        $childIds = array_column($this->withToken($this->createTokenFor($this->parentUser))->getJson(self::ME_URL)->assertOk()->json('children'), 'id');

        $this->assertSame([$benAquino->id, $zoeAquino->id, $amyRamos->id], $childIds);
    }

    public function test_password_change_works_for_a_parent(): void
    {
        $this->createLinkedChild(self::AQUINO, self::JOSE);
        $this->parentUser->forceFill(['must_change_password' => true])->save();

        $changeResponse = $this->withToken($this->createTokenFor($this->parentUser))->putJson(self::PASSWORD_URL, $this->buildPasswordPayload())->assertOk();

        $this->assertSame(self::SESSION_KEYS, array_keys($changeResponse->json()));
        $changeResponse->assertJsonPath('must_change_password', false)->assertJsonPath('student', null);
        $this->assertCount(1, $changeResponse->json('children'));
        $this->postJson(self::LOGIN_URL, ['username' => self::NUMBER, 'password' => self::OLD_PASSWORD, 'device_name' => 'test'])->assertUnprocessable();
        $this->postJson(self::LOGIN_URL, ['username' => self::NUMBER, 'password' => self::NEW_PASSWORD, 'device_name' => 'test'])->assertOk();
    }

    public function test_children_lists_only_the_linked_students(): void
    {
        $linkedChild = $this->createLinkedChild(self::AQUINO, self::JOSE);
        $otherParent = User::factory()->parentAccount()->create();
        $otherChild = $this->enrollStudent(['last_name' => self::BONIFACIO], ['section_id' => $this->rizalSection->id])->student;
        $otherParent->children()->attach($otherChild->id, ['relationship' => 'Mother']);
        $this->enrollStudent(['last_name' => self::MARIA], ['section_id' => $this->rizalSection->id]);

        $childrenResponse = $this->withToken($this->createTokenFor($this->parentUser))->getJson(self::CHILDREN_URL)->assertOk();

        $this->assertSame(['children'], array_keys($childrenResponse->json()));
        $this->assertSame([$linkedChild->id], array_column($childrenResponse->json('children'), 'id'));
        $this->assertSame(self::MOBILE_CHILD_KEYS, array_keys($childrenResponse->json('children.0')));
    }

    public function test_children_is_blocked_by_the_gate_while_the_password_must_change(): void
    {
        $this->parentUser->forceFill(['must_change_password' => true])->save();

        $this->withToken($this->createTokenFor($this->parentUser))->getJson(self::CHILDREN_URL)
            ->assertForbidden()
            ->assertExactJson(['message' => EnsurePasswordChanged::CHANGE_REQUIRED_MESSAGE]);
    }

    public function test_children_is_an_empty_list_without_links(): void
    {
        $this->withToken($this->createTokenFor($this->parentUser))->getJson(self::CHILDREN_URL)->assertOk()->assertExactJson(['children' => []]);
    }

    public function test_student_token_gets_403_on_children(): void
    {
        $studentToken = $this->createTokenFor(User::factory()->studentAccount()->create());

        $this->withToken($studentToken)->getJson(self::CHILDREN_URL)->assertForbidden()->assertExactJson(self::FORBIDDEN_BODY);
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $this->parentUser->createToken('other-device');
        $currentToken = $this->createTokenFor($this->parentUser);

        $this->withToken($currentToken)->postJson(self::LOGOUT_URL)->assertNoContent();

        $this->assertSame(['other-device'], $this->parentUser->tokens()->pluck('name')->all());
    }
}
