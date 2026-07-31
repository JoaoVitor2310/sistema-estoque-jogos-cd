<?php

namespace App\Models;

use App\Domain\Enums\FinancialMonthStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialMonth extends Model
{
    protected $table = 'financial_months';

    protected $fillable = [
        'year',
        'month',
        'status',
        'tf2_target_quantity',
        'tf2_increment',
        'tf2_price',
        'reinvestment_percent',
        'emergency_percent',
        'partner_one_share',
        'partner_one_name',
        'partner_two_name',
        'total_income',
        'total_expenses',
        'tf2_reserve',
        'base_balance',
        'reinvestment_amount',
        'emergency_amount',
        'distributable',
        'partner_one_amount',
        'partner_two_amount',
        'closed_at',
    ];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'status' => FinancialMonthStatus::class,
        'tf2_target_quantity' => 'integer',
        'tf2_increment' => 'integer',
        'tf2_price' => 'decimal:2',
        'reinvestment_percent' => 'decimal:4',
        'emergency_percent' => 'decimal:4',
        'partner_one_share' => 'decimal:4',
        'total_income' => 'decimal:2',
        'total_expenses' => 'decimal:2',
        'tf2_reserve' => 'decimal:2',
        'base_balance' => 'decimal:2',
        'reinvestment_amount' => 'decimal:2',
        'emergency_amount' => 'decimal:2',
        'distributable' => 'decimal:2',
        'partner_one_amount' => 'decimal:2',
        'partner_two_amount' => 'decimal:2',
        'closed_at' => 'datetime',
    ];

    public function movements(): HasMany
    {
        return $this->hasMany(FinancialMovement::class);
    }
}
