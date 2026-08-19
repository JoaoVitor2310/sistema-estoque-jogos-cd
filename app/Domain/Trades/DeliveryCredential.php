<?php

namespace App\Domain\Trades;

/**
 * O segredo que dá ao supplier acesso à entrega de **uma** trade.
 *
 * O link (`/deliveries/{uuid}`) endereça; este token é o que protege. Os dois
 * viajam juntos na mesma mensagem da Steam, mas só o token entra no corpo do
 * POST — a URL vaza por canais fora do nosso controle (histórico do navegador,
 * log de servidor e de proxy, header `Referer`, preview de link do chat), e o
 * corpo de um POST não passa por nenhum deles. Ver docs/adr/0008.
 *
 * "Digitado" aqui quer dizer "não vai na URL", não "curto": o caso normal é
 * colar. O alfabeto sem caracteres ambíguos existe para o caso de exceção —
 * digitar no celular — e é o que torna a normalização abaixo previsível.
 *
 * O token é **guardado encriptado, não em hash**, porque fica à vista na aba de
 * Trades — a equipe copia o par link + código quando precisar, e reconsultar
 * exige poder lê-lo de volta. Não é uma senha: ele guarda uma página que exibe
 * os `key_code` daquela trade, e esses estão em claro na mesma tabela ao lado.
 * Ver docs/adr/0008.
 */
final class DeliveryCredential
{
    /** ~80 bits sobre o alfabeto abaixo. Força bruta é irrelevante nessa escala. */
    public const TOKEN_LENGTH = 16;

    /** Exibido em blocos, `XXXX-XXXX-XXXX-XXXX`, para ser conferido a olho. */
    public const TOKEN_GROUP_SIZE = 4;

    /**
     * Crockford base32: os dígitos e as letras, menos `I`, `L`, `O` e `U`. As
     * três primeiras saem por serem confundíveis com `1` e `0`; `U` sai para não
     * formar palavra ofensiva por acaso num token que vai para um terceiro.
     */
    public const TOKEN_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * Prazo próprio da sessão da entrega, em minutos.
     *
     * O `SESSION_LIFETIME` do projeto é de 7 dias, escolhido para a equipe não
     * relogar. Herdá-lo daria uma semana de acesso ao dispositivo do supplier
     * sem que isso tivesse sido decidido; 12h cobrem um preenchimento longo e a
     * volta no mesmo dia.
     */
    public const SESSION_TTL_MINUTES = 720;

    /**
     * Tentativas de token por entrega e por IP, dentro da janela abaixo.
     *
     * Generosos de propósito: com ~80 bits o limite não segura força bruta,
     * segura ruído de enumeração. Apertar mais só produz falso positivo em cima
     * de quem tem direito de entrar. Os dois eixos existem porque só por IP um
     * atacante distribui, e só por entrega dá para travar de propósito a entrega
     * de um supplier legítimo.
     */
    public const MAX_ATTEMPTS_PER_DELIVERY = 10;

    public const MAX_ATTEMPTS_PER_IP = 30;

    public const ATTEMPT_WINDOW_MINUTES = 60;

    /** Caracteres que o Crockford dobra sobre um dígito ao ler. */
    private const FOLDED = ['O' => '0', 'I' => '1', 'L' => '1'];

    /**
     * A credencial de uma trade, pronta para gravar.
     *
     * Endereço e segredo nascem juntos porque um sem o outro não é credencial —
     * e é aqui, num lugar só, que "toda trade nasce com um par" fica dito. Os
     * três UseCases que criam trade gravam o retorno; ninguém mais escreve
     * nessas colunas, e é essa unicidade de escritor que garante um código por
     * trade, sem precisar de guarda em tempo de execução.
     *
     * @return array{delivery_uuid: string, delivery_token: string}
     */
    public static function issue(): array
    {
        return [
            'delivery_uuid' => self::uuid(),
            'delivery_token' => self::generate(),
        ];
    }

    /**
     * UUID **v4**, escrito à mão em vez de `Str::uuid()`.
     *
     * Duas razões. O Domain deste projeto não importa nada do Illuminate, e não
     * vale abrir a exceção por um gerador. E a versão é decisão registrada, não
     * detalhe: v1 e v7 embutem timestamp e recriariam a ordenação que o id
     * sequencial expunha — que é justamente o motivo de a URL não usar o id. Ao
     * escrever os bits aqui, essa decisão para de depender de qual versão o
     * helper do framework devolve.
     */
    private static function uuid(): string
    {
        $bytes = random_bytes(16);

        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40); // versão 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80); // variante RFC 4122

        $hex = bin2hex($bytes);

        return implode('-', [
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ]);
    }

    public static function generate(): string
    {
        $alphabet = self::TOKEN_ALPHABET;
        $last = strlen($alphabet) - 1;

        $raw = '';

        for ($i = 0; $i < self::TOKEN_LENGTH; $i++) {
            $raw .= $alphabet[random_int(0, $last)];
        }

        return implode('-', str_split($raw, self::TOKEN_GROUP_SIZE));
    }

    /**
     * O que o supplier digitou, reduzido à forma canônica.
     *
     * Recusar uma grafia aceitável seria transformar em erro de credencial o que
     * é só a forma como a pessoa leu o token: minúscula, sem os traços de
     * exibição, ou com `O` no lugar de `0`.
     */
    public static function normalize(string $typed): string
    {
        $upper = strtoupper($typed);
        $folded = strtr($upper, self::FOLDED);

        return preg_replace('/[^A-Z0-9]/', '', $folded) ?? '';
    }

    /**
     * Marca do token, para amarrar a sessão do supplier à credencial vigente.
     *
     * Não é como o token é guardado — a coluna guarda o token encriptado, e não
     * esta marca. Ela existe só para a sessão poder dizer "fui aberta com aquele
     * token" sem carregar o token; o que a rotação precisa comparar é isso.
     */
    public static function fingerprint(string $token): string
    {
        return hash('sha256', self::normalize($token));
    }

    /**
     * `$stored` é nulável porque uma trade sem credencial não tem token — e
     * nesse estado nada pode conferir.
     *
     * Comparação em tempo constante, pelas formas canônicas dos dois lados: o
     * guardado veio da geração, o digitado veio de uma pessoa lendo um chat.
     */
    public static function matches(string $typed, ?string $stored): bool
    {
        if ($stored === null || $stored === '') {
            return false;
        }

        return hash_equals(self::normalize($stored), self::normalize($typed));
    }
}
