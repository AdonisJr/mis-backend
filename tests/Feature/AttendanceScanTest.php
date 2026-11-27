<?php

namespace Tests\Feature;

use App\Http\Controllers\AttendanceController;
use App\Http\Requests\RecordAttendanceScanRequest;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\StudentAttendance;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithAttendance;
use Tests\TestCase;

class AttendanceScanTest extends TestCase
{
    use InteractsWithAttendance;
    use RefreshDatabase;

    private const THROTTLE_LIMIT = 60;

    private const UNIQUE_INDEX = 'student_attendances_student_date_unique';

    private SchoolYear $schoolYear;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schoolYear = $this->createActiveSchoolYear();
        $this->student = $this->enrollStudent($this->createJhsSection($this->schoolYear), ['rfid' => self::UID, 'last_name' => self::ABAD]);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function provideInvalidRfidBodies(): array
    {
        return [
            'array' => [['rfid' => ['04:A1']]],
            'blank' => [['rfid' => '   ']],
            '256 characters' => [['rfid' => str_repeat('A', 256)]],
            'missing' => [[]],
        ];
    }

    public function test_first_scan_records_time_in_as_present_with_the_active_school_year(): void
    {
        // an older inactive year exists first, so the active one is not id 1
        SchoolYear::factory()->create();
        $this->travelToManila(self::MONDAY.' 07:00:00');

        $scanResponse = $this->postScan(['rfid' => self::UID])->assertOk();

        $this->assertSame(self::STUDENT_KEYS, array_keys($scanResponse->json('student')));
        $this->assertSame(self::ATTENDANCE_KEYS, array_keys($scanResponse->json('attendance')));
        $this->assertSame($this->student->id, $scanResponse->json('student.id'));
        $this->assertSame(
            ['student_id' => $this->student->id, 'school_year_id' => $this->schoolYear->id, 'date' => self::MONDAY, 'time_in' => '07:00:00', 'time_out' => null, 'status' => StudentAttendance::STATUS_PRESENT],
            Arr::only($scanResponse->json('attendance'), ['student_id', 'school_year_id', 'date', 'time_in', 'time_out', 'status']),
        );
        $this->assertSame(1, StudentAttendance::count());
    }

    public function test_scan_at_the_cut_off_second_is_present(): void
    {
        $this->travelToManila(self::MONDAY.' 07:30:00');

        $this->postScan(['rfid' => self::UID])->assertOk()->assertJsonPath('attendance.status', StudentAttendance::STATUS_PRESENT);
    }

    public function test_scan_one_second_after_the_cut_off_is_late(): void
    {
        $this->travelToManila(self::MONDAY.' 07:30:01');

        $this->postScan(['rfid' => self::UID])->assertOk()->assertJsonPath('attendance.status', StudentAttendance::STATUS_LATE);
    }

    public function test_late_cut_off_follows_the_config(): void
    {
        config(['attendance.late_after' => '08:00']);
        $this->travelToManila(self::MONDAY.' 07:45:00');

        $this->postScan(['rfid' => self::UID])->assertOk()->assertJsonPath('attendance.status', StudentAttendance::STATUS_PRESENT);
    }

    public function test_late_cut_off_accepts_a_single_digit_hour(): void
    {
        config(['attendance.late_after' => '7:30']);

        $this->travelToManila(self::MONDAY.' 07:30:00');
        $this->postScan(['rfid' => self::UID])->assertOk()->assertJsonPath('attendance.status', StudentAttendance::STATUS_PRESENT);

        $this->travelToManila('2030-09-03 07:31:00');
        $this->postScan(['rfid' => self::UID])->assertOk()->assertJsonPath('attendance.status', StudentAttendance::STATUS_LATE);
    }

    public function test_invalid_late_cut_off_falls_back_to_0730(): void
    {
        config(['attendance.late_after' => 'garbage']);

        $this->travelToManila(self::MONDAY.' 07:30:00');
        $this->postScan(['rfid' => self::UID])->assertOk()->assertJsonPath('attendance.status', StudentAttendance::STATUS_PRESENT);

        $this->travelToManila('2030-09-03 07:30:01');
        $this->postScan(['rfid' => self::UID])->assertOk()->assertJsonPath('attendance.status', StudentAttendance::STATUS_LATE);
    }

    public function test_later_scan_records_the_time_out(): void
    {
        $this->travelToManila(self::MONDAY.' 07:00:00');
        $this->postScan(['rfid' => self::UID])->assertOk();

        $this->travelToManila(self::MONDAY.' 16:00:00');
        $this->postScan(['rfid' => self::UID])->assertOk()
            ->assertJsonPath('attendance.time_in', '07:00:00')
            ->assertJsonPath('attendance.time_out', '16:00:00');

        $this->assertSame(1, StudentAttendance::count());
    }

    public function test_tap_within_the_debounce_window_changes_nothing(): void
    {
        $this->travelToManila(self::MONDAY.' 07:00:00');
        $this->postScan(['rfid' => self::UID])->assertOk();

        $this->travelToManila(self::MONDAY.' 07:00:59');
        $this->postScan(['rfid' => self::UID])->assertOk()->assertJsonPath('attendance.time_out', null);

        $this->travelToManila(self::MONDAY.' 16:00:00');
        $this->postScan(['rfid' => self::UID])->assertOk();

        $this->travelToManila(self::MONDAY.' 16:00:59');
        $this->postScan(['rfid' => self::UID])->assertOk()->assertJsonPath('attendance.time_out', '16:00:00');
    }

