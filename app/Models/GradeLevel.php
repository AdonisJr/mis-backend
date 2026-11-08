<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GradeLevel extends Model
{
    public const STAGE_JHS = 'jhs';

    public const STAGE_SHS = 'shs';

    public const STAGE_COLLEGE = 'college';

    public const STAGES = [self::STAGE_JHS, self::STAGE_SHS, self::STAGE_COLLEGE];

    public const PROGRAM_STAGES = [self::STAGE_SHS, self::STAGE_COLLEGE];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'sort_order',
        'stage',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }
}
