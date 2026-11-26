<?php

namespace App\Models;

use Database\Factories\PostingMappingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class PostingMapping extends Model
{
    /** @use HasFactory<PostingMappingFactory> */
    use HasFactory;

    public const PURPOSE_AR_STUDENTS = 'ar_students';

    public const PURPOSE_RECEIVABLE_GOVERNMENT = 'receivable_government';

    public const PURPOSE_REVENUE_TUITION = 'revenue_tuition';

    public const PURPOSE_REVENUE_MISCELLANEOUS = 'revenue_miscellaneous';

    public const PURPOSE_REVENUE_OTHER = 'revenue_other';

    public const PURPOSE_INSTALLMENT_SURCHARGE = 'installment_surcharge';

    public const PURPOSE_SCHOOL_DISCOUNTS = 'school_discounts';

    public const PURPOSE_CASH_PAYMENT_DISCOUNT = 'cash_payment_discount';

    public const PURPOSE_CASH_CASH = 'cash_cash';

    public const PURPOSE_CASH_CHECK = 'cash_check';

    public const PURPOSE_CASH_E_WALLET = 'cash_e_wallet';

    public const PURPOSE_CASH_BANK_TRANSFER = 'cash_bank_transfer';

    public const GROUP_RECEIVABLES = 'receivables';

    public const GROUP_REVENUE = 'revenue';

    public const GROUP_DISCOUNTS_ADJUSTMENTS = 'discounts_adjustments';

    public const GROUP_CASH = 'cash';

    public const TRANSACTION_ATTEMPTS = 3;

    public const CATALOG = [
        self::PURPOSE_AR_STUDENTS => ['label' => 'AR – Students', 'group' => self::GROUP_RECEIVABLES, 'account_type' => Account::TYPE_ASSET],
        self::PURPOSE_RECEIVABLE_GOVERNMENT => ['label' => 'Receivable – Government', 'group' => self::GROUP_RECEIVABLES, 'account_type' => Account::TYPE_ASSET],
        self::PURPOSE_REVENUE_TUITION => ['label' => 'Tuition revenue', 'group' => self::GROUP_REVENUE, 'account_type' => Account::TYPE_REVENUE],
        self::PURPOSE_REVENUE_MISCELLANEOUS => ['label' => 'Miscellaneous fees revenue', 'group' => self::GROUP_REVENUE, 'account_type' => Account::TYPE_REVENUE],
        self::PURPOSE_REVENUE_OTHER => ['label' => 'Other fees revenue', 'group' => self::GROUP_REVENUE, 'account_type' => Account::TYPE_REVENUE],
        self::PURPOSE_INSTALLMENT_SURCHARGE => ['label' => 'Installment surcharge', 'group' => self::GROUP_DISCOUNTS_ADJUSTMENTS, 'account_type' => Account::TYPE_REVENUE],
        self::PURPOSE_SCHOOL_DISCOUNTS => ['label' => 'School discounts', 'group' => self::GROUP_DISCOUNTS_ADJUSTMENTS, 'account_type' => Account::TYPE_REVENUE],
        self::PURPOSE_CASH_PAYMENT_DISCOUNT => ['label' => 'Cash payment discount', 'group' => self::GROUP_DISCOUNTS_ADJUSTMENTS, 'account_type' => Account::TYPE_REVENUE],
        self::PURPOSE_CASH_CASH => ['label' => 'Cash – Cash', 'group' => self::GROUP_CASH, 'account_type' => Account::TYPE_ASSET],
        self::PURPOSE_CASH_CHECK => ['label' => 'Cash – Check', 'group' => self::GROUP_CASH, 'account_type' => Account::TYPE_ASSET],
        self::PURPOSE_CASH_E_WALLET => ['label' => 'Cash – E-wallet', 'group' => self::GROUP_CASH, 'account_type' => Account::TYPE_ASSET],
        self::PURPOSE_CASH_BANK_TRANSFER => ['label' => 'Cash – Bank transfer', 'group' => self::GROUP_CASH, 'account_type' => Account::TYPE_ASSET],
    ];

    public const PURPOSES = [
        self::PURPOSE_AR_STUDENTS,
        self::PURPOSE_RECEIVABLE_GOVERNMENT,
        self::PURPOSE_REVENUE_TUITION,
        self::PURPOSE_REVENUE_MISCELLANEOUS,
        self::PURPOSE_REVENUE_OTHER,
        self::PURPOSE_INSTALLMENT_SURCHARGE,
        self::PURPOSE_SCHOOL_DISCOUNTS,
        self::PURPOSE_CASH_PAYMENT_DISCOUNT,
        self::PURPOSE_CASH_CASH,
        self::PURPOSE_CASH_CHECK,
        self::PURPOSE_CASH_E_WALLET,
        self::PURPOSE_CASH_BANK_TRANSFER,
    ];

    public const REVENUE_PURPOSE_BY_CATEGORY = [
        FeeItem::CATEGORY_TUITION => self::PURPOSE_REVENUE_TUITION,
        FeeItem::CATEGORY_MISCELLANEOUS => self::PURPOSE_REVENUE_MISCELLANEOUS,
        FeeItem::CATEGORY_OTHER => self::PURPOSE_REVENUE_OTHER,
    ];

    public const CASH_PURPOSE_BY_METHOD = [
        Payment::METHOD_CASH => self::PURPOSE_CASH_CASH,
        Payment::METHOD_CHECK => self::PURPOSE_CASH_CHECK,
        Payment::METHOD_E_WALLET => self::PURPOSE_CASH_E_WALLET,
        Payment::METHOD_BANK_TRANSFER => self::PURPOSE_CASH_BANK_TRANSFER,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['purpose', 'account_id'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return Collection<string, Account> */
    public static function loadAccountsByPurpose(): Collection
    {
        return self::with('account')->get()->mapWithKeys(fn (self $mapping) => [$mapping->purpose => $mapping->account]);
    }

    /** @return list<array<string, mixed>> */
    public static function buildCatalogRows(): array
    {
        $accountsByPurpose = self::loadAccountsByPurpose();

        return array_map(
            fn (string $purpose) => ['purpose' => $purpose, ...self::CATALOG[$purpose], 'account' => $accountsByPurpose->get($purpose)],
            self::PURPOSES,
        );
    }
}
