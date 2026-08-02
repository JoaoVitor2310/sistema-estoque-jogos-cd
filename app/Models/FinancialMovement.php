<?php

namespace App\Models;

use App\Domain\Enums\AccountType;
use App\Domain\Enums\MovementCategory;
use App\Domain\Enums\MovementDirection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialMovement extends Model
{
    protected $table = 'financial_movements';

    protected $fillable = [
        'financial_month_id',
        'group_id',
        'account_type',
        'direction',
        'category',
        'amount',
        'description',
        'occurred_at',
        'quantity',
        'unit_price',
        'partner_slot',
        'is_generated',
    ];

    protected $casts = [
        'account_type' => AccountType::class,
        'direction' => MovementDirection::class,
        'category' => MovementCategory::class,
        'amount' => 'decimal:2',
        'occurred_at' => 'date:Y-m-d',
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'partner_slot' => 'integer',
        'is_generated' => 'boolean',
    ];

    public function financialMonth(): BelongsTo
    {
        return $this->belongsTo(FinancialMonth::class);
    }
}
