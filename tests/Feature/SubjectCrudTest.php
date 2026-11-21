<?php

namespace Tests\Feature;

use App\Http\Requests\StoreSubjectRequest;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithCurriculum;
use Tests\TestCase;

class SubjectCrudTest extends TestCase
{
    use InteractsWithCurriculum;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function provideInvalidFields(): array
    {
        return [
            'code missing' => [['code' => null], 'code'],
            'code too long' => [['code' => str_repeat('A', 21)], 'code'],
            'name missing' => [['name' => null], 'name'],
            'name too long' => [['name' => str_repeat('A', 151)], 'name'],
            'type missing' => [['type' => null], 'type'],
            'type unknown' => [['type' => 'college_major'], 'type'],
            'weekly hours missing' => [['weekly_hours' => null], 'weekly_hours'],
            'description too long' => [['description' => str_repeat('A', 501)], 'description'],
        ];
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function provideInvalidWeeklyHours(): array
    {
        return [
            'zero' => [0],
            'quarter' => [0.25],
            'four tenths' => [0.4],
            'one and a quarter' => [1.25],
            'forty and a half' => [40.5],
            'forty one' => [41],
            'negative' => [-0.5],
            'text' => ['abc'],
        ];
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function provideValidWeeklyHours(): array
    {
        return [
            'half' => [0.5, '0.5'],
            'forty' => [40, '40.0'],
            'twelve and a half' => [12.5, '12.5'],
            'three' => [3, '3.0'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideEveryType(): array
    {
        return [
            'jhs learning area' => [Subject::TYPE_JHS_LEARNING_AREA],
            'shs core' => [Subject::TYPE_SHS_CORE],
            'shs applied' => [Subject::TYPE_SHS_APPLIED],
            'shs specialized' => [Subject::TYPE_SHS_SPECIALIZED],
        ];
    }

    public function test_admin_creates_subject(): void
    {
        $createResponse = $this->withToken($this->adminToken)->postJson(self::SUBJECTS_URL, $this->buildSubjectPayload());

        $createResponse->assertCreated()
            ->assertJsonPath('subject.code', self::MATH_7)
            ->assertJsonPath('subject.name', 'Mathematics 7')
            ->assertJsonPath('subject.type', Subject::TYPE_JHS_LEARNING_AREA)
            ->assertJsonPath('subject.weekly_hours', '4.0')
            ->assertJsonPath('subject.description', 'Grade 7 mathematics')
            ->assertJsonPath('subject.is_active', true);
        $this->assertEqualsCanonicalizing(self::SUBJECT_KEYS, array_keys($createResponse->json('subject')));
        $this->assertDatabaseCount('subjects', 1);
    }

    public function test_create_ignores_is_active_in_body(): void
    {
        $this->withToken($this->adminToken)
            ->postJson(self::SUBJECTS_URL, $this->buildSubjectPayload(['is_active' => false]))
            ->assertCreated()
            ->assertJsonPath('subject.is_active', true);

        $this->assertTrue(Subject::firstOrFail()->is_active);
    }

    public function test_create_rejects_code_taken_ignoring_case(): void
    {
        $this->createSubject(self::MATH_7);

        $this->withToken($this->adminToken)
            ->postJson(self::SUBJECTS_URL, $this->buildSubjectPayload(['code' => ' math7 ']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => StoreSubjectRequest::CODE_TAKEN_MESSAGE]);

        $this->assertDatabaseCount('subjects', 1);
    }

    public function test_update_changes_subject_fields(): void
    {
        $subject = $this->createSubject(self::MATH_7);
        $changedPayload = $this->buildSubjectPayload([
            'code' => self::ENGLISH_7,
            'name' => 'English 7',
            'type' => Subject::TYPE_SHS_CORE,
            'weekly_hours' => 1.5,
            'description' => null,
        ]);

        $this->withToken($this->adminToken)
            ->putJson(self::SUBJECTS_URL.'/'.$subject->id, $changedPayload)
            ->assertOk()
            ->assertJsonPath('subject.id', $subject->id)
            ->assertJsonPath('subject.code', self::ENGLISH_7)
            ->assertJsonPath('subject.name', 'English 7')
            ->assertJsonPath('subject.type', Subject::TYPE_SHS_CORE)
            ->assertJsonPath('subject.weekly_hours', '1.5')
            ->assertJsonPath('subject.description', null);

        $this->assertSame(self::ENGLISH_7, $subject->fresh()->code);
    }

    public function test_update_keeps_own_code_with_new_case(): void
    {
        $subject = $this->createSubject(self::MATH_7);

        $this->withToken($this->adminToken)
            ->putJson(self::SUBJECTS_URL.'/'.$subject->id, $this->buildSubjectPayload(['code' => 'math7']))
            ->assertOk()
            ->assertJsonPath('subject.code', 'math7');
    }

    public function test_update_rejects_code_of_another_subject_ignoring_case(): void
    {
        $subject = $this->createSubject(self::MATH_7);
        $this->createSubject(self::ENGLISH_7);

        $this->withToken($this->adminToken)
            ->putJson(self::SUBJECTS_URL.'/'.$subject->id, $this->buildSubjectPayload(['code' => 'eng7']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => StoreSubjectRequest::CODE_TAKEN_MESSAGE]);

        $this->assertSame(self::MATH_7, $subject->fresh()->code);
    }

    public function test_update_ignores_is_active_in_body(): void
    {
        $subject = Subject::factory()->inactive()->create(['code' => self::MATH_7]);

        $this->withToken($this->adminToken)
            ->putJson(self::SUBJECTS_URL.'/'.$subject->id, $this->buildSubjectPayload(['is_active' => true]))
            ->assertOk()
            ->assertJsonPath('subject.is_active', false);

        $this->assertFalse($subject->fresh()->is_active);
    }

    public function test_update_missing_id_returns_404(): void
    {
        $this->withToken($this->adminToken)
            ->putJson(self::SUBJECTS_URL.'/'.self::MISSING_ID, $this->buildSubjectPayload())
            ->assertNotFound();
    }

    public function test_create_accepts_field_limits(): void
    {
        $limitPayload = $this->buildSubjectPayload([
            'code' => str_repeat('A', 20),
            'name' => str_repeat('B', 150),
            'description' => str_repeat('C', 500),
        ]);

        $this->withToken($this->adminToken)
            ->postJson(self::SUBJECTS_URL, $limitPayload)
            ->assertCreated()
            ->assertJsonPath('subject.code', $limitPayload['code'])
            ->assertJsonPath('subject.name', $limitPayload['name'])
            ->assertJsonPath('subject.description', $limitPayload['description']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('provideInvalidFields')]
    public function test_create_rejects_invalid_fields(array $overrides, string $errorField): void
    {
        $this->withToken($this->adminToken)
            ->postJson(self::SUBJECTS_URL, $this->buildSubjectPayload($overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$errorField]);

        $this->assertDatabaseCount('subjects', 0);
    }

    #[DataProvider('provideInvalidWeeklyHours')]
    public function test_create_rejects_invalid_weekly_hours(mixed $weeklyHours): void
    {
        $this->withToken($this->adminToken)
            ->postJson(self::SUBJECTS_URL, $this->buildSubjectPayload(['weekly_hours' => $weeklyHours]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['weekly_hours']);

        $this->assertDatabaseCount('subjects', 0);
    }

    #[DataProvider('provideValidWeeklyHours')]
    public function test_create_accepts_valid_weekly_hours(mixed $weeklyHours, string $expectedHours): void
    {
        $this->withToken($this->adminToken)
            ->postJson(self::SUBJECTS_URL, $this->buildSubjectPayload(['weekly_hours' => $weeklyHours]))
            ->assertCreated()
            ->assertJsonPath('subject.weekly_hours', $expectedHours);
    }

    #[DataProvider('provideEveryType')]
    public function test_create_accepts_every_type(string $type): void
    {
        $this->withToken($this->adminToken)
            ->postJson(self::SUBJECTS_URL, $this->buildSubjectPayload(['type' => $type]))
            ->assertCreated()
            ->assertJsonPath('subject.type', $type);
    }
}
