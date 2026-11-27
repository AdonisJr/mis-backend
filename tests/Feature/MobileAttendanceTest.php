<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePasswordChanged;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithAttendance;
use Tests\TestCase;

class MobileAttendanceTest extends TestCase
{
    use InteractsWithAttendance;
    use RefreshDatabase;

    private const SEPTEMBER = '2030-09';

    private const JUNE_WEEKDAYS = 20;

    private SchoolYear $schoolYear;

    private Section $rizal;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelToManila('2030-09-04 10:00:00');
        $this->schoolYear = $this->createActiveSchoolYear();
        $this->rizal = $this->createJhsSection($this->schoolYear);
        $this->student = $this->enrollStudent($this->rizal, ['last_name' => self::ABAD]);
    }

    private function createStudentToken(Student $student): string
    {
        return User::factory()->studentAccount($student)->create()->createToken(self::TOKEN_NAME)->plainTextToken;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function fetchMonth(Student $student, array $query = []): array
    {
        // one app instance serves every request of the test, so the guard must drop the previous student
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->createStudentToken($student))->getJson(self::MOBILE_URL.'?'.http_build_query($query))->assertOk()->json();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideInvalidMonths(): array
    {
        return ['month 13' => ['2030-13'], 'one digit month' => ['2030-9']];
    }

    public function test_student_gets_own_rows_for_the_month_newest_first(): void
    {
        $this->createAttendance($this->student, $this->schoolYear, '2030-09-02');
        $this->createAttendance($this->student, $this->schoolYear, '2030-09-03', ['status' => 'late', 'time_in' => '07:45:00']);
        $this->createAttendance($this->student, $this->schoolYear, '2030-08-30');
        $this->createAttendance($this->enrollStudent($this->rizal), $this->schoolYear, '2030-09-02');

        $monthBody = $this->fetchMonth($this->student, ['month' => self::SEPTEMBER]);

        $this->assertSame(['month', 'summary', 'days'], array_keys($monthBody));
        $this->assertSame(self::SEPTEMBER, $monthBody['month']);
        $this->assertSame(['2030-09-03', '2030-09-02'], array_column($monthBody['days'], 'date'));
        $this->assertSame(self::ATTENDANCE_KEYS, array_keys($monthBody['days'][0]));
        $this->assertSame([$this->student->id, $this->student->id], array_column($monthBody['days'], 'student_id'));
    }

    public function test_summary_counts_absent_weekdays_without_a_row_up_to_yesterday(): void
    {
        $this->travelToManila('2030-09-05 10:00:00');
        $this->createAttendance($this->student, $this->schoolYear, '2030-09-02');
        $this->createAttendance($this->student, $this->schoolYear, '2030-09-03', ['status' => 'late', 'time_in' => '07:45:00']);

        $monthBody = $this->fetchMonth($this->student, ['month' => self::SEPTEMBER]);

        // 2030-09-04 is the only weekday without a row, and Sept 1 is a Sunday
        $this->assertSame(['present' => 1, 'late' => 1, 'absent' => 1], $monthBody['summary']);
    }

    public function test_today_is_not_counted_absent_before_the_student_scans(): void
    {
        $this->createAttendance($this->student, $this->schoolYear, '2030-09-02');

        $monthBody = $this->fetchMonth($this->student, ['month' => self::SEPTEMBER]);

        // today is Wed 2030-09-04 and not over yet, so only Tue 2030-09-03 is absent
        $this->assertSame(['present' => 1, 'late' => 0, 'absent' => 1], $monthBody['summary']);
    }

    public function test_month_defaults_to_the_current_month(): void
    {
        $this->createAttendance($this->student, $this->schoolYear, '2030-09-02');

        $monthBody = $this->fetchMonth($this->student);

        $this->assertSame(self::SEPTEMBER, $monthBody['month']);
        $this->assertSame(['2030-09-02'], array_column($monthBody['days'], 'date'));
    }

    public function test_a_student_who_is_not_enrolled_now_has_no_absences(): void
    {
        $this->createAttendance($this->student, $this->schoolYear, '2030-09-02');
        Enrollment::where('student_id', $this->student->id)->update(['status' => Enrollment::STATUS_DROPPED]);

        $this->assertSame(['present' => 1, 'late' => 0, 'absent' => 0], $this->fetchMonth($this->student, ['month' => self::SEPTEMBER])['summary']);
    }

    public function test_an_shs_student_with_no_active_term_still_has_absences_counted(): void
    {
        $shsSection = Section::factory()->create(['school_year_id' => $this->schoolYear->id, 'name' => 'STEM']);
        $shsStudent = Student::factory()->withRfid()->create(['last_name' => 'Dizon']);
        $term = Term::factory()->create(['school_year_id' => $this->schoolYear->id, 'term_number' => 1]);
        Enrollment::factory()->create(['student_id' => $shsStudent->id, 'section_id' => $shsSection->id, 'term_id' => $term->id]);

        // Mon 09-02 and Tue 09-03 passed with no scan, and today (Wed) is not counted
        $this->assertSame(2, $this->fetchMonth($shsStudent, ['month' => self::SEPTEMBER])['summary']['absent']);
    }

    public function test_absences_stop_at_the_active_school_year_bounds(): void
    {
        $this->assertSame(0, $this->fetchMonth($this->student, ['month' => '2030-05'])['summary']['absent']);
        $this->assertSame(self::JUNE_WEEKDAYS, $this->fetchMonth($this->student, ['month' => '2030-06'])['summary']['absent']);
        $this->assertSame(0, $this->fetchMonth($this->student, ['month' => '2031-04'])['summary']['absent']);
    }

    #[DataProvider('provideInvalidMonths')]
    public function test_invalid_month_returns_422(string $month): void
    {
        $this->withToken($this->createStudentToken($this->student))
            ->getJson(self::MOBILE_URL.'?month='.$month)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['month']);
    }

    public function test_must_change_password_gets_the_gate_403(): void
    {
        $mustChangeToken = User::factory()->studentAccount($this->student)->mustChangePassword()->create()->createToken(self::TOKEN_NAME)->plainTextToken;

        $this->withToken($mustChangeToken)->getJson(self::MOBILE_URL)->assertForbidden()->assertExactJson(['message' => EnsurePasswordChanged::CHANGE_REQUIRED_MESSAGE]);
    }

    public function test_removed_student_row_returns_404(): void
    {
        $orphanToken = $this->createStudentToken($this->student);
        $this->student->enrollments()->delete();
        $this->student->delete();

        $this->withToken($orphanToken)->getJson(self::MOBILE_URL)->assertNotFound();
    }
}
