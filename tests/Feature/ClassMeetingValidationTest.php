<?php

namespace Tests\Feature;

use App\Http\Requests\StoreSchoolClassRequest;
use App\Http\Requests\StoreSectionRequest;
use App\Models\ClassMeeting;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithClasses;
use Tests\TestCase;

class ClassMeetingValidationTest extends TestCase
{
    use InteractsWithClasses;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClassFixtures();
    }

    public function test_every_school_day_from_monday_to_saturday_is_accepted(): void
    {
        $meetings = array_map(fn (int $day) => $this->buildMeeting(['day' => $day]), range(1, 6));

        $this->postClass(['meetings' => $meetings])->assertCreated()->assertJsonCount(6, 'class.meetings');
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function provideInvalidDays(): array
    {
        return ['text' => ['mon'], 'zero' => [0], 'seven' => [7], 'decimal' => [1.5], 'null' => [null]];
    }

    #[DataProvider('provideInvalidDays')]
    public function test_create_rejects_invalid_day(mixed $day): void
    {
        $this->postClass(['meetings' => [$this->buildMeeting(['day' => $day])]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['meetings.0.day']);

        $this->assertDatabaseCount('classes', 0);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function provideInvalidTimeFormats(): array
    {
        return ['no leading zero' => ['8:00'], 'hour 24' => ['24:00'], 'minute 60' => ['08:60'], 'no colon' => ['0800'], 'integer' => [800], 'empty' => ['']];
    }

    #[DataProvider('provideInvalidTimeFormats')]
    public function test_create_rejects_invalid_time_format(mixed $time): void
    {
        $this->postClass(['meetings' => [$this->buildMeeting(['start_time' => $time, 'end_time' => $time])]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['meetings.0.start_time', 'meetings.0.end_time']);
    }

    public function test_create_rejects_start_off_the_five_minute_step(): void
    {
        $this->postClass(['meetings' => [$this->buildMeeting(['start_time' => '08:03'])]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['meetings.0.start_time' => StoreSchoolClassRequest::TIME_STEP_MESSAGE]);
    }

    public function test_create_rejects_end_off_the_five_minute_step(): void
    {
        $this->postClass(['meetings' => [$this->buildMeeting(['end_time' => '09:07'])]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['meetings.0.end_time' => StoreSchoolClassRequest::TIME_STEP_MESSAGE]);
    }

    public function test_create_rejects_start_before_school_day(): void
    {
        $this->postClass(['meetings' => [$this->buildMeeting(['start_time' => '05:55', 'end_time' => '07:00'])]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['meetings.0.start_time' => $this->buildBoundsMessage()]);
    }

    public function test_create_rejects_end_after_school_day(): void
    {
        $this->postClass(['meetings' => [$this->buildMeeting(['start_time' => '17:00', 'end_time' => '18:05'])]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['meetings.0.end_time' => $this->buildBoundsMessage()]);
    }

    public function test_school_day_start_boundary_is_inclusive(): void
    {
        $this->postClass(['meetings' => [$this->buildMeeting(['start_time' => '06:00', 'end_time' => '07:00'])]])
            ->assertCreated()
            ->assertJsonPath('class.meetings.0.start_time', '06:00');
    }

    public function test_school_day_end_boundary_is_inclusive(): void
    {
        $this->postClass(['meetings' => [$this->buildMeeting(['start_time' => '17:00', 'end_time' => '18:00'])]])
            ->assertCreated()
            ->assertJsonPath('class.meetings.0.end_time', '18:00');
    }

    public function test_create_rejects_end_equal_to_start(): void
    {
        $this->postClass(['meetings' => [$this->buildMeeting(['start_time' => '08:00', 'end_time' => '08:00'])]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['meetings.0.end_time' => StoreSchoolClassRequest::END_BEFORE_START_MESSAGE]);
    }

    public function test_create_rejects_end_before_start(): void
    {
        $this->postClass(['meetings' => [$this->buildMeeting(['start_time' => '09:00', 'end_time' => '08:00'])]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['meetings.0.end_time' => StoreSchoolClassRequest::END_BEFORE_START_MESSAGE]);
    }

    public function test_create_rejects_missing_meetings(): void
    {
        $payload = $this->buildClassPayload();
        unset($payload['meetings']);

        $this->withToken($this->adminToken)->postJson(self::CLASSES_URL, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['meetings']);
    }

    public function test_create_rejects_meetings_that_is_a_string(): void
    {
        $this->postClass(['meetings' => 'monday'])->assertUnprocessable()->assertJsonValidationErrors(['meetings']);
    }

    public function test_create_rejects_more_than_thirty_meetings(): void
    {
        $meetings = array_map(fn (int $day) => $this->buildMeeting(['day' => ($day % 6) + 1]), range(1, StoreSchoolClassRequest::MEETINGS_MAX + 1));

        $this->postClass(['meetings' => $meetings])->assertUnprocessable()->assertJsonValidationErrors(['meetings']);
    }

    public function test_overlapping_meetings_in_one_payload_are_rejected(): void
    {
        $meetings = [
            $this->buildMeeting(['start_time' => '08:00', 'end_time' => '09:30']),
            $this->buildMeeting(['start_time' => '09:00', 'end_time' => '10:00']),
        ];

        $this->postClass(['meetings' => $meetings])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['meetings.1' => StoreSchoolClassRequest::MEETING_OVERLAP_MESSAGE]);
    }

    public function test_touching_meetings_in_one_payload_are_accepted(): void
    {
        $meetings = [
            $this->buildMeeting(['start_time' => '08:00', 'end_time' => '09:00']),
            $this->buildMeeting(['start_time' => '09:00', 'end_time' => '10:00']),
        ];

        $this->postClass(['meetings' => $meetings])->assertCreated()->assertJsonCount(2, 'class.meetings');
    }

    public function test_null_room_takes_the_section_room(): void
    {
        $sectionRoom = $this->createRoom('Rm 201');
        $this->rizalSection->update(['room_id' => $sectionRoom->id]);

        $this->postClass()
            ->assertCreated()
            ->assertJsonPath('class.meetings.0.room_id', $sectionRoom->id)
            ->assertJsonPath('class.meetings.0.room.name', 'Rm 201');
    }

    public function test_room_less_section_stores_null_room(): void
    {
        $this->postClass()->assertCreated()->assertJsonPath('class.meetings.0.room_id', null)->assertJsonPath('class.meetings.0.room', null);

        $this->assertDatabaseHas('class_meetings', ['room_id' => null]);
    }

    public function test_explicit_active_room_is_stored(): void
    {
        $pickedRoom = $this->createRoom(self::LAB_A);

        $this->postClass(['meetings' => [$this->buildMeeting(['room_id' => $pickedRoom->id])]])
            ->assertCreated()
            ->assertJsonPath('class.meetings.0.room_id', $pickedRoom->id);

        $this->assertDatabaseHas('class_meetings', ['room_id' => $pickedRoom->id]);
    }

    public function test_create_rejects_picked_inactive_room(): void
    {
        $inactiveRoom = $this->createRoom(self::LAB_A, ['is_active' => false]);

        $this->postClass(['meetings' => [$this->buildMeeting(['room_id' => $inactiveRoom->id])]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['meetings.0.room_id' => StoreSectionRequest::ROOM_INACTIVE_MESSAGE]);
    }

    public function test_create_rejects_defaulted_inactive_section_room(): void
    {
        $inactiveRoom = $this->createRoom(self::LAB_A, ['is_active' => false]);
        $this->rizalSection->update(['room_id' => $inactiveRoom->id]);

        $this->postClass()
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['meetings.0.room_id' => StoreSectionRequest::ROOM_INACTIVE_MESSAGE]);
    }

    public function test_update_keeps_unchanged_inactive_room(): void
    {
        $storedRoom = $this->createRoom(self::LAB_A);
        $schoolClass = $this->createScheduledClass(
            ['section_id' => $this->rizalSection->id, 'curriculum_entry_id' => $this->mathEntry->id, 'teacher_id' => $this->mathTeacher->id],
            [$this->buildMeeting(['room_id' => $storedRoom->id])],
        );
        $storedRoom->update(['is_active' => false]);

        $this->putClass($schoolClass->id, [
            'teacher_id' => $this->mathTeacher->id,
            'meetings' => [$this->buildMeeting(['room_id' => $storedRoom->id])],
        ])->assertOk()->assertJsonPath('class.meetings.0.room_id', $storedRoom->id);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function provideInvalidRoomIds(): array
    {
        return ['unknown' => [999], 'text' => ['abc']];
    }

    #[DataProvider('provideInvalidRoomIds')]
    public function test_create_rejects_invalid_room_id(mixed $roomId): void
    {
        $this->postClass(['meetings' => [$this->buildMeeting(['room_id' => $roomId])]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['meetings.0.room_id']);

        $this->assertSame(0, Room::where('id', 999)->count());
        $this->assertSame(0, ClassMeeting::count());
    }

    private function buildBoundsMessage(): string
    {
        return sprintf(StoreSchoolClassRequest::TIME_BOUNDS_MESSAGE, ClassMeeting::SCHOOL_DAY_START, ClassMeeting::SCHOOL_DAY_END);
    }
}
