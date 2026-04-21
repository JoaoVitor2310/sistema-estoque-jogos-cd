<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representação completa de uma Venda_chave_troca para endpoints de CRUD.
 *
 * Inclui todas as colunas operacionais e relações carregadas (whenLoaded).
 * Campos marcados para remoção no schema (precoVenda, incomeReal, etc.)
 * são mantidos aqui até a Fase Futura 0 (rename/drop de colunas),
 * para não quebrar o frontend durante a transição.
 */
class KeyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // Identificação
            'id'                          => $this->id,
            'nomeJogo'                    => $this->nomeJogo,
            'region'                      => $this->region,
            'chaveRecebida'               => $this->chaveRecebida,
            'plataformaIdentificada'      => $this->plataformaIdentificada,
            'idGamivo'                    => $this->idGamivo,
            'steamId'                     => $this->steamId,
            'repetido'                    => $this->repetido,
            'color'                       => $this->color,
            'observacao'                  => $this->observacao,
            'email'                       => $this->email,

            // Precificação
            'precoCliente'                => $this->precoCliente,
            'valorPagoIndividual'         => $this->valorPagoIndividual,
            'valorPagoTotal'              => $this->valorPagoTotal,
            'qtdTF2'                      => $this->qtdTF2,
            'incomeSimulado'              => $this->incomeSimulado,
            'lucroRS'                     => $this->lucroRS,
            'lucroPercentual'             => $this->lucroPercentual,
            'minimoParaVenda'             => $this->minimoParaVenda,
            'minApiGamivo'                => $this->minApiGamivo,
            'maxApiGamivo'                => $this->maxApiGamivo,
            'valorVendido'                => $this->valorVendido,
            'lucroVendaRS'                => $this->lucroVendaRS,
            'lucroVendaPercentual'        => $this->lucroVendaPercentual,

            // Fornecedor
            'perfilOrigem'                => $this->perfilOrigem,

            // Datas
            'dataAdquirida'               => $this->dataAdquirida,
            'dataVenda'                   => $this->dataVenda,
            'dataVendida'                 => $this->dataVendida,
            'dataExpiracao'               => $this->dataExpiracao,

            // Relações (incluídas apenas quando eager-loaded)
            'fornecedor'                  => $this->whenLoaded('fornecedor'),
            'tipoReclamacao'              => $this->whenLoaded('tipoReclamacao'),
            'tipoFormato'                 => $this->whenLoaded('tipoFormato'),
            'leilaoG2A'                   => $this->whenLoaded('leilaoG2A'),
            'leilaoGamivo'                => $this->whenLoaded('leilaoGamivo'),
            'leilaoKinguin'               => $this->whenLoaded('leilaoKinguin'),
            'plataforma'                  => $this->whenLoaded('plataforma'),
        ];
    }
}
