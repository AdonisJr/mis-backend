<?php

use App\Events\AssignRfid;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel(AssignRfid::CHANNEL_NAME, fn (User $user): bool => $user->hasAnyRole(Role::REGISTRY_ROLES));
