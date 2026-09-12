<?php

namespace App\UseCases\Bundles;

use App\Domain\Bundles\BundleResearchRequest;
use App\Models\Bundle;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pede ao price_researcher o preço e a popularidade dos jogos de um bundle.
 *
 * A pesquisa é assíncrona do outro lado: o serviço enfileira e responde 202 na
 * hora, então o retorno aqui confirma o enfileiramento, não o resultado. O
 * resultado chega minutos depois pelo callback `POST /trades/from-price-researcher`,
 * que cria a trade com o nome do bundle (`StoreListTradeUseCase`).
 *
 * Bundle em que nenhum jogo qualifica não gera callback nenhum — não existe
 * como distinguir "ainda processando" de "acabou sem resultado".
 */
class ResearchBundleGamesUseCase
{
    /**
     * @return array{success: true, data: mixed}|array{success: false, code: int, message: string, data: mixed}
     */
    public function execute(Bundle $bundle): array
    {
        $gameNames = $bundle->games()->pluck('games.name')->all();

        if ($gameNames === []) {
            return [
                'success' => false,
                'code' => 400,
                'message' => 'Bundle sem jogos para pesquisar.',
                'data' => [],
            ];
        }

        $internalSecret = config('services.price_researcher.internal_secret');

        // Sem segredo o price_researcher não recusa — ele responde 200 em modo
        // demo e nunca chama o callback. Barrar aqui evita o disparo que parece
        // ter dado certo e nunca vira trade.
        if (! $internalSecret) {
            Log::error('Price Researcher research: INTERNAL_SECRET não configurado.');

            return [
                'success' => false,
                'code' => 500,
                'message' => 'Pesquisa de bundle não configurada.',
                'data' => [],
            ];
        }

        $baseUrl = rtrim((string) config('services.price_researcher.base_url'), '/');

        try {
            $response = Http::post(
                $baseUrl.'/api/games/research',
                BundleResearchRequest::payload($bundle->name, $gameNames, $internalSecret),
            );
        } catch (ConnectionException $e) {
            // Serviço fora do ar não devolve status — sem este catch a exceção
            // sobe e vira 500 sem explicação (ver FindNewSuppliersUseCase).
            Log::error('Price Researcher research unreachable: '.$e->getMessage());

            return [
                'success' => false,
                'code' => 503,
                'message' => 'Serviço de pesquisa de preços indisponível.',
                'data' => [],
            ];
        }

        $data = $response->json() ?? [];

        if ($response->failed()) {
            return [
                'success' => false,
                'code' => $response->status(),
                'message' => 'Erro ao pesquisar os jogos do bundle.',
                'data' => $data,
            ];
        }

        // 200 com `demo: true` é sucesso na aparência e fracasso no efeito:
        // significa segredo ausente ou errado, e nada será enviado ao callback.
        if ($data['demo'] ?? false) {
            Log::error('Price Researcher research recusou o INTERNAL_SECRET (modo demo).');

            return [
                'success' => false,
                'code' => 500,
                'message' => 'Pesquisa de bundle não configurada.',
                'data' => $data,
            ];
        }

        return ['success' => true, 'data' => $data];
    }
}
