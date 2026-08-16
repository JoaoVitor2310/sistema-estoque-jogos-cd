<?php

use App\Domain\Trades\LegacyTradeLine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_lines', function (Blueprint $table) {
            $table->id();

            // cascadeOnDelete (e não nullOnDelete como keys.trade_id): a key
            // sobrevive à trade, a linha não tem existência fora dela.
            $table->foreignId('trade_id')
                ->constrained('trades')
                ->cascadeOnDelete();

            // Ordem de exibição explícita — duplicar uma linha insere logo abaixo
            // da original, e isso precisa sobreviver ao recarregamento.
            $table->unsignedInteger('position');

            // Nulável ao contrário de keys.game_name/key_code: linha em branco é
            // estado normal de trade — significa "ainda não preenchido".
            $table->string('game_name')->nullable();
            $table->decimal('market_price', 8, 2)->nullable();
            $table->unsignedInteger('popularity')->nullable();
            $table->string('region')->nullable();
            $table->string('bundle')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('key_code')->nullable();
            $table->string('gamivo_id')->nullable();

            $table->timestamps();

            $table->index(['trade_id', 'position']);
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_lines');
    }

    /**
     * Converte `trades.games` em linhas. A coluna JSON permanece intacta e
     * segue sendo a fonte de leitura — é removida só numa migration posterior,
     * depois de a conversão ser conferida em produção.
     */
    private function backfill(): void
    {
        $now = now();

        DB::table('trades')->orderBy('id')->chunkById(200, function ($trades) use ($now) {
            $rows = [];

            foreach ($trades as $trade) {
                $entries = json_decode($trade->games ?? '[]', true);

                if (! is_array($entries)) {
                    continue;
                }

                $position = 0;

                foreach ($entries as $entry) {
                    if (! is_array($entry)) {
                        continue;
                    }

                    $rows[] = LegacyTradeLine::toAttributes($entry, $position++) + [
                        'trade_id' => $trade->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            if ($rows !== []) {
                foreach (array_chunk($rows, 500) as $batch) {
                    DB::table('trade_lines')->insert($batch);
                }
            }
        });
    }
};
