<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SearchesPaginatedLists;
use App\Http\Requests\ListEmployeesRequest;
use App\Http\Requests\SetEmployeePasswordRequest;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class EmployeeController extends Controller
{
    use SearchesPaginatedLists;

    public const SELF_DEACTIVATE_MESSAGE = 'You cannot deactivate your own account.';

    public const LAST_ADMIN_DEACTIVATE_MESSAGE = 'The last active admin cannot be deactivated.';

    public const SELF_ADMIN_ROLE_MESSAGE = 'You cannot remove your own admin role.';

    public const LAST_ADMIN_ROLE_MESSAGE = 'The last active admin must keep the admin role.';

    private const SEARCH_COLUMNS = ['name', 'email'];

    private const CREATE_FIELDS = ['name', 'email', 'contact', 'password'];

    private const PROFILE_FIELDS = ['name', 'email', 'contact'];

    public function listEmployees(ListEmployeesRequest $request): JsonResponse
    {
        $employeeQuery = User::query()->with('roles')->orderByDesc('created_at')->orderByDesc('id');
        $this->applySearch($employeeQuery, (string) $request->validated('search'));
        $employeeQuery->when($request->filled('role'), fn (Builder $query) => $query->whereHas('roles', fn (Builder $roleQuery) => $roleQuery->where('name', $request->validated('role'))));
        $employeeQuery->when($request->filled('status'), fn (Builder $query) => $query->where('is_active', $request->validated('status') === ListEmployeesRequest::STATUS_ACTIVE));
        $employeePage = $employeeQuery->paginate();

        return response()->json([
            'employees' => EmployeeResource::collection($employeePage->getCollection()),
            'meta' => $this->buildPageMeta($employeePage),
        ]);
    }

    public function createEmployee(StoreEmployeeRequest $request): JsonResponse
    {
        $employee = DB::transaction(function () use ($request) {
            $newEmployee = User::create($request->safe()->only(self::CREATE_FIELDS));
            $newEmployee->roles()->sync($this->resolveRoleIds($request->validated('roles')));

            return $newEmployee;
        });

        return response()->json([
            'employee' => EmployeeResource::make($employee->refresh()->load('roles')),
        ], Response::HTTP_CREATED);
    }

    public function updateEmployee(UpdateEmployeeRequest $request, int $id): JsonResponse
    {
        $employee = DB::transaction(fn () => $this->updateWithinLock($request, $id));

        return response()->json([
            'employee' => EmployeeResource::make($employee),
        ]);
    }

    public function setEmployeePassword(SetEmployeePasswordRequest $request, int $id): JsonResponse
    {
        $employee = User::findOrFail($id);

        DB::transaction(function () use ($employee, $request) {
            $employee->update(['password' => $request->validated('password')]);
            $employee->tokens()->delete();
        });

        return response()->json([
            'employee' => EmployeeResource::make($employee->load('roles')),
        ]);
    }

    public function deactivateEmployee(Request $request, int $id): JsonResponse
    {
        $employee = DB::transaction(fn () => $this->deactivateWithinLock($request->user(), $id));

        return response()->json([
            'employee' => EmployeeResource::make($employee->load('roles')),
        ]);
    }

    public function reactivateEmployee(int $id): JsonResponse
    {
        $employee = User::findOrFail($id);
        $employee->forceFill(['is_active' => true])->save();

        return response()->json([
            'employee' => EmployeeResource::make($employee->load('roles')),
        ]);
    }

    protected function getSearchColumns(): array
    {
        return self::SEARCH_COLUMNS;
    }

    private function updateWithinLock(UpdateEmployeeRequest $request, int $id): User
    {
        $employee = User::lockForUpdate()->findOrFail($id);
        $this->ensureAdminRoleKept($request, $employee);
        $employee->update($request->safe()->only(self::PROFILE_FIELDS));
        $employee->roles()->sync($this->resolveRoleIds($request->validated('roles')));

        return $employee->load('roles');
    }

    private function deactivateWithinLock(User $actingAdmin, int $id): User
    {
        $employee = User::lockForUpdate()->findOrFail($id);
        $this->ensureDeactivationAllowed($actingAdmin, $employee);
        $employee->forceFill(['is_active' => false])->save();
        $employee->tokens()->delete();

        return $employee;
    }

    private function ensureAdminRoleKept(UpdateEmployeeRequest $request, User $employee): void
    {
        if (in_array(Role::ADMIN, $request->validated('roles'), true)) {
            return;
        }

        if ($employee->is($request->user())) {
            throw ValidationException::withMessages(['roles' => self::SELF_ADMIN_ROLE_MESSAGE]);
        }

        if ($this->isLastActiveAdmin($employee)) {
            throw ValidationException::withMessages(['roles' => self::LAST_ADMIN_ROLE_MESSAGE]);
        }
    }

    private function ensureDeactivationAllowed(User $actingAdmin, User $employee): void
    {
        // thrown, not returned, so the surrounding transaction rolls back
        if ($employee->is($actingAdmin)) {
            throw new HttpResponseException(response()->json(['message' => self::SELF_DEACTIVATE_MESSAGE], Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        if ($this->isLastActiveAdmin($employee)) {
            throw new HttpResponseException(response()->json(['message' => self::LAST_ADMIN_DEACTIVATE_MESSAGE], Response::HTTP_UNPROCESSABLE_ENTITY));
        }
    }

    private function isLastActiveAdmin(User $employee): bool
    {
        return $employee->is_active && $employee->hasAnyRole([Role::ADMIN]) && $this->countActiveAdmins() <= 1;
    }

    private function countActiveAdmins(): int
    {
        // rows are locked and counted in PHP: PostgreSQL rejects FOR UPDATE on an aggregate, and the lock serialises concurrent safeguard checks
        return User::where('is_active', true)->whereHas('roles', fn (Builder $roleQuery) => $roleQuery->where('name', Role::ADMIN))->lockForUpdate()->pluck('id')->count();
    }

    private function resolveRoleIds(array $roleNames): array
    {
        return Role::whereIn('name', $roleNames)->pluck('id')->all();
    }
}
