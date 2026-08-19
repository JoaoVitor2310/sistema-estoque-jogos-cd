<?php

namespace App\Mail;

use App\Models\Trade;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Aviso de que um supplier terminou de preencher a entrega dele.
 *
 * A entrega fecha para escrita no clique dele e fica esperando a conferência da
 * equipe (ver docs/adr/0008). Sem este aviso, quem chega primeiro é quem por
 * acaso abriu a aba de Trades — e o supplier fica sem resposta enquanto isso.
 *
 * **Não leva `key_code`.** O e-mail sai para fora do sistema, e a key é a coisa
 * de valor que a trade guarda: quem for conferir abre a aba, onde o acesso já é
 * controlado.
 */
class TradeDeliveredMail extends Mailable
{
    public function __construct(
        public Trade $trade,
        public int $filledLines,
        public int $totalLines,
    ) {}

    public function envelope(): Envelope
    {
        $who = $this->trade->title ?: ($this->trade->supplier?->name ?: 'Supplier');

        return new Envelope(
            subject: '📦 Trade recebida: '.$who.' ('.$this->filledLines.'/'.$this->totalLines.' keys)',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.trade-delivered',
            with: [
                'trade' => $this->trade,
                'filledLines' => $this->filledLines,
                'totalLines' => $this->totalLines,
                'tradesUrl' => route('trades', ['view' => 'awaiting_review']),
            ],
        );
    }
}
