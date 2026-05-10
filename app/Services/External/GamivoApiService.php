<?php

namespace App\Services\External;

use App\Mail\GamivoTokenExpiredMail;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Wrapper sobre a API pública da Gamivo.
 * Infraestrutura pura — sem regras de negócio.
 *
 * Documentação oficial: docs/Gamivo_Public_API.html
 * Base URL: config('services.gamivo.url') / api/public/v1
 *
 * ⚠️  API_KEY_GAMIVO aponta para produção real.
 * Nunca chamar métodos desta classe sem autorização explícita do usuário.
 */
class GamivoApiService
{
    // ── Offers ────────────────────────────────────────────────────────────────

    /**
     * Lista TODAS as próprias ofertas, iterando a paginação automaticamente (máx 100/req).
     *
     * @return array<int, array{id: int, product_id: int, status: int, seller_price: float, ...}>
     */
    public function getActiveOffers(): array
    {
        $all = [];
        $offset = 0;

        do {
            $page = $this->handleResponse(
                $this->http()->get('/api/public/v1/offers', ['offset' => $offset, 'limit' => 100])
            );
            $page = is_array($page) ? $page : [];
            $all = array_merge($all, $page);
            $offset += 100;
        } while (count($page) === 100);

        return $all;
    }

    /**
     * Busca todas as ofertas de todos os vendedores para um produto.
     * Retorna array ordenado por retail_price ASC (a API não garante ordem).
     *
     * @return array<int, array{id: int, seller_name: string, retail_price: float, completed_orders: int, wholesale_mode: int, ...}>
     */
    public function getOffersForProduct(int $productId): array
    {
        $offers = $this->handleResponse(
            $this->http()->get("/api/public/v1/products/{$productId}/offers")
        );
        $offers = is_array($offers) ? $offers : [];

        usort($offers, fn ($a, $b) => $a['retail_price'] <=> $b['retail_price']);

        return $offers;
    }

    /**
     * Retorna a própria oferta para um produto (sem precisar filtrar por seller_name).
     * Retorna null se não há oferta para este produto.
     *
     * @return array{id: int, retail_price: float, seller_price: float, wholesale_mode: int, ...}|null
     */
    public function getMyOfferForProduct(int $productId): ?array
    {
        try {
            $result = $this->handleResponse(
                $this->http()->get("/api/public/v1/products/{$productId}/offer-id")
            );

            return is_array($result) ? $result : null;
        } catch (\RuntimeException) {
            // 404 = sem oferta para este produto
            return null;
        }
    }

    /**
     * Atualiza preço de uma oferta existente.
     *
     * Para wholesale_mode = 0:
     *   ['wholesale_mode' => 0, 'seller_price' => X.XX]
     * Para wholesale_mode = 1 ou 2:
     *   ['wholesale_mode' => 1, 'seller_price' => X.XX,
     *    'tier_one_seller_price' => Y.YY, 'tier_two_seller_price' => Y.YY]
     *
     * IMPORTANTE: omitir 'keys' do body se não está alterando estoque declarado.
     * Retorna o offerId em caso de sucesso.
     */
    public function updateOffer(int $offerId, array $data): ?int
    {
        $response = $this->http()->put("/api/public/v1/offers/{$offerId}", $data);

        // "Wait for the current action to end" não é falha — Gamivo ainda está processando a ação anterior
        if ($response->status() === 400
            && str_contains($response->json('reason') ?? '', 'Wait for the current action')) {
            return $offerId;
        }

        return $this->handleResponse($response);
    }

    /**
     * Cria uma nova oferta para um produto.
     * Se a oferta já existe inativa, reativa automaticamente via change-status.
     * Retorna o offerId criado ou reativado.
     */
    public function createOffer(array $data): ?int
    {
        $response = $this->http()->post('/api/public/v1/offers', $data);

        // Oferta já existe inativa — API devolve o offerId no texto: "Offer already exists [12345]"
        if ($response->status() === 400) {
            $reason = $response->json('reason') ?? '';
            if (preg_match('/\[(\d+)\]/', $reason, $matches)) {
                return $this->changeOfferStatus((int) $matches[1], 1);
            }
        }

        return $this->handleResponse($response);
    }

    /**
     * Ativa (status=1) ou desativa (status=0) uma oferta.
     * Retorna o offerId.
     */
    public function changeOfferStatus(int $offerId, int $status): ?int
    {
        return $this->handleResponse(
            $this->http()->put("/api/public/v1/offers/{$offerId}/change-status", ['status' => $status])
        );
    }

    // ── Keys ──────────────────────────────────────────────────────────────────

    /**
     * Inicia upload de chaves de texto em uma oferta.
     * Operação ASSÍNCRONA — retorna jobId. Verificar conclusão com waitForUpload().
     * Limite: 10.000 chaves por chamada.
     *
     * @param  string[]  $keys
     */
    public function uploadKeys(int $offerId, array $keys): ?int
    {
        return $this->handleResponse(
            $this->http()->post("/api/public/v1/offers/{$offerId}/keys/upload", ['keys' => $keys])
        );
    }

