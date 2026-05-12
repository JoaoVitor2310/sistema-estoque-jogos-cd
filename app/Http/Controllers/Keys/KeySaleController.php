<?php

namespace App\Http\Controllers\Keys;

use App\Http\Controllers\Controller;
use App\Traits\HttpResponses;
use App\UseCases\Marketplaces\Gamivo\AutoSellUseCase;
use Illuminate\Http\Request;

/**
 * Operações de venda de keys na Gamivo.
 * Responsabilidade: HTTP only — recebe request, delega ao UseCase, retorna response.
 */
class KeySaleController extends Controller
{
    use HttpResponses;

    public function __construct(
        private readonly AutoSellUseCase $autoSellUseCase,
    ) {}

    /**
     * Lista automaticamente keys elegíveis na Gamivo.
     * Retorna os IDs das keys listadas com sucesso.
     */
    public function autoSell(Request $request)
    {
        try {
            $listed = $this->autoSellUseCase->execute();
        } catch (\Exception $e) {
            return $this->error(500, 'Erro interno ao listar jogos para venda automaticamente', [$e->getMessage()]);
        }

        return $this->response(
            200,
            count($listed).' key(s) listada(s) com sucesso',
            $listed,
        );
    }
}
