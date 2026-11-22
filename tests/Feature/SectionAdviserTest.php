<?php

namespace Tests\Feature;

use App\Http\Requests\StoreSectionRequest;
use App\Models\Role;
use App\Models\SchoolYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithRooms;
use Tests\TestCase;

class SectionAdviserTest extends TestCase
{
    use InteractsWithRooms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function provideInvalidAdviserIds(): array
    {
        return [
            'missing user' => [999],
            'text' => ['abc'],
            'boolean' => [true],
        ];
    }

    public function test_create_with_active_teacher_returns_adviser_summary(): void
    {
        $teacher = $this->createTeacher(['name' => 'Tess Teacher']);

        $this->withToken($this->adminToken)
            ->postJson(self::SECTIONS_URL, $this->buildSectionPayload(self::GRADE_7, ['name' => 'A', 'adviser_id' => $teacher->id]))
            ->assertCreated()
            ->assertJsonPath('section.adviser_id', $teacher->id)
            ->assertJsonPath('section.adviser', ['id' => $teacher->id, 'name' => 'Tess Teacher']);
    }

    public function test_create_accepts_registrar_who_is_also_teacher(): void
    {
        $registrarTeacher = User::factory()->registrar()->teacher()->create();

        $this->withToken($this->adminToken)
            ->postJson(self::SECTIONS_URL, $this->buildSectionPayload(self::GRADE_7, ['name' => 'A', 'adviser_id' => $registrarTeacher->id]))
            ->assertCreated()
            ->assertJsonPath('section.adviser_id', $registrarTeacher->id);
    }

