<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Requests\ShowMobileGradesRequest;
use App\Http\Requests\ShowReportCardRequest;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\InteractsWithGrading;
use Tests\TestCase;

class MobileParentGradesTest extends TestCase
{
    use InteractsWithGrading;
    use RefreshDatabase;

    private const GRADES_URL = '/api/mis/mobile/grades';

    private const UNLINKED_BODY = ['message' => ShowMobileGradesRequest::UNLINKED_CHILD_MESSAGE];

    private User $parentUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClassListFixtures();
        $this->schoolYear->forceFill(['is_active' => true])->save();
        $this->parentUser = User::factory()->parentAccount()->create();
    }

    private function enrollInRizal(string $lastName): Student
    {
        return $this->enrollStudent(['last_name' => $lastName], ['section_id' => $this->rizalSection->id])->student;
    }

    private function linkChild(Student $student): void
    {
        $this->parentUser->children()->attach($student->id, ['relationship' => 'Mother']);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function fetchGradesAs(User $user, array $query = []): TestResponse
    {
        // one app instance serves every request of the test, so the guard must drop the previous user
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->createTokenFor($user))->getJson(self::GRADES_URL.'?'.http_build_query($query));
    }

    public function test_parent_gets_the_same_report_card_as_the_child(): void
    {
        $child = $this->enrollInRizal(self::AQUINO);
        $this->linkChild($child);
        $this->createSubmittedQuarter($this->createClassForFixtures(), [['student_id' => $child->id, 'quarterly_grade' => 88]], 1);

        $ownBody = $this->fetchGradesAs(User::factory()->studentAccount($child)->create())->assertOk()->json();
        $parentBody = $this->fetchGradesAs($this->parentUser, ['student_id' => $child->id])->assertOk()->json();

        $this->assertSame($ownBody['report_card'], $parentBody['report_card']);
        $this->assertSame($ownBody, $parentBody);
    }

    public function test_unlinked_child_returns_403(): void
    {
        $child = $this->enrollInRizal(self::AQUINO);
        $this->linkChild($child);

        $this->fetchGradesAs($this->parentUser, ['student_id' => $child->id])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->deleteJson('/api/mis/students/'.$child->id.'/parents/'.$this->parentUser->id)->assertNoContent();

        $this->fetchGradesAs($this->parentUser, ['student_id' => $child->id])->assertForbidden()->assertExactJson(self::UNLINKED_BODY);
    }

    public function test_missing_malformed_unknown_and_unlinked_ids_return_403(): void
    {
        $linkedChild = $this->enrollInRizal(self::AQUINO);
        $this->linkChild($linkedChild);
        $unlinkedChild = $this->enrollInRizal(self::BONIFACIO);

        foreach ([[], ['student_id' => 'abc'], ['student_id' => $linkedChild->id.'abc'], ['student_id' => '-1'], ['student_id' => '999'], ['student_id' => $unlinkedChild->id]] as $query) {
            $this->fetchGradesAs($this->parentUser, $query)->assertForbidden()->assertExactJson(self::UNLINKED_BODY);
        }
    }

    public function test_linked_child_without_an_enrollment_in_the_year_returns_422(): void
    {
        $child = $this->enrollInRizal(self::AQUINO);
        $this->linkChild($child);
        $otherYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);
        $this->createSection(['name' => 'Mabini', 'school_year_id' => $otherYear->id]);

        $this->fetchGradesAs($this->parentUser, ['student_id' => $child->id, 'school_year_id' => $otherYear->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_year_id' => ShowReportCardRequest::NO_ENROLLMENT_MESSAGE]);
    }

    public function test_a_student_sending_another_students_id_gets_their_own_card(): void
    {
        $ownStudent = $this->enrollInRizal(self::AQUINO);
        $otherStudent = $this->enrollInRizal(self::BONIFACIO);
        $studentUser = User::factory()->studentAccount($ownStudent)->create();

        $ownBody = $this->fetchGradesAs($studentUser)->assertOk()->json();
        $withOtherId = $this->fetchGradesAs($studentUser, ['student_id' => $otherStudent->id])->assertOk()->json();

        $this->assertSame($ownBody, $withOtherId);
        $this->assertSame(self::AQUINO, $withOtherId['report_card']['student']['last_name']);
    }

    public function test_an_unlinked_id_is_refused_before_a_school_year_error(): void
    {
        $unlinkedChild = $this->enrollInRizal(self::AQUINO);

        $this->fetchGradesAs($this->parentUser, ['student_id' => $unlinkedChild->id, 'school_year_id' => 'abc'])->assertForbidden()->assertExactJson(self::UNLINKED_BODY);
    }

    public function test_parent_with_a_must_change_password_flag_gets_the_gate_403(): void
    {
        $child = $this->enrollInRizal(self::AQUINO);
        $this->linkChild($child);
        $this->parentUser->forceFill(['must_change_password' => true])->save();

        $this->fetchGradesAs($this->parentUser, ['student_id' => $child->id])
            ->assertForbidden()
            ->assertExactJson(['message' => EnsurePasswordChanged::CHANGE_REQUIRED_MESSAGE]);
    }
}
