<?php

namespace App\Http\Controllers;

use App\Domain\Games\GiveawayPolicy;
use App\Services\Games\GiveawayRepository;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Consulta dos giveaways registrados do GamerPower. Só leitura: os dados vêm
 * da sincronização ([[App\UseCases\Games\SyncGiveawaysUseCase]]), não de
 * cadastro.
 */
class GiveawayController extends Controller
{
    public function __construct(
        private readonly GiveawayRepository $giveawayRepository,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Giveaways', [
            'giveaways' => $this->giveawayRepository->forListing(),
            // As faixas da regra seguem na resposta, lidas das constantes do
            // Domain: quem explica a regra não precisa ter uma cópia dos números.
            'rule' => GiveawayPolicy::windowRule(),
        ]);
    }
}
