<?php

use App\Models\Venda_chave_troca;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Log;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::call(function () {
    // Buscar chaves que expiram em 14 dias ou menos (mas que ainda não expiraram)
    $dataLimite = now()->addDays(14);
    $keysAboutToExpire = Venda_chave_troca::where('dataExpiracao', '<=', $dataLimite)
        ->where('dataExpiracao', '>', now()) // Ainda não expiradas
        ->whereNull('dataVendida') // Não vendidas
        ->get();
    
    // Só enviar email se houver chaves para expirar
    if ($keysAboutToExpire->count() > 0) {
        try {
            Mail::send('emails.expiration-alert', ['jogos' => $keysAboutToExpire], function ($message) {
                $message->to('carcadeals@gmail.com')
                    ->subject('⚠️ Alerta: ' . \Carbon\Carbon::now()->format('d/m/Y') . ' - Jogos expirando em até 14 dias');
            });
            
            Log::info('Email de expiração enviado com sucesso. Jogos encontrados: ' . $keysAboutToExpire->count());
        } catch (\Exception $e) {
            Log::error('Erro ao enviar email de expiração: ' . $e->getMessage());
        }
    }
})->cron('0 7 * * *')->timezone('America/Sao_Paulo');