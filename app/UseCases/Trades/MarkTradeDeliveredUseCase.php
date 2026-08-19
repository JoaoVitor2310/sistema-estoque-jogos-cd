<?php

namespace App\UseCases\Trades;

use App\Domain\Trades\ImportReadinessPolicy;
use App\Mail\TradeDeliveredMail;
use App\Models\Trade;
use App\Models\TradeLine;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MarkTradeDeliveredUseCase
{
    /**
     * O botão de entregar do supplier.
     *
     * Guarda o **primeiro** clique, e é ele que fecha a entrega para escrita
     * (ver [[App\Domain\Enums\TradeDeliveryState::acceptsSupplierWrites]]). A
     * pergunta que o campo responde é "quando ele disse que terminou": se um
     * save posterior empurrasse a data, ela deixaria de dizer há quanto tempo a
     * trade espera conferência, que é a única leitura útil dela.
     *
     * A guarda aqui é redundante com a do middleware de propósito — a regra é
     * de domínio e não pode depender de por onde a chamada entrou. Não existe
     * "des-entregar" nem "reabrir": sair da fila é o import, e mais nada.
     *
     * Sem o total de TF2 acertado a entrega não é enviada. É o mesmo critério
     * que [[ImportReadinessPolicy]] aplica no import, cobrado antes: a equipe
     * sabe quanto foi acertado, mas recuperar o número relendo a conversa é a
     * transcrição que a entrega existe para eliminar — pedir ao supplier é o
     * que faz a trade chegar à fila pronta para importar. Falha antes de gravar
     * `delivered_at` — trade recusada não entra na fila nem dispara o aviso.
     *
     * @return bool se a entrega foi aceita
     */
    public function execute(Trade $trade): bool
    {
        if ($trade->delivered_at !== null) {
            return true;
        }

        if (! ImportReadinessPolicy::hasTf2Quantity($trade->tf2_qty)) {
            return false;
        }

        $trade->delivered_at = now();
        $trade->save();

        $this->notifyTeam($trade);

        return true;
    }

    /**
     * Avisa a equipe de que a entrega chegou.
     *
     * Depois do save e nunca antes: o aviso é consequência do fato, e um e-mail
     * anunciando uma entrega que não gravou seria pior que aviso nenhum.
     *
     * Falha de envio não derruba a entrega. Quem está do outro lado é o
     * supplier, que não tem o que fazer com um erro de SMTP — e o dado dele já
     * está salvo. Fica no log, e a fila no topo da aba continua sendo o caminho
     * que não depende de e-mail nenhum.
     */
    private function notifyTeam(Trade $trade): void
    {
        try {
            $lines = $trade->lines()->get();

            Mail::to(config('app.admin_email'))->send(new TradeDeliveredMail(
                $trade->load('supplier'),
                $lines->filter(fn (TradeLine $line) => trim((string) $line->key_code) !== '')->count(),
                $lines->count(),
            ));
        } catch (\Throwable $e) {
            Log::error('[MarkTradeDelivered] failed to notify the team', [
                'trade_id' => $trade->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
