<?php

namespace Tests\Feature;

use App\Http\Requests\StoreSubjectRequest;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithCurriculum;
use Tests\TestCase;

class SubjectWeightGroupTest extends TestCase
{
    use InteractsWithCurriculum;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideFittingPairs(): array
    {
        return [
            'jhs languages ap esp' => [Subject::TYPE_JHS_LEARNING_AREA, Subject::WEIGHT_GROUP_JHS_LANGUAGES_AP_ESP],
            'jhs science math' => [Subject::TYPE_JHS_LEARNING_AREA, Subject::WEIGHT_GROUP_JHS_SCIENCE_MATH],
            'jhs mapeh epp tle' => [Subject::TYPE_JHS_LEARNING_AREA, Subject::WEIGHT_GROUP_JHS_MAPEH_EPP_TLE],
            'shs core' => [Subject::TYPE_SHS_CORE, Subject::WEIGHT_GROUP_SHS_CORE],
            'shs academic other' => [Subject::TYPE_SHS_APPLIED, Subject::WEIGHT_GROUP_SHS_ACADEMIC_OTHER],
            'shs academic immersion research' => [Subject::TYPE_SHS_SPECIALIZED, Subject::WEIGHT_GROUP_SHS_ACADEMIC_IMMERSION_RESEARCH],
            'shs tvl sports arts' => [Subject::TYPE_SHS_APPLIED, Subject::WEIGHT_GROUP_SHS_TVL_SPORTS_ARTS],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideUnfitPairs(): array
    {
        return [
            'jhs with shs core' => [Subject::TYPE_JHS_LEARNING_AREA, Subject::WEIGHT_GROUP_SHS_CORE],
            'core with jhs science math' => [Subject::TYPE_SHS_CORE, Subject::WEIGHT_GROUP_JHS_SCIENCE_MATH],
            'core with shs academic other' => [Subject::TYPE_SHS_CORE, Subject::WEIGHT_GROUP_SHS_ACADEMIC_OTHER],
            'applied with shs core' => [Subject::TYPE_SHS_APPLIED, Subject::WEIGHT_GROUP_SHS_CORE],
            'specialized with jhs mapeh epp tle' => [Subject::TYPE_SHS_SPECIALIZED, Subject::WEIGHT_GROUP_JHS_MAPEH_EPP_TLE],
        ];
    }

    #[DataProvider('provideFittingPairs')]
    public function test_every_group_is_accepted_with_a_fitting_type(string $type, string $weightGroup): void
    {
        $this->withToken($this->adminToken)->postJson(self::SUBJECTS_URL, $this->buildSubjectPayload(['type' => $type, 'weight_group' => $weightGroup]))
            ->assertCreated()
            ->assertJsonPath('subject.weight_group', $weightGroup);

        $this->assertDatabaseHas('subjects', ['code' => self::MATH_7, 'weight_group' => $weightGroup]);
    }

    public function test_missing_weight_group_returns_422(): void
    {
        $this->withToken($this->adminToken)->postJson(self::SUBJECTS_URL, $this->buildSubjectPayload(['weight_group' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['weight_group']);
    }

    public function test_unknown_weight_group_returns_422(): void
    {
        $this->withToken($this->adminToken)->postJson(self::SUBJECTS_URL, $this->buildSubjectPayload(['weight_group' => 'college_major']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['weight_group']);
    }

    #[DataProvider('provideUnfitPairs')]
    public function test_unfit_pair_returns_422(string $type, string $weightGroup): void
    {
        $this->withToken($this->adminToken)->postJson(self::SUBJECTS_URL, $this->buildSubjectPayload(['type' => $type, 'weight_group' => $weightGroup]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['weight_group' => StoreSubjectRequest::WEIGHT_GROUP_UNFIT_MESSAGE]);

        $this->assertDatabaseCount('subjects', 0);
    }

    public function test_update_changes_the_group(): void
    {
        $subject = $this->createSubject(self::MATH_7);

        $this->withToken($this->adminToken)->putJson(self::SUBJECTS_URL.'/'.$subject->id, $this->buildSubjectPayload(['weight_group' => Subject::WEIGHT_GROUP_JHS_SCIENCE_MATH]))
            ->assertOk()
            ->assertJsonPath('subject.weight_group', Subject::WEIGHT_GROUP_JHS_SCIENCE_MATH);

        $this->assertSame(Subject::WEIGHT_GROUP_JHS_SCIENCE_MATH, $subject->fresh()->weight_group);
    }

    public function test_type_change_rechecks_the_fit(): void
    {
        $subject = $this->createSubject(self::MATH_7);

        $this->withToken($this->adminToken)->putJson(self::SUBJECTS_URL.'/'.$subject->id, $this->buildSubjectPayload(['type' => Subject::TYPE_SHS_CORE, 'weight_group' => Subject::WEIGHT_GROUP_JHS_LANGUAGES_AP_ESP]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['weight_group' => StoreSubjectRequest::WEIGHT_GROUP_UNFIT_MESSAGE]);
    }

    public function test_registrar_can_set_the_group(): void
    {
        $this->withToken($this->createRegistrarToken())->postJson(self::SUBJECTS_URL, $this->buildSubjectPayload(['weight_group' => Subject::WEIGHT_GROUP_JHS_MAPEH_EPP_TLE]))
            ->assertCreated()
            ->assertJsonPath('subject.weight_group', Subject::WEIGHT_GROUP_JHS_MAPEH_EPP_TLE);
    }

    public function test_list_shows_the_group(): void
    {
        Subject::factory()->weightGroup(Subject::WEIGHT_GROUP_JHS_SCIENCE_MATH)->create(['code' => self::MATH_7]);

        $listedSubject = $this->withToken($this->adminToken)->getJson(self::SUBJECTS_URL)->assertOk()->json('subjects.0');

        $this->assertSame(self::SUBJECT_KEYS, array_keys($listedSubject));
        $this->assertSame(Subject::WEIGHT_GROUP_JHS_SCIENCE_MATH, $listedSubject['weight_group']);
    }
}
