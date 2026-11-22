<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithClassLists;
use Tests\TestCase;

class ClassRosterAccessTest extends TestCase
{
    use InteractsWithClassLists;
    use RefreshDatabase;

    private User $classTeacher;

    private User $adviser;

    private SchoolClass $schoolClass;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClassListFixtures();
        $this->classTeacher = $this->createTeacher(['email' => 'class@school.test']);
        $this->adviser = $this->createTeacher(['email' => 'adviser@school.test']);
        $this->rizalSection->update(['adviser_id' => $this->adviser->id]);
        $this->schoolClass = $this->createJhsClass($this->classTeacher);
        $this->enrollStudent(['last_name' => self::AQUINO], ['section_id' => $this->rizalSection->id]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideClassListUrls(): array
    {
        return [
            'my classes' => [self::MY_CLASSES_URL.'?school_year_id=1'],
            'advisory section' => [self::ADVISORY_URL.'?school_year_id=1'],
            'class roster' => [self::CLASSES_URL.'/1/roster'],
        ];
    }

    #[DataProvider('provideClassListUrls')]
    public function test_cashier_gets_403(string $url): void
    {
        $this->withToken($this->createCashierToken())->getJson($url)
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);
    }

    #[DataProvider('provideClassListUrls')]
    public function test_user_without_a_role_gets_403(string $url): void
    {
        $this->withToken($this->createRoleLessToken())->getJson($url)
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);
    }

    #[DataProvider('provideClassListUrls')]
    public function test_guest_gets_401(string $url): void
    {
        $this->getJson($url)
            ->assertUnauthorized()
            ->assertExactJson(self::UNAUTHENTICATED_BODY);
    }

    public function test_class_teacher_gets_rows_without_guardian_keys(): void
    {
        $studentRow = $this->withToken($this->createTokenFor($this->classTeacher))->getJson($this->rosterUrl($this->schoolClass->id))
            ->assertOk()
            ->json('students.0');

        $this->assertSame(self::ROSTER_ROW_KEYS, array_keys($studentRow));
        $this->assertArrayNotHasKey('guardian_name', $studentRow);
        $this->assertArrayNotHasKey('guardian_relationship', $studentRow);
        $this->assertArrayNotHasKey('guardian_contact', $studentRow);
    }

    public function test_adviser_who_does_not_teach_the_class_sees_guardian_keys(): void
    {
        $studentRow = $this->withToken($this->createTokenFor($this->adviser))->getJson($this->rosterUrl($this->schoolClass->id))
            ->assertOk()
            ->json('students.0');

        $this->assertSame([...self::ROSTER_ROW_KEYS, ...self::GUARDIAN_KEYS], array_keys($studentRow));
    }

    public function test_adviser_who_also_teaches_the_class_sees_guardian_keys(): void
    {
        $this->schoolClass->update(['teacher_id' => $this->adviser->id]);

        $studentRow = $this->withToken($this->createTokenFor($this->adviser))->getJson($this->rosterUrl($this->schoolClass->id))
            ->assertOk()
            ->json('students.0');

        $this->assertSame([...self::ROSTER_ROW_KEYS, ...self::GUARDIAN_KEYS], array_keys($studentRow));
    }

    public function test_other_teacher_gets_403(): void
    {
        $this->withToken($this->createTokenFor($this->mathTeacher))->getJson($this->rosterUrl($this->schoolClass->id))
            ->assertForbidden()
            ->assertExactJson(self::CLASS_NOT_PERMITTED_BODY);
    }

    public function test_former_teacher_gets_403(): void
    {
        $this->schoolClass->update(['teacher_id' => $this->mathTeacher->id]);

        $this->withToken($this->createTokenFor($this->classTeacher))->getJson($this->rosterUrl($this->schoolClass->id))
            ->assertForbidden()
            ->assertExactJson(self::CLASS_NOT_PERMITTED_BODY);
    }

    public function test_former_adviser_gets_403(): void
    {
        $this->rizalSection->update(['adviser_id' => $this->mathTeacher->id]);

        $this->withToken($this->createTokenFor($this->adviser))->getJson($this->rosterUrl($this->schoolClass->id))
            ->assertForbidden()
            ->assertExactJson(self::CLASS_NOT_PERMITTED_BODY);
    }

    public function test_adviser_of_another_section_gets_403(): void
    {
        $this->createSection(['name' => self::MABINI, 'adviser_id' => $this->mathTeacher->id]);

        $this->withToken($this->createTokenFor($this->mathTeacher))->getJson($this->rosterUrl($this->schoolClass->id))
            ->assertForbidden()
            ->assertExactJson(self::CLASS_NOT_PERMITTED_BODY);
    }

    public function test_admin_sees_guardian_keys(): void
    {
        $studentRow = $this->withToken($this->adminToken)->getJson($this->rosterUrl($this->schoolClass->id))
            ->assertOk()
            ->json('students.0');

        $this->assertSame([...self::ROSTER_ROW_KEYS, ...self::GUARDIAN_KEYS], array_keys($studentRow));
    }

    public function test_registrar_sees_guardian_keys(): void
    {
        $studentRow = $this->withToken($this->createRegistrarToken())->getJson($this->rosterUrl($this->schoolClass->id))
            ->assertOk()
            ->json('students.0');

        $this->assertSame([...self::ROSTER_ROW_KEYS, ...self::GUARDIAN_KEYS], array_keys($studentRow));
    }
}
