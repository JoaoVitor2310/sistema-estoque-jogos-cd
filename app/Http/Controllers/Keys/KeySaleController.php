<?php

namespace App\Http\Controllers\Keys;

use App\Http\Controllers\Controller;
use App\Http\Resources\KeyAutoSellResource;
use App\Http\Resources\KeyGamivoMinMaxResource;
use App\Http\Resources\KeyWhenToSellResource;
use App\Models\Venda_chave_troca;
use App\Traits\HttpResponses;
use App\UseCases\Keys\AutoSellUseCase;
use App\UseCases\Keys\ListKeyForSaleUseCase;
use App\UseCases\Keys\UpdateSoldOffersUseCase;
use Illuminate\Http\Request;

/**
 * Operações de venda de keys: sugestão de listagem, atualização de vendas e consultas Gamivo.
 * Responsabilidade: HTTP only — recebe request, delega ao UseCase/Model, retorna response.
 */
class KeySaleController extends Controller
{
    use HttpResponses;

    public function __construct(
        private readonly AutoSellUseCase $autoSellUseCase,
        private readonly UpdateSoldOffersUseCase $updateSoldOffersUseCase,
        private readonly ListKeyForSaleUseCase $listKeyForSaleUseCase,
    ) {}

    /**
     * Retorna keys elegíveis para listagem automática no Gamivo.
     */
    public function autoSell(Request $request)
    {
        try {
            $keys = $this->autoSellUseCase->execute();
        } catch (\Exception $e) {
            return $this->error(500, 'Erro interno ao listar jogos para venda automaticamente', [$e->getMessage()]);
        }

        return $this->response(
            200,
            'Jogos para listar a venda automaticamente encontrados com sucesso',
            KeyAutoSellResource::collection($keys)
        );
    }

    /**
     * Retorna keys que já possuem gamivo_id e preço mínimo definido, mas ainda não foram listadas.
     */
    public function whenToSell(Request $request)
    {
        $keys = Venda_chave_troca::select([
            'gamivo_id', 'minimum_sale_price', 'individual_cost',
            'key_code', 'game_name', 'region',
            'acquired_at', 'listed_at', 'sold_at', 'expires_at',
        ])
            ->whereNotNull('gamivo_id')
            ->whereNotNull('minimum_sale_price')
            ->whereNull('listed_at')
            ->whereNull('sold_at')
            ->get();

        return $this->response(
            200,
            'Jogos para listar encontrados com sucesso',
            KeyWhenToSellResource::collection($keys)
        );
    }

    /**
     * Recebe dados de venda da API Gamivo e atualiza as keys correspondentes.
     */
    public function updateSoldOffers(Request $request)
    {
        $notUpdated = $this->updateSoldOffersUseCase->execute($request->all());

        return $this->response(200, 'Jogos atualizados com sucesso', $notUpdated);
    }

    /**
     * Retorna os preços mínimo e máximo da API Gamivo para keys de um jogo específico.
     */
    public function searchByIdGamivo(Request $request, string $idGamivo)
    {
        $keys = Venda_chave_troca::select(['minApiGamivo', 'maxApiGamivo'])
            ->where('gamivo_id', $idGamivo)
            ->whereNull('sold_at')
            ->whereNotNull('listed_at')
            ->get();

        return $this->response(200, 'Jogos encontrados com sucesso', KeyGamivoMinMaxResource::collection($keys));
    }

    /**
     * Registra a data em que a key foi colocada à venda (listed_at = hoje).
     * Opcionalmente reseta minApiGamivo para o piso de listagem pública do Gamivo.
     */
    public function insertDataVenda(Request $request)
    {
        $keyCode = $request->input('key_code');
        $resetMinApiGamivo = $request->input('updateMinApiGamivo', true);

        if (! $keyCode) {
            return $this->error(404, 'Chave não encontrada', ['key_code' => 'Chave não encontrada']);
        }

        $result = $this->listKeyForSaleUseCase->execute($keyCode, $resetMinApiGamivo);

        if (! $result['success']) {
            return $this->error(404, $result['message']);
        }

        return $this->response(200, $result['message'], []);
    }
}
