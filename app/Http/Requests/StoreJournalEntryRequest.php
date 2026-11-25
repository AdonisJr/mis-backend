<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Support\JournalPosting;
use Brick\Math\BigDecimal;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreJournalEntryRequest extends FormRequest
{
    // the largest value the decimal(12, 2) columns hold, so an overflow is a 422 and not a 500
    public const AMOUNT_MAX = '999999999.99';

    public const ONE_SIDE_MESSAGE = 'Enter an amount on exactly one side, debit or credit.';

    private const ZERO_AMOUNT = '0';

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $amountRules = ['bail', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::AMOUNT_MAX];

        return [
            'entry_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'description' => ['required', 'string', 'max:500'],
            'reference' => ['nullable', 'string', 'max:100'],
            'lines' => ['required', 'array', 'list', 'min:'.JournalEntry::MIN_LINES, 'max:'.JournalEntry::MAX_LINES],
            'lines.*' => ['array'],
            'lines.*.account_id' => StoreSectionRequest::buildIdRules('required', Account::class),
            'lines.*.debit' => $amountRules,
            'lines.*.credit' => $amountRules,
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->rejectInactiveAccounts($validator),
            fn (Validator $validator) => $this->rejectTwoSidedLines($validator),
        ];
    }

    protected function isLineUnchanged(array $line): bool
    {
        return false;
    }

    private function rejectInactiveAccounts(Validator $validator): void
    {
        $hasLineError = collect($validator->errors()->keys())->contains(fn (string $errorKey) => str_starts_with($errorKey, 'lines'));

        if ($hasLineError) {
            return;
        }

        $submittedLines = $this->input('lines');
        $inactiveIds = Account::query()->whereIn('id', array_column($submittedLines, 'account_id'))->where('is_active', false)->pluck('id');

        foreach ($submittedLines as $lineIndex => $submittedLine) {
            $isInactive = $inactiveIds->contains((int) $submittedLine['account_id']);

            if ($isInactive && ! $this->isLineUnchanged($submittedLine)) {
                $validator->errors()->add('lines.'.$lineIndex.'.account_id', JournalPosting::ACCOUNT_INACTIVE_MESSAGE);
            }
        }
    }

    private function rejectTwoSidedLines(Validator $validator): void
    {
        if ($validator->errors()->has('lines')) {
            return;
        }

        foreach (array_keys($this->input('lines')) as $lineIndex) {
            $this->rejectTwoSidedLine($validator, $lineIndex);
        }
    }

    private function rejectTwoSidedLine(Validator $validator, int $lineIndex): void
    {
        $linePath = 'lines.'.$lineIndex;

        if ($validator->errors()->hasAny([$linePath, $linePath.'.debit', $linePath.'.credit'])) {
            return;
        }

        $hasDebit = BigDecimal::of($this->input($linePath.'.debit') ?? self::ZERO_AMOUNT)->isPositive();
        $hasCredit = BigDecimal::of($this->input($linePath.'.credit') ?? self::ZERO_AMOUNT)->isPositive();

        if ($hasDebit !== $hasCredit) {
            return;
        }

        $validator->errors()->add($linePath.'.debit', self::ONE_SIDE_MESSAGE);
    }
}
