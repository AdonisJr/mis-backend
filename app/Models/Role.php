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

    public const STAFF_ROLES = [self::ADMIN, self::REGISTRAR, self::CASHIER];

    public const REGISTRY_ROLES = [self::ADMIN, self::REGISTRAR];

    public const BILLING_READ_ROLES = [self::ADMIN, self::REGISTRAR, self::CASHIER];

    public const PAYMENT_WRITE_ROLES = [self::ADMIN, self::CASHIER];

    // assignable and session roles; route groups keep their own lists so teacher gains no existing access
    public const EMPLOYEE_ROLES = [self::ADMIN, self::REGISTRAR, self::CASHIER, self::TEACHER];

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
