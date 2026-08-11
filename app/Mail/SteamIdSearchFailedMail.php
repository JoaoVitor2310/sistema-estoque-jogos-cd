<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Aviso de que o price_researcher não respondeu à busca de Steam IDs.
 *
 * Enquanto a busca falha, os jogos afetados seguem sem `steam_id` e ficam de
 * fora da atualização de popularidade.
 */
class SteamIdSearchFailedMail extends Mailable
{
    /**
     * @param  string  $summary  causa em uma linha ('HTTP 502', 'conexão falhou')
     * @param  string  $detail  corpo da resposta ou mensagem da exceção
     */
    public function __construct(
        public string $summary,
        public string $detail,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[Sistema Estoque] Erro na requisição do Price Researcher: '.$this->summary,
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<p>A busca de Steam IDs no price_researcher falhou.</p>'
                .'<p><strong>Causa:</strong> '.e($this->summary).'</p>'
                .'<p><strong>Detalhe:</strong></p>'
                .'<pre>'.e($this->detail).'</pre>'
                .'<p>Os jogos enviados continuam sem <code>steam_id</code> e serão'
                .' tentados de novo na próxima execução.</p>',
        );
    }
}
