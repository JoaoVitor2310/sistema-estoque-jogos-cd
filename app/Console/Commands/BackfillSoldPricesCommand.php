<?php

namespace App\Console\Commands;

use App\Domain\Enums\OrderPayoutAttribution;
use App\Services\Keys\KeyCalculationService;
use App\Services\Keys\KeyRepository;
use App\UseCases\Marketplaces\Gamivo\UpdateSoldOffersUseCase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Corrige sold_price de vendas gravadas errado, reconciliando com a Gamivo.
 *
 * O cron não faz isso sozinho: execute() nunca sobrescreve key já vendida, então
 * todo valor errado gravado antes da correção do rateio por linha é permanente.
 * Este comando ignora essa idempotência de propósito — e só ela.
 *
 * A seleção nunca é heurística: o valor recalculado vem do mesmo processOrder do
 * cron e só é gravado onde diverge do que está no banco. Uma venda já correta é
 * indistinguível de uma venda não processada aos olhos do comando: ambas passam.
 *
 * Reembolso lançado à mão é preservado — ver a regra no filtro de sold_price ≤ 0.
 */
class BackfillSoldPricesCommand extends Command
{
    protected $signature = 'gamivo:backfill-sold-prices
        {--days=30 : Janela em dias contados de hoje; ignorada se --since for passado}
        {--since= : Data inicial (Y-m-d) — use 2024-01-01 para varrer a base inteira}
        {--until= : Data final (Y-m-d); padrão hoje}
        {--order=* : Corrige só estes pedidos, consultados um a um (order_id); repetível}
        {--except=* : Deixa de fora um pedido ajustado à mão (order_id); repetível}
        {--except-key=* : Deixa de fora uma key específica (key_code); repetível}
        {--tolerance=0.01 : Diferença em euros ignorada como ruído de arredondamento}
        {--apply : Grava as correções; sem esta flag nada é alterado}';

    protected $description = 'Recalcula o payout das vendas na Gamivo e corrige os sold_price divergentes';

