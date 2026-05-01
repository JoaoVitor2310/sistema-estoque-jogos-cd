<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Chart from 'primevue/chart';
import { formatDateToBR } from '@/helpers/formatHelpers';

interface MonthlySales {
  count: number;
  gross_revenue: number;
  net_profit: number;
  avg_margin: number;
}

interface MonthlyPurchases {
  count: number;
  total_invested: number;
}

interface Stock {
  total_count: number;
  total_invested: number;
  total_simulated: number;
  listed_count: number;
  unlisted_count: number;
  expiring_count: number;
}

interface SoldGame {
  game_name: string;
  region: string | null;
  key_code: string;
  sold_price: number;
  sale_profit: number;
  sale_profit_percent: number;
  sold_at: string;
}

interface TrendEntry {
  month: string;
  count: number;
  gross_revenue: number;
  net_profit: number;
}

interface DashboardData {
  monthly_sales: MonthlySales;
  monthly_purchases: MonthlyPurchases;
  tf2_spent: number;
  stock: Stock;
  sold_games: SoldGame[];
  trend: TrendEntry[];
}

const props = defineProps<{
  data: DashboardData;
  year: number;
  month: number;
}>();

const selectedYear = ref(props.year);
const selectedMonth = ref(props.month);

const months = [
  { value: 1, label: 'Janeiro' }, { value: 2, label: 'Fevereiro' },
  { value: 3, label: 'Março' }, { value: 4, label: 'Abril' },
  { value: 5, label: 'Maio' }, { value: 6, label: 'Junho' },
  { value: 7, label: 'Julho' }, { value: 8, label: 'Agosto' },
  { value: 9, label: 'Setembro' }, { value: 10, label: 'Outubro' },
  { value: 11, label: 'Novembro' }, { value: 12, label: 'Dezembro' },
];

const currentYear = new Date().getFullYear();
const years = Array.from({ length: currentYear - 2023 }, (_, i) => 2024 + i).reverse();

function applyFilter() {
  router.get(route('financial'), { year: selectedYear.value, month: selectedMonth.value }, { preserveState: true });
}

function onFilterChange() {
  applyFilter();
}

const monthLabel = computed(() =>
  props.month === 0
    ? 'ANO COMPLETO / ' + props.year
    : months.find(m => m.value === props.month)?.label.toUpperCase() + ' / ' + props.year
);

const chartData = computed(() => ({
  labels: props.data.trend.map(t => {
    const [y, m] = t.month.split('-');
    return months[parseInt(m) - 1]?.label.slice(0, 3) + '/' + y.slice(2);
  }),
  datasets: [
    {
      label: 'Receita (€)',
      data: props.data.trend.map(t => t.gross_revenue),
      backgroundColor: 'rgba(128, 9, 239, 0.5)',
      borderColor: 'rgba(128, 9, 239, 1)',
      borderWidth: 1,
    },
    {
      label: 'Lucro (€)',
      data: props.data.trend.map(t => t.net_profit),
      backgroundColor: 'rgba(34, 197, 94, 0.5)',
      borderColor: 'rgba(34, 197, 94, 1)',
      borderWidth: 1,
    },
  ],
}));

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { position: 'top' as const },
  },
  scales: {
    y: { beginAtZero: true },
  },
};

function formatEur(val: number) {
  return '€ ' + val.toFixed(2);
}

function profitClass(val: number) {
  return val >= 0 ? 'text-success' : 'text-danger';
}
</script>

