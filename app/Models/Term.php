<?php

namespace App\Models;

use Database\Factories\TermFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Term extends Model
{
    /** @use HasFactory<TermFactory> */
    use HasFactory;

    public const ORDINALS = [1 => '1st', 2 => '2nd', 3 => '3rd'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'term_number',
        'start_date',
        'end_date',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'term_number' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<SchoolYear, $this> */
    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    public static function buildLabel(string $termType, int $termNumber): string
    {
        return self::ORDINALS[$termNumber].' '.SchoolYear::TERM_TYPE_LABELS[$termType];
    }

    public function getLabel(): string
    {
        return self::buildLabel($this->schoolYear->term_type, $this->term_number);
    }
}
