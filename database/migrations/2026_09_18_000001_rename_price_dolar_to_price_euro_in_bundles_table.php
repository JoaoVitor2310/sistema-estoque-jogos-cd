<?php

use App\Services\External\CurrencyConversionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * O preço do bundle volta a ser guardado em euro (a coluna nasceu `price_euro`,
 * virou `price_dolar` em 2025_09_21_155144 e agora volta).
 *
 * Euro é a moeda em que o resto do sistema decide: a tela lê euro e o
 * `minimum_price_tf2` passa a dividir pelo `assets.price_euro` da TF2 key, sem
 * o desvio pelo dólar.
 *
 * O backfill converte os valores que já estão na base — renomear sem converter
 * deixaria montante em dólar sob rótulo de euro, número plausível e errado.
 * Sem cotação disponível ele não escreve nada e loga: a próxima sincronização
 * regrava o preço de todo bundle que a GGDeals ainda lista.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bundles', function (Blueprint $table) {
            $table->renameColumn('price_dolar', 'price_euro');
        });

        $this->convertExistingPrices('USD', 'EUR');
    }

    public function down(): void
    {
        $this->convertExistingPrices('EUR', 'USD');

        Schema::table('bundles', function (Blueprint $table) {
            $table->renameColumn('price_euro', 'price_dolar');
        });
    }

    /**
     * Reconverte os preços já persistidos.
     *
     * Sempre lê `price_euro`: o `up()` renomeia antes de converter e o `down()`
     * converte antes de renomear, então a coluna tem esse nome nas duas vezes.
     *
     * A cotação é buscada **uma vez** e aplicada às linhas em memória. Converter
     * linha por linha custaria uma chamada à AwesomeAPI por bundle — são
     * centenas na base, e o tier público é limitado por IP: o backfill tomaria
     * 429 no meio e deixaria metade da tabela em dólar sob rótulo de euro.
     */
    private function convertExistingPrices(string $from, string $to): void
    {
        // Query builder não aplica o escopo de soft-delete, e é o que se quer
        // aqui: bundle apagado restaurado precisa do preço na mesma moeda.
        $bundles = DB::table('bundles')->whereNotNull('price_euro')->get(['id', 'price_euro']);

        if ($bundles->isEmpty()) {
            return;
        }

        // Converter 1 unidade devolve o próprio fator de câmbio.
        $rate = app(CurrencyConversionService::class)->convertCurrency($from, $to, 1.0);

        if (! $rate['success']) {
            // Sem cotação, nada é reescrito: preço em dólar continua sendo um
            // número plausível e errado se rotulado como euro. A próxima
            // sincronização regrava o preço de todo bundle que a GGDeals lista.
            Log::error('Backfill de preço de bundle abortado: cotação indisponível', [
                'from' => $from,
                'to' => $to,
                'bundles' => $bundles->count(),
            ]);

            return;
        }

        foreach ($bundles as $bundle) {
            DB::table('bundles')->where('id', $bundle->id)->update([
                'price_euro' => round((float) $bundle->price_euro * $rate['amount'], 2),
                'updated_at' => now(),
            ]);
        }
    }
};
