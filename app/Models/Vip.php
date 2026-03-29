<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Vip extends Model
{
    protected $table = 'vips';

    protected $fillable = [
        'name',
        'id_steam',
    ];

    public function list(): HasOne
    {
        return $this->hasOne(VipList::class, 'vip_id', 'id');
    }
}
