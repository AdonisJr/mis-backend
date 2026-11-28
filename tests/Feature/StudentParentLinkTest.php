<?php

namespace Tests\Feature;

use App\Http\Controllers\StudentParentController;
use App\Http\Requests\LinkParentRequest;
use App\Models\Student;
use App\Models\User;
use App\Support\MobileNumberNormaliser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithRooms;
use Tests\TestCase;

class StudentParentLinkTest extends TestCase
{
    use InteractsWithRooms;
    use RefreshDatabase;

    private const PARENT_KEYS = ['id', 'name', 'mobile_number', 'email', 'status', 'must_change_password', 'is_active', 'children', 'created_at', 'updated_at', 'relationship'];

    private const NUMBER = '09170000001';

    private const OTHER_NUMBER = '09170000002';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
    }

    private function buildLinkUrl(Student $student, string $suffix = ''): string
    {
        return '/api/mis/students/'.$student->id.'/parents'.$suffix;
    }

    private function createParentUser(string $mobileNumber, string $name = 'Ana Cruz'): User
    {
        return User::factory()->parentAccount()->create(['mobile_number' => $mobileNumber, 'name' => $name]);
    }

    /**
     * @return array<string, string>
     */
    private function buildLinkPayload(string $mobileNumber = self::NUMBER, string $relationship = 'Mother'): array
    {
        return ['mobile_number' => $mobileNumber, 'relationship' => $relationship];
    }

    public function test_link_returns_the_parent_with_the_relationship(): void
    {
        $student = Student::factory()->create();
        $parentUser = $this->createParentUser(self::NUMBER);

        $linkResponse = $this->withToken($this->adminToken)->postJson($this->buildLinkUrl($student), $this->buildLinkPayload())->assertCreated();

        $this->assertSame(['parent'], array_keys($linkResponse->json()));
        $this->assertSame(self::PARENT_KEYS, array_keys($linkResponse->json('parent')));
        $this->assertSame('Mother', $linkResponse->json('parent.relationship'));
        $this->assertSame($parentUser->id, $linkResponse->json('parent.id'));
        $this->assertSame([$student->id], array_column($linkResponse->json('parent.children'), 'id'));
    }

    public function test_link_accepts_a_formatted_number(): void
    {
        $student = Student::factory()->create();
        $this->createParentUser(self::NUMBER);

        $this->withToken($this->adminToken)->postJson($this->buildLinkUrl($student), $this->buildLinkPayload('0917 000 0001'))->assertCreated();
    }

    public function test_link_rejects_a_duplicate_pair(): void
    {
        $student = Student::factory()->create();
        $this->createParentUser(self::NUMBER);
        $this->withToken($this->adminToken)->postJson($this->buildLinkUrl($student), $this->buildLinkPayload())->assertCreated();

        $this->withToken($this->adminToken)->postJson($this->buildLinkUrl($student), $this->buildLinkPayload(relationship: 'Father'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mobile_number' => StudentParentController::ALREADY_LINKED_MESSAGE]);

        $this->assertSame(1, DB::table('parent_student')->count());
    }

    public function test_link_rejects_an_unknown_number_and_a_staff_contact_number(): void
    {
        $student = Student::factory()->create();
        User::factory()->registrar()->create(['email' => 'staff@school.test', 'contact' => self::OTHER_NUMBER]);

        $this->withToken($this->adminToken)->postJson($this->buildLinkUrl($student), $this->buildLinkPayload('09170000099'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mobile_number' => LinkParentRequest::NO_PARENT_MESSAGE]);
        $this->withToken($this->adminToken)->postJson($this->buildLinkUrl($student), $this->buildLinkPayload(self::OTHER_NUMBER))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mobile_number' => LinkParentRequest::NO_PARENT_MESSAGE]);

        $this->assertSame(0, DB::table('parent_student')->count());
    }

    public function test_link_rejects_missing_and_invalid_fields(): void
    {
        $student = Student::factory()->create();
        $this->createParentUser(self::NUMBER);

        $this->withToken($this->adminToken)->postJson($this->buildLinkUrl($student), [])->assertUnprocessable()->assertJsonValidationErrors(['mobile_number', 'relationship']);
        $this->withToken($this->adminToken)->postJson($this->buildLinkUrl($student), $this->buildLinkPayload(relationship: 'Aunt'))->assertUnprocessable()->assertJsonValidationErrors(['relationship']);
        $this->withToken($this->adminToken)->postJson($this->buildLinkUrl($student), $this->buildLinkPayload('08170000001'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mobile_number' => MobileNumberNormaliser::FORMAT_MESSAGE]);
    }

    public function test_link_returns_404_for_a_missing_student(): void
    {
        $this->createParentUser(self::NUMBER);

        $this->withToken($this->adminToken)->postJson('/api/mis/students/'.self::MISSING_ID.'/parents', $this->buildLinkPayload())->assertNotFound();
    }

    public function test_one_parent_can_link_two_students_and_one_student_two_parents(): void
    {
        $firstStudent = Student::factory()->create();
        $secondStudent = Student::factory()->create();
        $firstParent = $this->createParentUser(self::NUMBER);
        $secondParent = $this->createParentUser(self::OTHER_NUMBER, 'Ben Cruz');

        $this->withToken($this->adminToken)->postJson($this->buildLinkUrl($firstStudent), $this->buildLinkPayload())->assertCreated();
        $this->withToken($this->adminToken)->postJson($this->buildLinkUrl($secondStudent), $this->buildLinkPayload())->assertCreated();
        $this->withToken($this->adminToken)->postJson($this->buildLinkUrl($firstStudent), $this->buildLinkPayload(self::OTHER_NUMBER, 'Father'))->assertCreated();

        $this->assertSame(2, $firstParent->children()->count());
        $this->assertSame(2, $firstStudent->parents()->count());
        $this->assertSame(1, $secondParent->children()->count());
    }

    public function test_list_returns_the_parents_by_name_with_relationship_and_status(): void
    {
        $student = Student::factory()->create();
        $zoe = $this->createParentUser(self::NUMBER, 'Zoe Cruz');
        $amy = $this->createParentUser(self::OTHER_NUMBER, 'Amy Cruz');
        $zoe->children()->attach($student->id, ['relationship' => 'Guardian']);
        $amy->children()->attach($student->id, ['relationship' => 'Mother']);
        $amy->forceFill(['is_active' => false])->save();

        $listResponse = $this->withToken($this->adminToken)->getJson($this->buildLinkUrl($student))->assertOk();

        $this->assertSame(['parents'], array_keys($listResponse->json()));
        $this->assertSame([$amy->id, $zoe->id], array_column($listResponse->json('parents'), 'id'));
        $this->assertSame(['Mother', 'Guardian'], array_column($listResponse->json('parents'), 'relationship'));
        $this->assertSame(['inactive', 'active'], array_column($listResponse->json('parents'), 'status'));
        $this->assertSame(self::PARENT_KEYS, array_keys($listResponse->json('parents.0')));
    }

    public function test_list_returns_404_for_a_missing_student(): void
    {
        $this->withToken($this->adminToken)->getJson('/api/mis/students/'.self::MISSING_ID.'/parents')->assertNotFound();
    }

    public function test_unlink_removes_only_that_pair_and_keeps_the_parent_user(): void
    {
        $firstStudent = Student::factory()->create();
        $secondStudent = Student::factory()->create();
        $parentUser = $this->createParentUser(self::NUMBER);
        $parentUser->children()->attach($firstStudent->id, ['relationship' => 'Mother']);
        $parentUser->children()->attach($secondStudent->id, ['relationship' => 'Mother']);

        $this->withToken($this->adminToken)->deleteJson($this->buildLinkUrl($firstStudent, '/'.$parentUser->id))->assertNoContent();

        $this->assertSame([$secondStudent->id], $parentUser->children()->pluck('students.id')->all());
        $this->assertNotNull(User::find($parentUser->id));
    }

    public function test_unlink_returns_404_for_an_unlinked_pair_and_a_missing_student(): void
    {
        $student = Student::factory()->create();
        $parentUser = $this->createParentUser(self::NUMBER);

        $this->withToken($this->adminToken)->deleteJson($this->buildLinkUrl($student, '/'.$parentUser->id))->assertNotFound();
        $this->withToken($this->adminToken)->deleteJson('/api/mis/students/'.self::MISSING_ID.'/parents/'.$parentUser->id)->assertNotFound();
    }

    public function test_deleting_a_student_removes_its_links(): void
    {
        $student = Student::factory()->create();
        $parentUser = $this->createParentUser(self::NUMBER);
        $parentUser->children()->attach($student->id, ['relationship' => 'Mother']);

        $this->withToken($this->adminToken)->deleteJson('/api/mis/students/'.$student->id)->assertNoContent();

        $this->assertSame(0, DB::table('parent_student')->count());
        $this->assertNotNull(User::find($parentUser->id));
    }
}
