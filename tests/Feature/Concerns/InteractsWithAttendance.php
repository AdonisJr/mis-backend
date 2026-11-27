<?php

namespace Tests\Feature\Concerns;

use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Role;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

trait InteractsWithAttendance
{
    private const SCAN_URL = '/api/scan';

    private const RELAY_URL = '/api/rfid/scan';

    private const TODAY_URL = '/api/mis/attendance/today';

    private const RECORDS_URL = '/api/mis/attendance';

    private const MOBILE_URL = '/api/mis/mobile/attendance';

    private const UID = '04:A1:B2:C3';

    private const TOKEN_NAME = 'test';

    private const MANILA = 'Asia/Manila';

    private const MONDAY = '2030-09-02';

    private const FORBIDDEN_BODY = ['message' => 'Unauthorized'];

    private const UNAUTHENTICATED_BODY = ['message' => 'Unauthenticated.'];

    private const STUDENT_KEYS = ['id', 'lrn', 'first_name', 'middle_name', 'last_name', 'birth_date', 'gender', 'address', 'contact', 'guardian_name', 'guardian_relationship', 'guardian_contact', 'rfid', 'current_enrollment', 'created_at', 'updated_at'];

    private const ATTENDANCE_KEYS = ['id', 'student_id', 'school_year_id', 'date', 'time_in', 'time_out', 'status', 'created_at', 'updated_at'];

    private const TODAY_ROW_KEYS = ['attendance_id', 'student', 'grade_level', 'section', 'time_in', 'time_out', 'status'];

    private const RECORD_ROW_KEYS = ['attendance_id', 'date', 'student', 'grade_level', 'section', 'time_in', 'time_out', 'status'];

    private const ROW_STUDENT_KEYS = ['id', 'lrn', 'first_name', 'middle_name', 'last_name'];

    private const META_KEYS = ['current_page', 'last_page', 'per_page', 'total'];

    // these names never collide with StudentFactory names (Magsaysay/Quezon/Osmena/Laurel, Ligaya/Dakila/Tala/Bayani)
    private const ABAD = 'Abad';

    private const BAUTISTA = 'Bautista';

    private const CRUZ = 'Cruz';

    private function createActiveSchoolYear(): SchoolYear
    {
        return SchoolYear::factory()->active()->create(['name' => '2030-2031', 'start_date' => '2030-06-01', 'end_date' => '2031-03-31']);
    }

    private function createJhsSection(SchoolYear $schoolYear, string $name = 'Rizal', int $gradeOffset = 0): Section
    {
        $gradeLevelId = GradeLevel::where('stage', GradeLevel::STAGE_JHS)->orderBy('sort_order')->skip($gradeOffset)->value('id');

        return Section::factory()->jhs()->create(['school_year_id' => $schoolYear->id, 'grade_level_id' => $gradeLevelId, 'name' => $name]);
    }

    /**
     * @param  array<string, mixed>  $studentFields
     */
    private function enrollStudent(Section $section, array $studentFields = []): Student
    {
        $student = Student::factory()->withRfid()->create($studentFields);
        Enrollment::factory()->create(['student_id' => $student->id, 'section_id' => $section->id]);

        return $student;
    }

    private function createRoleToken(string $roleName): string
    {
        $user = User::factory()->create(['email' => $roleName.'@school.test']);
        $user->roles()->attach(Role::firstOrCreate(['name' => $roleName]));

        return $user->createToken(self::TOKEN_NAME)->plainTextToken;
    }

    // the firmware sends JSON with no Accept header
    private function postScan(array $scanBody): TestResponse
    {
        return $this->call('POST', self::SCAN_URL, [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($scanBody));
    }

    private function travelToManila(string $dateTime): void
    {
        $this->travelTo(CarbonImmutable::parse($dateTime, self::MANILA));
    }

    /**
     * @param  array<string, mixed>  $attendanceFields
     */
    private function createAttendance(Student $student, SchoolYear $schoolYear, string $date, array $attendanceFields = []): StudentAttendance
    {
        return StudentAttendance::factory()->create(['student_id' => $student->id, 'school_year_id' => $schoolYear->id, 'date' => $date, ...$attendanceFields]);
    }
}
