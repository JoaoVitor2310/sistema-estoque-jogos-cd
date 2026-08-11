<?php

namespace App\Mail;

use App\Domain\Assets\AssetAlert;
use App\Models\Asset;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;

/**
 * Aviso de que a cotação do dólar guardada para o TF2 se afastou da cotação
 * real. O custo das trades é calculado a partir do valor guardado, então uma
 * cotação defasada distorce a decisão de compra.
 *
 * @see AssetAlert::DOLLAR_PRICE_VARIATION_THRESHOLD
 */
class DollarVariationAlertMail extends Mailable
{
    /**
     * @param  array{price_brl: float, price_euro?: float, price_dollar: float}  $currentPrices
     */
    public function __construct(
        public Asset $tf2,
        public array $currentPrices,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '⚠️ Alerta: '.Carbon::now()->format('d/m/Y')
                .' - Dolar variou mais que '.AssetAlert::DOLLAR_PRICE_VARIATION_THRESHOLD,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.dollar-alert',
            with: [
                'tf2' => $this->tf2,
                'currentPrices' => $this->currentPrices,
                'threshold' => AssetAlert::DOLLAR_PRICE_VARIATION_THRESHOLD,
            ],
        );
    }
}
