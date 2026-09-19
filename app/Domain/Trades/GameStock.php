<?php

namespace App\Domain\Trades;

use App\Domain\Games\GameNameNormalizer;
use Carbon\Carbon;

/**
 * O estoque de um jogo numa região, na forma que a sinalização de encalhe lê.
 *
 * Três números do mesmo conceito — quantas keys paradas, desde quando, e
 * quantas saíram na janela de vendas — então eles viajam juntos em vez de
 * virarem parâmetros soltos de [[OverstockPolicy]].
 *
 * A identidade é o par **nome normalizado + região** ([[self::identityOf]]):
 *
 *  - O nome passa pelo [[GameNameNormalizer]] porque o mesmo jogo entra no
 *    estoque escrito de formas diferentes ("Alien Shooter 2: Reloaded" e
 *    "Alien Shooter 2 Reloaded"), e contar cada grafia como um jogo
 *    subestimaria o estoque justamente onde ele é maior.
 *  - A região separa porque estoque parado de uma região não vira venda em
 *    outra: somar EU com ROW deixaria um EU que vende esconder um ROW
 *    encalhado, ou um ROW encalhado condenar um EU que vende.
 *  - Região nula **é** uma região — a global, que não se escreve. Ela forma o
 *    próprio grupo, como qualquer outra.
 */
final readonly class GameStock
{
    /**
     * @param  string  $normalizedName  nome pelo [[GameNameNormalizer]]
     * @param  string|null  $region  região canônica ([[self::canonicalRegion]]); null = global
     * @param  string  $displayName  uma das grafias cruas, para exibir
     * @param  int  $stock  keys compradas e ainda não vendidas
     * @param  Carbon|null  $oldestAcquiredAt  aquisição da key parada mais antiga (null = estoque sem data)
     * @param  int  $soldInWindow  keys vendidas na janela de [[OverstockPolicy::SALES_WINDOW_DAYS]]
     */
    public function __construct(
        public string $normalizedName,
        public ?string $region,
        public string $displayName,
        public int $stock,
        public ?Carbon $oldestAcquiredAt,
        public int $soldInWindow,
    ) {}

    /**
     * A chave que junta keys e linhas do mesmo jogo na mesma região.
     *
     * Existe como função, e não como concatenação em cada chamador, porque dois
     * lados precisam produzir a mesma string — o estoque agregado e a linha da
     * trade —, e uma divergência entre eles não falha: só deixa de sinalizar.
     */
    public static function identityOf(string $gameName, ?string $region): string
    {
        return self::key(GameNameNormalizer::normalize($gameName), self::canonicalRegion($region));
    }

    /**
     * Região como a identidade a compara: sem espaço nas pontas e em
     * maiúsculas, porque `LATAM` e `latam` são a mesma região escrita duas
     * vezes. Vazia é global, igual a nula.
     *
     * Sinônimos (`US`/`USA`/`NA`) **não** são unidos aqui: decidir que duas
     * siglas são o mesmo mercado é canonizar regiões, pendência própria em
     * docs/IMPROVEMENTS.md.
     */
    public static function canonicalRegion(?string $region): ?string
    {
        $trimmed = mb_strtoupper(trim((string) $region));

        return $trimmed === '' ? null : $trimmed;
    }

    /** A identidade deste estoque — a mesma chave que [[self::identityOf]] monta do par cru. */
    public function identity(): string
    {
        return self::key($this->normalizedName, $this->region);
    }

    /**
     * Há quantos dias a key parada mais antiga está em estoque.
     *
     * `null` quando o estoque não tem data — a mesma ausência que faz
     * [[OverstockPolicy::isOverstocked]] não sinalizar o jogo.
     */
    public function daysInStock(?Carbon $now = null): ?int
    {
        if ($this->oldestAcquiredAt === null) {
            return null;
        }

        return (int) $this->oldestAcquiredAt->diffInDays($now ?? Carbon::now());
    }

    /**
     * Soma duas grafias do mesmo jogo na mesma região.
     *
     * O nome exibido é o da grafia com mais keys paradas — é a que a equipe
     * mais vê na tela de Keys. Empate fica com a que já estava.
     */
    public function combine(self $other): self
    {
        return new self(
            $this->normalizedName,
            $this->region,
            $other->stock > $this->stock ? $other->displayName : $this->displayName,
            $this->stock + $other->stock,
            $this->earliestAcquisition($other->oldestAcquiredAt),
            $this->soldInWindow + $other->soldInWindow,
        );
    }

    /** O formato da chave, escrito uma vez só para os dois lados. */
    private static function key(string $normalizedName, ?string $canonicalRegion): string
    {
        return $normalizedName.'|'.($canonicalRegion ?? '');
    }

    private function earliestAcquisition(?Carbon $other): ?Carbon
    {
        if ($this->oldestAcquiredAt === null || $other === null) {
            return $this->oldestAcquiredAt ?? $other;
        }

        return $other->lessThan($this->oldestAcquiredAt) ? $other : $this->oldestAcquiredAt;
    }
}
