<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Notificação enviada quando o token da API Gamivo expira.
 * Token precisa ser rotacionado manualmente no .env da VPS.
 */
class GamivoTokenExpiredMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[Sistema Estoque] Token Gamivo expirado',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<p>O token da API Gamivo expirou.</p>'
                .'<p>Acesse o painel Gamivo, gere um novo token e atualize <code>API_KEY_GAMIVO</code> no <code>.env</code> da VPS.</p>'
                .'<p>Em seguida execute:<br><code>docker exec app-cd php artisan config:cache</code></p>',
        );
    }
}
