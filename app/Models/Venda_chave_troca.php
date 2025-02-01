<?php

namespace App\Models;

use Database\Factories\VendaChaveTrocaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Fornecedor;
use App\Models\Tipo_reclamacao;
use App\Models\Tipo_formato;
use App\Models\Tipo_leilao;
use App\Models\Plataforma;

class Venda_chave_troca extends Model
{
    use HasFactory;

    protected $table = 'venda_chave_trocas';
    protected $fillable = [
        "id",
        "color",
        "id_fornecedor",
        "tipo_reclamacao_id",
        "steamId",
        "idGamivo",
        "chaveRecebida",
        "repetido",
        "plataformaIdentificada",
        "nomeJogo",
        "precoJogo",
        "notaMetacritic",
        "isSteam",
        "randomClassificationG2A",
        "randomClassificationKinguin",
        "observacao",
        "id_leilao_g2a",
        "id_leilao_gamivo",
        "id_leilao_kinguin",
        "id_plataforma",
        "precoCliente",
        "minimoParaVenda",
        "precoVenda",
        "incomeReal",
        "incomeSimulado",
        "chaveEntregue",
        "valorPagoTotal",
        "qtdTF2",
        "valorPagoIndividual",
        "vendido",
        "leiloes",
        "quantidade",
        "devolucoes",
        "lucroRS",
        "lucroPercentual",
        "valorVendido",
        "lucroVendaRS",
        "lucroVendaPercentual",
        "dataAdquirida",
        "dataVenda",
        "dataVendida",
        "perfilOrigem",
        "minApiGamivo",
        "maxApiGamivo",
        "email"
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

    protected $hidden = [
        'id_fornecedor',
        'tipo_reclamacao_id',
        'tipo_formato_id',
        'id_leilao_g2a',
        'id_leilao_gamivo',
        'id_leilao_kinguin',
        'id_plataforma'
    ];

    protected static function newFactory()
    {
        return VendaChaveTrocaFactory::new();
    }
}
