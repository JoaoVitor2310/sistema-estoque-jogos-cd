<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Aviso de que a conversão do preço de um bundle para USD falhou.
 *
 * Sem o preço em dólar não dá para calcular `minimum_price_tf2`, então o bundle
 * é pulado na sincronização e fica sem preço até a próxima rodada.
 */
class BundlePriceConversionFailedMail extends Mailable
{
    public function __construct(public string $bundleTitle) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '🎮 Erro ao converter preço do bundle: '.$this->bundleTitle,
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<p>Não foi possível converter o preço do bundle'
                .' <strong>'.e($this->bundleTitle).'</strong> para USD.</p>'
                .'<p>O bundle foi pulado nesta sincronização e segue sem preço.</p>',
        );
    }
}
