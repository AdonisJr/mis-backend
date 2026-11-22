<?php

namespace Tests\Feature;

use App\Http\Controllers\CurriculumEntryController;
use App\Http\Controllers\SectionController;
use App\Models\Enrollment;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Concerns\InteractsWithClasses;
use Tests\TestCase;

class ClassDeleteGuardTest extends TestCase
{
    use InteractsWithClasses;
    use RefreshDatabase;

    private const EMPLOYEES_URL = '/api/mis/employees';

    private const CURRICULUM_URL = '/api/mis/curriculum-entries';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClassFixtures();
    }

    public function test_section_with_a_class_cannot_be_deleted(): void
    {
        $this->createClassForFixtures();

        $this->withToken($this->adminToken)->deleteJson(self::SECTIONS_URL.'/'.$this->rizalSection->id)
            ->assertUnprocessable()
            ->assertExactJson(['message' => SectionController::CLASSES_DELETE_MESSAGE]);

        $this->assertDatabaseHas('sections', ['id' => $this->rizalSection->id]);
    }

    public function test_section_with_enrollments_and_classes_reports_the_enrollments_first(): void
    {
        $this->createClassForFixtures();
        Enrollment::factory()->create(['section_id' => $this->rizalSection->id]);

        $this->withToken($this->adminToken)->deleteJson(self::SECTIONS_URL.'/'.$this->rizalSection->id)
            ->assertUnprocessable()
            ->assertExactJson(['message' => SectionController::ENROLLMENTS_DELETE_MESSAGE]);
    }

    public function test_curriculum_entry_used_by_a_class_cannot_be_deleted(): void
    {
        $this->createClassForFixtures();

        $this->withToken($this->adminToken)->deleteJson(self::CURRICULUM_URL.'/'.$this->mathEntry->id)
            ->assertUnprocessable()
            ->assertExactJson(['message' => CurriculumEntryController::CLASSES_DELETE_MESSAGE]);

        $this->assertDatabaseHas('curriculum_entries', ['id' => $this->mathEntry->id]);
    }

    public function test_room_used_by_a_meeting_cannot_be_deleted_in_the_database(): void
    {
        $room = $this->createRoom(self::LAB_A);
        $this->createScheduledClass(
            ['section_id' => $this->rizalSection->id, 'curriculum_entry_id' => $this->mathEntry->id, 'teacher_id' => $this->mathTeacher->id],
            [$this->buildMeeting(['room_id' => $room->id])],
        );

        try {
            DB::table('rooms')->where('id', $room->id)->delete();
            $this->fail('The room delete should have been restricted.');
        } catch (QueryException) {
            $this->assertDatabaseHas('rooms', ['id' => $room->id]);
            $this->assertDatabaseCount('class_meetings', 1);
        }
    }

    public function test_room_has_no_delete_route(): void
    {
        $room = Room::factory()->create();

        $this->withToken($this->adminToken)->deleteJson(self::ROOMS_URL.'/'.$room->id)->assertStatus(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    public function test_teacher_of_a_class_cannot_be_deleted_in_the_database(): void
    {
        $this->createClassForFixtures();

        try {
            DB::table('users')->where('id', $this->mathTeacher->id)->delete();
            $this->fail('The teacher delete should have been restricted.');
        } catch (QueryException) {
            $this->assertDatabaseHas('users', ['id' => $this->mathTeacher->id]);
            $this->assertDatabaseCount('classes', 1);
        }
    }

    public function test_employee_has_no_delete_route(): void
    {
        $employee = User::factory()->create();

        $this->withToken($this->adminToken)->deleteJson(self::EMPLOYEES_URL.'/'.$employee->id)->assertStatus(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    public function test_section_can_be_deleted_after_its_class_is_deleted(): void
    {
        $schoolClass = $this->createClassForFixtures();

        $this->withToken($this->adminToken)->deleteJson(self::CLASSES_URL.'/'.$schoolClass->id)->assertNoContent();
        $this->withToken($this->adminToken)->deleteJson(self::SECTIONS_URL.'/'.$this->rizalSection->id)->assertNoContent();

        $this->assertDatabaseMissing('sections', ['id' => $this->rizalSection->id]);
    }
}
