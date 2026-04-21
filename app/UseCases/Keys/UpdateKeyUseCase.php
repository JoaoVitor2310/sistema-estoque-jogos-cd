<?php

namespace App\UseCases\Keys;

use App\Domain\Platform\PlatformIdentifier;
use App\Models\Venda_chave_troca;
use App\Services\Games\GameService;
use App\Services\Keys\KeyCalculationService;
use App\Services\Keys\KeyRepository;
use App\Services\Suppliers\SupplierService;

/**
 * Orquestra a atualização de uma key existente.
 *
 * Diferenças em relação ao RegisterKeyUseCase:
 *  - Preserva valorPagoIndividual do banco (custo já fixado na compra)
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
     * @param  string               $id        ID da key a ser atualizada
     * @param  array<string, mixed> $validated Dados validados do Form Request
     * @return Venda_chave_troca               Model atualizado com relacionamentos
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function execute(string $id, array $validated): Venda_chave_troca
    {
        $existing = Venda_chave_troca::findOrFail($id);

        // Custo individual vem do banco — não pode ser alterado no update
        $validated['valorPagoIndividual'] = $existing->valorPagoIndividual;

        // qtdTF2 é opcional no update; preserva do banco se ausente
        if (empty($validated['qtdTF2'])) {
            $validated['qtdTF2'] = $existing->qtdTF2;
        }

        // Recalcula incomeSimulado (necessário para os lucros de venda)
        $firstFormulas    = $this->calculationService->calculateFirstFormulas([$validated]);
        $data             = $firstFormulas['games'][0];
        $somatorioIncomes = $firstFormulas['somatorioIncomes'];

        // isEdit=true: não retoca valorPagoIndividual nem lucros de compra
        $data = $this->calculationService->calculateFormulas($data, $somatorioIncomes, true);

        // Plataforma e fornecedor
        $data['plataformaIdentificada'] = PlatformIdentifier::identify($data['chaveRecebida']);
        $data['id_fornecedor']          = $this->supplierService->findOrCreate($data['perfilOrigem']);

        // Verifica duplicidade excluindo o próprio registro
        $data['repetido'] = $this->keyRepository->findByKeyCode($data['chaveRecebida'], (int) $id) !== null;

        // Sincroniza idGamivo
        if (empty($data['idGamivo'])) {
            $idGamivo = $this->gameService->getIdGamivo($data['nomeJogo'], $data['region']);
            if ($idGamivo) {
                $data['idGamivo'] = $idGamivo;
            }
        }

        if (!empty($data['idGamivo'])) {
            $this->gameService->fillIdGamivo($data['nomeJogo'], $data['region'], $data['idGamivo']);
        }

        $existing->update($data);

        return $existing->load([
            'fornecedor',
            'tipoReclamacao',
            'tipoFormato',
            'leilaoG2A',
            'leilaoGamivo',
            'leilaoKinguin',
            'plataforma',
        ]);
    }
}
