<?php

namespace Tests\Feature;

use App\Http\Requests\StoreGradingActivityRequest;
use App\Http\Requests\UpdateGradingActivityRequest;
use App\Models\GradingActivity;
use App\Models\GradingScore;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithGrading;
use Tests\TestCase;

class GradingActivityTest extends TestCase
{
    use InteractsWithGrading;
    use RefreshDatabase;

    private SchoolClass $schoolClass;

    private string $teacherToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClassListFixtures();
        $this->schoolClass = $this->createJhsClass($this->mathTeacher);
        $this->teacherToken = $this->createTokenFor($this->mathTeacher);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function provideInvalidActivityFields(): array
    {
        return [
            'component missing' => [['component' => null], 'component'],
            'component unknown' => [['component' => 'xx'], 'component'],
            'title missing' => [['title' => null], 'title'],
            'title too long' => [['title' => str_repeat('a', 151)], 'title'],
            'highest zero' => [['highest_possible_score' => 0], 'highest_possible_score'],
            'highest fractional' => [['highest_possible_score' => 1.5], 'highest_possible_score'],
            'highest above 999' => [['highest_possible_score' => 1000], 'highest_possible_score'],
            'highest not a number' => [['highest_possible_score' => 'abc'], 'highest_possible_score'],
            'date in the wrong format' => [['activity_date' => '22-11-2026'], 'activity_date'],
        ];
    }

    public function test_list_is_empty_without_activities(): void
    {
        $this->withToken($this->teacherToken)->getJson($this->activitiesUrl($this->schoolClass, 1))
            ->assertOk()
            ->assertExactJson(['activities' => []]);
    }

    public function test_list_orders_ww_then_pt_then_qa_then_id(): void
    {
        $exam = $this->createActivity($this->schoolClass, ['component' => 'qa', 'title' => self::EXAM]);
        $project = $this->createActivity($this->schoolClass, ['component' => 'pt', 'title' => self::PROJECT]);
        $secondQuiz = $this->createActivity($this->schoolClass, ['title' => 'Quiz 2']);
        $firstQuiz = $this->createActivity($this->schoolClass, ['title' => self::QUIZ_1]);

        $this->withToken($this->teacherToken)->getJson($this->activitiesUrl($this->schoolClass, 1))
            ->assertOk()
            ->assertJsonPath('activities.*.id', [$secondQuiz->id, $firstQuiz->id, $project->id, $exam->id]);
    }

    public function test_list_holds_only_the_requested_quarter(): void
    {
        $this->createActivity($this->schoolClass, ['title' => self::QUIZ_1]);
        $this->createActivity($this->schoolClass, ['title' => 'Quiz 2', 'quarter' => 2]);

        $this->withToken($this->teacherToken)->getJson($this->activitiesUrl($this->schoolClass, 2))
            ->assertOk()
            ->assertJsonCount(1, 'activities')
            ->assertJsonPath('activities.0.title', 'Quiz 2');
    }

    public function test_create_returns_201_with_nine_keys(): void
    {
        $activityResponse = $this->withToken($this->teacherToken)
            ->postJson($this->activitiesUrl($this->schoolClass, 2), $this->buildActivityPayload(['activity_date' => '2026-11-22']))
            ->assertCreated();

        $activity = $activityResponse->json('activity');
        $this->assertSame(self::ACTIVITY_KEYS, array_keys($activity));
        $this->assertSame($this->schoolClass->id, $activity['class_id']);
        $this->assertSame(2, $activity['quarter']);
        $this->assertSame(20, $activity['highest_possible_score']);
        $this->assertSame('2026-11-22', $activity['activity_date']);
        $this->assertDatabaseHas('grading_activities', ['id' => $activity['id'], 'title' => self::QUIZ_1]);
    }

    public function test_create_without_a_date_stores_null(): void
    {
        $this->withToken($this->teacherToken)->postJson($this->activitiesUrl($this->schoolClass, 1), $this->buildActivityPayload())
            ->assertCreated()
            ->assertJsonPath('activity.activity_date', null);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('provideInvalidActivityFields')]
    public function test_create_rejects_invalid_fields(array $overrides, string $errorKey): void
    {
        $this->withToken($this->teacherToken)->postJson($this->activitiesUrl($this->schoolClass, 1), $this->buildActivityPayload($overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$errorKey]);

        $this->assertDatabaseCount('grading_activities', 0);
    }

