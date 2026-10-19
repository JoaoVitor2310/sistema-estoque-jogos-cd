<?php

namespace App\Models;

use App\Domain\Enums\GiveawayStatus;
use App\Domain\Games\GiveawayPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Giveaway extends Model
{
    protected $table = 'giveaways';

    protected $fillable = [
        'gamerpower_id',
        'title',
        'game_name',
        'normalized_game_name',
        'store',
        'worth',
        'gamerpower_url',
        'published_date',
        'end_date',
        'status',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'worth' => 'decimal:2',
            'published_date' => 'datetime',
            'end_date' => 'datetime',
            'last_seen_at' => 'datetime',
            'status' => GiveawayStatus::class,
        ];
    }

    public function period(): GiveawayPeriod
    {
        return new GiveawayPeriod($this->status, $this->published_date, $this->last_seen_at);
    }

    /** As linhas de `games` (uma por região) que este giveaway atinge. */
    public function games(): BelongsToMany
    {
        return $this->belongsToMany(Game::class, 'giveaway_games', 'giveaway_id', 'game_id')
            ->withTimestamps();
    }
}
