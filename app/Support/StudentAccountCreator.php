<?php

namespace App\Support;

use App\Models\Role;
use App\Models\Student;
use App\Models\User;

class StudentAccountCreator
{
    public function __construct(private readonly Student $student) {}

    /**
     * The caller holds the student row lock and the transaction. Returns the plain temporary password.
     */
    public function create(): string
    {
        $plainPassword = TemporaryPasswordGenerator::generate();
        $user = User::create(['name' => $this->student->buildFullName(), 'email' => null, 'password' => $plainPassword]);
        $user->forceFill(['must_change_password' => true])->save();
        $user->roles()->attach(Role::firstOrCreate(['name' => Role::STUDENT]));
        $this->student->forceFill(['user_id' => $user->id])->save();
        // is_active is only a database default, so the in-memory model needs the stored value
        $this->student->setRelation('user', $user->refresh());

        return $plainPassword;
    }
}
