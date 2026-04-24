<?php

namespace App\UseCases\Keys;

use App\Domain\Platform\PlatformIdentifier;
use App\Models\Key;
use App\Services\Games\GameService;
use App\Services\Keys\KeyCalculationService;
use App\Services\Keys\KeyRepository;
use App\Services\Suppliers\SupplierService;

/**
 * Orquestra a atualização de uma key existente.
 *
 * Diferenças em relação ao RegisterKeyUseCase:
 *  - Preserva individual_cost do banco (custo já fixado na compra)
 *  - Usa isEdit=true para não recalcular lucros de compra
 *  - Não cria o jogo na tabela games (já deve existir)
 *  - Verifica duplicidade excluindo o próprio registro
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
     * Atualiza os dados de uma key existente.
     *
     * @param  string  $id  ID da key a ser atualizada
     * @param  array<string, mixed>  $validated  Dados validados do Form Request
     * @return Key Model atualizado com relacionamentos
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function execute(string $id, array $validated): Key
    {
        $existing = Key::findOrFail($id);

        // Custo individual vem do banco — não pode ser alterado no update
        $validated['individual_cost'] = $existing->individual_cost;

        // tf2_quantity é opcional no update; preserva do banco se ausente
        if (empty($validated['tf2_quantity'])) {
            $validated['tf2_quantity'] = $existing->tf2_quantity;
        }

        // Recalcula simulated_income (necessário para os lucros de venda)
        $firstFormulas = $this->calculationService->calculateFirstFormulas([$validated]);
        $data = $firstFormulas['games'][0];
        $somatorioIncomes = $firstFormulas['somatorioIncomes'];

        // isEdit=true: não retoca individual_cost nem lucros de compra
        $data = $this->calculationService->calculateFormulas($data, $somatorioIncomes, true);

        // Plataforma e fornecedor
        $data['identified_platform'] = PlatformIdentifier::identify($data['key_code']);
        $data['supplier_id'] = $this->supplierService->findOrCreate($data['supplier_url']);

        // Verifica duplicidade excluindo o próprio registro
        $data['is_duplicate'] = $this->keyRepository->findByKeyCode($data['key_code'], (int) $id) !== null;

        // Sincroniza gamivo_id
        if (empty($data['gamivo_id'])) {
            $gamivoId = $this->gameService->getIdGamivo($data['game_name'], $data['region']);
            if ($gamivoId) {
                $data['gamivo_id'] = $gamivoId;
            }
        }

        if (! empty($data['gamivo_id'])) {
            $this->gameService->fillIdGamivo($data['game_name'], $data['region'], $data['gamivo_id']);
        }

        // Remove campos de lucro de venda nulos antes de persistir.
        // O banco tem DEFAULT 0 — a semântica "não vendida" já é capturada por sold_at IS NULL.
        if (($data['sale_profit'] ?? null) === null) {
            unset($data['sale_profit']);
        }
        if (($data['sale_profit_percent'] ?? null) === null) {
            unset($data['sale_profit_percent']);
        }

        $existing->update($data);

        return $existing->load(['supplier']);
    }
}
