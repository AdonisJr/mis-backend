<?php

namespace Tests\Feature;

use App\Models\ClassQuarter;
use App\Models\Enrollment;
use App\Models\GradingActivity;
use App\Models\QuarterGrade;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithGrading;
use Tests\TestCase;

class ClassQuarterSubmitTest extends TestCase
{
    use InteractsWithGrading;
    use RefreshDatabase;

    private SchoolClass $schoolClass;

    private string $teacherToken;

    private GradingActivity $quiz;

    private GradingActivity $project;

    private Student $aquino;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClassListFixtures();
        $this->schoolClass = $this->createJhsClass($this->mathTeacher);
        $this->teacherToken = $this->createTokenFor($this->mathTeacher);
        $this->quiz = $this->createActivity($this->schoolClass, ['title' => self::QUIZ_1]);
        $this->project = $this->createActivity($this->schoolClass, ['title' => self::PROJECT, 'component' => 'pt']);
        $this->aquino = $this->enrollStudent(['last_name' => self::AQUINO], ['section_id' => $this->rizalSection->id])->student;
        $this->createScore($this->quiz, $this->aquino, '15.0');
        $this->createScore($this->project, $this->aquino, '18.0');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideWriteKinds(): array
    {
        return ['activity create' => ['create'], 'activity update' => ['update'], 'activity delete' => ['delete'], 'score save' => ['scores']];
    }

    private function submitQuarter(?string $token = null): TestResponse
    {
        return $this->actWithToken($token ?? $this->teacherToken)->postJson($this->submitUrl($this->schoolClass, 1));
    }

    private function reopenQuarter(array $body = ['reason' => 'Wrong score entered']): TestResponse
    {
        return $this->actWithToken($this->adminToken)->postJson($this->reopenUrl($this->schoolClass, 1), $body);
    }

    private function actWithToken(string $token): static
    {
        // the sanctum guard keeps the first user of a test, so a second caller needs a fresh guard
        $this->app->make('auth')->forgetGuards();

        return $this->withToken($token);
    }

    private function fetchRecord(): array
    {
        return $this->actWithToken($this->teacherToken)->getJson($this->recordUrl($this->schoolClass, 1))->assertOk()->json('class_record');
    }

    public function test_submit_freezes_the_quarter_and_writes_one_history_row(): void
    {
        $record = $this->submitQuarter()->assertOk()->json('class_record');

        $this->assertSame('submitted', $record['status']);
        $this->assertSame(['id' => $this->mathTeacher->id, 'name' => $this->mathTeacher->name], $record['submitted_by']);
        $this->assertNotNull($record['submitted_at']);
        $this->assertCount(1, $record['history']);
        $this->assertSame(self::HISTORY_KEYS, array_keys($record['history'][0]));
        $this->assertSame('submitted', $record['history'][0]['action']);
        $this->assertNull($record['history'][0]['reason']);
        $this->assertTrue($record['students'][0]['is_snapshot']);
    }

    #[DataProvider('provideWriteKinds')]
    public function test_writes_after_submit_return_422(string $writeKind): void
    {
        $this->submitQuarter()->assertOk();
        $activitiesUrl = $this->activitiesUrl($this->schoolClass, 1);

        $writeResponse = match ($writeKind) {
            'create' => $this->withToken($this->teacherToken)->postJson($activitiesUrl, $this->buildActivityPayload()),
            'update' => $this->withToken($this->teacherToken)->putJson($activitiesUrl.'/'.$this->quiz->id, $this->buildActivityPayload()),
            'delete' => $this->withToken($this->teacherToken)->deleteJson($activitiesUrl.'/'.$this->quiz->id),
            'scores' => $this->withToken($this->teacherToken)->putJson($this->scoresUrl($this->schoolClass, 1), $this->buildScoresPayload([['student_id' => $this->aquino->id, 'activity_id' => $this->quiz->id, 'score' => 5]])),
        };

        $writeResponse->assertUnprocessable()->assertJsonValidationErrors(['quarter' => SchoolClass::QUARTER_SUBMITTED_MESSAGE]);
    }

    public function test_double_submit_returns_422(): void
    {
        $this->submitQuarter()->assertOk();

        $this->submitQuarter()->assertUnprocessable()->assertJsonValidationErrors(['quarter' => SchoolClass::QUARTER_SUBMITTED_MESSAGE]);
        $this->assertSame(1, ClassQuarter::count());
    }

    public function test_snapshot_excludes_students_who_left(): void
    {
        $leaver = $this->enrollStudent(['last_name' => self::RAMOS], ['section_id' => $this->rizalSection->id, 'status' => Enrollment::STATUS_DROPPED])->student;
        $this->createScore($this->quiz, $leaver, '10.0');

        $record = $this->submitQuarter()->assertOk()->json('class_record');

        $this->assertDatabaseCount('quarter_grades', 1);
        $this->assertFalse($record['left_the_class'][0]['is_snapshot']);
    }

