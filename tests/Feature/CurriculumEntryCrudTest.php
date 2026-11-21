<?php

namespace Tests\Feature;

use App\Models\CurriculumEntry;
use App\Models\Program;
use App\Models\SchoolYear;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithCurriculum;
use Tests\TestCase;

class CurriculumEntryCrudTest extends TestCase
{
    use InteractsWithCurriculum;
    use RefreshDatabase;

    private Program $stemProgram;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
        $this->stemProgram = $this->createProgram(self::STEM_PROGRAM);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function provideInvalidSortOrders(): array
    {
        return [
            'zero' => [0],
            'too large' => [10000],
            'text' => ['abc'],
            'fractional' => [2.5],
        ];
    }

    public function test_update_changes_only_sort_order(): void
    {
        $subject = $this->createSubject(self::GEN_MATH, Subject::TYPE_SHS_CORE);
        $entry = $this->createEntry($this->buildShsEntryPayload($subject, $this->stemProgram));
        $otherYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);
        $smuggledFields = [
            'school_year_id' => $otherYear->id,
            'grade_level_id' => $this->findGradeLevelId(self::GRADE_12),
            'subject_id' => $this->createSubject(self::ORAL_COM, Subject::TYPE_SHS_CORE)->id,
            'program_id' => $this->createProgram(self::ABM_PROGRAM)->id,
            'term_number' => 2,
        ];

        $this->putEntry($entry->id, ['sort_order' => 5, ...$smuggledFields])
            ->assertOk()
            ->assertJsonPath('curriculum_entry.sort_order', 5)
            ->assertJsonPath('curriculum_entry.subject_id', $subject->id)
            ->assertJsonPath('curriculum_entry.program_id', $this->stemProgram->id)
            ->assertJsonPath('curriculum_entry.term_number', 1);

        $reloadedEntry = $entry->fresh();
        $this->assertSame($this->schoolYear->id, $reloadedEntry->school_year_id);
        $this->assertSame($this->findGradeLevelId(self::GRADE_11), $reloadedEntry->grade_level_id);
        $this->assertSame(5, $reloadedEntry->sort_order);
    }

    public function test_update_null_clears_sort_order(): void
    {
        $entry = $this->createEntry([...$this->buildJhsEntryPayload($this->createSubject(self::MATH_7)), 'sort_order' => 3]);

        $this->putEntry($entry->id, ['sort_order' => null])
            ->assertOk()
            ->assertJsonPath('curriculum_entry.sort_order', null);

        $this->assertNull($entry->fresh()->sort_order);
    }

    public function test_update_requires_the_sort_order_key(): void
    {
        $entry = $this->createEntry([...$this->buildJhsEntryPayload($this->createSubject(self::MATH_7)), 'sort_order' => 3]);

        $this->putEntry($entry->id, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort_order']);

        $this->assertSame(3, $entry->fresh()->sort_order);
    }

    public function test_update_missing_id_returns_404(): void
    {
        $this->putEntry(self::MISSING_ID, ['sort_order' => 1])->assertNotFound();
    }

    public function test_two_updates_swap_the_order_of_two_entries(): void
    {
        $firstEntry = $this->createEntry([...$this->buildJhsEntryPayload($this->createSubject(self::MATH_7)), 'sort_order' => 1]);
        $secondEntry = $this->createEntry([...$this->buildJhsEntryPayload($this->createSubject(self::ENGLISH_7)), 'sort_order' => 2]);

        $this->putEntry($firstEntry->id, ['sort_order' => 2])->assertOk();
        $this->putEntry($secondEntry->id, ['sort_order' => 1])->assertOk();

        $listedIds = $this->withToken($this->adminToken)
            ->getJson(self::CURRICULUM_URL.'?school_year_id='.$this->schoolYear->id)
            ->assertOk()
            ->json('curriculum_entries.*.id');
        $this->assertSame([$secondEntry->id, $firstEntry->id], $listedIds);
    }

    public function test_delete_removes_the_entry(): void
    {
        $entry = $this->createEntry($this->buildJhsEntryPayload($this->createSubject(self::MATH_7)));

        $this->withToken($this->adminToken)->deleteJson(self::CURRICULUM_URL.'/'.$entry->id)->assertNoContent();

        $this->assertNull($entry->fresh());
        $this->assertSame(1, Subject::count());
    }

    public function test_delete_missing_id_returns_404(): void
    {
        $this->withToken($this->adminToken)->deleteJson(self::CURRICULUM_URL.'/'.self::MISSING_ID)->assertNotFound();
    }

    public function test_update_allows_inactive_subject_entry(): void
    {
        $subject = $this->createSubject(self::MATH_7);
        $entry = $this->createEntry($this->buildJhsEntryPayload($subject));
        $subject->forceFill(['is_active' => false])->save();

        $this->putEntry($entry->id, ['sort_order' => 4])
            ->assertOk()
            ->assertJsonPath('curriculum_entry.sort_order', 4)
            ->assertJsonPath('curriculum_entry.subject.is_active', false);
    }

    #[DataProvider('provideInvalidSortOrders')]
    public function test_update_rejects_invalid_sort_order(mixed $sortOrder): void
    {
        $entry = $this->createEntry([...$this->buildJhsEntryPayload($this->createSubject(self::MATH_7)), 'sort_order' => 3]);

        $this->putEntry($entry->id, ['sort_order' => $sortOrder])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort_order']);

        $this->assertSame(3, $entry->fresh()->sort_order);
        $this->assertSame(1, CurriculumEntry::count());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function putEntry(int $entryId, array $payload): TestResponse
    {
        return $this->withToken($this->adminToken)->putJson(self::CURRICULUM_URL.'/'.$entryId, $payload);
    }
}
