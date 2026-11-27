<?php

namespace Tests\Feature;

use App\Http\Requests\UpdateStudentRfidRequest;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StudentRfidTest extends TestCase
{
    use RefreshDatabase;

    private const RFID_URL = '/api/mis/students/rfid';

    private const TOKEN_NAME = 'test';

    private const MISSING_ID = 999;

    private const UID = '04:A1:B2:C3';

    private const STUDENT_KEYS = [
        'id', 'lrn', 'first_name', 'middle_name', 'last_name', 'birth_date', 'gender',
        'address', 'contact', 'guardian_name', 'guardian_relationship', 'guardian_contact', 'rfid', 'current_enrollment',
        'created_at', 'updated_at',
    ];

    private const ANDREA = [
        'lrn' => '136512340001',
        'first_name' => 'Andrea',
        'middle_name' => 'Santos',
        'last_name' => 'Dela Cruz',
    ];

    private const MIGUEL = [
        'lrn' => '136512340002',
        'first_name' => 'Miguel',
        'middle_name' => null,
        'last_name' => 'Reyes',
    ];

    public function test_assign_stores_the_normalised_uid_and_returns_the_student(): void
    {
        $student = Student::factory()->create(self::ANDREA);

        $response = $this->assignRfid($student->id, ' 04:a1:b2:c3 ', User::factory()->registrar()->create())
            ->assertOk()
            ->assertJsonPath('student.id', $student->id)
            ->assertJsonPath('student.rfid', self::UID);

        $this->assertSame(self::STUDENT_KEYS, array_keys($response->json('student')));
        $this->assertSame(self::UID, DB::table('students')->where('id', $student->id)->value('rfid'));
    }

    public function test_assign_replaces_the_existing_card(): void
    {
        $student = Student::factory()->withRfid()->create();
        $oldUid = $student->rfid;

        $this->assignRfid($student->id, self::UID)
            ->assertOk()
            ->assertJsonPath('student.rfid', self::UID);

        $this->assertDatabaseMissing('students', ['rfid' => $oldUid]);
        $this->assertDatabaseHas('students', ['id' => $student->id, 'rfid' => self::UID]);
    }

    public function test_assign_card_of_another_student_returns_422_naming_the_holder(): void
    {
        $holder = Student::factory()->create([...self::ANDREA, 'rfid' => self::UID]);
        $student = Student::factory()->create(self::MIGUEL);

        $this->assignRfid($student->id, self::UID)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rfid'])
            ->assertJsonPath('errors.rfid.0', sprintf(UpdateStudentRfidRequest::CARD_TAKEN_MESSAGE, 'Andrea Santos Dela Cruz', '136512340001'));

        $this->assertSame(self::UID, $holder->fresh()->rfid);
        $this->assertNull($student->fresh()->rfid);
    }

    public function test_assign_conflict_ignores_case_and_whitespace(): void
    {
        Student::factory()->create([...self::ANDREA, 'rfid' => self::UID]);
        $student = Student::factory()->create(self::MIGUEL);

        $this->assignRfid($student->id, '  04:a1:b2:c3')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rfid']);

        $this->assertNull($student->fresh()->rfid);
    }

    public function test_assign_same_card_to_same_student_is_a_no_op(): void
    {
        $student = Student::factory()->create([...self::ANDREA, 'rfid' => self::UID]);
        $updatedAt = $student->fresh()->updated_at;

        $this->travel(1)->minute();

        $this->assignRfid($student->id, ' 04:a1:b2:c3 ')
            ->assertOk()
            ->assertJsonPath('student.rfid', self::UID);

        $this->assertTrue($updatedAt->equalTo($student->fresh()->updated_at));
    }

    public function test_assign_returns_404_for_a_missing_student(): void
    {
        config(['app.debug' => false]);

        $this->assignRfid(self::MISSING_ID, self::UID)
            ->assertNotFound()
            ->assertExactJson(['message' => 'No query results for model ['.Student::class.'] '.self::MISSING_ID]);
    }

    public function test_assign_rejects_missing_or_invalid_rfid(): void
    {
        $student = Student::factory()->create();
        $token = $this->createTokenFor(User::factory()->admin()->create());

        foreach ([[], ['rfid' => null], ['rfid' => ['x']], ['rfid' => '   '], ['rfid' => Str::repeat('A', 256)]] as $body) {
            $this->withToken($token)
                ->putJson(self::RFID_URL.'/'.$student->id, $body)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['rfid']);
        }

        $this->assertNull($student->fresh()->rfid);
    }

    public function test_assign_race_on_the_unique_index_returns_422_naming_the_holder(): void
    {
        $holder = Student::factory()->create(self::ANDREA);
        $student = Student::factory()->create(self::MIGUEL);
        $isArmed = true;

        // the card is given to another row just before the write, after the request check has passed
        Student::updating(function (Student $updatingStudent) use ($holder, &$isArmed) {
            if ($isArmed && $updatingStudent->isDirty('rfid')) {
                $isArmed = false;
                DB::table('students')->where('id', $holder->id)->update(['rfid' => self::UID]);
            }
        });

        $this->assignRfid($student->id, self::UID)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rfid'])
            ->assertJsonPath('errors.rfid.0', sprintf(UpdateStudentRfidRequest::CARD_TAKEN_MESSAGE, 'Andrea Santos Dela Cruz', '136512340001'));

        $isArmed = false;
        $this->assertNull($student->fresh()->rfid);
        $this->assertSame(self::UID, $holder->fresh()->rfid);
    }

    public function test_unassign_clears_the_card_and_returns_the_student(): void
    {
        $student = Student::factory()->create([...self::ANDREA, 'rfid' => self::UID]);

        $response = $this->unassignRfid($student->id)
            ->assertOk()
            ->assertJsonPath('student.id', $student->id)
            ->assertJsonPath('student.rfid', null);

        $this->assertSame(self::STUDENT_KEYS, array_keys($response->json('student')));
        $this->assertNull($student->fresh()->rfid);
    }

    public function test_unassign_without_a_card_is_a_no_op(): void
    {
        $student = Student::factory()->create();
        $updatedAt = $student->fresh()->updated_at;

        $this->travel(1)->minute();

        $this->unassignRfid($student->id)
            ->assertOk()
            ->assertJsonPath('student.rfid', null);

        $this->assertTrue($updatedAt->equalTo($student->fresh()->updated_at));
    }

    public function test_unassign_returns_404_for_a_missing_student(): void
    {
        config(['app.debug' => false]);

        $this->unassignRfid(self::MISSING_ID)
            ->assertNotFound()
            ->assertExactJson(['message' => 'No query results for model ['.Student::class.'] '.self::MISSING_ID]);
    }

    public function test_unassigned_card_can_be_assigned_to_another_student(): void
    {
        $firstStudent = Student::factory()->create([...self::ANDREA, 'rfid' => self::UID]);
        $secondStudent = Student::factory()->create(self::MIGUEL);

        $this->unassignRfid($firstStudent->id)->assertOk();
        $this->assignRfid($secondStudent->id, self::UID)
            ->assertOk()
            ->assertJsonPath('student.rfid', self::UID);

        $this->assertNull($firstStudent->fresh()->rfid);
        $this->assertSame(self::UID, $secondStudent->fresh()->rfid);
    }

    private function assignRfid(int $studentId, string $rfid, ?User $user = null): TestResponse
    {
        return $this->withToken($this->createTokenFor($user ?? User::factory()->admin()->create()))
            ->putJson(self::RFID_URL.'/'.$studentId, ['rfid' => $rfid]);
    }

    private function unassignRfid(int $studentId): TestResponse
    {
        return $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->deleteJson(self::RFID_URL.'/'.$studentId);
    }

    private function createTokenFor(User $user): string
    {
        return $user->createToken(self::TOKEN_NAME)->plainTextToken;
    }
}
