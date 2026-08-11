<?php

namespace App\Mail;

use App\Domain\Keys\KeyEligibility;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;

/**
 * Aviso de que há keys perto de expirar — depois do prazo elas viram prejuízo
 * total, então o alerta existe para dar tempo de baixar o preço e vender.
 */
class ExpiringKeysAlertMail extends Mailable
{
    /**
     * @param  Collection<int, \App\Models\Key>  $keys
     */
    public function __construct(public Collection $keys) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '⚠️ Alerta: '.Carbon::now()->format('d/m/Y')
                .' - Jogos expirando em até '.KeyEligibility::EXPIRY_ALERT_DAYS.' dias',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.expiration-alert',
            with: [
                'keys' => $this->keys,
                'alertDays' => KeyEligibility::EXPIRY_ALERT_DAYS,
            ],
        );
    }
}
