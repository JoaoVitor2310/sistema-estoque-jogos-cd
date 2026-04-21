<?php

namespace App\UseCases\Keys;

use App\Domain\Pricing\MinMaxPriceCalculator;
use App\Models\Venda_chave_troca;

/**
 * Registra uma key como "posta à venda" no Gamivo.
 *
 * Regras de negócio:
 * - Só atualiza se a key ainda não estiver listada (dataVenda IS NULL).
 * - Opcionalmente reseta minApiGamivo para o piso global de preços
 *   (MinMaxPriceCalculator::FLOOR) para ganhar visibilidade imediata na plataforma.
 */
class ListKeyForSaleUseCase
{
    /**
     * @return array{success: bool, message: string}
     */
    public function execute(string $keyCode, bool $resetMinApiGamivo = true): array
    {
        $data = ['dataVenda' => now()->toDateString()];

        if ($resetMinApiGamivo) {
            $data['minApiGamivo'] = MinMaxPriceCalculator::FLOOR;
        }

        $updated = Venda_chave_troca::where('chaveRecebida', $keyCode)
            ->whereNull('dataVenda')
            ->update($data);

        if ($updated === 0) {
            return [
                'success' => false,
                'message' => 'Nenhum registro foi atualizado. Verifique se a chave existe ou se já possui dataVenda.',
            ];
        }

        return [
            'success' => true,
            'message' => 'Data posto a venda inserida com sucesso.',
        ];
    }
}
