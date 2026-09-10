<?php

namespace App\UseCases\Trades;

use App\Domain\Trades\DeliveryCredential;
use App\Models\Trade;
use App\Services\Trades\DeliverySession;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\RateLimiter;

class AuthenticateDeliveryUseCase
{
    /** Como a tentativa terminou — o controller só traduz isso em status HTTP. */
    public const RESULT_OK = 'ok';

    public const RESULT_WRONG_TOKEN = 'wrong_token';

    public const RESULT_RATE_LIMITED = 'rate_limited';

    /**
     * Confere o token e abre a sessão daquela entrega.
     *
     * Escrita orquestrada: consulta os dois limitadores, confere a credencial,
     * pune a tentativa errada e regenera a sessão. Ver docs/adr/0007 — autenticar
     * é orquestrar passos, ainda que os colaboradores sejam poucos.
     *
     * @return self::RESULT_*
     */
    public function execute(Trade $trade, string $token, Session $session, ?string $ip): string
    {
        $limits = $this->limits($trade, $ip);

        foreach ($limits as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return self::RESULT_RATE_LIMITED;
            }
        }

        if (! DeliveryCredential::matches($token, $trade->readableDeliveryToken())) {
            foreach (array_keys($limits) as $key) {
                RateLimiter::hit($key, DeliveryCredential::ATTEMPT_WINDOW_MINUTES * 60);
            }

            return self::RESULT_WRONG_TOKEN;
        }

        // Sessão nova para o token certo: sem isso, uma sessão fixada antes da
        // autenticação continuaria valendo depois dela.
        $session->regenerate();
        DeliverySession::open($session, $trade);

        return self::RESULT_OK;
    }

    /**
     * Quanto falta para a próxima tentativa ser aceita, em segundos.
     *
     * Só os limitadores efetivamente estourados contam: `availableIn` responde
     * quando a janela de uma chave expira, tenha ela batido no teto ou não, e o
     * eixo que ainda tem cota não faz ninguém esperar. Zero quando nenhum
     * estourou.
     */
    public function retryAfter(Trade $trade, ?string $ip): int
    {
        $seconds = 0;

        foreach ($this->limits($trade, $ip) as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $seconds = max($seconds, RateLimiter::availableIn($key));
            }
        }

        return $seconds;
    }

    /**
     * Os dois eixos do rate limit: só por IP um atacante distribui, e só por
     * entrega dá para travar de propósito a entrega de um supplier legítimo.
     *
     * @return array<string, int> chave do limitador => teto de tentativas
     */
    private function limits(Trade $trade, ?string $ip): array
    {
        return [
            'delivery-token:'.$trade->delivery_uuid => DeliveryCredential::MAX_ATTEMPTS_PER_DELIVERY,
            'delivery-ip:'.($ip ?? 'unknown') => DeliveryCredential::MAX_ATTEMPTS_PER_IP,
        ];
    }
}
