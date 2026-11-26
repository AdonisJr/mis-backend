<?php

namespace App\Models;

use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    public const TYPE_ASSET = 'asset';

    public const TYPE_LIABILITY = 'liability';

    public const TYPE_EQUITY = 'equity';

    public const TYPE_REVENUE = 'revenue';

    public const TYPE_EXPENSE = 'expense';

    public const TYPES = [self::TYPE_ASSET, self::TYPE_LIABILITY, self::TYPE_EQUITY, self::TYPE_REVENUE, self::TYPE_EXPENSE];

    public const SUBTYPE_CURRENT_ASSET = 'current_asset';

    public const SUBTYPE_NON_CURRENT_ASSET = 'non_current_asset';

    public const SUBTYPE_CURRENT_LIABILITY = 'current_liability';

    public const SUBTYPE_NON_CURRENT_LIABILITY = 'non_current_liability';

    public const SUBTYPE_EQUITY = 'equity';

    public const SUBTYPE_OPERATING_REVENUE = 'operating_revenue';

    public const SUBTYPE_OTHER_INCOME = 'other_income';

    public const SUBTYPE_OPERATING_EXPENSE = 'operating_expense';

    public const SUBTYPE_OTHER_EXPENSE = 'other_expense';

    public const SUBTYPES_BY_TYPE = [
        self::TYPE_ASSET => [self::SUBTYPE_CURRENT_ASSET, self::SUBTYPE_NON_CURRENT_ASSET],
        self::TYPE_LIABILITY => [self::SUBTYPE_CURRENT_LIABILITY, self::SUBTYPE_NON_CURRENT_LIABILITY],
        self::TYPE_EQUITY => [self::SUBTYPE_EQUITY],
        self::TYPE_REVENUE => [self::SUBTYPE_OPERATING_REVENUE, self::SUBTYPE_OTHER_INCOME],
        self::TYPE_EXPENSE => [self::SUBTYPE_OPERATING_EXPENSE, self::SUBTYPE_OTHER_EXPENSE],
    ];

    public const NORMAL_BALANCE_DEBIT = 'debit';

    public const NORMAL_BALANCE_CREDIT = 'credit';

    public const USED_FLAG_ATTRIBUTE = 'journal_lines_exists';

    public const DEBIT_NORMAL_TYPES = [self::TYPE_ASSET, self::TYPE_EXPENSE];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['code', 'name', 'type', 'subtype', 'is_contra', 'description'];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['is_contra' => false, 'is_active' => true];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_contra' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function getNormalBalance(): string
    {
        $isDebitType = in_array($this->type, self::DEBIT_NORMAL_TYPES, true);

        return $isDebitType !== $this->is_contra ? self::NORMAL_BALANCE_DEBIT : self::NORMAL_BALANCE_CREDIT;
    }

    /** @return HasMany<JournalLine, $this> */
    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function isUsed(): bool
    {
        // listAccounts preloads the flag with withExists, so a list needs no query per row
        if (array_key_exists(self::USED_FLAG_ATTRIBUTE, $this->attributes)) {
            return (bool) $this->attributes[self::USED_FLAG_ATTRIBUTE];
        }

        // the seed migration's down() runs after journal_lines is dropped
        return Schema::hasTable('journal_lines') && $this->journalLines()->exists();
    }
}
