<?php

namespace Tests\Feature\Concerns;

use App\Models\CurriculumEntry;
use App\Models\Program;
use App\Models\Subject;
use App\Models\User;

trait InteractsWithCurriculum
{
    use InteractsWithFees;

    private const SUBJECTS_URL = '/api/mis/subjects';

    private const ACTIVE_SUBJECTS_URL = '/api/mis/subjects/active';

    private const CURRICULUM_URL = '/api/mis/curriculum-entries';

    private const CURRICULUM_COPY_URL = '/api/mis/curriculum-entries/copy';

    private const SUBJECT_KEYS = ['id', 'code', 'name', 'type', 'weekly_hours', 'description', 'is_active', 'created_at', 'updated_at'];

    private const ENTRY_KEYS = ['id', 'school_year_id', 'grade_level_id', 'subject_id', 'program_id', 'term_number', 'sort_order', 'created_at', 'updated_at', 'subject', 'grade_level', 'program'];

    private const ENTRY_SUBJECT_KEYS = ['id', 'code', 'name', 'type', 'weekly_hours', 'is_active'];

    private const GROUP_TOTAL_KEYS = ['grade_level_id', 'program_id', 'term_number', 'entries_count', 'weekly_hours_total'];

    private const MATH_7 = 'MATH7';

    private const ENGLISH_7 = 'ENG7';

    private const GEN_MATH = 'GENMATH';

    private const ORAL_COM = 'ORALCOM';

    private const EMP_TECH = 'EMPTECH';

    private function createCashierToken(): string
    {
        return $this->createTokenFor(User::factory()->cashier()->create(['email' => 'cashier@school.test']));
    }

    private function createRoleLessToken(): string
    {
        return $this->createTokenFor(User::factory()->create());
    }

    private function createSubject(string $code, string $type = Subject::TYPE_JHS_LEARNING_AREA): Subject
    {
        return Subject::factory()->create(['code' => $code, 'type' => $type]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function buildSubjectPayload(array $overrides = []): array
    {
        return [
            'code' => self::MATH_7,
            'name' => 'Mathematics 7',
            'type' => Subject::TYPE_JHS_LEARNING_AREA,
            'weekly_hours' => 4,
            'description' => 'Grade 7 mathematics',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function buildJhsEntryPayload(Subject $subject, array $overrides = []): array
    {
        return [
            'school_year_id' => $this->schoolYear->id,
            'grade_level_id' => $this->findGradeLevelId(self::GRADE_7),
            'subject_id' => $subject->id,
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildShsEntryPayload(Subject $subject, Program $program): array
    {
        return [
            'school_year_id' => $this->schoolYear->id,
            'grade_level_id' => $this->findGradeLevelId(self::GRADE_11),
            'subject_id' => $subject->id,
            'program_id' => $program->id,
            'term_number' => 1,
        ];
    }

    /**
     * @param  array<string, mixed>  $entryFields
     */
    private function createEntry(array $entryFields): CurriculumEntry
    {
        return CurriculumEntry::factory()->create($entryFields);
    }
}
