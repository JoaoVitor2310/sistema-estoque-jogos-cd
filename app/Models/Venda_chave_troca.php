<?php

namespace App\Models;

use Carbon\Carbon;
use Database\Factories\VendaChaveTrocaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Venda_chave_troca extends Model
{
    use HasFactory;

    protected $table = 'venda_chave_trocas';

    protected $fillable = [
        'id',
        'color',
        'id_fornecedor',
        'tipo_reclamacao_id',
        'steamId',
        'idGamivo',
        'chaveRecebida',
        'repetido',
        'plataformaIdentificada',
        'nomeJogo',
        'region',
        'precoJogo',
        'notaMetacritic',
        'isSteam',
        'randomClassificationG2A',
        'randomClassificationKinguin',
        'observacao',
        'id_leilao_g2a',
        'id_leilao_gamivo',
        'id_leilao_kinguin',
        'id_plataforma',
        'precoCliente',
        'minimoParaVenda',
        'precoVenda',
        'incomeReal',
        'incomeSimulado',
        'chaveEntregue',
        'valorPagoTotal',
        'qtdTF2',
        'valorPagoIndividual',
        'vendido',
        'leiloes',
        'quantidade',
        'devolucoes',
        'lucroRS',
        'lucroPercentual',
        'valorVendido',
        'lucroVendaRS',
        'lucroVendaPercentual',
        'dataAdquirida',
        'dataVenda', // Data posto a venda
        'dataVendida',
        'dataExpiracao',
        'perfilOrigem',
        'minApiGamivo',
        'maxApiGamivo',
        'email',
    ];

    public function fornecedor()
    {
        return $this->belongsTo(Fornecedor::class, 'id_fornecedor');
    }

    public function tipoReclamacao()
    {
        return $this->belongsTo(Tipo_reclamacao::class, 'tipo_reclamacao_id');
    }

    public function tipoFormato()
    {
        return $this->belongsTo(Tipo_formato::class, 'tipo_formato_id');
    }

    public function leilaoG2A()
    {
        return $this->belongsTo(Tipo_leilao::class, 'id_leilao_g2a');
    }

    public function leilaoGamivo()
    {
        return $this->belongsTo(Tipo_leilao::class, 'id_leilao_gamivo');
    }

    public function leilaoKinguin()
    {
        return $this->belongsTo(Tipo_leilao::class, 'id_leilao_kinguin');
    }

    public function plataforma()
    {
        return $this->belongsTo(Plataforma::class, 'id_plataforma');
    }

    public function game()
    {
        return $this->belongsTo(Game::class, 'idGamivo', 'id_gamivo');
    }

    public function scopeRegisteredOnGamivo(Builder $query): void
    {
        $query->whereNotNull('idGamivo')->where('idGamivo', '!=', '');
    }

    public function scopeNotYetListed(Builder $query): void
    {
        $query->whereNull('dataVenda')->whereNull('dataVendida');
    }

    public function scopeNotGiftLink(Builder $query): void
    {
        $query->where('chaveRecebida', 'not like', '%http%');
    }

    public function scopeWithoutRecentBundle(Builder $query, int $days): void
    {
        $query->whereDoesntHave(
            'game.bundles',
            fn (Builder $b) => $b->where('bundles.release_date', '>', Carbon::now()->subDays($days))
        );
    }

    /** Sempre grava null quando região for string vazia */
    public function setRegionAttribute($value): void
    {
        $this->attributes['region'] = ($value === '' || $value === null) ? null : $value;
    }

    protected $hidden = [
        'id_fornecedor',
        'tipo_reclamacao_id',
        'tipo_formato_id',
        'id_leilao_g2a',
        'id_leilao_gamivo',
        'id_leilao_kinguin',
        'id_plataforma',
    ];

    protected static function newFactory()
    {
        return VendaChaveTrocaFactory::new();
    }
}
