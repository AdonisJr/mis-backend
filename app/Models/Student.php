<?php

namespace App\Models;

use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    /** @use HasFactory<StudentFactory> */
    use HasFactory;

    public const GENDERS = ['male', 'female'];

    public const GRADE_LEVELS = [
        'Grade 7',
        'Grade 8',
        'Grade 9',
        'Grade 10',
        'Grade 11',
        'Grade 12',
        '1st Year College',
        '2nd Year College',
        '3rd Year College',
        '4th Year College',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'lrn',
        'first_name',
        'middle_name',
        'last_name',
        'birth_date',
        'gender',
        'grade_level',
        'section',
        'address',
        'contact',
        'guardian_name',
        'guardian_relationship',
        'guardian_contact',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
        ];
    }
}
