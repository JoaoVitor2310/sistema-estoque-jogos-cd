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
        'reinvestment_percent',
        'emergency_percent',
        'partner_one_share',
        'closed_at',
    ];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'status' => FinancialMonthStatus::class,
        'reinvestment_percent' => 'decimal:4',
        'emergency_percent' => 'decimal:4',
        'partner_one_share' => 'decimal:4',
        'closed_at' => 'datetime',
    ];

    public function movements(): HasMany
    {
        return $this->hasMany(FinancialMovement::class);
    }
}
