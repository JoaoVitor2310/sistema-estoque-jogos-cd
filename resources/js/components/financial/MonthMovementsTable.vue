<script setup lang="ts">
/**
 * O extrato de um mês — a mesma tabela para o mês em aberto e para o mês do
 * histórico.
 *
 * Uma tabela só porque "o que aconteceu no mês" é a mesma leitura nos dois
 * lugares; o que muda é só poder apagar, e isso é o mês estar em aberto. Duas
 * tabelas parecidas divergiriam na primeira coluna nova.
 */
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import Tag from 'primevue/tag';

import { formatDateToBR } from '@/helpers/formatHelpers';
import {
  accountLabel,
  brl,
  groupSizeOf,
  isDeletable,
  movementLabel,
  signedAmount,
  subcategoryLabel,
  type Movement,
} from '@/helpers/financial';

const props = withDefaults(defineProps<{
  movements: Movement[];
  /** Mês fechado não apaga lançamento — o domínio recusa, e a coluna some. */
  deletable?: boolean;
  scrollHeight?: string;
  emptyMessage?: string;
}>(), {
  deletable: false,
  scrollHeight: 'min(55vh, 560px)',
  emptyMessage: 'Nenhum lançamento ainda.',
});

const emit = defineEmits<{ (e: 'delete', event: Event, movement: Movement): void }>();

// Uma transferência vira duas linhas; apagar leva o par junto.
const groupSize = (movement: Movement): number => groupSizeOf(props.movements, movement);
</script>

<template>
  <DataTable :value="movements" showGridlines size="small" scrollable :scrollHeight="scrollHeight" dataKey="id"
    tableStyle="min-width: 40rem;">
    <template #empty>{{ emptyMessage }}</template>
    <Column field="occurred_at" header="Data" :style="{ width: '7rem' }">
      <template #body="{ data }">{{ formatDateToBR(data.occurred_at) }}</template>
    </Column>
    <Column field="category" header="Lançamento">
      <template #body="{ data }">{{ movementLabel(data) }}</template>
    </Column>
    <Column field="account_type" header="Conta" :style="{ width: '9rem' }">
      <template #body="{ data }">{{ accountLabel(data.account_type) }}</template>
    </Column>
    <Column header="Categoria" :style="{ width: '9rem' }">
      <template #body="{ data }">{{ subcategoryLabel(data) }}</template>
    </Column>
    <Column field="amount" header="Valor" :style="{ width: '9rem' }">
      <template #body="{ data }">
        <span :class="data.direction === 'credit' ? 'text-success' : 'text-danger'">{{ signedAmount(data) }}</span>
      </template>
    </Column>
    <Column header="Qtd × Preço" :style="{ width: '10rem' }">
      <template #body="{ data }">
        <span v-if="data.quantity">{{ Number(data.quantity) }} × {{ brl(data.unit_price) }}</span>
        <span v-else class="text-muted">—</span>
      </template>
    </Column>
    <Column field="description" header="Descrição">
      <template #body="{ data }">
        <span>{{ data.description ?? '—' }}</span>
        <Tag v-if="data.is_generated" value="gerado" severity="secondary" class="ms-2" />
      </template>
    </Column>
    <Column v-if="deletable" header="" :style="{ width: '4rem' }" frozen alignFrozen="right">
      <template #body="{ data }">
        <Button v-if="isDeletable(data)" icon="pi pi-trash" severity="danger" text rounded size="small"
          :title="groupSize(data) > 1 ? `Apaga as ${groupSize(data)} linhas do lançamento` : 'Apagar lançamento'"
          @click="emit('delete', $event, data)" />
      </template>
    </Column>
  </DataTable>
</template>
