<?php

namespace Tests\Feature;

use App\Http\Requests\StoreCurriculumEntryRequest;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithClassLists;
use Tests\TestCase;

class AdvisorySectionTest extends TestCase
{
    use InteractsWithClassLists;
    use RefreshDatabase;

    private User $adviser;

    private string $adviserToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClassListFixtures();
        $this->adviser = $this->createTeacher(['email' => 'adviser@school.test']);
        $this->adviserToken = $this->createTokenFor($this->adviser);
    }

    public function test_teacher_without_an_advisory_section_gets_null(): void
    {
        $this->withToken($this->adviserToken)->getJson($this->buildAdvisoryUrl(['school_year_id' => $this->schoolYear->id]))
            ->assertOk()
            ->assertExactJson(['advisory_section' => null]);
    }

    public function test_jhs_advisory_returns_section_timetable_and_roster_with_guardians(): void
    {
        $this->rizalSection->update(['adviser_id' => $this->adviser->id]);
        $this->createClassForFixtures();
        $this->createJhsEntry(self::ENGLISH_7);
        $this->enrollStudent(['last_name' => self::AQUINO], ['section_id' => $this->rizalSection->id]);
        $this->enrollStudent(['last_name' => self::BONIFACIO], ['section_id' => $this->rizalSection->id, 'status' => Enrollment::STATUS_MOVED]);

        $advisory = $this->withToken($this->adviserToken)->getJson($this->buildAdvisoryUrl(['school_year_id' => $this->schoolYear->id]))
            ->assertOk()
            ->assertJsonStructure(['advisory_section' => ['section', 'term_number', 'timetable' => ['*' => self::TIMETABLE_ROW_KEYS], 'students', 'left_the_class']])
            ->json('advisory_section');

        $this->assertSame(self::SECTION_RESOURCE_KEYS, array_keys($advisory['section']));
        $this->assertSame($this->rizalSection->id, $advisory['section']['id']);
        $this->assertNull($advisory['term_number']);
        $this->assertCount(2, $advisory['timetable']);
        $timetableClasses = array_column($advisory['timetable'], 'class');
        $this->assertCount(1, array_filter($timetableClasses));
        $this->assertCount(1, array_filter($timetableClasses, fn (?array $classRow) => $classRow === null));
        $this->assertSame([...self::ROSTER_ROW_KEYS, ...self::GUARDIAN_KEYS], array_keys($advisory['students'][0]));
        $this->assertSame([self::AQUINO], $this->pluckLastNames($advisory['students']));
        $this->assertSame([self::BONIFACIO], $this->pluckLastNames($advisory['left_the_class']));
        $this->assertSame(1, $advisory['section']['enrolled_count']);
    }

    public function test_jhs_advisory_ignores_term_number(): void
    {
        $this->rizalSection->update(['adviser_id' => $this->adviser->id]);
        $this->createClassForFixtures();
        $this->enrollStudent(['last_name' => self::AQUINO], ['section_id' => $this->rizalSection->id]);
        $withoutTerm = $this->withToken($this->adviserToken)->getJson($this->buildAdvisoryUrl(['school_year_id' => $this->schoolYear->id]))->assertOk()->json();

        $withTerm = $this->withToken($this->adviserToken)->getJson($this->buildAdvisoryUrl(['school_year_id' => $this->schoolYear->id, 'term_number' => 2]))
            ->assertOk()
            ->json();

        $this->assertSame($withoutTerm, $withTerm);
    }

    public function test_shs_advisory_with_term_returns_that_terms_timetable_and_roster(): void
    {
        $this->stemSection->update(['adviser_id' => $this->adviser->id]);
        $termOne = $this->createTerm(1);
        $termTwo = $this->createTerm(2);
        $termOneClass = $this->createShsClass($this->mathTeacher, 1);
        $this->createShsClass($this->mathTeacher, 2);
        $this->enrollStudent(['last_name' => self::AQUINO], ['section_id' => $this->stemSection->id, 'term_id' => $termOne->id]);
        $this->enrollStudent(['last_name' => self::BONIFACIO], ['section_id' => $this->stemSection->id, 'term_id' => $termTwo->id]);

        $advisory = $this->withToken($this->adviserToken)->getJson($this->buildAdvisoryUrl(['school_year_id' => $this->schoolYear->id, 'term_number' => 1]))
            ->assertOk()
            ->json('advisory_section');

        $this->assertSame(1, $advisory['term_number']);
        $this->assertCount(1, $advisory['timetable']);
        $this->assertSame($termOneClass->id, $advisory['timetable'][0]['class']['id']);
        $this->assertSame([self::AQUINO], $this->pluckLastNames($advisory['students']));
        $this->assertSame([], $advisory['left_the_class']);
    }

    public function test_shs_advisory_without_term_gets_422(): void
    {
        $this->stemSection->update(['adviser_id' => $this->adviser->id]);

        $this->withToken($this->adviserToken)->getJson($this->buildAdvisoryUrl(['school_year_id' => $this->schoolYear->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term_number' => StoreCurriculumEntryRequest::TERM_REQUIRED_MESSAGE]);
    }

    public function test_term_number_three_gets_422(): void
    {
        $this->withToken($this->adviserToken)->getJson($this->buildAdvisoryUrl(['school_year_id' => $this->schoolYear->id, 'term_number' => 3]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term_number']);
    }

    public function test_missing_school_year_gets_422(): void
    {
        $this->withToken($this->adviserToken)->getJson(self::ADVISORY_URL)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_year_id']);
    }

    public function test_reassigned_adviser_moves_access(): void
    {
        $this->rizalSection->update(['adviser_id' => $this->adviser->id]);
        $this->rizalSection->update(['adviser_id' => $this->mathTeacher->id]);
        $advisoryUrl = $this->buildAdvisoryUrl(['school_year_id' => $this->schoolYear->id]);

        $this->withToken($this->adviserToken)->getJson($advisoryUrl)->assertOk()->assertJsonPath('advisory_section', null);
        // the test app keeps the first authenticated user, so a second token needs a fresh guard
        $this->app['auth']->forgetGuards();
        $this->withToken($this->createTokenFor($this->mathTeacher))->getJson($advisoryUrl)
            ->assertOk()
            ->assertJsonPath('advisory_section.section.id', $this->rizalSection->id);
    }

    public function test_other_years_advisory_is_not_returned(): void
    {
        $otherYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);
        $otherSection = Section::factory()->jhs()->create(['school_year_id' => $otherYear->id, 'adviser_id' => $this->adviser->id]);

        $this->withToken($this->adviserToken)->getJson($this->buildAdvisoryUrl(['school_year_id' => $this->schoolYear->id]))
            ->assertOk()
            ->assertJsonPath('advisory_section', null);
        $this->withToken($this->adviserToken)->getJson($this->buildAdvisoryUrl(['school_year_id' => $otherYear->id]))
            ->assertOk()
            ->assertJsonPath('advisory_section.section.id', $otherSection->id);
    }

    public function test_admin_with_teacher_id_reads_that_teachers_advisory(): void
    {
        $this->rizalSection->update(['adviser_id' => $this->adviser->id]);

        $this->withToken($this->adminToken)->getJson($this->buildAdvisoryUrl(['school_year_id' => $this->schoolYear->id, 'teacher_id' => $this->adviser->id]))
            ->assertOk()
            ->assertJsonPath('advisory_section.section.id', $this->rizalSection->id);
    }

    public function test_registrar_with_teacher_id_reads_that_teachers_advisory(): void
    {
        $this->rizalSection->update(['adviser_id' => $this->adviser->id]);

        $this->withToken($this->createRegistrarToken())->getJson($this->buildAdvisoryUrl(['school_year_id' => $this->schoolYear->id, 'teacher_id' => $this->adviser->id]))
            ->assertOk()
            ->assertJsonPath('advisory_section.section.id', $this->rizalSection->id);
    }

    public function test_teacher_with_foreign_teacher_id_gets_403(): void
    {
        $this->withToken($this->adviserToken)->getJson($this->buildAdvisoryUrl(['school_year_id' => $this->schoolYear->id, 'teacher_id' => $this->mathTeacher->id]))
            ->assertForbidden()
            ->assertExactJson(self::FOREIGN_TEACHER_BODY);
    }
}
