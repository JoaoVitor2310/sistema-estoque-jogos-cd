<script setup lang="ts">
/**
 * O que aconteceu num mês do histórico.
 *
 * Fechar o mês tirava o extrato da tela: a lista do histórico dizia que o mês
 * existia e estava fechado, e mais nada — conferir um número de três meses
 * atrás só no banco. Este modal devolve a leitura sem devolver a escrita: o
 * mesmo extrato do mês em aberto, sem o botão de apagar e sem os formulários.
 *
 * O extrato é buscado ao abrir, não recebido pronto: a página manda só o
 * cabeçalho de cada mês fechado (ver `FinancialMonthService::details`).
 */
import { computed, ref, watch } from 'vue';
import Dialog from 'primevue/dialog';
import Tag from 'primevue/tag';
import Button from 'primevue/button';

import axiosInstance from '@/axios';
import { formatDateToBR } from '@/helpers/formatHelpers';
import MonthMovementsTable from './MonthMovementsTable.vue';
import {
  ACCOUNTS,
  brl,
  monthLabel,
  percentOf,
  tf2SummaryOf,
  totalBalanceOf,
  type Balances,
  type FinancialMonth,
  type Movement,
} from '@/helpers/financial';

const props = defineProps<{ month: FinancialMonth | null }>();

const visible = defineModel<boolean>('visible', { required: true });

const loading = ref(false);
const errorMessage = ref<string | null>(null);
const movements = ref<Movement[]>([]);
const balances = ref<Balances | null>(null);

const tf2 = computed(() => tf2SummaryOf(movements.value));

// Progresso só faz sentido enquanto ainda dá para comprar. No mês fechado os
// números são o resultado, e uma barra ao lado deles sugere um andamento que
// não existe mais — o mês acabou com o que acabou.
const showProgress = computed(() => props.month?.status === 'draft');
const total = computed(() => totalBalanceOf(balances.value));

const percents = computed(() => {
  const month = props.month;
  if (!month) return null;

  return {
    reinvestment: percentOf(month.reinvestment_percent),
    emergency: percentOf(month.emergency_percent),
    partnerOne: percentOf(month.partner_one_share),
  };
});

const load = async () => {
  const month = props.month;
  if (!month) return;

  loading.value = true;
  errorMessage.value = null;

  try {
    const { data } = await axiosInstance.get(`/financial-months/${month.id}`);

    movements.value = data.month?.movements ?? [];
    balances.value = data.balances ?? null;
  } catch {
    // Sem toast: o erro pertence a este modal, e um toast atrás dele some da
    // vista de quem está olhando para o extrato que não carregou.
    errorMessage.value = 'Não foi possível carregar o mês. Tente de novo.';
  } finally {
    loading.value = false;
  }
};

// Recarrega a cada abertura, e ao trocar de mês com o modal aberto. Guardar o
// que já foi buscado economizaria uma requisição e mostraria um extrato velho
// depois de uma reabertura.
watch(
  [visible, () => props.month?.id],
  ([open]) => {
    movements.value = [];
    balances.value = null;

    if (open) load();
  },
);
</script>

<template>
  <Dialog v-model:visible="visible" modal dismissableMask :style="{ width: 'min(1100px, 95vw)' }"
    :breakpoints="{ '960px': '95vw' }">
    <template #header>
      <div class="d-flex flex-wrap align-items-center gap-2">
        <span class="fw-bold fs-5">{{ month ? monthLabel(month) : '' }}</span>
        <Tag v-if="month?.status === 'closed'" value="Fechado" severity="success" />
        <Tag v-else value="Em aberto" severity="info" />
        <span v-if="month?.closed_at" class="text-muted small">
          fechado em {{ formatDateToBR(month.closed_at.slice(0, 10)) }}
        </span>
      </div>
    </template>

    <div v-if="loading" class="text-center text-muted py-5">
      <i class="pi pi-spin pi-spinner me-2" />Carregando o mês…
    </div>

    <div v-else-if="errorMessage" class="text-center py-5">
      <p class="text-danger mb-3">{{ errorMessage }}</p>
      <Button label="Tentar de novo" icon="pi pi-refresh" size="small" outlined @click="load" />
    </div>

    <template v-else>
      <!-- Saldos com que o mês terminou -->
      <div class="row g-2 mb-3">
        <div class="col-6 col-md-3" v-for="account in ACCOUNTS" :key="account.value">
          <div class="card h-100">
            <div class="card-body text-center py-2">
              <div class="text-muted small">{{ account.label }}</div>
              <div class="fs-6 fw-bold" :class="{ 'text-danger': (balances?.[account.value] ?? 0) < 0 }">
                {{ brl(balances?.[account.value] ?? 0) }}
              </div>
            </div>
          </div>
        </div>
        <div class="col-12">
          <div class="card border-primary">
            <div class="card-body d-flex justify-content-between align-items-center py-2">
              <span class="text-muted small">
                {{ month?.status === 'closed' ? 'Total da empresa no fechamento' : 'Total da empresa' }}
              </span>
              <span class="fs-6 fw-bold" :class="{ 'text-danger': total < 0 }">{{ brl(total) }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Verba de TF2: só quando houve meta, senão é uma linha de zeros -->
      <div v-if="tf2.allocated > 0" class="card mb-3">
        <div class="card-body py-2">
          <!-- Dois números, e nenhum derivado deles: a sobra da verba voltou ao
               Principal no fechamento e está no extrato abaixo, como
               lançamento. Repeti-la aqui em TF2 era um terceiro número sem par
               no resto da tela.

               `justify-content-around` e não um grid de colunas: com dois
               itens, a coluna do meio deixava um vão no meio do card. Espaço
               entre os dois **e** nas bordas — encostá-los nos extremos deixava
               o par largo demais para dois números curtos. -->
          <div class="d-flex flex-wrap align-items-center justify-content-around gap-3">
            <div>
              <div class="text-muted small">Meta de TF2</div>
              <div class="fw-bold">{{ tf2.allocated }} TF2</div>
            </div>

            <!-- No mês em aberto a barra entra entre os dois e come a sobra
                 da largura; no fechado ela não existe (ver showProgress). -->
            <div v-if="showProgress" class="progress order-1 flex-grow-1" style="height: 8px; min-width: 8rem;">
              <div class="progress-bar" :class="tf2.remaining < 0 ? 'bg-success' : 'bg-primary'"
                :style="{ width: `${tf2.percent}%` }" />
            </div>

            <div class="order-2 text-end">
              <div class="text-muted small">Comprado</div>
              <div class="fw-bold">{{ tf2.purchased }} TF2</div>
            </div>
          </div>
        </div>
      </div>

      <!-- As porcentagens valiam para este mês; o mês seguinte pode ter outras -->
      <p v-if="percents" class="text-muted small mb-2">
        Reinvestimento {{ percents.reinvestment }}% · Emergência {{ percents.emergency }}% ·
        Sócio 1 {{ percents.partnerOne }}% ·
        {{ movements.length }} {{ movements.length === 1 ? 'lançamento' : 'lançamentos' }}
      </p>

      <MonthMovementsTable :movements="movements" scrollHeight="min(50vh, 480px)"
        emptyMessage="Nenhum lançamento neste mês." />
    </template>
  </Dialog>
</template>
