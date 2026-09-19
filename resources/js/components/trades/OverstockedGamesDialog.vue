<script setup lang="ts">
/**
 * Os jogos encalhados hoje — os que fazem a linha da trade nascer com o aviso
 * "Encalhado".
 *
 * O aviso na linha diz só que a regra agiu; esta lista diz por quê. Por isso o
 * modal explica a regra ao lado dos números que a dispararam, por jogo e região.
 *
 * A lista é buscada ao abrir, nunca junto da aba: ela sai de uma agregação
 * sobre a tabela de keys e quase ninguém que abre Trades quer vê-la.
 */
import { ref, watch } from 'vue';
import Dialog from 'primevue/dialog';
import Button from 'primevue/button';

import axiosInstance from '@/axios';

/** Um jogo encalhado numa região, como o servidor manda. */
interface OverstockedGame {
  name: string;
  /** null = global; no sistema inteiro, a região global não é escrita. */
  region: string | null;
  stock: number;
  days_in_stock: number | null;
  sold_in_window: number;
  /** Dias para o estoque escoar no ritmo atual; null quando não houve venda. */
  coverage_days: number | null;
}

/** Espelho das constantes de App\Domain\Trades\OverstockPolicy. */
interface OverstockRule {
  min_stock: number;
  min_age_days: number;
  sales_window_days: number;
  max_coverage_days: number;
}

const visible = defineModel<boolean>('visible', { required: true });

const loading = ref(false);
const errorMessage = ref<string | null>(null);
const games = ref<OverstockedGame[]>([]);
const rule = ref<OverstockRule | null>(null);

// Cada abertura recebe um número; só a resposta da requisição mais recente
// escreve na tela. Sem isso, fechar e reabrir depressa deixa duas em voo, e a
// mais velha sobrescreve a mais nova — inclusive uma falha velha por cima de
// uma lista que carregou.
let currentRequest = 0;

const load = async () => {
  const request = ++currentRequest;

  loading.value = true;
  errorMessage.value = null;

  try {
    const { data } = await axiosInstance.get(route('trades.overstocked-games'));

    if (request !== currentRequest) return;

    games.value = data.games ?? [];
    rule.value = data.rule ?? null;
  } catch {
    if (request !== currentRequest) return;

    // Sem toast: o erro pertence a este modal, e um toast atrás dele some da
    // vista de quem está olhando para a lista que não carregou.
    errorMessage.value = 'Não foi possível carregar os jogos encalhados. Tente de novo.';
  } finally {
    if (request === currentRequest) loading.value = false;
  }
};

// Recarrega a cada abertura: o estoque muda a cada importação e a cada venda, e
// uma lista guardada mostraria encalhe que já escoou.
watch(visible, (open) => {
  if (open) load();
});
</script>

<template>
  <Dialog v-model:visible="visible" modal dismissableMask :style="{ width: 'min(900px, 95vw)' }"
    :breakpoints="{ '960px': '95vw' }">
    <template #header>
      <div class="d-flex flex-wrap align-items-center gap-2">
        <span class="fw-bold fs-5">Jogos encalhados</span>
        <span v-if="!loading && !errorMessage" class="badge bg-secondary">{{ games.length }}</span>
      </div>
    </template>

    <!-- Curto de propósito: o que a regra faz cabe em duas linhas, e a conta do
         escoamento é explicada junto da coluna que a mostra. -->
    <p v-if="rule" class="text-muted small mb-3">
      Encalhado numa região = <strong>{{ rule.min_stock }}+ keys</strong>,
      <strong>a mais antiga parada há {{ rule.min_age_days }}+ dias</strong> e
      <strong>escoamento acima de {{ rule.max_coverage_days }} dias</strong> — as três juntas.
      Esses jogos ficam fora do comentário ao fornecedor, mas continuam na trade, marcados.
    </p>

    <div v-if="loading" class="text-center text-muted py-5">
      <i class="pi pi-spin pi-spinner me-2" />Carregando os jogos encalhados…
    </div>

    <div v-else-if="errorMessage" class="text-center py-5">
      <p class="text-danger mb-3">{{ errorMessage }}</p>
      <Button label="Tentar de novo" icon="pi pi-refresh" size="small" outlined @click="load" />
    </div>

    <p v-else-if="games.length === 0" class="text-center text-muted py-5 mb-0">
      Nenhum jogo está encalhado hoje.
    </p>

    <!-- Acima da tabela, não depois: a lista é longa, e a explicação no rodapé só
         era lida por quem rolasse até o fim. -->
    <template v-else>
      <p v-if="rule" class="text-muted small mb-2">
        <strong>Escoa em</strong>: em quantos dias o estoque acabaria no ritmo das vendas dos últimos
        {{ rule.sales_window_days }} dias — estoque × {{ rule.sales_window_days }} ÷ vendas.
        9 keys com 4 vendas escoam em {{ Math.ceil(9 * rule.sales_window_days / 4) }} dias;
        6 keys com 3 vendas, em {{ 6 * rule.sales_window_days / 3 }}.
        Sem venda na janela não há ritmo para projetar: <span class="text-danger">não vende</span>.
      </p>

      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr class="text-muted small">
              <th>Jogo</th>
              <th>Região</th>
              <th class="text-end">Em estoque</th>
              <th class="text-end">Parado há</th>
              <!-- A janela vem do servidor, nunca de um número escrito aqui: a
                   constante mora no Domain, e uma cópia no front mentiria no dia
                   em que ela mudasse. -->
              <th class="text-end">Vendas<span v-if="rule"> em {{ rule.sales_window_days }} dias</span></th>
              <th class="text-end" title="Estoque ÷ ritmo de venda da janela">Escoa em</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="game in games" :key="`${game.name}|${game.region ?? ''}`">
              <td>{{ game.name }}</td>
              <!-- Região vazia é a global: em todo o sistema, a global não se
                   escreve — só as restritas (EU, ROW…) ganham texto. -->
              <td>{{ game.region }}</td>
              <td class="text-end">{{ game.stock }}</td>
              <td class="text-end">
                {{ game.days_in_stock === null ? '—' : `${game.days_in_stock} dias` }}
              </td>
              <td class="text-end">{{ game.sold_in_window }}</td>
              <td class="text-end">
                <!-- Sem venda na janela não há ritmo para projetar prazo nenhum. -->
                <span v-if="game.coverage_days === null" class="text-danger">não vende</span>
                <span v-else>{{ game.coverage_days }} dias</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </Dialog>
</template>
