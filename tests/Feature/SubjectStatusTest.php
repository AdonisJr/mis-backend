<?php

namespace Tests\Feature;

use App\Models\CurriculumEntry;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithCurriculum;
use Tests\TestCase;

class SubjectStatusTest extends TestCase
{
    use InteractsWithCurriculum;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
    }

    public function test_deactivate_marks_subject_inactive(): void
    {
        $subject = $this->createSubject(self::MATH_7);

        $this->withToken($this->adminToken)
            ->putJson(self::SUBJECTS_URL.'/'.$subject->id.'/deactivate')
            ->assertOk()
            ->assertJsonPath('subject.id', $subject->id)
            ->assertJsonPath('subject.is_active', false);

        $this->assertFalse($subject->fresh()->is_active);
    }

    public function test_reactivate_marks_subject_active(): void
    {
        $subject = Subject::factory()->inactive()->create(['code' => self::MATH_7]);

        $this->withToken($this->adminToken)
            ->putJson(self::SUBJECTS_URL.'/'.$subject->id.'/reactivate')
            ->assertOk()
            ->assertJsonPath('subject.is_active', true);

        $this->assertTrue($subject->fresh()->is_active);
    }

    public function test_deactivate_is_idempotent(): void
    {
        $subject = Subject::factory()->inactive()->create(['code' => self::MATH_7]);

        $this->withToken($this->adminToken)
            ->putJson(self::SUBJECTS_URL.'/'.$subject->id.'/deactivate')
            ->assertOk()
            ->assertJsonPath('subject.is_active', false);

        $this->assertFalse($subject->fresh()->is_active);
    }

    public function test_status_routes_return_404_for_missing_id(): void
    {
        $this->withToken($this->adminToken)->putJson(self::SUBJECTS_URL.'/'.self::MISSING_ID.'/deactivate')->assertNotFound();
        $this->withToken($this->adminToken)->putJson(self::SUBJECTS_URL.'/'.self::MISSING_ID.'/reactivate')->assertNotFound();
    }

    public function test_deactivated_subject_keeps_its_entries(): void
    {
        $subject = $this->createSubject(self::MATH_7);
        $entry = $this->createEntry($this->buildJhsEntryPayload($subject));

        $this->withToken($this->adminToken)->putJson(self::SUBJECTS_URL.'/'.$subject->id.'/deactivate')->assertOk();

        $this->assertNotNull($entry->fresh());
        $this->assertSame(1, CurriculumEntry::where('subject_id', $subject->id)->count());
    }

    public function test_delete_subject_route_does_not_exist(): void
    {
        $subject = $this->createSubject(self::MATH_7);

        $this->withToken($this->adminToken)
            ->deleteJson(self::SUBJECTS_URL.'/'.$subject->id)
            ->assertMethodNotAllowed();

        $this->assertNotNull($subject->fresh());
    }
}