    public function test_tap_at_the_debounce_window_edge_is_recorded(): void
    {
        $this->travelToManila(self::MONDAY.' 07:00:00');
        $this->postScan(['rfid' => self::UID])->assertOk();

        $this->travelToManila(self::MONDAY.' 07:01:00');
        $this->postScan(['rfid' => self::UID])->assertOk()->assertJsonPath('attendance.time_out', '07:01:00');

        $this->travelToManila(self::MONDAY.' 07:02:00');
        $this->postScan(['rfid' => self::UID])->assertOk()->assertJsonPath('attendance.time_out', '07:02:00');
    }

    public function test_scan_normalises_the_uid(): void
    {
        $this->postScan(['rfid' => '  04:a1:b2:c3 '])->assertOk()->assertJsonPath('student.id', $this->student->id);
    }

    public function test_unknown_card_returns_404_and_writes_nothing(): void
    {
        $this->postScan(['rfid' => 'FF:FF:FF:FF'])
            ->assertNotFound()
            ->assertExactJson(['message' => AttendanceController::STUDENT_NOT_FOUND_MESSAGE]);

        $this->assertSame(0, StudentAttendance::count());
    }

    public function test_no_active_school_year_returns_422_before_the_card_lookup(): void
    {
        SchoolYear::query()->update(['is_active' => false]);

        $this->postScan(['rfid' => 'FF:FF:FF:FF'])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => RecordAttendanceScanRequest::NO_ACTIVE_YEAR_MESSAGE,
                'errors' => [RecordAttendanceScanRequest::NO_ACTIVE_YEAR_KEY => [RecordAttendanceScanRequest::NO_ACTIVE_YEAR_MESSAGE]],
            ]);

        $this->assertSame(0, StudentAttendance::count());
    }

    /**
     * @param  array<string, mixed>  $scanBody
     */
    #[DataProvider('provideInvalidRfidBodies')]
    public function test_invalid_body_returns_422(array $scanBody): void
    {
        $this->postScan($scanBody)->assertUnprocessable()->assertJsonValidationErrors(['rfid']);

        $this->assertSame(0, StudentAttendance::count());
    }

    public function test_scan_is_throttled_to_60_requests_per_minute(): void
    {
        $this->freezeSecond();

        for ($attempt = 0; $attempt < self::THROTTLE_LIMIT; $attempt++) {
            $this->postScan(['rfid' => 'FF:FF:FF:FF'])->assertNotFound();
        }

        $this->postScan(['rfid' => 'FF:FF:FF:FF'])->assertTooManyRequests();

        $this->travel(59)->seconds();
        $this->postScan(['rfid' => 'FF:FF:FF:FF'])->assertTooManyRequests();

        $this->travel(1)->seconds();
        $this->postScan(['rfid' => 'FF:FF:FF:FF'])->assertNotFound();
    }

    public function test_scan_throttle_is_separate_from_the_relay(): void
    {
        $this->freezeSecond();

        for ($attempt = 0; $attempt < self::THROTTLE_LIMIT; $attempt++) {
            $this->post(self::RELAY_URL, ['rfid' => self::UID])->assertForbidden();
        }

        $this->post(self::RELAY_URL, ['rfid' => self::UID])->assertTooManyRequests();
        $this->postScan(['rfid' => self::UID])->assertOk();
    }

    public function test_day_boundary_follows_asia_manila(): void
    {
        $this->travelToManila(self::MONDAY.' 23:59:59');
        $this->postScan(['rfid' => self::UID])->assertOk();

        $this->travelToManila('2030-09-03 00:00:30');
        $this->postScan(['rfid' => self::UID])->assertOk()
            ->assertJsonPath('attendance.date', '2030-09-03')
            ->assertJsonPath('attendance.time_in', '00:00:30')
            ->assertJsonPath('attendance.time_out', null);

        $this->assertSame(['2030-09-02', '2030-09-03'], StudentAttendance::orderBy('date')->get()->map(fn (StudentAttendance $attendance) => $attendance->date->toDateString())->all());
    }

    public function test_date_is_the_manila_date_when_utc_is_the_previous_day(): void
    {
        $this->travelTo(now()->setTimezone('UTC')->setDate(2030, 9, 2)->setTime(23, 0, 0));

        $this->postScan(['rfid' => self::UID])->assertOk()
            ->assertJsonPath('attendance.date', '2030-09-03')
            ->assertJsonPath('attendance.time_in', '07:00:00');
    }

    public function test_unique_index_rejects_a_second_row_for_the_same_day(): void
    {
        $this->createAttendance($this->student, $this->schoolYear, self::MONDAY);

        $this->assertSame(['student_id', 'date'], collect(Schema::getIndexes('student_attendances'))->firstWhere('name', self::UNIQUE_INDEX)['columns']);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->createAttendance($this->student, $this->schoolYear, self::MONDAY);
    }
}
