<?php

namespace Tests\Feature;

use App\Http\Requests\ListAttendanceRecordsRequest;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Student;
use App\Models\Term;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\InteractsWithAttendance;
use Tests\TestCase;

class AttendanceRecordsTest extends TestCase
{
    use InteractsWithAttendance;
    use RefreshDatabase;

    private const SEPTEMBER = ['date_from' => '2030-09-01', 'date_to' => '2030-09-30'];

    private SchoolYear $schoolYear;

    private Section $rizal;

    private Section $mabini;

    private Student $abad;

    private Student $bautista;

    private Student $cruz;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schoolYear = $this->createActiveSchoolYear();
        $this->rizal = $this->createJhsSection($this->schoolYear);
        $this->mabini = $this->createJhsSection($this->schoolYear, 'Mabini', 1);
        $this->abad = $this->enrollStudent($this->rizal, ['lrn' => '100000000001', 'first_name' => 'Ana', 'last_name' => self::ABAD]);
        $this->bautista = $this->enrollStudent($this->rizal, ['lrn' => '100000000002', 'first_name' => 'Ben', 'last_name' => self::BAUTISTA]);
        $this->cruz = $this->enrollStudent($this->mabini, ['lrn' => '100000000003', 'first_name' => 'Cai', 'last_name' => self::CRUZ]);
        $this->adminToken = $this->createRoleToken('admin');
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function requestRecords(array $query): TestResponse
    {
        return $this->withToken($this->adminToken)->getJson(self::RECORDS_URL.'?'.http_build_query($query));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<string>
     */
    private function listRecordLastNames(array $query): array
    {
        $recordBody = $this->requestRecords($query)->assertOk()->json();

        return array_column(array_column($recordBody['attendances'], 'student'), 'last_name');
    }

    public function test_records_include_both_edges_in_date_then_name_order(): void
    {
        $this->createAttendance($this->abad, $this->schoolYear, '2030-08-31');
        $this->createAttendance($this->bautista, $this->schoolYear, '2030-09-01');
        $this->createAttendance($this->cruz, $this->schoolYear, '2030-09-01');
        $this->createAttendance($this->abad, $this->schoolYear, '2030-09-02', ['time_in' => '07:45:00', 'status' => 'late']);
        $this->createAttendance($this->abad, $this->schoolYear, '2030-09-03');

        $recordBody = $this->requestRecords(['date_from' => '2030-09-01', 'date_to' => '2030-09-02'])->assertOk()->json();

        $this->assertSame(['attendances', 'meta'], array_keys($recordBody));
        $this->assertSame(['current_page' => 1, 'last_page' => 1, 'per_page' => 15, 'total' => 3], $recordBody['meta']);
        $this->assertSame(
            [['2030-09-02', self::ABAD], ['2030-09-01', self::BAUTISTA], ['2030-09-01', self::CRUZ]],
            array_map(fn (array $row) => [$row['date'], $row['student']['last_name']], $recordBody['attendances']),
        );
        $this->assertSame(self::RECORD_ROW_KEYS, array_keys($recordBody['attendances'][0]));
        $this->assertSame(self::ROW_STUDENT_KEYS, array_keys($recordBody['attendances'][0]['student']));
        $this->assertSame(['late', '07:45:00', null], [$recordBody['attendances'][0]['status'], $recordBody['attendances'][0]['time_in'], $recordBody['attendances'][0]['time_out']]);
        $this->assertSame(['Rizal', 'Mabini'], [$recordBody['attendances'][0]['section']['name'], $recordBody['attendances'][2]['section']['name']]);
        $this->assertSame(['id', 'name'], array_keys($recordBody['attendances'][0]['grade_level']));
    }

    public function test_missing_dates_return_422(): void
    {
        $this->requestRecords([])->assertUnprocessable()->assertJsonValidationErrors(['date_from', 'date_to']);
    }

    public function test_date_to_before_date_from_returns_422(): void
    {
        $this->requestRecords(['date_from' => '2030-09-10', 'date_to' => '2030-09-09'])->assertUnprocessable()->assertJsonValidationErrors(['date_to']);
    }

    public function test_records_accept_31_days_and_reject_32(): void
    {
        $this->requestRecords(['date_from' => '2030-09-01', 'date_to' => '2030-10-01'])->assertOk();

        $this->requestRecords(['date_from' => '2030-09-01', 'date_to' => '2030-10-02'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.date_to', [ListAttendanceRecordsRequest::RANGE_TOO_LONG_MESSAGE]);
    }

    public function test_student_and_status_filters(): void
    {
        $this->createAttendance($this->abad, $this->schoolYear, '2030-09-02');
        $this->createAttendance($this->abad, $this->schoolYear, '2030-09-03', ['status' => 'late']);
        $this->createAttendance($this->bautista, $this->schoolYear, '2030-09-02', ['status' => 'late']);

        $this->assertSame([self::ABAD, self::ABAD], $this->listRecordLastNames([...self::SEPTEMBER, 'student_id' => $this->abad->id]));
        $this->assertSame([self::ABAD, self::BAUTISTA], $this->listRecordLastNames([...self::SEPTEMBER, 'status' => 'late']));
        $this->assertSame([self::ABAD], $this->listRecordLastNames([...self::SEPTEMBER, 'student_id' => $this->abad->id, 'status' => 'present']));
    }

    public function test_grade_level_and_section_filters(): void
    {
        $this->createAttendance($this->abad, $this->schoolYear, '2030-09-02');
        $this->createAttendance($this->cruz, $this->schoolYear, '2030-09-02');

        $this->assertSame([self::CRUZ], $this->listRecordLastNames([...self::SEPTEMBER, 'grade_level_id' => $this->mabini->grade_level_id]));
        $this->assertSame([self::ABAD], $this->listRecordLastNames([...self::SEPTEMBER, 'section_id' => $this->rizal->id]));
    }

    public function test_records_without_a_current_enrollment_show_null_and_drop_out_of_filters(): void
    {
        $this->createAttendance($this->cruz, $this->schoolYear, '2030-09-02');
        Enrollment::where('student_id', $this->cruz->id)->update(['status' => Enrollment::STATUS_DROPPED]);

        $recordBody = $this->requestRecords(self::SEPTEMBER)->assertOk()->json();

        $this->assertSame([null, null], [$recordBody['attendances'][0]['grade_level'], $recordBody['attendances'][0]['section']]);
        $this->assertSame([], $this->listRecordLastNames([...self::SEPTEMBER, 'section_id' => $this->mabini->id]));
    }

    public function test_records_search_matches_names_and_lrn(): void
    {
        $this->createAttendance($this->abad, $this->schoolYear, '2030-09-02');
        $this->createAttendance($this->cruz, $this->schoolYear, '2030-09-02');

        $this->assertSame([self::ABAD], $this->listRecordLastNames([...self::SEPTEMBER, 'search' => 'abad']));
        $this->assertSame([self::CRUZ], $this->listRecordLastNames([...self::SEPTEMBER, 'search' => '100000000003']));
    }

    public function test_records_paginate_fifteen_rows_per_page(): void
    {
        for ($day = 1; $day <= 16; $day++) {
            $this->createAttendance($this->abad, $this->schoolYear, sprintf('2030-09-%02d', $day));
        }

        $secondPage = $this->requestRecords([...self::SEPTEMBER, 'page' => 2])->assertOk()->json();

        $this->assertCount(1, $secondPage['attendances']);
        $this->assertSame(['current_page' => 2, 'last_page' => 2, 'per_page' => 15, 'total' => 16], $secondPage['meta']);
    }

    public function test_per_page_100_is_accepted_and_echoed(): void
    {
        $this->createAttendance($this->abad, $this->schoolYear, '2030-09-02');

        $this->requestRecords([...self::SEPTEMBER, 'per_page' => 100])->assertOk()->assertJsonPath('meta.per_page', 100);
    }

    public function test_per_page_above_100_returns_422(): void
    {
        $this->requestRecords([...self::SEPTEMBER, 'per_page' => 101])->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
    }

    public function test_per_page_below_1_returns_422(): void
    {
        $this->requestRecords([...self::SEPTEMBER, 'per_page' => 0])->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
    }

    public function test_absent_status_and_unknown_ids_return_422(): void
    {
        $this->requestRecords([...self::SEPTEMBER, 'status' => 'absent'])->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->requestRecords([...self::SEPTEMBER, 'student_id' => 999])->assertUnprocessable()->assertJsonValidationErrors(['student_id']);
        $this->requestRecords([...self::SEPTEMBER, 'grade_level_id' => 999])->assertUnprocessable()->assertJsonValidationErrors(['grade_level_id']);
        $this->requestRecords([...self::SEPTEMBER, 'section_id' => 999])->assertUnprocessable()->assertJsonValidationErrors(['section_id']);
    }

    public function test_grade_and_section_follow_the_latest_current_enrollment(): void
    {
        // no active term, so a student with a term 1 and a term 2 row has two current rows; the latest one decides
        $firstTermSection = Section::factory()->create(['school_year_id' => $this->schoolYear->id, 'name' => 'STEM-A']);
        $secondTermSection = Section::factory()->create(['school_year_id' => $this->schoolYear->id, 'name' => 'STEM-B']);
        $shsStudent = Student::factory()->withRfid()->create(['last_name' => 'Dizon']);
        Enrollment::factory()->create(['student_id' => $shsStudent->id, 'section_id' => $firstTermSection->id, 'term_id' => Term::factory()->create(['school_year_id' => $this->schoolYear->id, 'term_number' => 1])->id]);
        Enrollment::factory()->create(['student_id' => $shsStudent->id, 'section_id' => $secondTermSection->id, 'term_id' => Term::factory()->create(['school_year_id' => $this->schoolYear->id, 'term_number' => 2])->id]);
        $this->createAttendance($shsStudent, $this->schoolYear, '2030-09-02');

        $recordBody = $this->requestRecords(self::SEPTEMBER)->assertOk()->json();

        $this->assertSame('STEM-B', $recordBody['attendances'][0]['section']['name']);
        $this->assertSame([], $this->listRecordLastNames([...self::SEPTEMBER, 'section_id' => $firstTermSection->id]));
        $this->assertSame(['Dizon'], $this->listRecordLastNames([...self::SEPTEMBER, 'section_id' => $secondTermSection->id]));
        $this->assertCount(1, $recordBody['attendances']);
    }
}
