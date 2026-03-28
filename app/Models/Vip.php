<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Vip extends Model
{
    protected $table = 'vips';

    protected $fillable = [
        'name',
        'first_link',
        'second_link',
        'third_link',
        'steam_link',
        'result',
        'result_at',
    ];

    protected $casts = [
        'result_at' => 'datetime',
    ];

    public function getListLinks(): array
    {
        $links = [];
        if ($this->first_link) $links[] = $this->first_link;
        if ($this->second_link) $links[] = $this->second_link;
        if ($this->third_link) $links[] = $this->third_link;
        return $links ?? [];
    }

    public function list(): HasOne
    {
        return $this->hasOne(VipList::class, 'vip_id', 'id');
    }
}
