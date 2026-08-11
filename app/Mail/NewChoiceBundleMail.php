<?php

namespace App\Mail;

use App\Models\Bundle;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Aviso de Choice novo detectado na GG.deals.
 *
 * Lançamento de bundle derruba o preço dos jogos que ele contém, o que abre
 * janela de compra barata — por isso o alerta é imediato.
 */
class NewChoiceBundleMail extends Mailable
{
    public function __construct(public Bundle $bundle) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '🎮 Choice novo: '.$this->bundle->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<p>Choice novo detectado: <strong>'.e($this->bundle->name).'</strong></p>'
                .'<p><a href="'.e($this->bundle->url).'">'.e($this->bundle->url).'</a></p>',
        );
    }
}
