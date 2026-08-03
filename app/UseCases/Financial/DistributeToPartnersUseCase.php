<?php

namespace App\UseCases\Financial;

use App\Domain\Financial\PartnerDistribution;
use App\Models\FinancialMovement;
use App\Services\Financial\FinancialMonthService;
use App\Services\Financial\MovementRecorder;
use App\UseCases\Financial\DTO\DistributeToPartnersDTO;
use Illuminate\Support\Collection;

/**
 * Saca para os dois sócios num único lançamento.
 *
 * Gera dois débitos na mesma conta — um por sócio, marcados por `partner_slot` —
 * porque o dinheiro sai da empresa e não tem contrapartida em conta nenhuma. A
 * divisão fica com o `PartnerSplit`, que reconcilia exato e manda o centavo
 * órfão para o Sócio 1.
 *
 * Os dois débitos compartilham `group_id`: apagar só um deles devolveria à
 * empresa a parte de um sócio e deixaria a do outro, quebrando o par.
 */
class DistributeToPartnersUseCase
{
    public function __construct(
        private readonly FinancialMonthService $financialMonthService,
        private readonly MovementRecorder $movementRecorder,
    ) {}

    /**
     * @return Collection<int, FinancialMovement>
     */
    public function execute(DistributeToPartnersDTO $data): Collection
    {
        $month = $this->financialMonthService->currentDraftOrFail();

        $distribution = PartnerDistribution::from(
            $data->source,
            $data->amount,
            $data->partnerOneShare,
            $data->description,
        );

        return $this->movementRecorder->record(
            $month,
            $distribution->legs(),
            $data->description,
            $data->occurredAt,
        );
    }
}
