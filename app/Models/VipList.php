<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VipList extends Model
{
    protected $table = 'vip_lists';

    protected $fillable = [
        'vip_id',
        'status',
        'result',
        'created_at',
        'updated_at',
    ];

    public function vip(): BelongsTo
    {
        return $this->belongsTo(Vip::class, 'vip_id', 'id');
    }
}
