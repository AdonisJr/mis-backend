<?php

namespace App\Models;

use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    public const ADMIN = 'admin';

    public const REGISTRAR = 'registrar';

    public const CASHIER = 'cashier';

    public const TEACHER = 'teacher';

    public const ACCOUNTANT = 'accountant';

    public const STUDENT = 'student';

    public const PARENT = 'parent';

    public const STAFF_ROLES = [self::ADMIN, self::REGISTRAR, self::CASHIER];

    public const REGISTRY_ROLES = [self::ADMIN, self::REGISTRAR];

    public const BILLING_READ_ROLES = [self::ADMIN, self::REGISTRAR, self::CASHIER, self::ACCOUNTANT];

    public const PAYMENT_WRITE_ROLES = [self::ADMIN, self::CASHIER];

    public const ACCOUNTING_ROLES = [self::ADMIN, self::ACCOUNTANT];

    // assignable and session roles; the session group also holds the school-year, term and grade-level picker reads; teacher stays last for the route reachability test
    public const EMPLOYEE_ROLES = [self::ADMIN, self::REGISTRAR, self::CASHIER, self::ACCOUNTANT, self::TEACHER];

    // the /mobile group: students and parents
    public const MOBILE_ROLES = [self::STUDENT, self::PARENT];

    // mobile roles are seeded but kept out of every staff group
    public const SEEDED_ROLES = [...self::EMPLOYEE_ROLES, ...self::MOBILE_ROLES];

    // cashier has no academic reads
    public const CLASS_LIST_ROLES = [self::ADMIN, self::REGISTRAR, self::TEACHER];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
    ];

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }
}
