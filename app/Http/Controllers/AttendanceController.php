<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SearchesPaginatedLists;
use App\Http\Requests\ListAttendanceRecordsRequest;
use App\Http\Requests\ListTodayAttendanceRequest;
use App\Http\Requests\RecordAttendanceScanRequest;
use App\Http\Resources\AttendanceRecordResource;
use App\Http\Resources\StudentAttendanceResource;
use App\Http\Resources\StudentResource;
use App\Http\Resources\TodayAttendanceRowResource;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Support\AttendanceScanRecorder;
use App\Support\TodayAttendanceRoster;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class AttendanceController extends Controller
{
    use SearchesPaginatedLists;

    public const STUDENT_NOT_FOUND_MESSAGE = 'Student not found';

    private const SEARCH_COLUMNS = ['students.lrn', 'students.first_name', 'students.middle_name', 'students.last_name'];

    private const RECORD_RELATIONS = ['student.currentEnrollments.section.gradeLevel'];

    private const DEFAULT_PER_PAGE = 15;

    protected function getSearchColumns(): array
    {
        return self::SEARCH_COLUMNS;
    }

    public function recordScan(RecordAttendanceScanRequest $request): JsonResponse
    {
        $student = Student::where('rfid', $request->validated('rfid'))->first();

        if (! $student) {
            return response()->json(['message' => self::STUDENT_NOT_FOUND_MESSAGE], Response::HTTP_NOT_FOUND);
        }

        $attendance = (new AttendanceScanRecorder($student, $request->findActiveSchoolYear()))->record();

        return response()->json([
            'student' => StudentResource::make($student->load(StudentController::CURRENT_ENROLLMENT_RELATIONS)),
            'attendance' => StudentAttendanceResource::make($attendance),
        ]);
    }

    public function listTodayAttendance(ListTodayAttendanceRequest $request): JsonResponse
    {
        $roster = new TodayAttendanceRoster($request->validated());
        $rowQuery = $roster->buildRowQuery();
        $this->applySearch($rowQuery, (string) $request->validated('search'));
        $rowPage = $rowQuery->paginate(self::DEFAULT_PER_PAGE);

        return response()->json([
            'date' => $request->validated('date'),
            'summary' => $roster->countSummary(),
            'attendances' => TodayAttendanceRowResource::collection($rowPage->getCollection()),
            'meta' => $this->buildPageMeta($rowPage),
        ]);
    }

    public function listAttendanceRecords(ListAttendanceRecordsRequest $request): JsonResponse
    {
        $recordQuery = $this->buildRecordQuery($request);
        $this->applySearch($recordQuery, (string) $request->validated('search'));
        $recordPage = $recordQuery->paginate((int) ($request->validated('per_page') ?? self::DEFAULT_PER_PAGE));

        return response()->json([
            'attendances' => AttendanceRecordResource::collection($recordPage->getCollection()),
            'meta' => $this->buildPageMeta($recordPage),
        ]);
    }

    /** @return Builder<StudentAttendance> */
    private function buildRecordQuery(ListAttendanceRecordsRequest $request): Builder
    {
        return StudentAttendance::query()
            ->select('student_attendances.*')
            ->join('students', 'students.id', '=', 'student_attendances.student_id')
            ->with(self::RECORD_RELATIONS)
            ->whereDate('student_attendances.date', '>=', $request->validated('date_from'))
            ->whereDate('student_attendances.date', '<=', $request->validated('date_to'))
            ->when($request->validated('student_id'), fn (Builder $query, $studentId) => $query->where('student_attendances.student_id', $studentId))
            ->when($request->validated('status'), fn (Builder $query, $status) => $query->where('student_attendances.status', $status))
            ->when($request->validated('grade_level_id'), fn (Builder $query, $gradeLevelId) => $this->filterByLatestCurrentEnrollment($query, fn (Builder $enrollmentQuery) => $enrollmentQuery->whereHas('section', fn (Builder $sectionQuery) => $sectionQuery->where('grade_level_id', $gradeLevelId))))
            ->when($request->validated('section_id'), fn (Builder $query, $sectionId) => $this->filterByLatestCurrentEnrollment($query, fn (Builder $enrollmentQuery) => $enrollmentQuery->where('enrollments.section_id', $sectionId)))
            ->orderByDesc('student_attendances.date')
            ->orderBy('students.last_name')
            ->orderBy('students.first_name')
            ->orderBy('students.id');
    }

    /**
     * @param  Builder<StudentAttendance>  $recordQuery
     * @return Builder<StudentAttendance>
     */
    private function filterByLatestCurrentEnrollment(Builder $recordQuery, Closure $enrollmentFilter): Builder
    {
        // the same pick the row's grade level and section show, so a filtered row never displays another section
        return $recordQuery->whereHas('student.currentEnrollments', function (Builder $enrollmentQuery) use ($enrollmentFilter) {
            TodayAttendanceRoster::applyActivePeriod($enrollmentQuery);
            $enrollmentFilter($enrollmentQuery);
        });
    }
}