    /**
     * Verifica se uma key de texto está ativa na oferta após o upload assíncrono.
     * Filtra pelo código exato da key para confirmar que foi processada com sucesso.
     *
     * Usado em AutoSellUseCase após waitForUpload() para só marcar listed_at
     * quando a key estiver realmente disponível na oferta.
     */
    public function isKeyListed(int $offerId, string $keyCode): bool
    {
        try {
            $result = $this->handleResponse(
                $this->http()->get("/api/public/v1/offers/{$offerId}/keys/active/0/1", [
                    'filters' => json_encode(['keys' => [$keyCode], 'type' => 1]),
                ])
            );

            return is_array($result) && ($result['count'] ?? 0) > 0;
        } catch (\RuntimeException) {
            return false;
        }
    }

    /**
     * Aguarda a conclusão de um upload de chaves via polling do job assíncrono.
     * Faz polling a cada 1 segundo até o job concluir, falhar ou atingir o limite.
     *
     * @throws \RuntimeException se o job falhar ou exceder o número máximo de tentativas
     */
    public function waitForUpload(int $offerId, int $jobId, int $maxAttempts = 10): void
    {
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $response = $this->http()->get("/api/public/v1/offers/{$offerId}/jobs/{$jobId}/result");

            if ($response->successful()) {
                $data = $response->json();

                // Job concluído — resposta é a string "Done" ou um arquivo zip
                if ($data === 'Done' || str_contains($response->header('Content-Type') ?? '', 'zip')) {
                    return;
                }

                if (is_array($data) && ($data['status'] ?? '') === 'failed') {
                    Log::error("Gamivo upload job failed: offer={$offerId} job={$jobId}");
                    throw new \RuntimeException("Gamivo upload job {$jobId} failed for offer {$offerId}.");
                }
            }

            // Job ainda em andamento (created/running) — aguardar antes de tentar novamente
            if ($attempt < $maxAttempts) {
                sleep(1);
            }
        }

        throw new \RuntimeException("Gamivo upload job {$jobId} timed out after {$maxAttempts} attempts.");
    }

    // ── Sales ─────────────────────────────────────────────────────────────────

    /**
     * Retorna uma página do histórico de vendas (máx 25 por página).
     * Paginar incrementando $offset de 25 em 25 até o retorno ser array vazio.
     *
     * $filters: ['dateFrom' => 'Y-m-d', 'dateTo' => 'Y-m-d', 'statuses' => ['COMPLETED']]
     *
     * Nota: o campo created_at da API retorna formato não-padrão "2025-04-13UTC17:44:480".
     * Para extrair a data: explode('UTC', $sale['created_at'])[0]
     *
     * @return array<int, array{product_id: int, order_id: string, profit: float, seller_tax: float, quantity: int, created_at: string, ...}>
     */
    public function getSalesHistory(array $filters, int $offset = 0): array
    {
        $result = $this->handleResponse(
            $this->http()->get("/api/public/v1/accounts/sales/history/{$offset}/25", [
                'filters' => json_encode($filters),
            ])
        );

        // A API retorna { count: N, data: [...] } — extrair apenas o array de vendas
        if (is_array($result) && isset($result['data'])) {
            return $result['data'];
        }

        return [];
    }

    /**
     * Detalhes de um pedido, incluindo as chaves entregues ao comprador.
     *
     * Estrutura retornada:
     * [
     *   'id' => 'uuid',
     *   'keys' => [
     *     '<offer_id>' => ['keys' => [['type' => 'TEXT', 'key' => 'XXXX-YYYY-ZZZZ']], 'rating' => '-']
     *   ]
     * ]
     *
     * ATENÇÃO: a chave do objeto 'keys' é o offer_id como string, não o product_name.
     * Ver nota de inconsistência em docs/DOCUMENTACAO.md.
     *
     * Retorna null em caso de erro (loga internamente).
     */
    public function getSaleOrderDetails(string $orderId): ?array
    {
        try {
            $result = $this->handleResponse(
                $this->http()->get("/api/public/v1/accounts/sales/order-details/{$orderId}")
            );

            return is_array($result) ? $result : null;
        } catch (\RuntimeException $e) {
            Log::error("Gamivo getSaleOrderDetails failed [{$orderId}]: {$e->getMessage()}");

            return null;
        }
    }

    // ── Privados ──────────────────────────────────────────────────────────────

    /**
     * Instância HTTP pré-configurada com autenticação Bearer e timeout.
     */
    private function http(): PendingRequest
    {
        return Http::baseUrl(config('services.gamivo.url'))
            ->withToken(config('services.gamivo.token'))
            ->timeout(15);
    }

    /**
     * Processa a resposta HTTP: detecta token expirado, loga erros, retorna o JSON decodificado.
     *
     * @throws \RuntimeException em erros de autenticação ou falhas HTTP
     */
    private function handleResponse(Response $response): mixed
    {
        if ($response->status() === 401) {
            $code = $response->json('codeMessage') ?? 'UNAUTHORIZED';

            Log::error("Gamivo API: autenticação falhou — {$code}");

            if ($code === 'UNAUTHORIZED_EXPIRED_TOKEN') {
                $this->notifyTokenExpired();
            }

            throw new \RuntimeException("Gamivo auth error: {$code}");
        }

        if ($response->failed()) {
            $reason = $response->json('reason') ?? $response->body();
            throw new \RuntimeException("Gamivo API error {$response->status()}: {$reason}");
        }

        return $response->json();
    }

    /**
     * Envia e-mail de alerta quando o token da Gamivo expira.
     * Token precisa ser rotacionado manualmente no .env da VPS.
     */
    private function notifyTokenExpired(): void
    {
        Mail::to(config('app.admin_email'))->send(new GamivoTokenExpiredMail);
    }
}
