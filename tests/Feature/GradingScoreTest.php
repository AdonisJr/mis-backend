<?php

namespace Tests\Feature;

use App\Http\Requests\SaveGradingScoresRequest;
use App\Models\Enrollment;
use App\Models\GradingActivity;
use App\Models\GradingScore;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithGrading;
use Tests\TestCase;

class GradingScoreTest extends TestCase
{
    use InteractsWithGrading;
    use RefreshDatabase;

    private SchoolClass $schoolClass;

    private string $teacherToken;

    private GradingActivity $quiz;

    private Student $aquino;

    private Student $bonifacio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClassListFixtures();
        $this->schoolClass = $this->createJhsClass($this->mathTeacher);
        $this->teacherToken = $this->createTokenFor($this->mathTeacher);
        $this->quiz = $this->createActivity($this->schoolClass, ['title' => self::QUIZ_1]);
        $this->aquino = $this->enrollStudent(['last_name' => self::AQUINO], ['section_id' => $this->rizalSection->id])->student;
        $this->bonifacio = $this->enrollStudent(['last_name' => self::BONIFACIO], ['section_id' => $this->rizalSection->id])->student;
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function provideInvalidScores(): array
    {
        return [
            'negative' => [-1],
            'above the highest score' => [20.5],
            'two decimals' => [9.55],
            'not a number' => ['abc'],
        ];
    }

    /**
     * @param  list<array{student_id: int, activity_id: int, score: mixed}>  $rows
     */
    private function putScores(array $rows): TestResponse
    {
        return $this->withToken($this->teacherToken)->putJson($this->scoresUrl($this->schoolClass, 1), $this->buildScoresPayload($rows));
    }

    /**
     * @return array{student_id: int, activity_id: int, score: mixed}
     */
    private function buildRow(Student $student, GradingActivity $activity, mixed $score): array
    {
        return ['student_id' => $student->id, 'activity_id' => $activity->id, 'score' => $score];
    }

    public function test_save_creates_the_scores(): void
    {
        $this->putScores([$this->buildRow($this->aquino, $this->quiz, 15), $this->buildRow($this->bonifacio, $this->quiz, '12.5')])->assertOk();

        $this->assertDatabaseCount('grading_scores', 2);
        $this->assertSame('15.0', GradingScore::where('student_id', $this->aquino->id)->firstOrFail()->score);
        $this->assertSame('12.5', GradingScore::where('student_id', $this->bonifacio->id)->firstOrFail()->score);
    }

    public function test_second_save_updates_without_duplicates(): void
    {
        $this->putScores([$this->buildRow($this->aquino, $this->quiz, 10)])->assertOk();
        $this->putScores([$this->buildRow($this->aquino, $this->quiz, 18)])->assertOk();

        $this->assertDatabaseCount('grading_scores', 1);
        $this->assertSame('18.0', GradingScore::firstOrFail()->score);
    }

    public function test_blank_score_is_stored_as_null(): void
    {
        $this->putScores([$this->buildRow($this->aquino, $this->quiz, 10)])->assertOk();
        $this->putScores([$this->buildRow($this->aquino, $this->quiz, null)])->assertOk();

        $this->assertNull(GradingScore::firstOrFail()->score);
    }

    public function test_response_is_the_class_record(): void
    {
        $recordResponse = $this->putScores([$this->buildRow($this->aquino, $this->quiz, 15)])->assertOk();

        $this->assertSame(self::CLASS_RECORD_KEYS, array_keys($recordResponse->json('class_record')));
        $this->assertSame('15.0', $recordResponse->json('class_record.students.0.scores.0.score'));
    }

    #[DataProvider('provideInvalidScores')]
    public function test_invalid_score_returns_422(mixed $score): void
    {
        $this->putScores([$this->buildRow($this->aquino, $this->quiz, $score)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scores.0.score']);

        $this->assertDatabaseCount('grading_scores', 0);
    }

    public function test_zero_and_the_highest_score_are_accepted(): void
    {
        $this->putScores([$this->buildRow($this->aquino, $this->quiz, 0), $this->buildRow($this->bonifacio, $this->quiz, 20)])->assertOk();

        $this->assertSame(['0.0', '20.0'], GradingScore::orderBy('id')->pluck('score')->all());
    }

    public function test_student_off_the_roster_returns_422(): void
    {
        $otherSection = $this->createSection(['name' => self::MABINI]);
        $outsider = $this->enrollStudent(['last_name' => self::RAMOS], ['section_id' => $otherSection->id])->student;

        $this->putScores([$this->buildRow($outsider, $this->quiz, 10)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scores.0.student_id' => SaveGradingScoresRequest::NOT_ON_ROSTER_MESSAGE]);
    }

    public function test_left_student_returns_422(): void
    {
        $leaver = $this->enrollStudent(['last_name' => self::RAMOS], ['section_id' => $this->rizalSection->id, 'status' => Enrollment::STATUS_DROPPED])->student;

        $this->putScores([$this->buildRow($leaver, $this->quiz, 10)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scores.0.student_id' => SaveGradingScoresRequest::NOT_ON_ROSTER_MESSAGE]);
    }

    public function test_activity_of_another_class_returns_422(): void
    {
        $otherClass = $this->createJhsClass($this->mathTeacher);
        $foreignActivity = $this->createActivity($otherClass, ['title' => self::PROJECT]);

        $this->putScores([$this->buildRow($this->aquino, $foreignActivity, 10)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scores.0.activity_id' => SaveGradingScoresRequest::ACTIVITY_OUTSIDE_MESSAGE]);
    }

    public function test_activity_of_another_quarter_returns_422(): void
    {
        $quarterTwoActivity = $this->createActivity($this->schoolClass, ['title' => self::PROJECT, 'quarter' => 2]);

        $this->putScores([$this->buildRow($this->aquino, $quarterTwoActivity, 10)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scores.0.activity_id' => SaveGradingScoresRequest::ACTIVITY_OUTSIDE_MESSAGE]);
    }

    public function test_duplicate_student_and_activity_pair_returns_422(): void
    {
        $this->putScores([$this->buildRow($this->aquino, $this->quiz, 10), $this->buildRow($this->aquino, $this->quiz, 11)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scores.1' => SaveGradingScoresRequest::DUPLICATE_ROW_MESSAGE]);

        $this->assertDatabaseCount('grading_scores', 0);
    }

    public function test_empty_scores_return_422(): void
    {
        $this->putScores([])->assertUnprocessable()->assertJsonValidationErrors(['scores']);
    }

    public function test_bulk_save_is_all_or_nothing(): void
    {
        $this->putScores([$this->buildRow($this->aquino, $this->quiz, 10), $this->buildRow($this->bonifacio, $this->quiz, 99)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scores.1.score' => sprintf(SaveGradingScoresRequest::SCORE_ABOVE_HIGHEST_MESSAGE, 20)]);

        $this->assertDatabaseCount('grading_scores', 0);
    }

    public function test_score_sent_as_a_string_is_accepted(): void
    {
        $this->putScores([$this->buildRow($this->aquino, $this->quiz, '9.5')])->assertOk();

        $this->assertSame('9.5', GradingScore::firstOrFail()->score);
    }
}
