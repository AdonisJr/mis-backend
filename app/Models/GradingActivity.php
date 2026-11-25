<?php

namespace App\Models;

use Brick\Math\BigDecimal;
use Database\Factories\GradingActivityFactory;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class GradingActivity extends Model
{
    /** @use HasFactory<GradingActivityFactory> */
    use HasFactory;

    public const COMPONENT_WW = 'ww';

    public const COMPONENT_PT = 'pt';

    public const COMPONENT_QA = 'qa';

    public const COMPONENTS = [self::COMPONENT_WW, self::COMPONENT_PT, self::COMPONENT_QA];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['component', 'title', 'highest_possible_score', 'activity_date'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'class_id' => 'integer',
            'quarter' => 'integer',
            'highest_possible_score' => 'integer',
            'activity_date' => 'date:Y-m-d',
        ];
    }

    /** @return BelongsTo<SchoolClass, $this> */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /** @return HasMany<GradingScore, $this> */
    public function scores(): HasMany
    {
        return $this->hasMany(GradingScore::class);
    }

    public function findHighestRecordedScore(): ?string
    {
        return $this->scores()->max('score');
    }

    /** The highest recorded score when it is above the given highest possible score, else null. */
    public function findScoreAbove(int|string $highestPossibleScore): ?string
    {
        $highestRecordedScore = $this->findHighestRecordedScore();

        if ($highestRecordedScore === null) {
            return null;
        }

        return BigDecimal::of($highestRecordedScore)->isGreaterThan(BigDecimal::of($highestPossibleScore)) ? $highestRecordedScore : null;
    }

    /**
     * @param  Collection<int, GradingActivity>|EloquentCollection<int, GradingActivity>  $activities
     * @return Collection<int, GradingActivity>
     */
    public static function sortForRecord(Collection $activities): Collection
    {
        return $activities
            ->sortBy(fn (GradingActivity $activity) => [array_search($activity->component, self::COMPONENTS, true), $activity->id])
            ->values();
    }
}
