<?php

namespace App\UseCases\Keys;

use App\Domain\Platform\PlatformIdentifier;
use App\Models\Key;
use App\Services\Games\GameService;
use App\Services\Keys\KeyCalculationService;
use App\Services\Keys\KeyRepository;
use App\Services\Suppliers\SupplierService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Orquestra a atualização de uma key existente.
 *
 * Diferenças em relação ao RegisterKeyUseCase:
 *  - Não cria o jogo na tabela games (já deve existir)
 *  - Verifica duplicidade excluindo o próprio registro
 *
 * Entre os campos editáveis, apenas o market_price alimenta as fórmulas de custo
 * e lucro; as demais edições persistem só os campos alterados, sem recalcular
 * nada. Quando o market_price muda, o somatório de incomes do lote muda e o
 * rateio de individual_cost de TODA a trade precisa ser refeito — por isso o
 * recálculo é do lote inteiro, identificado pela FK trade_id. Keys sem trade_id
 * (anteriores a esse vínculo) recalculam só a própria key. Ver docs/adr/0004.
 */
class UpdateKeyUseCase
{
    public function __construct(
        private readonly KeyCalculationService $calculationService,
        private readonly SupplierService $supplierService,
        private readonly GameService $gameService,
        private readonly KeyRepository $keyRepository,
    ) {}

    /**
     * Atualiza os dados de uma key existente. Só recalcula as fórmulas de custo e
     * lucro quando o market_price muda; nesse caso, recalcula o lote inteiro.
     *
     * Retorna as keys **afetadas** (com relacionamentos) e a mensagem pronta: só a
     * editada quando nenhuma fórmula muda; o lote inteiro quando o market_price muda
     * — para a tela conseguir atualizar as irmãs sem recarregar.
     *
     * @param  Key  $existing  Key a ser atualizada (resolvida por route model binding)
     * @param  array<string, mixed>  $validated  Dados validados do Form Request
     * @return array{keys: Collection<int, Key>, message: string}
     */
    public function execute(Key $existing, array $validated): array
    {
        // tf2_quantity é opcional no update; preserva do banco se ausente
        if (empty($validated['tf2_quantity'])) {
            $validated['tf2_quantity'] = $existing->tf2_quantity;
        }

        $attributes = $validated;

        // Campos derivados de key_code/game_name — atualizam em qualquer edição
        $attributes['identified_platform'] = PlatformIdentifier::identify($attributes['key_code']);
        $attributes['supplier_id'] = $this->supplierService->findOrCreate($attributes['supplier_url']);
        $attributes['is_duplicate'] = $this->keyRepository->findByKeyCode($attributes['key_code'], $existing->id) !== null;

        // Sincroniza gamivo_id
        if (empty($attributes['gamivo_id'])) {
            $gamivoId = $this->gameService->getIdGamivo($attributes['game_name'], $attributes['region']);
            if ($gamivoId) {
                $attributes['gamivo_id'] = $gamivoId;
            }
        }

        if (! empty($attributes['gamivo_id'])) {
            $this->gameService->fillIdGamivo($attributes['game_name'], $attributes['region'], $attributes['gamivo_id']);
        }

        // Sem mudança de market_price, o custo do lote não muda — persiste só os
        // campos editados ($validated não traz simulated_income/custo/lucros de
        // compra). Mas informar um sold_price sempre recalcula o lucro de venda:
        // o individual_cost não mudou, então basta aplicá-lo ao novo preço vendido.
        if (! $this->marketPriceChanged($existing, $validated)) {
            $soldPrice = $this->parseSoldPrice($attributes['sold_price'] ?? null);
            if ($soldPrice !== null) {
                $sale = $this->calculationService->calculateSaleFormulas($soldPrice, (float) $existing->individual_cost);
                $attributes['sale_profit'] = $sale['sale_profit'];
                $attributes['sale_profit_percent'] = $sale['sale_profit_percent'];
            }

            $existing->update($attributes);

            return [
                'keys' => new Collection([$existing->fresh()->load('supplier')]),
                'message' => $this->buildMessage($existing),
            ];
        }

        // Novo market_price → novo simulated_income → recalcula o rateio do lote.
        $attributes = $this->calculationService->calculateFirstFormulas([$attributes])['games'][0];

        // Campos derivados são recalculados para o lote inteiro em recalculateBatch().
        unset(
            $attributes['individual_cost'],
            $attributes['purchase_profit'],
            $attributes['purchase_profit_percent'],
            $attributes['sale_profit'],
            $attributes['sale_profit_percent'],
        );

        $batch = new Collection;

        DB::transaction(function () use ($existing, $attributes, &$batch) {
            // Precisa vir antes de recalculateBatch para o novo market_price já
            // estar no banco quando o lote for lido.
            $existing->update($attributes);

            // Sem trade_id (keys anteriores ao vínculo) não há lote — recalcula só esta key.
            $batch = $existing->trade_id !== null
                ? $this->keyRepository->findByTradeId($existing->trade_id)
                : new Collection([$existing]);

            if ($batch->isEmpty()) {
                $batch = new Collection([$existing]);
            }

            $this->recalculateBatch($batch);
        });

        // O lote inteiro foi recalculado — devolve todas as keys afetadas (a editada
        // já está no lote), com relacionamentos, em ordem de id.
        return [
            'keys' => $batch->map(fn (Key $key) => $key->fresh()->load('supplier'))->values(),
            'message' => $this->buildMessage($existing),
        ];
    }