<template>
  <div class="container-fluid py-4 px-4">

    <!-- Filtro -->
    <div class="d-flex align-items-center gap-3 mb-4">
      <h4 class="mb-0 fw-bold">Financeiro</h4>
      <select v-model="selectedMonth" class="form-select form-select-sm w-auto" @change="onFilterChange">
        <option :value="0">Todos os meses</option>
        <option v-for="m in months" :key="m.value" :value="m.value">{{ m.label }}</option>
      </select>
      <select v-model="selectedYear" class="form-select form-select-sm w-auto" @change="onFilterChange">
        <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
      </select>
    </div>

    <!-- KPIs do mês -->
    <h6 class="text-muted mb-2 text-uppercase fw-semibold">{{ monthLabel }}</h6>
    <div class="row g-3 mb-4">
      <div class="col-6 col-md-3">
        <div class="card h-100 border-0 shadow-sm">
          <div class="card-body">
            <div class="text-muted small mb-1">Lucro líquido</div>
            <div class="fs-4 fw-bold" :class="profitClass(data.monthly_sales.net_profit)">
              {{ formatEur(data.monthly_sales.net_profit) }}
            </div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card h-100 border-0 shadow-sm">
          <div class="card-body">
            <div class="text-muted small mb-1">Receita bruta</div>
            <div class="fs-4 fw-bold">{{ formatEur(data.monthly_sales.gross_revenue) }}</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card h-100 border-0 shadow-sm">
          <div class="card-body">
            <div class="text-muted small mb-1">Keys vendidas</div>
            <div class="fs-4 fw-bold">{{ data.monthly_sales.count }}</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card h-100 border-0 shadow-sm">
          <div class="card-body">
            <div class="text-muted small mb-1">Margem média</div>
            <div class="fs-4 fw-bold" :class="profitClass(data.monthly_sales.avg_margin)">
              {{ data.monthly_sales.avg_margin }}%
            </div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card h-100 border-0 shadow-sm">
          <div class="card-body">
            <div class="text-muted small mb-1">Keys compradas</div>
            <div class="fs-4 fw-bold">{{ data.monthly_purchases.count }}</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card h-100 border-0 shadow-sm">
          <div class="card-body">
            <div class="text-muted small mb-1">Investido em compras</div>
            <div class="fs-4 fw-bold">{{ formatEur(data.monthly_purchases.total_invested) }}</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card h-100 border-0 shadow-sm">
          <div class="card-body">
            <div class="text-muted small mb-1">
              TF2 keys gastas <small class="text-muted">(trades únicas)</small>
            </div>
            <div class="fs-4 fw-bold">{{ data.tf2_spent.toFixed(2) }}</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Estoque + Gráfico -->
    <div class="row g-3 mb-4">
      <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
          <div class="card-body">
            <h6 class="fw-semibold mb-3">Estoque atual</h6>
            <table class="table table-sm mb-0">
              <tbody>
                <tr>
                  <td class="text-muted">Total em estoque</td>
                  <td class="fw-semibold text-end">{{ data.stock.total_count }} keys</td>
                </tr>
                <tr>
                  <td class="text-muted">Investido</td>
                  <td class="fw-semibold text-end">{{ formatEur(data.stock.total_invested) }}</td>
                </tr>
                <tr>
                  <td class="text-muted">Receita simulada</td>
                  <td class="fw-semibold text-end text-success">{{ formatEur(data.stock.total_simulated) }}</td>
                </tr>
                <tr>
                  <td class="text-muted">Listadas</td>
                  <td class="fw-semibold text-end">{{ data.stock.listed_count }}</td>
                </tr>
                <tr>
                  <td class="text-muted">Não listadas</td>
                  <td class="fw-semibold text-end">{{ data.stock.unlisted_count }}</td>
                </tr>
                <tr>
                  <td class="text-muted">Expirando em 30 dias</td>
                  <td class="fw-semibold text-end" :class="data.stock.expiring_count > 0 ? 'text-warning' : ''">
                    {{ data.stock.expiring_count }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="col-md-8">
        <div class="card border-0 shadow-sm h-100">
          <div class="card-body">
            <h6 class="fw-semibold mb-3">Evolução — últimos 12 meses</h6>
            <div style="height: 240px;">
              <Chart type="bar" :data="chartData" :options="chartOptions" style="height: 100%;" />
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Jogos vendidos -->
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-body">
        <h6 class="fw-semibold mb-3">Jogos vendidos — {{ monthLabel }}</h6>
        <div v-if="data.sold_games.length === 0" class="text-muted">Nenhuma venda neste período.</div>
        <table v-else class="table table-sm table-hover mb-0">
          <thead class="table-light">
            <tr>
              <th>Jogo</th>
              <th>Região</th>
              <th>Key</th>
              <th class="text-end">Preço vendido</th>
              <th class="text-end">Lucro</th>
              <th class="text-end">Margem</th>
              <th class="text-end">Data</th>
            </tr>
          </thead>
          <tbody>
            <tr class="table-light fw-semibold">
              <td colspan="3">Total</td>
              <td class="text-end">{{ formatEur(data.monthly_sales.gross_revenue) }}</td>
              <td class="text-end" :class="profitClass(data.monthly_sales.net_profit)">
                {{ formatEur(data.monthly_sales.net_profit) }}
              </td>
              <td class="text-end">{{ data.monthly_sales.avg_margin }}%</td>
              <td></td>
            </tr>
            <tr v-for="(game, i) in data.sold_games" :key="i">
              <td>{{ game.game_name }}</td>
              <td>{{ game.region ?? '—' }}</td>
              <td class="font-monospace small">{{ game.key_code ?? '—' }}</td>
              <td class="text-end">{{ formatEur(game.sold_price) }}</td>
              <td class="text-end fw-semibold" :class="profitClass(game.sale_profit)">
                {{ formatEur(game.sale_profit) }}
              </td>
              <td class="text-end" :class="profitClass(game.sale_profit_percent)">
                {{ game.sale_profit_percent }}%
              </td>
              <td class="text-end text-muted">{{ formatDateToBR(game.sold_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</template>
