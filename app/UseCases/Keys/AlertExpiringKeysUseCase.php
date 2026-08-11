<?php

namespace App\UseCases\Keys;

use App\Domain\Keys\KeyEligibility;
use App\Mail\ExpiringKeysAlertMail;
use App\Models\Key;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Avisa por e-mail quando há keys perto de expirar.
 *
 * A `MinimumMarginPolicy` já rebaixa o `min_api` dessas keys ao piso; o e-mail
 * existe porque o piso sozinho pode não bastar para vender a tempo, e depois da
 * data de expiração a key não vale mais nada.
 *
 * Roda no scheduler diário (ver routes/console.php).
 */
class AlertExpiringKeysUseCase
{
    /**
     * @return int quantidade de keys no alerta (0 = nada a avisar)
     */
    public function execute(): int
    {
        // `with('supplier')`: a lista do e-mail mostra o perfil de origem de cada
        // key, e sem o eager load isso vira uma query por linha.
        $expiringKeys = Key::with('supplier')
            ->where('expires_at', '<=', now()->addDays(KeyEligibility::EXPIRY_ALERT_DAYS))
            ->where('expires_at', '>', now())
            ->whereNull('sold_at')
            ->get();

        if ($expiringKeys->isEmpty()) {
            return 0;
        }

        // Falha de envio não pode derrubar o scheduler: as outras tarefas do dia
        // ainda precisam rodar. Fica registrada no log.
        try {
            Mail::to(config('app.admin_email'))->send(new ExpiringKeysAlertMail($expiringKeys));

            Log::info('Expiring keys alert sent. Keys found: '.$expiringKeys->count());
        } catch (\Exception $e) {
            Log::error('Failed to send expiring keys alert: '.$e->getMessage());
        }

        return $expiringKeys->count();
    }
}
