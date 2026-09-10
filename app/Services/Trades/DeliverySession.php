<?php

namespace App\Services\Trades;

use App\Domain\Trades\DeliveryCredential;
use App\Models\Trade;
use Illuminate\Contracts\Session\Session;

/**
 * A sessão aberta pelo supplier ao acertar o token de **uma** entrega.
 *
 * Escopada por UUID: acertar o token de uma trade não dá acesso a nenhuma outra.
 * Guarda duas coisas, e cada uma fecha um buraco (ver docs/adr/0008):
 *
 * - **o instante da autenticação**, porque o prazo é próprio. O
 *   `SESSION_LIFETIME` do projeto é de 7 dias, número escolhido para a equipe
 *   não relogar; herdá-lo daria uma semana de acesso ao dispositivo do supplier
 *   sem que isso tivesse sido decidido;
 * - **a marca do token com que ela foi aberta**, para nenhuma sessão sobreviver
 *   à credencial que a abriu. É a marca, e não o token: a sessão não precisa
 *   carregar o segredo para saber de qual credencial veio.
 */
final class DeliverySession
{
    private const PREFIX = 'delivery:';

    public static function open(Session $session, Trade $trade): void
    {
        $session->put(self::PREFIX.$trade->delivery_uuid, [
            'at' => now()->getTimestamp(),
            'token' => DeliveryCredential::fingerprint((string) $trade->readableDeliveryToken()),
        ]);
    }

    public static function isOpen(Session $session, Trade $trade): bool
    {
        $entry = $session->get(self::PREFIX.$trade->delivery_uuid);

        if (! is_array($entry) || ! isset($entry['at'], $entry['token'])) {
            return false;
        }

        $current = DeliveryCredential::fingerprint((string) $trade->readableDeliveryToken());

        if (! hash_equals($current, (string) $entry['token'])) {
            return false;
        }

        return now()->getTimestamp() - (int) $entry['at'] < DeliveryCredential::SESSION_TTL_MINUTES * 60;
    }
}