    /**
     * Mensagem de retorno, sinalizando quando a plataforma da key não foi identificada.
     */
    private function buildMessage(Key $key): string
    {
        return $key->identified_platform === 'DESCONHECIDO'
            ? 'Jogo atualizado, mas a plataforma não foi identificada.'
            : 'Jogo atualizado com sucesso';
    }

    /**
     * Normaliza o sold_price do request para float, ou null quando ausente/vazio
     * (null = key não vendida, distinto de vendida por €0).
     */
    private function parseSoldPrice(mixed $rawSold): ?float
    {
        return ($rawSold !== null && $rawSold !== '') ? (float) $rawSold : null;
    }

    /**
     * Compara o market_price editado com o do banco, normalizando para 2 casas
     * (o banco guarda decimal; o request chega como string).
     *
     * @param  array<string, mixed>  $validated
     */
    private function marketPriceChanged(Key $existing, array $validated): bool
    {
        $new = number_format((float) ($validated['market_price'] ?? 0), 2, '.', '');
        $old = number_format((float) $existing->market_price, 2, '.', '');

        return $new !== $old;
    }

    /**
     * Recalcula o rateio do custo (individual_cost) e os lucros de compra/venda de
     * todas as keys de um lote, exatamente como o RegisterKeyUseCase faz na criação:
     * o somatório de incomes do lote é a base do rateio proporcional.
     *
     * @param  Collection<int, Key>  $batch
     */
    private function recalculateBatch(Collection $batch): void
    {
        // Recalcula simulated_income de cada key (a partir do market_price atual do banco)
        // e o somatório do lote — a base do rateio.
        $games = $batch->map(fn (Key $key) => [
            'market_price' => $key->market_price,
            'tf2_quantity' => $key->tf2_quantity,
            'sold_price' => $key->sold_price,
        ])->all();

        $firstFormulas = $this->calculationService->calculateFirstFormulas($games);
        $somatorioIncomes = $firstFormulas['somatorioIncomes'];

        foreach ($batch->values() as $index => $key) {
            $recalculated = $this->calculationService->calculateFormulas(
                $firstFormulas['games'][$index],
                $somatorioIncomes,
                false,
            );

            $update = [
                'simulated_income' => $recalculated['simulated_income'],
                'individual_cost' => $recalculated['individual_cost'],
                'purchase_profit' => $recalculated['purchase_profit'],
                'purchase_profit_percent' => $recalculated['purchase_profit_percent'],
            ];

            // sale_profit/percent só são gravados quando a key foi vendida; do contrário
            // o DEFAULT 0 do banco mantém a semântica "não vendida" (sold_at IS NULL).
            if ($recalculated['sale_profit'] !== null) {
                $update['sale_profit'] = $recalculated['sale_profit'];
                $update['sale_profit_percent'] = $recalculated['sale_profit_percent'];
            }

            $key->update($update);
        }
    }
}
