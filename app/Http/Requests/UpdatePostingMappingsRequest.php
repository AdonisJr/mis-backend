<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\PostingMapping;
use App\Support\JournalPosting;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;

class UpdatePostingMappingsRequest extends FormRequest
{
    public const TYPE_MISMATCH_FORMAT = 'The account must be of type %s for this purpose.';

    /** @var Collection<int, Account> */
    private Collection $submittedAccounts;

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
        $accountRules = collect(PostingMapping::PURPOSES)
            ->mapWithKeys(fn (string $purpose) => ['mappings.'.$purpose => StoreSectionRequest::buildIdRules('sometimes', Account::class)])
            ->all();

        return ['mappings' => ['required', 'array', 'min:1', 'array:'.implode(',', PostingMapping::PURPOSES)], ...$accountRules];
    }

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->rejectUnfitAccounts($validator),
        ];
    }

    private function rejectUnfitAccounts(Validator $validator): void
    {
        if ($validator->errors()->has('mappings')) {
            return;
        }

        $accountIdsByPurpose = $this->input('mappings');
        // ids with a field error are skipped: a malformed id must never reach the query
        $validIds = collect($accountIdsByPurpose)->reject(fn ($accountId, string $purpose) => $validator->errors()->has('mappings.'.$purpose))->values();
        $this->submittedAccounts = Account::whereIn('id', $validIds)->get()->keyBy('id');

        foreach (array_keys($accountIdsByPurpose) as $purpose) {
            $this->rejectUnfitAccount($validator, $purpose);
        }
    }

    private function rejectUnfitAccount(Validator $validator, string $purpose): void
    {
        $field = 'mappings.'.$purpose;

        if ($validator->errors()->has($field)) {
            return;
        }

        $account = $this->submittedAccounts->get((int) $this->input($field));
        $requiredType = PostingMapping::CATALOG[$purpose]['account_type'];

        if (! $account->is_active) {
            $validator->errors()->add($field, JournalPosting::ACCOUNT_INACTIVE_MESSAGE);
        } elseif ($account->type !== $requiredType) {
            $validator->errors()->add($field, sprintf(self::TYPE_MISMATCH_FORMAT, $requiredType));
        }
    }
}
