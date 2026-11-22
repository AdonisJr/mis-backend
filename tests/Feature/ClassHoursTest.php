<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithClasses;
use Tests\TestCase;

class ClassHoursTest extends TestCase
{
    use InteractsWithClasses;
    use RefreshDatabase;

    private const MINUTES_PER_HOUR = 60;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClassFixtures();
    }

    public function test_required_hours_come_from_the_subject(): void
    {
        $this->postClass()->assertCreated()->assertJsonPath('class.required_weekly_hours', '4.00');
    }

    public function test_scheduled_hours_are_summed_in_minutes_and_rounded_to_two_decimals(): void
    {
        $meetings = [
            $this->buildMeeting(['day' => 1, 'start_time' => '08:00', 'end_time' => '09:00']),
            $this->buildMeeting(['day' => 2, 'start_time' => '08:00', 'end_time' => '08:25']),
        ];

        $this->postClass(['meetings' => $meetings])
            ->assertCreated()
            ->assertJsonPath('class.scheduled_weekly_minutes', 85)
            ->assertJsonPath('class.scheduled_weekly_hours', '1.42');
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function provideHoursStatuses(): array
    {
        return [
            'under' => [180, SchoolClass::HOURS_UNDER],
            'exact' => [240, SchoolClass::HOURS_EXACT],
            'over' => [300, SchoolClass::HOURS_OVER],
        ];
    }

    #[DataProvider('provideHoursStatuses')]
    public function test_hours_status_compares_scheduled_with_required_minutes(int $scheduledMinutes, string $expectedStatus): void
    {
        $endTime = sprintf('%02d:00', 6 + intdiv($scheduledMinutes, self::MINUTES_PER_HOUR));

        $this->postClass(['meetings' => [$this->buildMeeting(['start_time' => '06:00', 'end_time' => $endTime])]])
            ->assertCreated()
            ->assertJsonPath('class.scheduled_weekly_minutes', $scheduledMinutes)
            ->assertJsonPath('class.hours_status', $expectedStatus);
    }

    public function test_class_without_meetings_is_under_with_zero_hours(): void
    {
        $this->postClass(['meetings' => []])
            ->assertCreated()
            ->assertJsonPath('class.scheduled_weekly_hours', '0.00')
            ->assertJsonPath('class.hours_status', SchoolClass::HOURS_UNDER);
    }
}
