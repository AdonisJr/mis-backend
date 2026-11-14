<?php

namespace Tests\Feature;

use App\Http\Controllers\StudentController;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentDiscount;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudentCrudTest extends TestCase
{
    use RefreshDatabase;

    private const STUDENTS_URL = '/api/mis/students';

    private const TOKEN_NAME = 'test';

    private const MISSING_ID = 999;

    private const ATTEMPTED_RFID = 'ABC123';

    private const VALID_PAYLOAD = [
        'lrn' => '136512340001',
        'first_name' => 'Andrea',
        'middle_name' => 'Santos',
        'last_name' => 'Dela Cruz',
        'birth_date' => '2010-05-14',
        'gender' => 'female',
        'address' => 'Purok 3, Barangay Poblacion',
        'contact' => '09181234567',
        'guardian_name' => 'Maria Dela Cruz',
        'guardian_relationship' => 'Mother',
        'guardian_contact' => '09171234567',
    ];

    private const STUDENT_KEYS = [
        'id', 'lrn', 'first_name', 'middle_name', 'last_name', 'birth_date', 'gender',
        'address', 'contact', 'guardian_name', 'guardian_relationship', 'guardian_contact', 'rfid', 'current_enrollment',
        'created_at', 'updated_at',
    ];

    private const NULLABLE_COLUMNS = ['middle_name', 'address', 'contact', 'rfid', 'user_id', 'created_at', 'updated_at'];

    private const STUDENT_COLUMNS = [
        'id', 'lrn', 'first_name', 'middle_name', 'last_name', 'birth_date', 'gender',
        'address', 'contact', 'guardian_name', 'guardian_relationship', 'guardian_contact', 'rfid', 'user_id',
        'created_at', 'updated_at',
    ];

    public function test_students_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('students', self::STUDENT_COLUMNS));
        $this->assertSame('date', Schema::getColumnType('students', 'birth_date'));

        $nullableByColumn = collect(Schema::getColumns('students'))->pluck('nullable', 'name');
        $this->assertCount(16, $nullableByColumn);

        foreach ($nullableByColumn as $columnName => $isNullable) {
            $this->assertSame(in_array($columnName, self::NULLABLE_COLUMNS, true), $isNullable, $columnName);
        }
    }

    public function test_students_table_has_unique_indexes_and_user_foreign_key(): void
    {
        $uniqueIndexNames = collect(Schema::getIndexes('students'))->where('unique', true)->pluck('name');

        foreach (['students_lrn_unique', 'students_rfid_unique', 'students_user_id_unique'] as $indexName) {
            $this->assertContains($indexName, $uniqueIndexNames);
        }

        $userForeignKey = collect(Schema::getForeignKeys('students'))->firstWhere('columns', ['user_id']);
        $this->assertNotNull($userForeignKey);
        $this->assertSame('users', $userForeignKey['foreign_table']);
        $this->assertSame(['id'], $userForeignKey['foreign_columns']);
        $this->assertSame('set null', $userForeignKey['on_delete']);
    }

    public function test_lrn_unique_index_rejects_duplicate_at_db_level(): void
    {
        Student::factory()->create(['lrn' => '136512340001']);

        $this->expectException(UniqueConstraintViolationException::class);

        Student::factory()->create(['lrn' => '136512340001']);
    }

    public function test_rfid_unique_index_rejects_duplicate_and_allows_many_nulls(): void
    {
        Student::factory()->count(3)->create();
        $this->assertSame(3, Student::whereNull('rfid')->count());

        Student::factory()->create(['rfid' => 'A1:B2:C3:D4']);

        $this->expectException(UniqueConstraintViolationException::class);

        Student::factory()->create(['rfid' => 'A1:B2:C3:D4']);
    }

    public function test_deleting_linked_user_sets_user_id_to_null(): void
    {
        $linkedUser = User::factory()->create();
        $student = Student::factory()->create(['user_id' => $linkedUser->id]);

        $linkedUser->delete();

        $this->assertNotNull($student->fresh());
        $this->assertNull($student->fresh()->user_id);
    }

    public function test_deleting_student_keeps_linked_user(): void
    {
        $linkedUser = User::factory()->create();
        $student = Student::factory()->create(['user_id' => $linkedUser->id]);

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->deleteJson(self::STUDENTS_URL.'/'.$student->id)
            ->assertNoContent();

        $this->assertNull(Student::find($student->id));
        $this->assertNotNull($linkedUser->fresh());
    }

    public function test_admin_creates_student(): void
    {
        $createResponse = $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->postJson(self::STUDENTS_URL, self::VALID_PAYLOAD)
            ->assertCreated()
            ->assertJsonPath('student.rfid', null);

        $this->assertSame(self::STUDENT_KEYS, array_keys($createResponse->json('student')));

        foreach (self::VALID_PAYLOAD as $field => $submittedValue) {
            $createResponse->assertJsonPath('student.'.$field, $submittedValue);
        }

        $createdStudent = Student::findOrFail($createResponse->json('student.id'));
        $this->assertSame('2010-05-14', $createdStudent->birth_date->toDateString());
        $this->assertSame('136512340001', $createdStudent->lrn);
    }

    public function test_create_without_optional_fields_stores_null(): void
    {
        $createResponse = $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->postJson(self::STUDENTS_URL, Arr::except(self::VALID_PAYLOAD, ['middle_name', 'address', 'contact']))
            ->assertCreated()
            ->assertJsonPath('student.middle_name', null)
            ->assertJsonPath('student.address', null)
            ->assertJsonPath('student.contact', null);

        $createdStudent = Student::findOrFail($createResponse->json('student.id'));
        $this->assertNull($createdStudent->middle_name);
        $this->assertNull($createdStudent->address);
        $this->assertNull($createdStudent->contact);
    }

    public function test_create_requires_lrn(): void
    {
        $adminToken = $this->createTokenFor(User::factory()->admin()->create());

        foreach ([Arr::except(self::VALID_PAYLOAD, 'lrn'), [...self::VALID_PAYLOAD, 'lrn' => '']] as $invalidPayload) {
            $this->withToken($adminToken)
                ->postJson(self::STUDENTS_URL, $invalidPayload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['lrn']);
        }

        $this->assertDatabaseCount('students', 0);
    }

    public function test_create_rejects_malformed_lrn(): void
    {
        $adminToken = $this->createTokenFor(User::factory()->admin()->create());

        foreach (['12345', '1365123400011', '13651234000A', '1365 1234 0001', 136512340001] as $malformedLrn) {
            $this->withToken($adminToken)
                ->postJson(self::STUDENTS_URL, [...self::VALID_PAYLOAD, 'lrn' => $malformedLrn])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['lrn']);
        }

        $this->assertDatabaseCount('students', 0);
    }

    public function test_create_keeps_leading_zero_lrn(): void
    {
        $createResponse = $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->postJson(self::STUDENTS_URL, [...self::VALID_PAYLOAD, 'lrn' => '012345678901'])
            ->assertCreated()
            ->assertJsonPath('student.lrn', '012345678901');

        $this->assertSame('012345678901', Student::findOrFail($createResponse->json('student.id'))->lrn);
    }

    public function test_create_rejects_duplicate_lrn(): void
    {
        Student::factory()->create(['lrn' => '136512340001']);

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->postJson(self::STUDENTS_URL, self::VALID_PAYLOAD)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lrn']);

        $this->assertDatabaseCount('students', 1);
    }

    public function test_create_requires_first_and_last_name(): void
    {
        $adminToken = $this->createTokenFor(User::factory()->admin()->create());
        $invalidPayloads = [
            Arr::except(self::VALID_PAYLOAD, ['first_name', 'middle_name', 'last_name']),
            [...self::VALID_PAYLOAD, 'first_name' => '', 'middle_name' => '', 'last_name' => ''],
        ];

        foreach ($invalidPayloads as $invalidPayload) {
            $this->withToken($adminToken)
                ->postJson(self::STUDENTS_URL, $invalidPayload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['first_name', 'last_name'])
                ->assertJsonMissingValidationErrors('middle_name');
        }

        $this->assertDatabaseCount('students', 0);
    }

    public function test_create_requires_birth_gender_and_guardian(): void
    {
        $adminToken = $this->createTokenFor(User::factory()->admin()->create());
        $requiredFields = ['birth_date', 'gender', 'guardian_name', 'guardian_relationship', 'guardian_contact'];

        foreach ($requiredFields as $requiredField) {
            $this->withToken($adminToken)
                ->postJson(self::STUDENTS_URL, Arr::except(self::VALID_PAYLOAD, $requiredField))
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$requiredField]);
        }

        $this->assertDatabaseCount('students', 0);
    }

    public function test_create_rejects_invalid_birth_date(): void
    {
        $adminToken = $this->createTokenFor(User::factory()->admin()->create());
        $invalidBirthDates = [
            '2010-02-30',
            '05/14/2010',
            'not-a-date',
            today()->toDateString(),
            today()->addDay()->toDateString(),
            '2999-01-01',
        ];

        foreach ($invalidBirthDates as $invalidBirthDate) {
            $this->withToken($adminToken)
                ->postJson(self::STUDENTS_URL, [...self::VALID_PAYLOAD, 'birth_date' => $invalidBirthDate])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['birth_date']);
        }

        $this->assertDatabaseCount('students', 0);
    }

    public function test_create_accepts_birth_date_yesterday(): void
    {
        $yesterday = today()->subDay()->toDateString();

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->postJson(self::STUDENTS_URL, [...self::VALID_PAYLOAD, 'birth_date' => $yesterday])
            ->assertCreated()
            ->assertJsonPath('student.birth_date', $yesterday);
    }

    public function test_create_rejects_gender_outside_its_set(): void
    {
        $adminToken = $this->createTokenFor(User::factory()->admin()->create());

        foreach (['Male', 'other'] as $invalidGender) {
            $this->withToken($adminToken)
                ->postJson(self::STUDENTS_URL, [...self::VALID_PAYLOAD, 'gender' => $invalidGender])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['gender']);
        }

        $this->assertDatabaseCount('students', 0);
    }

    public function test_create_rejects_oversized_fields(): void
    {
        $oversizedText = Str::repeat('a', 256);
        $oversizedContact = Str::repeat('0', 21);

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->postJson(self::STUDENTS_URL, [
                ...self::VALID_PAYLOAD,
                'first_name' => $oversizedText,
                'last_name' => $oversizedText,
                'address' => $oversizedText,
                'guardian_name' => $oversizedText,
                'contact' => $oversizedContact,
                'guardian_contact' => $oversizedContact,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name', 'last_name', 'address', 'guardian_name', 'contact', 'guardian_contact']);

        $this->assertDatabaseCount('students', 0);
    }

    public function test_contact_fields_accept_twenty_characters(): void
    {
        $twentyCharacterContact = Str::repeat('0', 20);

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->postJson(self::STUDENTS_URL, [
                ...self::VALID_PAYLOAD,
                'contact' => $twentyCharacterContact,
                'guardian_contact' => $twentyCharacterContact,
            ])
            ->assertCreated()
            ->assertJsonPath('student.contact', $twentyCharacterContact)
            ->assertJsonPath('student.guardian_contact', $twentyCharacterContact);
    }

    public function test_create_ignores_rfid_user_id_and_id(): void
    {
        $existingUser = User::factory()->create();

        $createResponse = $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->postJson(self::STUDENTS_URL, [
                ...self::VALID_PAYLOAD,
                'rfid' => self::ATTEMPTED_RFID,
                'user_id' => $existingUser->id,
                'id' => 5000,
            ])
            ->assertCreated()
            ->assertJsonPath('student.rfid', null);

        $this->assertNotSame(5000, $createResponse->json('student.id'));

        $createdStudent = Student::findOrFail($createResponse->json('student.id'));
        $this->assertNull($createdStudent->rfid);
        $this->assertNull($createdStudent->user_id);
    }

    public function test_admin_updates_student(): void
    {
        $student = Student::factory()->create();

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->putJson(self::STUDENTS_URL.'/'.$student->id, [...self::VALID_PAYLOAD, 'middle_name' => 'Bonifacio'])
            ->assertOk()
            ->assertJsonPath('student.id', $student->id)
            ->assertJsonPath('student.lrn', '136512340001')
            ->assertJsonPath('student.middle_name', 'Bonifacio');

        $updatedStudent = $student->fresh();
        $this->assertSame('Bonifacio', $updatedStudent->middle_name);
        $this->assertSame('2010-05-14', $updatedStudent->birth_date->toDateString());
    }

    public function test_create_ignores_grade_level_and_section(): void
    {
        $createResponse = $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->postJson(self::STUDENTS_URL, [...self::VALID_PAYLOAD, 'grade_level' => 'Grade 10', 'section' => 'Rizal'])
            ->assertCreated()
            ->assertJsonPath('student.current_enrollment', null);

        $this->assertSame(self::STUDENT_KEYS, array_keys($createResponse->json('student')));
    }

    public function test_delete_student_with_enrollments_returns_422(): void
    {
        $enrollment = Enrollment::factory()->create();

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->deleteJson(self::STUDENTS_URL.'/'.$enrollment->student_id)
            ->assertUnprocessable()
            ->assertExactJson(['message' => StudentController::ENROLLMENTS_DELETE_MESSAGE]);

        $this->assertNotNull($enrollment->student->fresh());
    }

    public function test_delete_student_with_discounts_returns_422(): void
    {
        $studentDiscount = StudentDiscount::factory()->create();

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->deleteJson(self::STUDENTS_URL.'/'.$studentDiscount->student_id)
            ->assertUnprocessable()
            ->assertExactJson(['message' => StudentController::DISCOUNTS_DELETE_MESSAGE]);

        $this->assertNotNull($studentDiscount->student->fresh());
        $this->assertNotNull($studentDiscount->fresh());
    }

    public function test_delete_student_with_enrollments_and_discounts_reports_enrollments(): void
    {
        $enrollment = Enrollment::factory()->create();
        StudentDiscount::factory()->create(['student_id' => $enrollment->student_id]);

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->deleteJson(self::STUDENTS_URL.'/'.$enrollment->student_id)
            ->assertUnprocessable()
            ->assertExactJson(['message' => StudentController::ENROLLMENTS_DELETE_MESSAGE]);
    }

    public function test_update_returns_current_enrollment(): void
    {
        $schoolYear = SchoolYear::factory()->active()->create();
        $section = Section::factory()->jhs()->create(['school_year_id' => $schoolYear->id]);
        $enrollment = Enrollment::factory()->create(['section_id' => $section->id]);

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->putJson(self::STUDENTS_URL.'/'.$enrollment->student_id, self::VALID_PAYLOAD)
            ->assertOk()
            ->assertJsonPath('student.current_enrollment', [
                'id' => $enrollment->id,
                'section_id' => $section->id,
                'term_id' => null,
                'section' => ['id' => $section->id, 'name' => $section->name],
                'grade_level' => ['id' => $section->grade_level_id, 'name' => $section->gradeLevel->name, 'stage' => $section->gradeLevel->stage],
            ]);
    }

    public function test_update_allows_own_unchanged_lrn(): void
    {
        $student = Student::factory()->create(['lrn' => '136512340001']);

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->putJson(self::STUDENTS_URL.'/'.$student->id, [...self::VALID_PAYLOAD, 'first_name' => 'Andi'])
            ->assertOk()
            ->assertJsonPath('student.lrn', '136512340001')
            ->assertJsonPath('student.first_name', 'Andi');
    }

    public function test_update_rejects_other_students_lrn(): void
    {
        Student::factory()->create(['lrn' => '136512340001']);
        $student = Student::factory()->create();

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->putJson(self::STUDENTS_URL.'/'.$student->id, self::VALID_PAYLOAD)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lrn']);

        $this->assertSame($student->lrn, $student->fresh()->lrn);
    }

    public function test_update_rejects_malformed_lrn(): void
    {
        $student = Student::factory()->create();

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->putJson(self::STUDENTS_URL.'/'.$student->id, [...self::VALID_PAYLOAD, 'lrn' => '12345'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lrn']);

        $this->assertSame($student->lrn, $student->fresh()->lrn);
    }

    public function test_update_requires_first_and_last_name(): void
    {
        $student = Student::factory()->create();

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->putJson(self::STUDENTS_URL.'/'.$student->id, [...self::VALID_PAYLOAD, 'first_name' => '', 'last_name' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name', 'last_name']);

        $this->assertSame($student->first_name, $student->fresh()->first_name);
        $this->assertSame($student->last_name, $student->fresh()->last_name);
    }

    public function test_update_rejects_invalid_birth_date(): void
    {
        $student = Student::factory()->create();
        $adminToken = $this->createTokenFor(User::factory()->admin()->create());

        foreach (['2010-02-30', today()->addDay()->toDateString()] as $invalidBirthDate) {
            $this->withToken($adminToken)
                ->putJson(self::STUDENTS_URL.'/'.$student->id, [...self::VALID_PAYLOAD, 'birth_date' => $invalidBirthDate])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['birth_date']);
        }

        $this->assertSame($student->birth_date->toDateString(), $student->fresh()->birth_date->toDateString());
    }

    public function test_update_ignores_rfid_and_user_id(): void
    {
        $studentWithCard = Student::factory()->withRfid()->create();
        $studentWithoutCard = Student::factory()->create();
        $existingUser = User::factory()->create();
        $adminToken = $this->createTokenFor(User::factory()->admin()->create());

        foreach ([self::ATTEMPTED_RFID, null] as $attemptedRfid) {
            $this->withToken($adminToken)
                ->putJson(self::STUDENTS_URL.'/'.$studentWithCard->id, [
                    ...self::VALID_PAYLOAD,
                    'rfid' => $attemptedRfid,
                    'user_id' => $existingUser->id,
                ])
                ->assertOk()
                ->assertJsonPath('student.rfid', $studentWithCard->rfid);
        }

        $this->assertSame($studentWithCard->rfid, $studentWithCard->fresh()->rfid);
        $this->assertNull($studentWithCard->fresh()->user_id);

        $this->withToken($adminToken)
            ->putJson(self::STUDENTS_URL.'/'.$studentWithoutCard->id, [
                ...self::VALID_PAYLOAD,
                'lrn' => '136512340002',
                'rfid' => self::ATTEMPTED_RFID,
            ])
            ->assertOk()
            ->assertJsonPath('student.rfid', null);

        $this->assertNull($studentWithoutCard->fresh()->rfid);
    }

    public function test_update_validates_before_lookup(): void
    {
        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->putJson(self::STUDENTS_URL.'/'.self::MISSING_ID, [...self::VALID_PAYLOAD, 'lrn' => '12345'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lrn']);
    }

    public function test_update_missing_id_returns_404(): void
    {
        config(['app.debug' => false]);

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->putJson(self::STUDENTS_URL.'/'.self::MISSING_ID, self::VALID_PAYLOAD)
            ->assertNotFound()
            ->assertExactJson(['message' => 'No query results for model ['.Student::class.'] '.self::MISSING_ID]);
    }

    public function test_admin_deletes_student(): void
    {
        $student = Student::factory()->create(['lrn' => '136512340001']);
        $adminToken = $this->createTokenFor(User::factory()->admin()->create());

        $this->withToken($adminToken)
            ->deleteJson(self::STUDENTS_URL.'/'.$student->id)
            ->assertNoContent();

        $this->assertNull(Student::find($student->id));

        $listResponse = $this->withToken($adminToken)
            ->getJson(self::STUDENTS_URL)
            ->assertOk();

        $this->assertNotContains('136512340001', collect($listResponse->json('students'))->pluck('lrn'));
    }

    public function test_delete_missing_id_returns_404(): void
    {
        config(['app.debug' => false]);

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->deleteJson(self::STUDENTS_URL.'/'.self::MISSING_ID)
            ->assertNotFound()
            ->assertExactJson(['message' => 'No query results for model ['.Student::class.'] '.self::MISSING_ID]);
    }

    public function test_non_numeric_id_returns_404(): void
    {
        $adminToken = $this->createTokenFor(User::factory()->admin()->create());

        $this->withToken($adminToken)
            ->putJson(self::STUDENTS_URL.'/abc', self::VALID_PAYLOAD)
            ->assertNotFound();

        $this->withToken($adminToken)
            ->deleteJson(self::STUDENTS_URL.'/abc')
            ->assertNotFound();
    }

    private function createTokenFor(User $user): string
    {
        return $user->createToken(self::TOKEN_NAME)->plainTextToken;
    }
}
