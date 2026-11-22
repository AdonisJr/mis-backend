<?php

namespace App\Models;

use Database\Factories\ClassMeetingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassMeeting extends Model
{
    /** @use HasFactory<ClassMeetingFactory> */
    use HasFactory;

    public const DAY_MONDAY = 1;

    public const DAY_SATURDAY = 6;

    public const DAY_LABELS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];

    public const SCHOOL_DAY_START = '06:00';

    public const SCHOOL_DAY_END = '18:00';

    public const MINUTE_STEP = 5;

    private const MINUTES_PER_HOUR = 60;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['day', 'start_minutes', 'end_minutes', 'room_id'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day' => 'integer',
            'start_minutes' => 'integer',
            'end_minutes' => 'integer',
            'room_id' => 'integer',
        ];
    }

    /** @return BelongsTo<SchoolClass, $this> */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public static function convertToMinutes(string $time): int
    {
        [$hours, $minutes] = explode(':', $time);

        return (int) $hours * self::MINUTES_PER_HOUR + (int) $minutes;
    }

    public static function formatMinutes(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, self::MINUTES_PER_HOUR), $minutes % self::MINUTES_PER_HOUR);
    }

    public function countMinutes(): int
    {
        return $this->end_minutes - $this->start_minutes;
    }
}
