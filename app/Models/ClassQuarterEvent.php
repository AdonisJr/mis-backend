<?php

namespace App\Models;

use Database\Factories\ClassQuarterEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassQuarterEvent extends Model
{
    /** @use HasFactory<ClassQuarterEventFactory> */
    use HasFactory;

    public const ACTION_SUBMITTED = 'submitted';

    public const ACTION_REOPENED = 'reopened';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['action', 'reason'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'class_quarter_id' => 'integer',
            'user_id' => 'integer',
        ];
    }

    /** @return BelongsTo<ClassQuarter, $this> */
    public function classQuarter(): BelongsTo
    {
        return $this->belongsTo(ClassQuarter::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
