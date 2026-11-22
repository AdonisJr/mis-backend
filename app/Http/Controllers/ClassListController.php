<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListTeacherClassesRequest;
use App\Http\Requests\ShowAdvisorySectionRequest;
use App\Http\Resources\RosterStudentResource;
use App\Http\Resources\SectionResource;
use App\Http\Resources\SectionTimetableRowResource;
use App\Http\Resources\TeacherClassResource;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\User;
use App\Support\ClassListAccess;
use App\Support\SectionRoster;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

class ClassListController extends Controller
{
    public const CLASS_NOT_PERMITTED_MESSAGE = 'You can only view class lists of classes you teach or of the section you advise.';

    public function listMyClasses(ListTeacherClassesRequest $request): JsonResponse
    {
        $teacher = User::findOrFail($request->resolveTeacherId());
        $schoolClasses = $this->buildTeacherClassQuery($request, $teacher)->get();
        $studentCounts = SectionRoster::countListedStudents($schoolClasses);

        foreach ($schoolClasses as $schoolClass) {
            $schoolClass->setAttribute('student_count', $studentCounts[$schoolClass->id]);
        }

        return response()->json([
            'teacher' => ['id' => $teacher->id, 'name' => $teacher->name],
            'classes' => TeacherClassResource::collection($schoolClasses),
        ]);
    }

    public function showAdvisorySection(ShowAdvisorySectionRequest $request): JsonResponse
    {
        $section = $request->findAdvisedSection();

        if (! $section) {
            return response()->json(['advisory_section' => null]);
        }

        $termNumber = $request->resolveTermNumber();
        $roster = new SectionRoster($section, $termNumber);
        $section->load(SectionController::RELATIONS)->loadCount(Section::buildEnrolledCount($roster->resolveTermId()));
        $canSeeGuardians = (new ClassListAccess($request->user()))->canSeeGuardians($section);

        return response()->json([
            'advisory_section' => [
                'section' => SectionResource::make($section),
                'term_number' => $termNumber,
                'timetable' => SectionTimetableRowResource::collection($section->fetchTimetableEntries($termNumber)),
                ...$this->buildRosterGroups($roster, $canSeeGuardians),
            ],
        ]);
    }

    public function showClassRoster(Request $request, int $id): JsonResponse
    {
        $schoolClass = SchoolClass::with(SchoolClass::CLASS_LIST_RELATIONS)->findOrFail($id);
        $access = new ClassListAccess($request->user());

        if (! $access->canReadClass($schoolClass)) {
            return response()->json(['message' => self::CLASS_NOT_PERMITTED_MESSAGE], Response::HTTP_FORBIDDEN);
        }

        $roster = new SectionRoster($schoolClass->section, $schoolClass->term_number);
        $rosterGroups = $this->buildRosterGroups($roster, $access->canSeeGuardians($schoolClass->section));
        $schoolClass->setAttribute('student_count', count($rosterGroups['students']));

        return response()->json([
            'class' => TeacherClassResource::make($schoolClass),
            ...$rosterGroups,
        ]);
    }

    /** @return Builder<SchoolClass> */
    private function buildTeacherClassQuery(ListTeacherClassesRequest $request, User $teacher): Builder
    {
        $termNumber = $request->validated('term_number');

        // select only class columns: the joined tables also have id and timestamps
        return SchoolClass::query()
            ->select('classes.*')
            ->join('sections', 'sections.id', '=', 'classes.section_id')
            ->join('grade_levels', 'grade_levels.id', '=', 'sections.grade_level_id')
            ->join('curriculum_entries', 'curriculum_entries.id', '=', 'classes.curriculum_entry_id')
            ->where('classes.teacher_id', $teacher->id)
            ->where('classes.school_year_id', $request->validated('school_year_id'))
            ->when($request->filled('term_number'), fn (Builder $query) => $query->where(fn (Builder $termQuery) => $termQuery->whereNull('classes.term_number')->orWhere('classes.term_number', $termNumber)))
            ->with(SchoolClass::CLASS_LIST_RELATIONS)
            ->orderBy('grade_levels.sort_order')
            ->orderBy('sections.name')
            ->orderBy('sections.id')
            ->orderBy('curriculum_entries.sort_order')
            ->orderBy('classes.id');
    }

    /** @return array{students: Collection<int, RosterStudentResource>, left_the_class: Collection<int, RosterStudentResource>} */
    private function buildRosterGroups(SectionRoster $roster, bool $canSeeGuardians): array
    {
        return array_map(
            fn (Collection $enrollments) => $enrollments->map(fn (Enrollment $enrollment) => RosterStudentResource::make($enrollment)->includeGuardians($canSeeGuardians))->values(),
            $roster->fetchGroups(),
        );
    }
}