    public function test_second_qa_returns_422(): void
    {
        $this->createActivity($this->schoolClass, ['component' => 'qa', 'title' => self::EXAM]);

        $this->withToken($this->teacherToken)->postJson($this->activitiesUrl($this->schoolClass, 1), $this->buildActivityPayload(['component' => 'qa']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['component' => StoreGradingActivityRequest::SECOND_QA_MESSAGE]);
    }

    public function test_qa_in_another_quarter_is_allowed(): void
    {
        $this->createActivity($this->schoolClass, ['component' => 'qa', 'title' => self::EXAM]);

        $this->withToken($this->teacherToken)->postJson($this->activitiesUrl($this->schoolClass, 2), $this->buildActivityPayload(['component' => 'qa']))
            ->assertCreated();
    }

    public function test_update_changes_the_fields(): void
    {
        $activity = $this->createActivity($this->schoolClass, ['title' => self::QUIZ_1]);
        $updateFields = $this->buildActivityPayload(['component' => 'pt', 'title' => self::PROJECT, 'highest_possible_score' => 50, 'activity_date' => '2026-12-01']);

        $this->withToken($this->teacherToken)->putJson($this->activitiesUrl($this->schoolClass, 1).'/'.$activity->id, $updateFields)
            ->assertOk()
            ->assertJsonPath('activity.component', 'pt')
            ->assertJsonPath('activity.title', self::PROJECT)
            ->assertJsonPath('activity.highest_possible_score', 50)
            ->assertJsonPath('activity.activity_date', '2026-12-01');
    }

    public function test_update_of_the_qa_keeps_its_own_qa(): void
    {
        $exam = $this->createActivity($this->schoolClass, ['component' => 'qa', 'title' => self::EXAM]);

        $this->withToken($this->teacherToken)->putJson($this->activitiesUrl($this->schoolClass, 1).'/'.$exam->id, $this->buildActivityPayload(['component' => 'qa', 'title' => 'Final Exam']))
            ->assertOk()
            ->assertJsonPath('activity.title', 'Final Exam');
    }

    public function test_update_to_qa_with_another_qa_returns_422(): void
    {
        $this->createActivity($this->schoolClass, ['component' => 'qa', 'title' => self::EXAM]);
        $quiz = $this->createActivity($this->schoolClass, ['title' => self::QUIZ_1]);

        $this->withToken($this->teacherToken)->putJson($this->activitiesUrl($this->schoolClass, 1).'/'.$quiz->id, $this->buildActivityPayload(['component' => 'qa']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['component' => StoreGradingActivityRequest::SECOND_QA_MESSAGE]);
    }

    public function test_lowering_below_recorded_score_returns_422(): void
    {
        $quiz = $this->createActivity($this->schoolClass, ['title' => self::QUIZ_1]);
        $this->createScore($quiz, Student::factory()->create(), '15.5');

        $this->withToken($this->teacherToken)->putJson($this->activitiesUrl($this->schoolClass, 1).'/'.$quiz->id, $this->buildActivityPayload(['highest_possible_score' => 15]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['highest_possible_score' => sprintf(UpdateGradingActivityRequest::BELOW_RECORDED_SCORE_MESSAGE, '15.5')]);
    }

    public function test_lowering_to_recorded_max_is_allowed(): void
    {
        $quiz = $this->createActivity($this->schoolClass, ['title' => self::QUIZ_1]);
        $this->createScore($quiz, Student::factory()->create(), '15.0');

        $this->withToken($this->teacherToken)->putJson($this->activitiesUrl($this->schoolClass, 1).'/'.$quiz->id, $this->buildActivityPayload(['highest_possible_score' => 15]))
            ->assertOk()
            ->assertJsonPath('activity.highest_possible_score', 15);
    }

    public function test_update_of_a_foreign_activity_returns_404(): void
    {
        $otherClass = $this->createJhsClass($this->mathTeacher);
        $foreignActivity = $this->createActivity($otherClass, ['title' => self::QUIZ_1]);

        $this->withToken($this->teacherToken)->putJson($this->activitiesUrl($this->schoolClass, 1).'/'.$foreignActivity->id, $this->buildActivityPayload())
            ->assertNotFound();
    }

    public function test_delete_returns_204_and_removes_its_scores(): void
    {
        $quiz = $this->createActivity($this->schoolClass, ['title' => self::QUIZ_1]);
        $this->createScore($quiz, Student::factory()->create(), '10.0');

        $this->withToken($this->teacherToken)->deleteJson($this->activitiesUrl($this->schoolClass, 1).'/'.$quiz->id)->assertNoContent();

        $this->assertModelMissing($quiz);
        $this->assertSame(0, GradingScore::count());
    }

    public function test_delete_of_an_activity_in_another_quarter_returns_404(): void
    {
        $quiz = $this->createActivity($this->schoolClass, ['title' => self::QUIZ_1, 'quarter' => 2]);

        $this->withToken($this->teacherToken)->deleteJson($this->activitiesUrl($this->schoolClass, 1).'/'.$quiz->id)->assertNotFound();

        $this->assertModelExists($quiz);
        $this->assertSame(1, GradingActivity::count());
    }
}