    public function handle(
        UpdateSoldOffersUseCase $useCase,
        KeyRepository $keyRepository,
        KeyCalculationService $calculationService,
    ): int {
        $tolerance = (float) $this->option('tolerance');
        $onlyOrders = (array) $this->option('order');
        $apply = (bool) $this->option('apply');

        $bar = null;

        $progress = function (int $done, int $total) use (&$bar): void {
            $bar ??= $this->output->createProgressBar($total);
            $bar->setProgress($done);
        };

        // Com uma lista de pedidos em mãos, o filtro `order` da API evita paginar o
        // histórico inteiro: dois requests por pedido em vez de milhares na janela
        if ($onlyOrders !== []) {
            $dateFrom = $dateTo = 'pedidos informados';

            $this->info(count($onlyOrders).' pedido(s) informado(s) — consultando um a um…');

            $breakdowns = $useCase->reconcileOrders($onlyOrders, $progress);
        } else {
            $dateTo = (string) ($this->option('until') ?: now()->toDateString());
            $dateFrom = (string) ($this->option('since')
                ?: now()->subDays((int) $this->option('days'))->toDateString());

            $this->info("Consultando a Gamivo — vendas de {$dateFrom} a {$dateTo}…");

            $breakdowns = $useCase->reconcileFromGamivo($dateFrom, $dateTo, $progress);
        }

        $bar?->finish();
        $this->newLine();

        $exceptOrders = (array) $this->option('except');

        if ($exceptOrders !== []) {
            $breakdowns = array_diff_key($breakdowns, array_flip($exceptOrders));
        }

        if ($breakdowns === []) {
            $this->warn('Nenhum pedido encontrado na janela.');

            return self::SUCCESS;
        }

        $exceptKeys = (array) $this->option('except-key');

        $divergences = [];
        $skippedOrders = [];
        $refunds = [];
        $manuallyExcluded = [];
        $unknownKeys = 0;

        foreach ($breakdowns as $orderId => $breakdown) {
            // Rateio igual é chute: trocar um valor errado por outro não é conserto
            if ($breakdown->attribution !== OrderPayoutAttribution::Matched
                && $breakdown->attribution !== OrderPayoutAttribution::PartiallyMatched) {
                $skippedOrders[$orderId] = $breakdown->attribution->value;

                continue;
            }

            $orderKeys = [];

            foreach ($breakdown->entries as $entry) {
                foreach ($entry['keys'] as $keyCode) {
                    $key = $keyRepository->findByKeyCode($keyCode);

                    if (! $key) {
                        $unknownKeys++;

                        continue;
                    }

                    if (in_array($key->key_code, $exceptKeys, true)) {
                        $manuallyExcluded[] = $key;

                        continue;
                    }

                    $orderKeys[] = [
                        'key' => $key,
                        'stored' => $key->sold_price === null ? null : (float) $key->sold_price,
                        'computed' => (float) $entry['profit'],
                        'sale_date' => $entry['saleDate'],
                    ];
                }
            }

            // O rateio errado gravava o MESMO valor em todas as keys do pedido, então
            // valor repetido entre irmãs é digital do bug — e não de um reembolso, que
            // é lançado key a key. Sem isso, uma key cujo rateio caiu por acaso sobre o
            // próprio custo seria confundida com venda desfeita e nunca corrigida
            $repeated = [];

            foreach (array_count_values(array_map(
                fn (array $k) => $k['stored'] === null ? 'null' : number_format($k['stored'], 2),
                $orderKeys,
            )) as $value => $times) {
                if ($times > 1) {
                    $repeated[$value] = true;
                }
            }

            foreach ($orderKeys as $orderKey) {
                ['key' => $key, 'stored' => $stored, 'computed' => $computed] = $orderKey;

                $splitFingerprint = $stored !== null
                        && isset($repeated[number_format($stored, 2)]);

                $reason = $stored === null
                    ? null
                    : self::refundSignature($stored, $key, $splitFingerprint);

                if ($reason !== null) {
                    $refunds[] = [
                        'order_id' => $orderId,
                        'key' => $key,
                        'stored' => $stored,
                        'reason' => $reason,
                    ];

                    continue;
                }

                // Arredondar antes de comparar: os dois lados são valores em
                // centavos, e a subtração crua devolve 0,0100000000000002
                if ($stored !== null && round(abs($stored - $computed), 2) <= $tolerance) {
                    continue;
                }

                $formulas = $calculationService->calculateSaleFormulas(
                    $computed,
                    (float) $key->individual_cost,
                );

                $divergences[] = [
                    'order_id' => $orderId,
                    'key' => $key,
                    'stored' => $stored ?? 0.0,
                    'never_written' => $stored === null,
                    'computed' => $computed,
                    'sale_date' => $orderKey['sale_date'],
                    'attribution' => $breakdown->attribution->value,
                    'before' => [
                        'sold_at' => $key->sold_at,
                        'sold_price' => $stored,
                        'sale_profit' => (float) $key->sale_profit,
                        'sale_profit_percent' => (float) $key->sale_profit_percent,
                    ],
                    'after' => [
                        'sold_at' => $orderKey['sale_date'],
                        'sold_price' => $computed,
                        'sale_profit' => $formulas['sale_profit'],
                        'sale_profit_percent' => $formulas['sale_profit_percent'],
                    ],
                ];
            }
        }

        $this->renderReport($breakdowns, $divergences, $skippedOrders, $refunds, $manuallyExcluded, $unknownKeys);

        $auditPath = $this->writeAudit(
            $dateFrom,
            $dateTo,
            $divergences,
            $refunds,
            $skippedOrders,
            $manuallyExcluded,
            $unknownKeys,
            applied: false,
        );

        $this->newLine();
        $this->line("Trilha de auditoria: {$auditPath}");

        if ($divergences === []) {
            return self::SUCCESS;
        }

        if (! $apply) {
            $this->newLine();
            $this->comment('Nada foi alterado. Rode de novo com --apply para gravar.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Gravar as '.count($divergences).' correção(ões) acima?', false)) {
            $this->warn('Cancelado.');

            return self::SUCCESS;
        }

        $written = $this->apply($divergences);

        $auditPath = $this->writeAudit(
            $dateFrom,
            $dateTo,
            $divergences,
            $refunds,
            $skippedOrders,
            $manuallyExcluded,
            $unknownKeys,
            applied: true,
        );

        $this->info("{$written} key(s) corrigida(s).");
        $this->line("Trilha de auditoria: {$auditPath}");

        return self::SUCCESS;
    }

