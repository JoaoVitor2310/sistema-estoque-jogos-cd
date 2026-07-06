<?php

namespace App\Models;

use App\Domain\Enums\SupplierCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use HasFactory;

    protected $table = 'suppliers';

    protected $fillable = [
        'id',
        'name',
        'steam_id',
        'url',
        'region',
        'initial_offer_pct',
        'is_added',
        'has_traded',
        'category',
    ];

    protected $casts = [
        'is_added' => 'boolean',
        'has_traded' => 'boolean',
        'initial_offer_pct' => 'integer',
        'category' => SupplierCategory::class,
    ];

    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class);
    }
}