    public function test_snapshot_stores_the_weights_used(): void
    {
        $this->submitQuarter()->assertOk();

        $snapshot = QuarterGrade::firstOrFail();
        $this->assertSame([30, 50, 20], [$snapshot->ww_weight, $snapshot->pt_weight, $snapshot->qa_weight]);
        $this->assertSame('75.00', $snapshot->ww_percentage);
        $this->assertSame('90.00', $snapshot->pt_percentage);
        $this->assertSame('22.50', $snapshot->ww_weighted);
        $this->assertSame('45.00', $snapshot->pt_weighted);
        $this->assertSame('67.50', $snapshot->initial_grade);
        $this->assertSame(79, $snapshot->quarterly_grade);
    }

    public function test_weight_group_edit_keeps_the_snapshot(): void
    {
        $this->submitQuarter()->assertOk();
        $this->schoolClass->curriculumEntry->subject->update(['weight_group' => Subject::WEIGHT_GROUP_JHS_SCIENCE_MATH]);

        $record = $this->fetchRecord();

        $this->assertSame(['ww' => 30, 'pt' => 50, 'qa' => 20], $record['weights']);
        $this->assertSame(79, $record['students'][0]['quarterly_grade']);
        $this->assertTrue($record['students'][0]['is_snapshot']);
    }

    public function test_admin_reopen_makes_the_quarter_a_draft_with_two_history_rows(): void
    {
        $this->submitQuarter()->assertOk();

        $record = $this->reopenQuarter()->assertOk()->json('class_record');

        $this->assertSame('draft', $record['status']);
        $this->assertNull($record['submitted_by']);
        $this->assertNull($record['submitted_at']);
        $this->assertSame(['submitted', 'reopened'], array_column($record['history'], 'action'));
        $this->assertSame('Wrong score entered', $record['history'][1]['reason']);
    }

    public function test_reopen_without_a_reason_returns_422(): void
    {
        $this->submitQuarter()->assertOk();

        $this->reopenQuarter(['reason' => ''])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->assertTrue(ClassQuarter::firstOrFail()->isSubmitted());
    }

    public function test_reopen_of_a_draft_returns_422(): void
    {
        $this->reopenQuarter()->assertUnprocessable()->assertJsonValidationErrors(['quarter' => SchoolClass::QUARTER_NOT_SUBMITTED_MESSAGE]);
    }

    public function test_reopen_deletes_the_snapshot(): void
    {
        $this->submitQuarter()->assertOk();

        $record = $this->reopenQuarter()->assertOk()->json('class_record');

        $this->assertDatabaseCount('quarter_grades', 0);
        $this->assertFalse($record['students'][0]['is_snapshot']);
    }

    public function test_score_save_after_reopen_returns_200(): void
    {
        $this->submitQuarter()->assertOk();
        $this->reopenQuarter()->assertOk();

        $this->actWithToken($this->teacherToken)
            ->putJson($this->scoresUrl($this->schoolClass, 1), $this->buildScoresPayload([['student_id' => $this->aquino->id, 'activity_id' => $this->quiz->id, 'score' => 5]]))
            ->assertOk();
    }

    public function test_resubmit_writes_a_new_snapshot_with_the_new_weights(): void
    {
        $this->submitQuarter()->assertOk();
        $this->reopenQuarter()->assertOk();
        $this->schoolClass->curriculumEntry->subject->update(['weight_group' => Subject::WEIGHT_GROUP_JHS_SCIENCE_MATH]);

        $this->submitQuarter()->assertOk();

        $this->assertDatabaseCount('quarter_grades', 1);
        $this->assertSame(40, QuarterGrade::firstOrFail()->ww_weight);
        $this->assertSame(3, ClassQuarter::firstOrFail()->events()->count());
    }

    public function test_reopen_reason_of_501_characters_returns_422(): void
    {
        $this->submitQuarter()->assertOk();

        $this->reopenQuarter(['reason' => str_repeat('a', 501)])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    }

    public function test_submit_with_blank_scores_is_allowed(): void
    {
        $this->schoolClass->gradingActivities()->delete();
        $this->createActivity($this->schoolClass, ['title' => self::QUIZ_1]);

        $this->submitQuarter()->assertOk();

        $this->assertSame(60, QuarterGrade::firstOrFail()->quarterly_grade);
    }

    public function test_class_with_an_activity_cannot_be_deleted(): void
    {
        $this->withToken($this->adminToken)->deleteJson(self::CLASSES_URL.'/'.$this->schoolClass->id)
            ->assertUnprocessable()
            ->assertExactJson(self::GRADING_DELETE_BODY);

        $this->assertModelExists($this->schoolClass);
        $this->assertSame(2, GradingActivity::count());
    }

    public function test_class_with_quarter_history_but_no_activities_cannot_be_deleted(): void
    {
        $this->submitQuarter()->assertOk();
        $this->reopenQuarter()->assertOk();
        $this->schoolClass->gradingActivities()->delete();

        $this->withToken($this->adminToken)->deleteJson(self::CLASSES_URL.'/'.$this->schoolClass->id)
            ->assertUnprocessable()
            ->assertExactJson(self::GRADING_DELETE_BODY);

        $this->assertModelExists($this->schoolClass);
    }

    public function test_class_without_grading_data_is_deleted(): void
    {
        $emptyClass = $this->createJhsClass($this->mathTeacher);

        $this->withToken($this->adminToken)->deleteJson(self::CLASSES_URL.'/'.$emptyClass->id)->assertNoContent();

        $this->assertModelMissing($emptyClass);
    }
}