    /**
     * Grava o antes/depois de cada key num JSON, para conferir depois o que mudou
     * e por quê — inclusive o que foi deliberadamente preservado.
     *
     * @param  array<int, array<string, mixed>>  $divergences
     * @param  array<int, array<string, mixed>>  $refunds
     * @param  array<string, string>  $skippedOrders
     * @param  array<int, \App\Models\Key>  $manuallyExcluded
     */
    private function writeAudit(
        string $dateFrom,
        string $dateTo,
        array $divergences,
        array $refunds,
        array $skippedOrders,
        array $manuallyExcluded,
        int $unknownKeys,
        bool $applied,
    ): string {
        $payload = [
            'generated_at' => now()->toIso8601String(),
            'window' => ['from' => $dateFrom, 'to' => $dateTo],
            'applied' => $applied,
            'tolerance' => (float) $this->option('tolerance'),
            'totals' => [
                'divergences' => count($divergences),
                'refunds_preserved' => count($refunds),
                'manually_excluded' => count($manuallyExcluded),
                'orders_without_basis' => count($skippedOrders),
                'keys_outside_base' => $unknownKeys,
                'unrecorded_revenue' => round(
                    array_sum(array_map(fn (array $d) => $d['computed'] - $d['stored'], $divergences)),
                    2,
                ),
            ],
            'corrections' => array_map(fn (array $d) => [
                'order_id' => $d['order_id'],
                'key_id' => $d['key']->id,
                'key_code' => $d['key']->key_code,
                'game_name' => $d['key']->game_name,
                'gamivo_id' => $d['key']->gamivo_id,
                'individual_cost' => (float) $d['key']->individual_cost,
                'attribution' => $d['attribution'],
                'reason' => $d['never_written'] ? 'venda sem baixa' : 'rateio por linha',
                'before' => $d['before'],
                'after' => $d['after'],
            ], $divergences),
            'preserved_refunds' => array_map(fn (array $r) => [
                'order_id' => $r['order_id'],
                'key_id' => $r['key']->id,
                'key_code' => $r['key']->key_code,
                'game_name' => $r['key']->game_name,
                'sold_price' => $r['stored'],
                'individual_cost' => (float) $r['key']->individual_cost,
                'sale_profit' => (float) $r['key']->sale_profit,
                'reason' => $r['reason'],
            ], $refunds),
            'orders_without_basis' => $skippedOrders,
            'manually_excluded' => array_map(fn ($key) => [
                'key_id' => $key->id,
                'key_code' => $key->key_code,
                'game_name' => $key->game_name,
                'sold_price' => (float) $key->sold_price,
            ], $manuallyExcluded),
        ];

        $path = 'diagnostics/backfill-sold-prices-'.now()->format('Y-m-d_His')
            .($applied ? '-applied' : '-dry-run').'.json';

        Storage::disk('local')->put(
            $path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        return Storage::disk('local')->path($path);
    }

    /**
     * Reconhece um lançamento manual de venda desfeita, que o backfill nunca sobrescreve.
     * Ver o verbete "Venda reembolsada" em CONTEXT.md.
     *
     * @param  \App\Models\Key  $key
     */
    private static function refundSignature(float $stored, $key, bool $splitFingerprint): ?string
    {
        // Payout de venda real é sempre positivo: o fornecedor não devolveu nem a key
        // nem a taxa de €1, e o prejuízo dos dois foi lançado à mão
        if ($stored <= 0) {
            return 'prejuízo lançado à mão';
        }

        // Valor repetido entre keys do mesmo pedido veio do rateio, não de um lançamento
        if ($splitFingerprint) {
            return null;
        }

        // O fornecedor devolveu key e taxa: a venda foi zerada contra o próprio custo
        $zeroedAtCost = round(abs($stored - (float) $key->individual_cost), 2) === 0.0
            && round(abs((float) $key->sale_profit), 2) === 0.0;

        if ($zeroedAtCost) {
            return 'zerada contra o custo';
        }

        // O fornecedor devolveu a key, mas não a taxa: sobrou exatamente a punição
        // de €1 da Gamivo como prejuízo, e o custo foi recuperado por inteiro
        $feeOnlyLoss = round((float) $key->sale_profit, 2) === -1.0
            && round((float) $key->individual_cost - $stored, 2) === 1.0;

        return $feeOnlyLoss ? 'só a taxa de €1 perdida' : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $divergences
     */
    private function apply(array $divergences): int
    {
        $written = 0;

        DB::transaction(function () use ($divergences, &$written) {
            foreach ($divergences as $divergence) {
                $divergence['key']->update($divergence['after']);

                $written++;
            }
        });

        return $written;
    }

    /**
     * @param  array<string, mixed>  $breakdowns
     * @param  array<int, array<string, mixed>>  $divergences
     * @param  array<string, string>  $skippedOrders
     * @param  array<int, array<string, mixed>>  $refunds
     * @param  array<int, \App\Models\Key>  $manuallyExcluded
     */
    private function renderReport(
        array $breakdowns,
        array $divergences,
        array $skippedOrders,
        array $refunds,
        array $manuallyExcluded,
        int $unknownKeys,
    ): void {
        $this->newLine();
        $this->line(sprintf(
            '%d pedido(s) analisado(s) · %d divergência(s) · %d pedido(s) sem base para corrigir · %d reembolso(s) preservado(s) · %d key(s) fora da base',
            count($breakdowns),
            count($divergences),
            count($skippedOrders),
            count($refunds),
            $unknownKeys,
        ));

        if ($divergences !== []) {
            $this->newLine();
            $this->table(
                ['Pedido', 'Key', 'Jogo', 'Gravado', 'Correto', 'Diferença'],
                array_map(fn (array $d) => [
                    substr($d['order_id'], 0, 8).'…',
                    substr($d['key']->key_code, 0, 12).'…',
                    substr((string) $d['key']->game_name, 0, 28),
                    $d['never_written'] ? '— sem baixa' : number_format($d['stored'], 2),
                    number_format($d['computed'], 2),
                    sprintf('%+.2f', $d['computed'] - $d['stored']),
                ], $divergences),
            );

            $total = array_sum(array_map(fn (array $d) => $d['computed'] - $d['stored'], $divergences));
            $this->line(sprintf('Receita não contabilizada: %+.2f', $total));
        }

        if ($manuallyExcluded !== []) {
            $this->newLine();
            $this->warn('Keys excluídas à mão (--except-key):');

            foreach ($manuallyExcluded as $key) {
                $this->line(sprintf('  %s — %s: %s', $key->key_code, $key->game_name, number_format((float) $key->sold_price, 2)));
            }
        }

        if ($refunds !== []) {
            $this->newLine();
            $this->warn('Reembolsos preservados (valor lançado à mão, não sobrescrito):');

            foreach ($refunds as $refund) {
                $this->line(sprintf(
                    '  %s — %s: %s',
                    substr($refund['order_id'], 0, 8).'…',
                    $refund['key']->game_name,
                    number_format($refund['stored'], 2).' — '.$refund['reason'],
                ));
            }
        }

        if ($skippedOrders !== []) {
            $this->newLine();
            $this->warn('Pedidos ignorados (o recálculo também não sabe o valor por key):');

            foreach ($skippedOrders as $orderId => $attribution) {
                $this->line("  {$orderId} — {$attribution}");
            }
        }
    }
}
