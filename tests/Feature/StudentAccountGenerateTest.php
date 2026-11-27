<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\Feature\Concerns\InteractsWithClassLists;
use Tests\TestCase;

class StudentAccountGenerateTest extends TestCase
{
    use InteractsWithClassLists;
    use RefreshDatabase;

    private const FAILING_LRN = '100000000002';

    private const ROW_KEYS = ['student_id', 'lrn', 'name', 'temporary_password'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClassListFixtures();
        $this->schoolYear->forceFill(['is_active' => true])->save();
    }

    private function buildGenerateUrl(int $sectionId): string
    {
        return '/api/mis/sections/'.$sectionId.'/mobile-accounts';
    }

    private function enrollInRizal(string $lastName, array $studentFields = []): Student
    {
        return $this->enrollStudent(['last_name' => $lastName, ...$studentFields], ['section_id' => $this->rizalSection->id])->student;
    }

    public function test_generate_creates_accounts_for_unlinked_students_ordered_by_last_name(): void
    {
        $this->enrollInRizal(self::RAMOS);
        $this->enrollInRizal(self::AQUINO);
        $linkedStudent = $this->enrollInRizal(self::BONIFACIO);
        User::factory()->studentAccount($linkedStudent)->create();

        $generateResponse = $this->withToken($this->adminToken)->postJson($this->buildGenerateUrl($this->rizalSection->id))
            ->assertOk()
            ->assertJsonPath('created_count', 2)
            ->assertJsonPath('skipped_count', 1);

        $this->assertSame(['accounts', 'created_count', 'skipped_count'], array_keys($generateResponse->json()));
        $this->assertSame(self::ROW_KEYS, array_keys($generateResponse->json('accounts.0')));
        $this->assertSame([self::AQUINO, self::RAMOS], array_map(fn (array $row) => explode(' ', $row['name'])[2], $generateResponse->json('accounts')));
    }

    public function test_generate_skips_moved_dropped_and_other_term_students(): void
    {
        $this->enrollStudent(['last_name' => self::AQUINO], ['section_id' => $this->rizalSection->id, 'status' => Enrollment::STATUS_MOVED]);
        $this->enrollStudent(['last_name' => self::BONIFACIO], ['section_id' => $this->rizalSection->id, 'status' => Enrollment::STATUS_DROPPED]);
        Term::factory()->active()->create(['school_year_id' => $this->schoolYear->id, 'term_number' => 1]);
        $secondTerm = $this->createTerm(2);
        $this->enrollStudent(['last_name' => self::RAMOS], ['section_id' => $this->stemSection->id, 'term_id' => $secondTerm->id]);

        foreach ([$this->rizalSection, $this->stemSection] as $section) {
            $this->withToken($this->adminToken)->postJson($this->buildGenerateUrl($section->id))
                ->assertOk()
                ->assertJsonPath('created_count', 0)
                ->assertJsonPath('skipped_count', 0);
        }
    }

    public function test_section_in_an_inactive_year_yields_nothing(): void
    {
        $this->enrollInRizal(self::AQUINO);
        $this->schoolYear->forceFill(['is_active' => false])->save();
        SchoolYear::factory()->active()->create();

        $this->withToken($this->adminToken)->postJson($this->buildGenerateUrl($this->rizalSection->id))
            ->assertOk()
            ->assertJsonPath('created_count', 0)
            ->assertJsonPath('skipped_count', 0);
    }

    public function test_generate_twice_creates_no_duplicates(): void
    {
        $this->enrollInRizal(self::AQUINO);
        $this->enrollInRizal(self::RAMOS);
        $this->withToken($this->adminToken)->postJson($this->buildGenerateUrl($this->rizalSection->id))->assertOk()->assertJsonPath('created_count', 2);
        $userCount = User::count();

        $this->withToken($this->adminToken)->postJson($this->buildGenerateUrl($this->rizalSection->id))
            ->assertOk()
            ->assertJsonPath('created_count', 0)
            ->assertJsonPath('skipped_count', 2);

        $this->assertSame($userCount, User::count());
    }

    public function test_generated_passwords_are_distinct_and_stored_hashed(): void
    {
        $this->enrollInRizal(self::AQUINO);
        $this->enrollInRizal(self::RAMOS);

        $accountRows = $this->withToken($this->adminToken)->postJson($this->buildGenerateUrl($this->rizalSection->id))->assertOk()->json('accounts');

        $this->assertCount(2, array_unique(array_column($accountRows, 'temporary_password')));

        foreach ($accountRows as $accountRow) {
            $storedUser = Student::findOrFail($accountRow['student_id'])->user;
            $this->assertNotSame($accountRow['temporary_password'], $storedUser->password);
            $this->assertTrue(Hash::check($accountRow['temporary_password'], $storedUser->password));
            $this->assertTrue($storedUser->must_change_password);
            $this->assertTrue($storedUser->is_active);
        }
    }

    public function test_missing_section_returns_404(): void
    {
        $this->withToken($this->adminToken)->postJson($this->buildGenerateUrl(self::MISSING_ID))->assertNotFound();
    }

    public function test_generate_rolls_back_every_account_when_one_fails(): void
    {
        $this->enrollInRizal(self::AQUINO, ['lrn' => '100000000001']);
        $this->enrollInRizal(self::RAMOS, ['lrn' => self::FAILING_LRN]);
        $userCountBefore = User::count();
        Student::saving(fn (Student $student) => $student->lrn === self::FAILING_LRN ? throw new RuntimeException('forced') : null);

        $this->withToken($this->adminToken)->postJson($this->buildGenerateUrl($this->rizalSection->id))->assertStatus(500);

        $this->assertSame($userCountBefore, User::count());
        $this->assertSame(0, Student::whereNotNull('user_id')->count());
    }
}
