<?php

namespace Tests\Feature\Concerns;

use App\Http\Controllers\SchoolClassController;
use App\Http\Requests\ChangeClassQuarterRequest;
use App\Http\Requests\ShowClassQuarterRequest;
use App\Http\Requests\ShowReportCardRequest;
use App\Http\Requests\ShowSectionReportCardsRequest;
use App\Models\ClassQuarter;
use App\Models\GradingActivity;
use App\Models\GradingScore;
use App\Models\QuarterGrade;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;

trait InteractsWithGrading
{
    use InteractsWithClassLists;

    private const CLASS_RECORD_KEYS = ['class', 'quarter', 'quarters', 'weights', 'status', 'submitted_by', 'submitted_at', 'activities', 'students', 'left_the_class', 'components_without_activities', 'history'];

    private const RECORD_ROW_KEYS = ['enrollment_id', 'student_id', 'last_name', 'first_name', 'middle_name', 'lrn', 'status', 'scores', 'ww', 'pt', 'qa', 'initial_grade', 'quarterly_grade', 'is_snapshot'];

    private const ACTIVITY_KEYS = ['id', 'class_id', 'quarter', 'component', 'title', 'highest_possible_score', 'activity_date', 'created_at', 'updated_at'];

    private const HISTORY_KEYS = ['id', 'action', 'reason', 'user', 'created_at'];

    private const REPORT_CARD_KEYS = ['student', 'school_year', 'grade_level', 'program', 'section', 'adviser', 'subjects', 'general_averages'];

    private const QUIZ_1 = 'Quiz 1';

    private const PROJECT = 'Project';

    private const EXAM = 'Quarter Exam';

    private const READ_NOT_PERMITTED_BODY = ['message' => ShowClassQuarterRequest::READ_NOT_PERMITTED_MESSAGE];

    private const WRITE_NOT_PERMITTED_BODY = ['message' => ChangeClassQuarterRequest::WRITE_NOT_PERMITTED_MESSAGE];

    private const REPORT_NOT_PERMITTED_BODY = ['message' => ShowReportCardRequest::NOT_PERMITTED_MESSAGE];

    private const SECTION_CARDS_NOT_PERMITTED_BODY = ['message' => ShowSectionReportCardsRequest::NOT_PERMITTED_MESSAGE];

    private const GRADING_DELETE_BODY = ['message' => SchoolClassController::GRADING_DELETE_MESSAGE];

    private function recordUrl(SchoolClass $schoolClass, int $quarter): string
    {
        return $this->buildQuarterUrl($schoolClass, $quarter, 'record');
    }

    private function activitiesUrl(SchoolClass $schoolClass, int $quarter): string
    {
        return $this->buildQuarterUrl($schoolClass, $quarter, 'activities');
    }

    private function scoresUrl(SchoolClass $schoolClass, int $quarter): string
    {
        return $this->buildQuarterUrl($schoolClass, $quarter, 'scores');
    }

    private function submitUrl(SchoolClass $schoolClass, int $quarter): string
    {
        return $this->buildQuarterUrl($schoolClass, $quarter, 'submit');
    }

    private function reopenUrl(SchoolClass $schoolClass, int $quarter): string
    {
        return $this->buildQuarterUrl($schoolClass, $quarter, 'reopen');
    }

    private function reportCardUrl(Student $student, int $schoolYearId): string
    {
        return '/api/mis/students/'.$student->id.'/report-card?school_year_id='.$schoolYearId;
    }

    private function sectionReportCardsUrl(Section $section): string
    {
        return '/api/mis/sections/'.$section->id.'/report-cards';
    }

    private function buildQuarterUrl(SchoolClass $schoolClass, int $quarter, string $suffix): string
    {
        return self::CLASSES_URL.'/'.$schoolClass->id.'/quarters/'.$quarter.'/'.$suffix;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function createActivity(SchoolClass $schoolClass, array $fields = []): GradingActivity
    {
        return GradingActivity::factory()->create(['class_id' => $schoolClass->id, 'quarter' => 1, ...$fields]);
    }

    private function createScore(GradingActivity $activity, Student $student, ?string $score): GradingScore
    {
        return GradingScore::factory()->create(['grading_activity_id' => $activity->id, 'student_id' => $student->id, 'score' => $score]);
    }

    /**
     * @param  array<string, mixed>  $gradeRows  QuarterGrade fields; student_id is required
     */
    private function createSubmittedQuarter(SchoolClass $schoolClass, array $gradeRows, int $quarter = 1): ClassQuarter
    {
        $classQuarter = ClassQuarter::factory()->submitted()->create(['class_id' => $schoolClass->id, 'quarter' => $quarter]);

        foreach ($gradeRows as $gradeRow) {
            QuarterGrade::factory()->create(['class_quarter_id' => $classQuarter->id, ...$gradeRow]);
        }

        return $classQuarter;
    }

    /**
     * @param  list<array{student_id: int, activity_id: int, score: mixed}>  $rows
     * @return array{scores: list<array{student_id: int, activity_id: int, score: mixed}>}
     */
    private function buildScoresPayload(array $rows): array
    {
        return ['scores' => $rows];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function buildActivityPayload(array $overrides = []): array
    {
        return ['component' => GradingActivity::COMPONENT_WW, 'title' => self::QUIZ_1, 'highest_possible_score' => 20, ...$overrides];
    }

    private function createTeacherOfClassToken(SchoolClass $schoolClass): string
    {
        return $this->createTokenFor(User::findOrFail($schoolClass->teacher_id));
    }
}
