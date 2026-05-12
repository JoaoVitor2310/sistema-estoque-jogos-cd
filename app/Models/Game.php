<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Game extends Model
{
    use HasFactory;

    protected $table = 'games';

    protected $fillable = [
        'id',
        'name',
        'region',
        'gamivo_id',
        'steam_id',
        'popularity',
        'price_tf2',
        'price_euro',
        'release_date',
    ];

    public function bundles(): BelongsToMany
    {
        return $this->belongsToMany(Bundle::class, 'bundle_games', 'game_id', 'bundle_id')
            ->using(BundleGame::class)
            ->withPivot('bundle_launch_price')
            ->withTimestamps();
    }

    /**
     * Último bundle do jogo (pelo release_date mais recente).
     *
     * Constrói um HasOne manualmente (hasOne() auto-qualificaria o FK como
     * "bundles.game_id", que não existe — o game_id vem da pivot bundle_games).
     *
     * Usa subconsulta correlacionada para filtrar apenas o bundle com o maior
     * release_date por jogo, sem depender de ordenação de resultados — abordagem
     * determinística mesmo quando o eager load reconstrói a query internamente.
     */
    public function latestBundle(): HasOne
    {
        $query = Bundle::query()
            ->join('bundle_games', 'bundles.id', '=', 'bundle_games.bundle_id')
            ->select('bundles.*', 'bundle_games.game_id')
            ->whereRaw('bundles.release_date = (
                SELECT MAX(b2.release_date)
                FROM bundles b2
                INNER JOIN bundle_games bg2 ON b2.id = bg2.bundle_id
                WHERE bg2.game_id = bundle_games.game_id
            )');

        return new HasOne($query, $this, 'game_id', 'id');
    }

    /** Sempre grava null quando região for string vazia */
    public function setRegionAttribute($value): void
    {
        $this->attributes['region'] = ($value === '' || $value === null) ? null : $value;
    }
}
