<?php

namespace Tests\Feature;

use App\Http\Requests\ShowMobileAttendanceRequest;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\InteractsWithAttendance;
use Tests\TestCase;

class MobileParentAttendanceTest extends TestCase
{
    use InteractsWithAttendance;
    use RefreshDatabase;

    private const SEPTEMBER = '2030-09';

    private const UNLINKED_BODY = ['message' => ShowMobileAttendanceRequest::UNLINKED_CHILD_MESSAGE];

    private SchoolYear $schoolYear;

    private Section $rizal;

    private Student $child;

    private User $parentUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelToManila('2030-09-04 10:00:00');
        $this->schoolYear = $this->createActiveSchoolYear();
        $this->rizal = $this->createJhsSection($this->schoolYear);
        $this->child = $this->enrollStudent($this->rizal, ['last_name' => self::ABAD]);
        $this->parentUser = User::factory()->parentAccount()->create();
        $this->parentUser->children()->attach($this->child->id, ['relationship' => 'Mother']);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function fetchMonthAs(User $user, array $query = []): TestResponse
    {
        // one app instance serves every request of the test, so the guard must drop the previous user
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken(self::TOKEN_NAME)->plainTextToken)->getJson(self::MOBILE_URL.'?'.http_build_query($query));
    }

    public function test_parent_gets_the_same_month_as_the_child(): void
    {
        $this->createAttendance($this->child, $this->schoolYear, '2030-09-02');
        $this->createAttendance($this->child, $this->schoolYear, '2030-09-03', ['status' => 'late', 'time_in' => '07:45:00']);

        $ownBody = $this->fetchMonthAs(User::factory()->studentAccount($this->child)->create(), ['month' => self::SEPTEMBER])->assertOk()->json();
        $parentBody = $this->fetchMonthAs($this->parentUser, ['month' => self::SEPTEMBER, 'student_id' => $this->child->id])->assertOk()->json();

        $this->assertSame($ownBody, $parentBody);
        $this->assertCount(2, $parentBody['days']);
    }

    public function test_unlinked_child_returns_403(): void
    {
        $unlinkedChild = $this->enrollStudent($this->rizal, ['last_name' => 'Bautista']);

        $this->fetchMonthAs($this->parentUser, ['student_id' => $unlinkedChild->id])->assertForbidden()->assertExactJson(self::UNLINKED_BODY);
        $this->fetchMonthAs($this->parentUser, ['student_id' => '999'])->assertForbidden()->assertExactJson(self::UNLINKED_BODY);
    }

    public function test_missing_student_id_returns_403(): void
    {
        $this->fetchMonthAs($this->parentUser)->assertForbidden()->assertExactJson(self::UNLINKED_BODY);
    }

    public function test_a_students_student_id_is_ignored(): void
    {
        $otherStudent = $this->enrollStudent($this->rizal, ['last_name' => 'Bautista']);
        $this->createAttendance($this->child, $this->schoolYear, '2030-09-02');
        $this->createAttendance($otherStudent, $this->schoolYear, '2030-09-03');

        $monthBody = $this->fetchMonthAs(User::factory()->studentAccount($this->child)->create(), ['month' => self::SEPTEMBER, 'student_id' => $otherStudent->id])->assertOk()->json();

        $this->assertSame([$this->child->id], array_unique(array_column($monthBody['days'], 'student_id')));
    }

    public function test_invalid_month_for_a_linked_child_returns_422(): void
    {
        $this->fetchMonthAs($this->parentUser, ['month' => '2030-13', 'student_id' => $this->child->id])->assertUnprocessable()->assertJsonValidationErrors(['month']);
    }

    public function test_two_children_each_return_only_their_own_rows(): void
    {
        $secondChild = $this->enrollStudent($this->rizal, ['last_name' => 'Bautista']);
        $this->parentUser->children()->attach($secondChild->id, ['relationship' => 'Mother']);
        $this->createAttendance($this->child, $this->schoolYear, '2030-09-02');
        $this->createAttendance($secondChild, $this->schoolYear, '2030-09-03');

        $firstDays = $this->fetchMonthAs($this->parentUser, ['month' => self::SEPTEMBER, 'student_id' => $this->child->id])->assertOk()->json('days');
        $secondDays = $this->fetchMonthAs($this->parentUser, ['month' => self::SEPTEMBER, 'student_id' => $secondChild->id])->assertOk()->json('days');

        $this->assertSame(['2030-09-02'], array_column($firstDays, 'date'));
        $this->assertSame(['2030-09-03'], array_column($secondDays, 'date'));
    }
}
