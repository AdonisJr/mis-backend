<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithGrading;
use Tests\TestCase;

class ClassRecordTest extends TestCase
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

    public function test_record_has_twelve_keys_and_an_eleven_key_header(): void
    {
        $record = $this->withToken($this->teacherToken)->getJson($this->recordUrl($this->schoolClass, 1))->assertOk()->json('class_record');

        $this->assertSame(self::CLASS_RECORD_KEYS, array_keys($record));
        $this->assertSame(self::CLASS_LIST_CLASS_KEYS, array_keys($record['class']));
        $this->assertSame(1, $record['quarter']);
        $this->assertSame([1, 2, 3, 4], $record['quarters']);
    }

    public function test_fixture_typical_case_is_computed_through_the_http_record(): void
    {
        $student = $this->enrollStudent(['last_name' => self::AQUINO], ['section_id' => $this->rizalSection->id])->student;
        $fixtureCase = $this->loadFixtureCase('typical_jhs_languages');
        $this->seedFixtureScores($fixtureCase['activities'], $student);

        $studentRow = $this->withToken($this->teacherToken)->getJson($this->recordUrl($this->schoolClass, 1))->assertOk()->json('class_record.students.0');

        $this->assertSame(self::RECORD_ROW_KEYS, array_keys($studentRow));
        $this->assertSame($fixtureCase['expected']['ww'], $studentRow['ww']);
        $this->assertSame($fixtureCase['expected']['pt'], $studentRow['pt']);
        $this->assertSame($fixtureCase['expected']['qa'], $studentRow['qa']);
        $this->assertSame($fixtureCase['expected']['initial_grade'], $studentRow['initial_grade']);
        $this->assertSame($fixtureCase['expected']['quarterly_grade'], $studentRow['quarterly_grade']);
        $this->assertFalse($studentRow['is_snapshot']);
    }

    public function test_blank_score_counts_as_zero_and_an_empty_component_is_listed(): void
    {
        $student = $this->enrollStudent(['last_name' => self::AQUINO], ['section_id' => $this->rizalSection->id])->student;
        $fixtureCase = $this->loadFixtureCase('blank_and_empty_component');
        $this->seedFixtureScores($fixtureCase['activities'], $student);

        $record = $this->withToken($this->teacherToken)->getJson($this->recordUrl($this->schoolClass, 1))->assertOk()->json('class_record');

        $this->assertSame(['qa'], $record['components_without_activities']);
        $this->assertSame($fixtureCase['expected']['ww'], $record['students'][0]['ww']);
        $this->assertSame($fixtureCase['expected']['qa'], $record['students'][0]['qa']);
        $this->assertSame($fixtureCase['expected']['quarterly_grade'], $record['students'][0]['quarterly_grade']);
    }

    public function test_scores_hold_one_entry_per_activity_with_null_for_blanks(): void
    {
        $student = $this->enrollStudent(['last_name' => self::AQUINO], ['section_id' => $this->rizalSection->id])->student;
        $quiz = $this->createActivity($this->schoolClass, ['title' => self::QUIZ_1]);
        $project = $this->createActivity($this->schoolClass, ['title' => self::PROJECT, 'component' => 'pt']);
        $this->createScore($quiz, $student, '15.5');

        $this->withToken($this->teacherToken)->getJson($this->recordUrl($this->schoolClass, 1))
            ->assertOk()
            ->assertJsonPath('class_record.students.0.scores', [
                ['activity_id' => $quiz->id, 'score' => '15.5'],
                ['activity_id' => $project->id, 'score' => null],
            ]);
    }

    public function test_left_students_are_listed_with_their_scores(): void
    {
        $leaver = $this->enrollStudent(['last_name' => self::RAMOS], ['section_id' => $this->rizalSection->id, 'status' => Enrollment::STATUS_DROPPED])->student;
        $quiz = $this->createActivity($this->schoolClass, ['title' => self::QUIZ_1]);
        $this->createScore($quiz, $leaver, '10.0');

        $record = $this->withToken($this->teacherToken)->getJson($this->recordUrl($this->schoolClass, 1))->assertOk()->json('class_record');

        $this->assertSame([], $record['students']);
        $this->assertSame([self::RAMOS], $this->pluckLastNames($record['left_the_class']));
        $this->assertSame('10.0', $record['left_the_class'][0]['scores'][0]['score']);
        $this->assertSame('dropped', $record['left_the_class'][0]['status']);
    }

    public function test_student_who_moved_in_has_blank_scores_and_the_floor_grade(): void
    {
        $this->createActivity($this->schoolClass, ['title' => self::QUIZ_1]);
        $this->enrollStudent(['last_name' => self::AQUINO], ['section_id' => $this->rizalSection->id]);

        $studentRow = $this->withToken($this->teacherToken)->getJson($this->recordUrl($this->schoolClass, 1))->assertOk()->json('class_record.students.0');

        $this->assertNull($studentRow['scores'][0]['score']);
        $this->assertSame('0.00', $studentRow['initial_grade']);
        $this->assertSame(60, $studentRow['quarterly_grade']);
    }

    public function test_rows_follow_the_roster_order(): void
    {
        $this->enrollStudent(['last_name' => self::BONIFACIO, 'first_name' => 'Ana'], ['section_id' => $this->rizalSection->id]);
        $this->enrollStudent(['last_name' => self::AQUINO, 'first_name' => 'Zed'], ['section_id' => $this->rizalSection->id]);
        $this->enrollStudent(['last_name' => self::AQUINO, 'first_name' => 'Ana'], ['section_id' => $this->rizalSection->id]);

        $record = $this->withToken($this->teacherToken)->getJson($this->recordUrl($this->schoolClass, 1))->assertOk()->json('class_record');

        $this->assertSame(['Ana', 'Zed', 'Ana'], array_column($record['students'], 'first_name'));
        $this->assertSame([self::AQUINO, self::AQUINO, self::BONIFACIO], $this->pluckLastNames($record['students']));
    }

    public function test_draft_record_has_no_submission_data(): void
    {
        $record = $this->withToken($this->teacherToken)->getJson($this->recordUrl($this->schoolClass, 1))->assertOk()->json('class_record');

        $this->assertSame('draft', $record['status']);
        $this->assertNull($record['submitted_by']);
        $this->assertNull($record['submitted_at']);
        $this->assertSame([], $record['history']);
    }

    public function test_weights_follow_the_subject_weight_group(): void
    {
        $this->schoolClass->curriculumEntry->subject->update(['weight_group' => Subject::WEIGHT_GROUP_JHS_SCIENCE_MATH]);

        $this->withToken($this->teacherToken)->getJson($this->recordUrl($this->schoolClass, 1))
            ->assertOk()
            ->assertJsonPath('class_record.weights', ['ww' => 40, 'pt' => 40, 'qa' => 20]);
    }

    public function test_class_without_activities_or_students_is_an_empty_record(): void
    {
        $record = $this->withToken($this->teacherToken)->getJson($this->recordUrl($this->schoolClass, 1))->assertOk()->json('class_record');

        $this->assertSame([], $record['activities']);
        $this->assertSame([], $record['students']);
        $this->assertSame(['ww', 'pt', 'qa'], $record['components_without_activities']);
        $this->assertSame(['ww' => 30, 'pt' => 50, 'qa' => 20], $record['weights']);
    }

    /** @return array<string, mixed> */
    private function loadFixtureCase(string $caseName): array
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/grade-computation.json')), true, flags: JSON_THROW_ON_ERROR);

        return collect($fixture['quarter_cases'])->firstWhere('name', $caseName);
    }

    /**
     * @param  list<array{component: string, highest: int, score: string|null}>  $fixtureActivities
     */
    private function seedFixtureScores(array $fixtureActivities, Student $student): void
    {
        foreach ($fixtureActivities as $fixtureActivity) {
            $activity = $this->createActivity($this->schoolClass, ['component' => $fixtureActivity['component'], 'highest_possible_score' => $fixtureActivity['highest']]);

            if ($fixtureActivity['score'] !== null) {
                $this->createScore($activity, $student, $fixtureActivity['score']);
            }
        }
    }
}
