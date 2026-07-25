<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Trade extends Model
{
    protected $table = 'trades';

    protected $fillable = ['supplier_id', 'list_code', 'last_commented_at', 'title', 'date', 'message_sent', 'is_imported', 'tf2_qty', 'games'];

    protected $casts = [
        'games' => 'array',
        'message_sent' => 'boolean',
        'is_imported' => 'boolean',
        'last_commented_at' => 'datetime',
        'date' => 'date',
        'tf2_qty' => 'decimal:2',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function keys(): HasMany
    {
        return $this->hasMany(Key::class);
    }
}
