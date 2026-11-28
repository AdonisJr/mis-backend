<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Student;
use App\Models\Term;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithAttendance;
use Tests\TestCase;

class AttendanceTodayTest extends TestCase
{
    use InteractsWithAttendance;
    use RefreshDatabase;

    private const PAGE_SIZE = 15;

    private SchoolYear $schoolYear;

    private Section $rizal;

    private Section $mabini;

    private Student $abad;

    private Student $bautista;

    private Student $cruz;

    private string $registrarToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelToManila(self::MONDAY.' 09:00:00');
        $this->schoolYear = $this->createActiveSchoolYear();
        $this->rizal = $this->createJhsSection($this->schoolYear);
        $this->mabini = $this->createJhsSection($this->schoolYear, 'Mabini', 1);
        $this->abad = $this->enrollStudent($this->rizal, ['lrn' => '100000000001', 'first_name' => 'Ana', 'last_name' => self::ABAD]);
        $this->bautista = $this->enrollStudent($this->rizal, ['lrn' => '100000000002', 'first_name' => 'Ben', 'last_name' => self::BAUTISTA]);
        $this->cruz = $this->enrollStudent($this->mabini, ['lrn' => '100000000003', 'first_name' => 'Cai', 'last_name' => self::CRUZ]);
        $this->createAttendance($this->abad, $this->schoolYear, self::MONDAY);
        $this->createAttendance($this->bautista, $this->schoolYear, self::MONDAY, ['time_in' => '07:45:00', 'time_out' => '16:00:00', 'status' => 'late']);
        $this->registrarToken = $this->createRoleToken('registrar');
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function fetchToday(array $query = []): array
    {
        return $this->withToken($this->registrarToken)->getJson(self::TODAY_URL.'?'.http_build_query($query))->assertOk()->json();
    }

