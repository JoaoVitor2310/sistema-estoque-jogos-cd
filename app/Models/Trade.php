<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Trade extends Model
{
    protected $table = 'trades';

    protected $fillable = ['supplier_id', 'list_code', 'last_commented_at', 'title', 'date', 'tf2_qty', 'games'];

    protected $casts = [
        'games' => 'array',
        'last_commented_at' => 'datetime',
        'date' => 'date',
        'tf2_qty' => 'decimal:2',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
