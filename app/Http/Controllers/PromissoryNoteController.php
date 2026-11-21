<?php

namespace App\Http\Controllers;

use App\Http\Requests\CancelPromissoryNoteRequest;
use App\Http\Requests\StorePromissoryNoteRequest;
use App\Http\Resources\PromissoryNoteResource;
use App\Models\Assessment;
use App\Models\PromissoryNote;
use App\Models\Student;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class PromissoryNoteController extends Controller
{
    public const NO_BALANCE_MESSAGE = 'The student has no unpaid balance for this school year.';

    public const EXCEEDS_BALANCE_MESSAGE = 'The promised amount exceeds the remaining balance.';

    public const ACTIVE_NOTE_EXISTS_MESSAGE = 'The student already has an active promissory note for this school year.';

    public const ALREADY_CANCELLED_MESSAGE = 'The promissory note is already cancelled.';

    private const MONEY_SCALE = 2;

    public function createNote(StorePromissoryNoteRequest $request): JsonResponse
    {
        $promissoryNote = DB::transaction(fn () => $this->createWithinLock($request), PromissoryNote::TRANSACTION_ATTEMPTS);

        return response()->json([
            'promissory_note' => PromissoryNoteResource::make($promissoryNote),
        ], Response::HTTP_CREATED);
    }

    public function cancelNote(CancelPromissoryNoteRequest $request, int $id): JsonResponse
    {
        $storedNote = PromissoryNote::findOrFail($id);
        $promissoryNote = DB::transaction(fn () => $this->cancelWithinLock($request, $storedNote), PromissoryNote::TRANSACTION_ATTEMPTS);

        return response()->json([
            'promissory_note' => PromissoryNoteResource::make($promissoryNote),
        ]);
    }

    private function createWithinLock(StorePromissoryNoteRequest $request): PromissoryNote
    {
        // the student row is the lock payments, re-assess and enrollment share, so the balance and one-active checks are race-free
        $lockedStudent = Student::lockForUpdate()->findOrFail($request->validated('student_id'));
        $balance = $this->findOpenBalance($request);
        $promisedAmount = BigDecimal::of((string) $request->validated('promised_amount'))->toScale(self::MONEY_SCALE);

        if ($promisedAmount->isGreaterThan($balance)) {
            throw ValidationException::withMessages(['promised_amount' => self::EXCEEDS_BALANCE_MESSAGE]);
        }

        $hasActiveNote = $lockedStudent->promissoryNotes()->active()->where('promissory_notes.school_year_id', $request->validated('school_year_id'))->exists();

        if ($hasActiveNote) {
            throw ValidationException::withMessages(['school_year_id' => self::ACTIVE_NOTE_EXISTS_MESSAGE]);
        }

        $promissoryNote = new PromissoryNote($request->safe()->only(['promise_date', 'remarks']));
        $promissoryNote->forceFill([
            'student_id' => $lockedStudent->id,
            'school_year_id' => $request->validated('school_year_id'),
            'promised_amount' => (string) $promisedAmount,
            'granted_by' => $request->user()->id,
        ])->save();

        return $promissoryNote->load(PromissoryNote::ROW_RELATIONS)->assignYearBalance($balance);
    }

    private function findOpenBalance(StorePromissoryNoteRequest $request): BigDecimal
    {
        $currentAssessment = Assessment::current()
            ->where('assessments.student_id', $request->validated('student_id'))
            ->where('assessments.school_year_id', $request->validated('school_year_id'))
            ->with('payments')
            ->first();

        if ($currentAssessment === null || ! $currentAssessment->calculateBalance()->isPositive()) {
            throw ValidationException::withMessages(['school_year_id' => self::NO_BALANCE_MESSAGE]);
        }

        return $currentAssessment->calculateBalance();
    }

    private function cancelWithinLock(CancelPromissoryNoteRequest $request, PromissoryNote $storedNote): PromissoryNote
    {
        Student::whereKey($storedNote->student_id)->lockForUpdate()->first();
        // re-read under the lock: a second tab may have cancelled this note meanwhile
        $promissoryNote = PromissoryNote::lockForUpdate()->findOrFail($storedNote->id);

        if ($promissoryNote->isCancelled()) {
            throw ValidationException::withMessages(['promissory_note' => self::ALREADY_CANCELLED_MESSAGE]);
        }

        $promissoryNote->forceFill([
            'cancelled_at' => now(),
            'cancelled_by' => $request->user()->id,
            'cancel_reason' => $request->validated('cancel_reason'),
        ])->save();

        return $promissoryNote->load(PromissoryNote::ROW_RELATIONS);
    }
}