    /**
     * @param  array<string, mixed>  $todayBody
     * @return list<string>
     */
    private function listLastNames(array $todayBody): array
    {
        return array_column(array_column($todayBody['attendances'], 'student'), 'last_name');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideStatusFilters(): array
    {
        return [
            'present' => ['present', self::ABAD],
            'late' => ['late', self::BAUTISTA],
            'absent' => ['absent', self::CRUZ],
        ];
    }

    public function test_today_lists_enrolled_students_with_derived_absent(): void
    {
        $todayBody = $this->fetchToday();

        $this->assertSame(['date', 'summary', 'attendances', 'meta'], array_keys($todayBody));
        $this->assertSame(self::MONDAY, $todayBody['date']);
        $this->assertSame(['enrolled' => 3, 'present' => 1, 'late' => 1, 'absent' => 1, 'timed_out' => 1], $todayBody['summary']);
        $this->assertSame(['current_page' => 1, 'last_page' => 1, 'per_page' => 15, 'total' => 3], $todayBody['meta']);
        $this->assertSame([self::ABAD, self::BAUTISTA, self::CRUZ], $this->listLastNames($todayBody));
        $this->assertSame(self::TODAY_ROW_KEYS, array_keys($todayBody['attendances'][0]));
        $this->assertSame(self::ROW_STUDENT_KEYS, array_keys($todayBody['attendances'][0]['student']));
        $this->assertSame(['present', 'late', 'absent'], array_column($todayBody['attendances'], 'status'));
        $this->assertSame([null, null, null], [$todayBody['attendances'][2]['attendance_id'], $todayBody['attendances'][2]['time_in'], $todayBody['attendances'][2]['time_out']]);
        $this->assertSame(['07:45:00', '16:00:00'], [$todayBody['attendances'][1]['time_in'], $todayBody['attendances'][1]['time_out']]);
        $this->assertSame(['Rizal', 'Mabini'], [$todayBody['attendances'][0]['section']['name'], $todayBody['attendances'][2]['section']['name']]);
    }

    public function test_summary_follows_grade_and_section_only(): void
    {
        $unfilteredSummary = $this->fetchToday(['status' => 'late', 'search' => self::BAUTISTA])['summary'];
        $sectionBody = $this->fetchToday(['section_id' => $this->rizal->id, 'status' => 'late']);

        $this->assertSame(['enrolled' => 3, 'present' => 1, 'late' => 1, 'absent' => 1, 'timed_out' => 1], $unfilteredSummary);
        $this->assertSame(['enrolled' => 2, 'present' => 1, 'late' => 1, 'absent' => 0, 'timed_out' => 1], $sectionBody['summary']);
        $this->assertSame([self::BAUTISTA], $this->listLastNames($sectionBody));
    }

    #[DataProvider('provideStatusFilters')]
    public function test_status_filter_returns_only_that_status(string $status, string $lastName): void
    {
        $this->assertSame([$lastName], $this->listLastNames($this->fetchToday(['status' => $status])));
    }

    public function test_grade_level_and_section_filters(): void
    {
        $gradeBody = $this->fetchToday(['grade_level_id' => $this->mabini->grade_level_id]);
        $sectionBody = $this->fetchToday(['section_id' => $this->rizal->id]);

        $this->assertSame([self::CRUZ], $this->listLastNames($gradeBody));
        $this->assertSame([self::ABAD, self::BAUTISTA], $this->listLastNames($sectionBody));
    }

    public function test_search_matches_names_and_lrn(): void
    {
        $this->assertSame([self::BAUTISTA], $this->listLastNames($this->fetchToday(['search' => 'bautista'])));
        $this->assertSame([self::CRUZ], $this->listLastNames($this->fetchToday(['search' => '100000000003'])));
        $this->assertSame([self::ABAD], $this->listLastNames($this->fetchToday(['search' => 'ana abad'])));
    }

    public function test_today_excludes_students_not_enrolled_in_the_active_period(): void
    {
        $otherYear = SchoolYear::factory()->create();
        $this->enrollStudent($this->createJhsSection($otherYear, 'Other'), ['last_name' => 'Dizon']);
        $droppedStudent = $this->enrollStudent($this->rizal, ['last_name' => 'Evangelista']);
        Enrollment::where('student_id', $droppedStudent->id)->update(['status' => Enrollment::STATUS_DROPPED]);
        Student::factory()->create(['last_name' => 'Fabian']);

        $todayBody = $this->fetchToday();

        $this->assertSame([self::ABAD, self::BAUTISTA, self::CRUZ], $this->listLastNames($todayBody));
        $this->assertSame(3, $todayBody['summary']['enrolled']);
    }

    public function test_today_lists_an_shs_student_once_when_no_term_is_active(): void
    {
        $shsSection = Section::factory()->create(['school_year_id' => $this->schoolYear->id, 'name' => 'STEM']);
        $firstTerm = Term::factory()->create(['school_year_id' => $this->schoolYear->id, 'term_number' => 1]);
        $secondTerm = Term::factory()->create(['school_year_id' => $this->schoolYear->id, 'term_number' => 2]);
        $shsStudent = Student::factory()->withRfid()->create(['last_name' => 'Dizon']);

        Enrollment::factory()->create(['student_id' => $shsStudent->id, 'section_id' => $shsSection->id, 'term_id' => $firstTerm->id]);
        $this->assertSame(1, array_count_values($this->listLastNames($this->fetchToday()))['Dizon']);
        $this->assertSame(4, $this->fetchToday()['summary']['enrolled']);

        Enrollment::factory()->create(['student_id' => $shsStudent->id, 'section_id' => $shsSection->id, 'term_id' => $secondTerm->id]);
        $todayBody = $this->fetchToday();

        $this->assertSame(1, array_count_values($this->listLastNames($todayBody))['Dizon']);
        $this->assertSame(4, $todayBody['summary']['enrolled']);
        $this->assertSame(4, $todayBody['meta']['total']);
    }

    public function test_today_shows_a_past_date(): void
    {
        $this->createAttendance($this->cruz, $this->schoolYear, '2030-08-30', ['time_in' => '07:10:00']);

        $todayBody = $this->fetchToday(['date' => '2030-08-30']);

        $this->assertSame('2030-08-30', $todayBody['date']);
        $this->assertSame(['absent', 'absent', 'present'], array_column($todayBody['attendances'], 'status'));
        $this->assertSame(['enrolled' => 3, 'present' => 1, 'late' => 0, 'absent' => 2, 'timed_out' => 0], $todayBody['summary']);
    }

    public function test_today_derives_no_absences_on_a_weekend(): void
    {
        $saturdayBody = $this->fetchToday(['date' => '2030-08-31']);

        $this->assertSame([null, null, null], array_column($saturdayBody['attendances'], 'status'));
        $this->assertSame(0, $saturdayBody['summary']['absent']);
        $this->assertSame(3, $saturdayBody['summary']['enrolled']);
        $this->assertSame([], $this->fetchToday(['date' => '2030-08-31', 'status' => 'absent'])['attendances']);
    }

    public function test_summary_counts_only_rows_with_a_time_out(): void
    {
        $this->assertSame(1, $this->fetchToday()['summary']['timed_out']);

        $this->createAttendance($this->cruz, $this->schoolYear, self::MONDAY, ['time_out' => '15:30:00']);

        $this->assertSame(2, $this->fetchToday()['summary']['timed_out']);
    }

    public function test_today_paginates_fifteen_rows_per_page(): void
    {
        for ($index = 0; $index < 13; $index++) {
            $this->enrollStudent($this->rizal, ['last_name' => 'Zamora']);
        }

        $firstPage = $this->fetchToday();
        $secondPage = $this->fetchToday(['page' => 2]);

        $this->assertCount(self::PAGE_SIZE, $firstPage['attendances']);
        $this->assertCount(1, $secondPage['attendances']);
        $this->assertSame(['current_page' => 2, 'last_page' => 2, 'per_page' => 15, 'total' => 16], $secondPage['meta']);
        $this->assertSame(16, $firstPage['summary']['enrolled']);
    }

    public function test_today_with_no_active_school_year_is_empty(): void
    {
        SchoolYear::query()->update(['is_active' => false]);

        $todayBody = $this->fetchToday();

        $this->assertSame([], $todayBody['attendances']);
        $this->assertSame(['enrolled' => 0, 'present' => 0, 'late' => 0, 'absent' => 0, 'timed_out' => 0], $todayBody['summary']);
    }

    public function test_today_rejects_invalid_parameters(): void
    {
        $this->withToken($this->registrarToken)->getJson(self::TODAY_URL.'?date=2030-09-03')->assertUnprocessable()->assertJsonValidationErrors(['date']);
        $this->withToken($this->registrarToken)->getJson(self::TODAY_URL.'?date=02-09-2030')->assertUnprocessable()->assertJsonValidationErrors(['date']);
        $this->withToken($this->registrarToken)->getJson(self::TODAY_URL.'?status=timed_out')->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->withToken($this->registrarToken)->getJson(self::TODAY_URL.'?section_id=999')->assertUnprocessable()->assertJsonValidationErrors(['section_id']);
        $this->withToken($this->registrarToken)->getJson(self::TODAY_URL.'?grade_level_id=999')->assertUnprocessable()->assertJsonValidationErrors(['grade_level_id']);
    }
}
