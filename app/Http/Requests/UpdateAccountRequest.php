<?php

namespace App\Http\Requests;

use App\Models\Account;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Validator;

class UpdateAccountRequest extends StoreAccountRequest
{
    public const CODE_LOCKED_MESSAGE = 'The code cannot change while the account is in use.';

    public const TYPE_LOCKED_MESSAGE = 'The type cannot change while the account is in use.';

    private const LOCKED_MESSAGES = ['code' => self::CODE_LOCKED_MESSAGE, 'type' => self::TYPE_LOCKED_MESSAGE];

    private ?Account $storedAccount = null;

    private bool $isStoredAccountLoaded = false;

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [
            ...parent::after(),
            fn (Validator $validator) => $this->rejectLockedChangesWhileUsed($validator),
        ];
    }

    protected function getUniqueCodeRule(): Unique
    {
        return parent::getUniqueCodeRule()->ignore($this->route('id'));
    }

    protected function buildSameNameQuery(): Builder
    {
        return parent::buildSameNameQuery()->whereKeyNot($this->route('id'));
    }

    private function findStoredAccount(): ?Account
    {
        if (! $this->isStoredAccountLoaded) {
            $this->storedAccount = Account::find($this->route('id'));
            $this->isStoredAccountLoaded = true;
        }

        return $this->storedAccount;
    }

    private function rejectLockedChangesWhileUsed(Validator $validator): void
    {
        $storedAccount = $this->findStoredAccount();

        // a missing account has nothing to lock; the controller answers 404 for it
        if (! $storedAccount || ! $storedAccount->isUsed()) {
            return;
        }

        foreach (array_keys(self::LOCKED_MESSAGES) as $fieldName) {
            $this->rejectChangedField($validator, $fieldName);
        }
    }

    private function rejectChangedField(Validator $validator, string $fieldName): void
    {
        if ($validator->errors()->has($fieldName) || $this->input($fieldName) === $this->findStoredAccount()->{$fieldName}) {
            return;
        }

        $validator->errors()->add($fieldName, self::LOCKED_MESSAGES[$fieldName]);
    }
}
