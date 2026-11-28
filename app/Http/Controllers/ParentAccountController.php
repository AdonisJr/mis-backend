<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SearchesPaginatedLists;
use App\Http\Requests\ListParentsRequest;
use App\Http\Requests\StoreParentAccountRequest;
use App\Http\Resources\ParentAccountResource;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Support\TemporaryPasswordGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ParentAccountController extends Controller
{
    use SearchesPaginatedLists;

    private const TRANSACTION_ATTEMPTS = 3;

    private const SEARCH_COLUMNS = ['name', 'mobile_number', 'email'];

    private const CREATE_FIELDS = ['name', 'mobile_number', 'email'];

    // MySQL and PostgreSQL name the index; SQLite (tests) names the column
    private const EMAIL_VIOLATION_MARKERS = ['users_email_unique', 'users.email'];

    public const MOBILE_TAKEN_MESSAGE = 'The mobile number has already been taken.';

    public const EMAIL_TAKEN_MESSAGE = 'The email has already been taken.';

    public function listParents(ListParentsRequest $request): JsonResponse
    {
        $parentQuery = User::parentAccounts()->with('children')->orderByDesc('created_at')->orderByDesc('id');
        $this->applySearch($parentQuery, (string) $request->validated('search'));
        $parentQuery->when($request->filled('status'), fn (Builder $query) => $this->applyStatusFilter($query, $request->validated('status')));
        $parentPage = $parentQuery->paginate();

        return response()->json([
            'parents' => ParentAccountResource::collection($parentPage->getCollection()),
            'meta' => $this->buildPageMeta($parentPage),
        ]);
    }

    public function createParent(StoreParentAccountRequest $request): JsonResponse
    {
        try {
            [$parent, $plainPassword] = DB::transaction(fn () => $this->createWithinTransaction($request), self::TRANSACTION_ATTEMPTS);
        } catch (UniqueConstraintViolationException $exception) {
            // a double submit passes the unique rule twice; the index is the final guard and the mobile number is the login key
            throw $this->buildTakenException($exception);
        }

        return response()->json([
            'parent' => ParentAccountResource::make($parent),
            'temporary_password' => $plainPassword,
        ], Response::HTTP_CREATED);
    }

    public function resetPassword(int $id): JsonResponse
    {
        [$parent, $plainPassword] = DB::transaction(function () use ($id) {
            $parent = $this->lockParent($id);
            $plainPassword = TemporaryPasswordGenerator::generate();
            $parent->forceFill(['password' => $plainPassword, 'must_change_password' => true])->save();
            $parent->tokens()->delete();

            return [$parent->load('children'), $plainPassword];
        }, self::TRANSACTION_ATTEMPTS);

        return response()->json([
            'parent' => ParentAccountResource::make($parent),
            'temporary_password' => $plainPassword,
        ]);
    }

    public function deactivateParent(int $id): JsonResponse
    {
        $parent = DB::transaction(function () use ($id) {
            $parent = $this->lockParent($id);
            $parent->forceFill(['is_active' => false])->save();
            $parent->tokens()->delete();

            return $parent->load('children');
        }, self::TRANSACTION_ATTEMPTS);

        return response()->json(['parent' => ParentAccountResource::make($parent)]);
    }

    public function reactivateParent(int $id): JsonResponse
    {
        $parent = DB::transaction(function () use ($id) {
            $parent = $this->lockParent($id);
            $parent->forceFill(['is_active' => true])->save();

            return $parent->load('children');
        }, self::TRANSACTION_ATTEMPTS);

        return response()->json(['parent' => ParentAccountResource::make($parent)]);
    }

    /** @return array{User, string} */
    private function createWithinTransaction(StoreParentAccountRequest $request): array
    {
        $plainPassword = TemporaryPasswordGenerator::generate();
        $parent = User::create([...$request->safe()->only(self::CREATE_FIELDS), 'password' => $plainPassword]);
        $parent->forceFill(['must_change_password' => true])->save();
        $parent->roles()->attach(Role::firstOrCreate(['name' => Role::PARENT]));
        $this->attachInitialChild($parent, $request);

        // refresh because is_active is only a database default
        return [$parent->refresh()->load('children'), $plainPassword];
    }

    private function attachInitialChild(User $parent, StoreParentAccountRequest $request): void
    {
        if (! $request->filled('student_id')) {
            return;
        }

        $student = Student::lockForUpdate()->findOrFail($request->validated('student_id'));
        $parent->children()->attach($student->id, ['relationship' => $request->validated('relationship')]);
    }

    private function buildTakenException(UniqueConstraintViolationException $exception): ValidationException
    {
        if (Str::contains($exception->getMessage(), self::EMAIL_VIOLATION_MARKERS)) {
            return ValidationException::withMessages(['email' => self::EMAIL_TAKEN_MESSAGE]);
        }

        return ValidationException::withMessages(['mobile_number' => self::MOBILE_TAKEN_MESSAGE]);
    }

    private function applyStatusFilter(Builder $parentQuery, string $status): void
    {
        match ($status) {
            Student::ACCOUNT_STATUS_INACTIVE => $parentQuery->where('is_active', false),
            Student::ACCOUNT_STATUS_MUST_CHANGE_PASSWORD => $parentQuery->where('is_active', true)->where('must_change_password', true),
            default => $parentQuery->where('is_active', true)->where('must_change_password', false),
        };
    }

    private function lockParent(int $id): User
    {
        return User::parentAccounts()->lockForUpdate()->findOrFail($id);
    }

    protected function getSearchColumns(): array
    {
        return self::SEARCH_COLUMNS;
    }
}
