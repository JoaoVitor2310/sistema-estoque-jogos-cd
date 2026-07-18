<?php

namespace App\Services;

use App\Domain\Keys\KeyEligibility;
use App\Models\Key;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class KeyService
{
    /**
     * Envia alerta por e-mail quando há keys expirando nos próximos 30 dias.
     */
    public function checkExpiringKeys(): void
    {
        $keysAboutToExpire = Key::where('expires_at', '<=', now()->addDays(KeyEligibility::EXPIRY_ALERT_DAYS))
            ->where('expires_at', '>', now())
            ->whereNull('sold_at')
            ->get();

        if ($keysAboutToExpire->isEmpty()) {
            return;
        }

        try {
            Mail::send('emails.expiration-alert', ['jogos' => $keysAboutToExpire], function ($message) {
                $message->to('carcadeals@gmail.com')
                    ->subject('⚠️ Alerta: '.Carbon::now()->format('d/m/Y').' - Jogos expirando em até 30 dias');
            });

            Log::info('Email de expiração enviado com sucesso. Jogos encontrados: '.$keysAboutToExpire->count());
        } catch (\Exception $e) {
            Log::error('Erro ao enviar email de expiração: '.$e->getMessage());
        }
    }
}