    public function test_create_rejects_adviser_without_teacher_role(): void
    {
        $registrarOnly = User::factory()->registrar()->create();

        $this->withToken($this->adminToken)
            ->postJson(self::SECTIONS_URL, $this->buildSectionPayload(self::GRADE_7, ['name' => 'A', 'adviser_id' => $registrarOnly->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['adviser_id' => StoreSectionRequest::ADVISER_NOT_TEACHER_MESSAGE]);

        $this->assertDatabaseCount('sections', 0);
    }

    public function test_create_rejects_inactive_teacher(): void
    {
        $inactiveTeacher = $this->createTeacher(['is_active' => false]);

        $this->withToken($this->adminToken)
            ->postJson(self::SECTIONS_URL, $this->buildSectionPayload(self::GRADE_7, ['name' => 'A', 'adviser_id' => $inactiveTeacher->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['adviser_id' => StoreSectionRequest::ADVISER_NOT_TEACHER_MESSAGE]);
    }

    public function test_create_rejects_teacher_advising_another_section_same_year(): void
    {
        $teacher = $this->createTeacher();
        $this->createSection(['name' => 'Rizal', 'adviser_id' => $teacher->id]);

        $this->withToken($this->adminToken)
            ->postJson(self::SECTIONS_URL, $this->buildSectionPayload(self::GRADE_7, ['name' => 'Bonifacio', 'adviser_id' => $teacher->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['adviser_id' => sprintf(StoreSectionRequest::ADVISER_BUSY_MESSAGE, 'Rizal')]);

        $this->assertDatabaseCount('sections', 1);
    }

    public function test_create_allows_same_teacher_in_another_school_year(): void
    {
        $teacher = $this->createTeacher();
        $this->createSection(['name' => 'Rizal', 'adviser_id' => $teacher->id]);
        $otherYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);

        $this->withToken($this->adminToken)
            ->postJson(self::SECTIONS_URL, $this->buildSectionPayload(self::GRADE_7, ['name' => 'Rizal', 'school_year_id' => $otherYear->id, 'adviser_id' => $teacher->id]))
            ->assertCreated()
            ->assertJsonPath('section.adviser_id', $teacher->id);
    }

    public function test_update_resaves_own_adviser(): void
    {
        $teacher = $this->createTeacher();
        $section = $this->createSection(['name' => 'Rizal', 'adviser_id' => $teacher->id]);

        $this->withToken($this->adminToken)
            ->putJson(self::SECTIONS_URL.'/'.$section->id, $this->buildSectionPayload(self::GRADE_7, ['name' => 'Rizal', 'capacity' => 45, 'adviser_id' => $teacher->id]))
            ->assertOk()
            ->assertJsonPath('section.capacity', 45)
            ->assertJsonPath('section.adviser_id', $teacher->id);
    }

    public function test_update_keeps_unchanged_deactivated_adviser(): void
    {
        $teacher = $this->createTeacher();
        $section = $this->createSection(['name' => 'Rizal', 'adviser_id' => $teacher->id]);
        $teacher->forceFill(['is_active' => false])->save();

        $this->withToken($this->adminToken)
            ->putJson(self::SECTIONS_URL.'/'.$section->id, $this->buildSectionPayload(self::GRADE_7, ['name' => 'Rizal', 'capacity' => 45, 'adviser_id' => $teacher->id]))
            ->assertOk()
            ->assertJsonPath('section.adviser_id', $teacher->id);
    }

    public function test_update_keeps_unchanged_adviser_without_teacher_role(): void
    {
        $teacher = $this->createTeacher();
        $section = $this->createSection(['name' => 'Rizal', 'adviser_id' => $teacher->id]);
        $teacher->roles()->detach(Role::where('name', Role::TEACHER)->first());

        $this->withToken($this->adminToken)
            ->putJson(self::SECTIONS_URL.'/'.$section->id, $this->buildSectionPayload(self::GRADE_7, ['name' => 'Rizal', 'capacity' => 45, 'adviser_id' => $teacher->id]))
            ->assertOk()
            ->assertJsonPath('section.adviser_id', $teacher->id);
    }

    public function test_update_rejects_change_to_inactive_teacher(): void
    {
        $teacher = $this->createTeacher();
        $inactiveTeacher = $this->createTeacher(['is_active' => false]);
        $section = $this->createSection(['name' => 'Rizal', 'adviser_id' => $teacher->id]);

        $this->withToken($this->adminToken)
            ->putJson(self::SECTIONS_URL.'/'.$section->id, $this->buildSectionPayload(self::GRADE_7, ['name' => 'Rizal', 'adviser_id' => $inactiveTeacher->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['adviser_id' => StoreSectionRequest::ADVISER_NOT_TEACHER_MESSAGE]);

        $this->assertSame($teacher->id, $section->fresh()->adviser_id);
    }

    public function test_update_clears_adviser(): void
    {
        $teacher = $this->createTeacher();
        $section = $this->createSection(['name' => 'Rizal', 'adviser_id' => $teacher->id]);

        $this->withToken($this->adminToken)
            ->putJson(self::SECTIONS_URL.'/'.$section->id, $this->buildSectionPayload(self::GRADE_7, ['name' => 'Rizal', 'adviser_id' => null]))
            ->assertOk()
            ->assertJsonPath('section.adviser_id', null)
            ->assertJsonPath('section.adviser', null);
    }

    public function test_update_to_other_school_year_rejects_busy_adviser(): void
    {
        $teacher = $this->createTeacher();
        $otherYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);
        $this->createSection(['name' => 'Busy One', 'school_year_id' => $otherYear->id, 'adviser_id' => $teacher->id]);
        $section = $this->createSection(['name' => 'Rizal', 'adviser_id' => $teacher->id]);

        $this->withToken($this->adminToken)
            ->putJson(self::SECTIONS_URL.'/'.$section->id, $this->buildSectionPayload(self::GRADE_7, ['name' => 'Rizal', 'school_year_id' => $otherYear->id, 'adviser_id' => $teacher->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['adviser_id' => sprintf(StoreSectionRequest::ADVISER_BUSY_MESSAGE, 'Busy One')]);

        $this->assertSame($this->schoolYear->id, $section->fresh()->school_year_id);
    }

    public function test_list_shows_deactivated_adviser_summary(): void
    {
        $teacher = $this->createTeacher(['name' => 'Tess Teacher']);
        $this->createSection(['name' => 'Rizal', 'adviser_id' => $teacher->id]);
        $teacher->forceFill(['is_active' => false])->save();

        $this->withToken($this->adminToken)
            ->getJson(self::SECTIONS_URL.'?school_year_id='.$this->schoolYear->id)
            ->assertOk()
            ->assertJsonPath('sections.0.adviser', ['id' => $teacher->id, 'name' => 'Tess Teacher']);
    }

    public function test_list_filters_by_adviser(): void
    {
        $teacher = $this->createTeacher();
        $this->createSection(['name' => 'Advised', 'adviser_id' => $teacher->id]);
        $this->createSection(['name' => 'Unadvised']);

        $listResponse = $this->withToken($this->adminToken)
            ->getJson(self::SECTIONS_URL.'?school_year_id='.$this->schoolYear->id.'&adviser_id='.$teacher->id)
            ->assertOk();

        $this->assertSame(['Advised'], array_column($listResponse->json('sections'), 'name'));
    }

    public function test_list_rejects_invalid_adviser_filter(): void
    {
        $this->withToken($this->adminToken)
            ->getJson(self::SECTIONS_URL.'?school_year_id='.$this->schoolYear->id.'&adviser_id=abc')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['adviser_id']);
        $this->withToken($this->adminToken)
            ->getJson(self::SECTIONS_URL.'?school_year_id='.$this->schoolYear->id.'&adviser_id='.self::MISSING_ID)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['adviser_id']);
    }

    #[DataProvider('provideInvalidAdviserIds')]
    public function test_create_rejects_invalid_adviser_id(mixed $adviserId): void
    {
        $this->withToken($this->adminToken)
            ->postJson(self::SECTIONS_URL, $this->buildSectionPayload(self::GRADE_7, ['name' => 'A', 'adviser_id' => $adviserId]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['adviser_id']);

        $this->assertDatabaseCount('sections', 0);
    }
}
