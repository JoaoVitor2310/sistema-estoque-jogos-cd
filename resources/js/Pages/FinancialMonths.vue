<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';

// PrimeVue
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import Select from 'primevue/select';
import Button from 'primevue/button';
import Tag from 'primevue/tag';
import Dialog from 'primevue/dialog';
import Toast from 'primevue/toast';
import { useToast } from 'primevue/usetoast';
import ConfirmPopup from 'primevue/confirmpopup';
import { useConfirm } from 'primevue/useconfirm';
import 'primeicons/primeicons.css';

// Inertia / Helpers
import { router } from '@inertiajs/vue3';
import axiosInstance from '../axios';
import { showResponse } from '../helpers/showResponse';
import { formatDateToBR } from '@/helpers/formatHelpers';

type AccountType = 'principal' | 'tf2' | 'reinvestment' | 'emergency';
type Numeric = string | number | null;

interface Movement {
  id: number;
  group_id: string | null;
  account_type: AccountType;
  direction: 'credit' | 'debit';
  category: string;
  expense_category: string | null;
  income_category: string | null;
  amount: Numeric;
  description: string | null;
  occurred_at: string;
  quantity: Numeric;
  unit_price: Numeric;
  partner_slot: number | null;
  is_generated: boolean;
}

interface FinancialMonth {
  id: number;
  year: number;
  month: number;
  status: 'draft' | 'closed';
  reinvestment_percent: Numeric;
  emergency_percent: Numeric;
  partner_one_share: Numeric;
  closed_at: string | null;
  movements?: Movement[];
}

type Balances = Record<AccountType, number>;

interface Tf2Prefill {
  quantity: number | null;
  unit_price: number | null;
}

const props = defineProps<{
  current: FinancialMonth | null;
  balances: Balances | null;
  closed: FinancialMonth[];
  tf2Prefill: Tf2Prefill;
}>();

const toast = useToast();
const confirm = useConfirm();

// ── Rótulos e formatação ──────────────────────────────────────────────────────

const MONTHS = [
  'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
  'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro',
];

const CATEGORY_LABELS: Record<string, string> = {
  opening: 'Abertura',
  income: 'Entrada',
  expense: 'Saída',
  tf2_allocation: 'Verba de TF2',
  tf2_purchase: 'Compra de TF2',
  transfer: 'Transferência',
  partner_distribution: 'Saque de sócio',
};

const ACCOUNTS: { label: string; value: AccountType }[] = [
  { label: 'Principal', value: 'principal' },
  { label: 'Verba de TF2', value: 'tf2' },
  { label: 'Reinvestimento', value: 'reinvestment' },
  { label: 'Emergência', value: 'emergency' },
];

const accountLabel = (account: AccountType): string =>
  ACCOUNTS.find((a) => a.value === account)?.label ?? account;

const EXPENSE_CATEGORIES = [
  { label: 'Impostos', value: 'taxes' },
  { label: 'Assinaturas', value: 'subscriptions' },
  { label: 'Outros', value: 'other' },
];

const INCOME_CATEGORIES = [
  { label: 'Saque Gamivo', value: 'gamivo_payout' },
  { label: 'Investimento externo', value: 'external_investment' },
  { label: 'Outros', value: 'other' },
];

const subcategoryLabel = (movement: Movement): string => {
  if (movement.expense_category) {
    return EXPENSE_CATEGORIES.find((c) => c.value === movement.expense_category)?.label ?? movement.expense_category;
  }
  if (movement.income_category) {
    return INCOME_CATEGORIES.find((c) => c.value === movement.income_category)?.label ?? movement.income_category;
  }

  return '—';
};

const brl = (value: Numeric): string =>
  value == null ? '—' : Number(value).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

const monthLabel = (month: FinancialMonth): string => `${MONTHS[month.month - 1]}/${month.year}`;

const signedAmount = (movement: Movement): string =>
  `${movement.direction === 'credit' ? '+' : '−'} ${brl(movement.amount)}`;

const movementLabel = (movement: Movement): string => {
  const base = CATEGORY_LABELS[movement.category] ?? movement.category;

  return movement.partner_slot ? `${base} ${movement.partner_slot}` : base;
};

// ── Saldos ────────────────────────────────────────────────────────────────────

const totalBalance = computed(() =>
  props.balances ? ACCOUNTS.reduce((sum, a) => sum + props.balances![a.value], 0) : 0,
);

// A alocação grava duas pernas com a mesma quantidade (débito no Principal,
// crédito no TF2); somar só a perna que credita o TF2 evita contar em dobro —
// mesmo cuidado do prefill (ver FinancialMonthService::tf2AllocationPrefill).
const tf2AllocatedQuantity = computed(() => {
  const movements = props.current?.movements ?? [];

  return movements
    .filter((m) => m.category === 'tf2_allocation' && m.account_type === 'tf2')
    .reduce((sum, m) => sum + Number(m.quantity ?? 0), 0);
});

const mostRecentClosedId = computed(() => (props.closed.length ? props.closed[0].id : null));

const refresh = () => router.reload({ only: ['current', 'balances', 'closed'] });

const reportError = (error: any) =>
  showResponse(error.response ?? { status: 500, data: { message: String(error) } }, toast.add);

// ── Bootstrap (primeiro mês) ──────────────────────────────────────────────────

const now = new Date();
const bootstrapForm = reactive({
  year: now.getFullYear(),
  month: now.getMonth() + 1,
  reinvestment_percent: 20,
  emergency_percent: 10,
  partner_one_share: 50,
  opening_principal: 0,
  opening_tf2: 0,
  opening_reinvestment: 0,
  opening_emergency: 0,
});

const savingBootstrap = ref(false);

const submitBootstrap = async () => {
  savingBootstrap.value = true;
  try {
    const res = await axiosInstance.post('/financial-months', {
      year: bootstrapForm.year,
      month: bootstrapForm.month,
      reinvestment_percent: bootstrapForm.reinvestment_percent / 100,
      emergency_percent: bootstrapForm.emergency_percent / 100,
      partner_one_share: bootstrapForm.partner_one_share / 100,
      opening_balances: {
        principal: bootstrapForm.opening_principal,
        tf2: bootstrapForm.opening_tf2,
        reinvestment: bootstrapForm.opening_reinvestment,
        emergency: bootstrapForm.opening_emergency,
      },
    });
    showResponse(res, toast.add);
    if (res.status === 201) refresh();
  } catch (error: any) {
    reportError(error);
  } finally {
    savingBootstrap.value = false;
  }
};

// ── Lançar movimento simples ──────────────────────────────────────────────────

const MOVEMENT_CATEGORIES = [
  { label: 'Entrada', value: 'income' },
  { label: 'Saída', value: 'expense' },
  { label: 'Compra de TF2', value: 'tf2_purchase' },
];

const movementDialog = ref(false);
const savingMovement = ref(false);
const movementForm = reactive({
  category: 'income',
  account: 'principal' as AccountType,
  amount: null as number | null,
  quantity: null as number | null,
  unit_price: null as number | null,
  description: '',
  occurred_at: '',
  expense_category: null as string | null,
  income_category: null as string | null,
});

const isTf2Purchase = computed(() => movementForm.category === 'tf2_purchase');
const isExpense = computed(() => movementForm.category === 'expense');
const isIncome = computed(() => movementForm.category === 'income');

const derivedTf2Total = computed(() => (movementForm.quantity ?? 0) * (movementForm.unit_price ?? 0));

// Debitar uma caixinha exige justificativa — mesma regra do domínio.
const requiresJustification = computed(
  () => movementForm.category === 'expense'
    && (movementForm.account === 'reinvestment' || movementForm.account === 'emergency'),
);

// Um único diálogo cobre entrada, saída e compra de TF2 — a categoria é
// escolhida pelo próprio select, no mesmo padrão do diálogo de Transferência.
const openMovementDialog = () => {
  Object.assign(movementForm, {
    category: 'income', account: 'principal', amount: null,
    quantity: null, unit_price: null, description: '', occurred_at: '',
    expense_category: null, income_category: null,
  });
  movementDialog.value = true;
};

const submitMovement = async () => {
  const payload: Record<string, unknown> = { category: movementForm.category };

  if (isTf2Purchase.value) {
    payload.quantity = movementForm.quantity;
    payload.unit_price = movementForm.unit_price;
  } else {
    payload.account = movementForm.account;
    payload.amount = movementForm.amount;
  }
  if (isExpense.value) payload.expense_category = movementForm.expense_category;
  if (isIncome.value) payload.income_category = movementForm.income_category;
  if (movementForm.description) payload.description = movementForm.description;
  if (movementForm.occurred_at) payload.occurred_at = movementForm.occurred_at;

  savingMovement.value = true;
  try {
    const res = await axiosInstance.post('/financial-months/movements', payload);
    showResponse(res, toast.add);
    if (res.status === 201) {
      movementDialog.value = false;
      refresh();
    }
  } catch (error: any) {
    reportError(error);
  } finally {
    savingMovement.value = false;
  }
};

// ── Porcentagens do mês (só prefill — nada é aplicado sozinho) ────────────────

const percentOf = (value: Numeric): number => Math.round(Number(value ?? 0) * 100);

const monthPercents = computed(() => ({
  reinvestment: props.current ? percentOf(props.current.reinvestment_percent) : 20,
  emergency: props.current ? percentOf(props.current.emergency_percent) : 10,
  partnerOne: props.current ? percentOf(props.current.partner_one_share) : 50,
}));

const balanceOf = (account: AccountType): number => props.balances?.[account] ?? 0;

// ── Transferência entre contas ────────────────────────────────────────────────

const transferDialog = ref(false);
const savingTransfer = ref(false);
const transferForm = reactive({
  source: 'principal' as AccountType,
  destination: 'reinvestment' as AccountType,
  mode: 'percent' as 'amount' | 'percent',
  amount: null as number | null,
  percent: null as number | null,
  description: '',
  occurred_at: '',
});

// A porcentagem incide sobre o saldo atual da origem; sem saldo positivo o
// domínio recusa, então a tela nem oferece a opção (evita erro desnecessário).
const sourceHasPositiveBalance = computed(() => balanceOf(transferForm.source) > 0);

const transferPreview = computed(() => {
  if (transferForm.mode === 'amount') return transferForm.amount ?? 0;

  return Math.round(balanceOf(transferForm.source) * ((transferForm.percent ?? 0) / 100) * 100) / 100;
});

const transferNeedsJustification = computed(
  () => transferForm.source === 'reinvestment' || transferForm.source === 'emergency',
);

// Reinvestimento e Emergência têm % sugerida; qualquer outro destino não tem
// sugestão — é uma transferência de valor fechado.
const suggestedPercentFor = (destination: AccountType): number | null => {
  if (destination === 'reinvestment') return monthPercents.value.reinvestment;
  if (destination === 'emergency') return monthPercents.value.emergency;
  return null;
};

// Um único diálogo cobre qualquer par de contas — "o que fazer" é escolhido
// pelos próprios selects de origem/destino, não por um botão dedicado. A
// sugestão de % reage à escolha, então trocar o destino já reaplica o prefill.
const applyPrefillForCurrentSelection = () => {
  const suggested = transferForm.source === 'principal' ? suggestedPercentFor(transferForm.destination) : null;

  transferForm.percent = suggested;
  transferForm.mode = suggested !== null && sourceHasPositiveBalance.value ? 'percent' : 'amount';
};

watch([() => transferForm.source, () => transferForm.destination], applyPrefillForCurrentSelection);

const openTransferDialog = () => {
  Object.assign(transferForm, {
    source: 'principal',
    destination: 'reinvestment',
    mode: 'amount',
    amount: null,
    percent: null,
    description: '',
    occurred_at: '',
  });
  applyPrefillForCurrentSelection();
  transferDialog.value = true;
};

const submitTransfer = async () => {
  const payload: Record<string, unknown> = {
    source: transferForm.source,
    destination: transferForm.destination,
  };

  // Valor ou fração, nunca os dois — o backend recusa se vierem juntos.
  if (transferForm.mode === 'percent') {
    payload.fraction = (transferForm.percent ?? 0) / 100;
  } else {
    payload.amount = transferForm.amount;
  }
  if (transferForm.description) payload.description = transferForm.description;
  if (transferForm.occurred_at) payload.occurred_at = transferForm.occurred_at;

  savingTransfer.value = true;
  try {
    const res = await axiosInstance.post('/financial-months/transfers', payload);
    showResponse(res, toast.add);
    if (res.status === 201) {
      transferDialog.value = false;
      refresh();
    }
  } catch (error: any) {
    reportError(error);
  } finally {
    savingTransfer.value = false;
  }
};

// ── Verba de TF2 ──────────────────────────────────────────────────────────────

const allocationDialog = ref(false);
const savingAllocation = ref(false);
const allocationForm = reactive({
  quantity: null as number | null,
  unit_price: null as number | null,
  description: '',
  occurred_at: '',
});

const allocationTotal = computed(() => (allocationForm.quantity ?? 0) * (allocationForm.unit_price ?? 0));

const hasTf2Prefill = computed(() => props.tf2Prefill.quantity != null);

const openAllocationDialog = () => {
  // Pré-preenche com o mês anterior; não há incremento automático de meta.
  Object.assign(allocationForm, {
    quantity: props.tf2Prefill.quantity,
    unit_price: props.tf2Prefill.unit_price,
    description: '',
    occurred_at: '',
  });
  allocationDialog.value = true;
};

const submitAllocation = async () => {
  const payload: Record<string, unknown> = {
    quantity: allocationForm.quantity,
    unit_price: allocationForm.unit_price,
  };
  if (allocationForm.description) payload.description = allocationForm.description;
  if (allocationForm.occurred_at) payload.occurred_at = allocationForm.occurred_at;

  savingAllocation.value = true;
  try {
    const res = await axiosInstance.post('/financial-months/tf2-allocations', payload);
    showResponse(res, toast.add);
    if (res.status === 201) {
      allocationDialog.value = false;
      refresh();
    }
  } catch (error: any) {
    reportError(error);
  } finally {
    savingAllocation.value = false;
  }
};

// ── Saque dos sócios ──────────────────────────────────────────────────────────

const distributionDialog = ref(false);
const savingDistribution = ref(false);
const distributionForm = reactive({
  source: 'principal' as AccountType,
  amount: null as number | null,
  partner_one_share: 50,
  description: '',
  occurred_at: '',
});

// O Sócio 2 leva o resto exato; o centavo órfão fica com o Sócio 1.
const partnerOneAmount = computed(
  () => Math.round((distributionForm.amount ?? 0) * (distributionForm.partner_one_share / 100) * 100) / 100,
);
const partnerTwoAmount = computed(
  () => Math.round(((distributionForm.amount ?? 0) - partnerOneAmount.value) * 100) / 100,
);

const distributionNeedsJustification = computed(
  () => distributionForm.source === 'reinvestment' || distributionForm.source === 'emergency',
);

const openDistributionDialog = () => {
  Object.assign(distributionForm, {
    source: 'principal',
    amount: null,
    partner_one_share: monthPercents.value.partnerOne,
    description: '',
    occurred_at: '',
  });
  distributionDialog.value = true;
};

const submitDistribution = async () => {
  const payload: Record<string, unknown> = {
    source: distributionForm.source,
    amount: distributionForm.amount,
    partner_one_share: distributionForm.partner_one_share / 100,
  };
  if (distributionForm.description) payload.description = distributionForm.description;
  if (distributionForm.occurred_at) payload.occurred_at = distributionForm.occurred_at;

  savingDistribution.value = true;
  try {
    const res = await axiosInstance.post('/financial-months/partner-distributions', payload);
    showResponse(res, toast.add);
    if (res.status === 201) {
      distributionDialog.value = false;
      refresh();
    }
  } catch (error: any) {
    reportError(error);
  } finally {
    savingDistribution.value = false;
  }
};

// ── Apagar lançamento ─────────────────────────────────────────────────────────

// Espelha a MovementDeletionPolicy: o que o sistema gerou e o saldo de abertura
// não são lançamentos do usuário.
const canDelete = (movement: Movement): boolean =>
  !movement.is_generated && movement.category !== 'opening';

// Uma transferência vira duas linhas; apagar leva o par junto.
const groupSize = (movement: Movement): number =>
  movement.group_id
    ? (props.current?.movements ?? []).filter((m) => m.group_id === movement.group_id).length
    : 1;

const deleteMovement = async (movement: Movement) => {
  try {
    const res = await axiosInstance.delete(`/financial-months/movements/${movement.id}`);
    showResponse(res, toast.add);
    if (res.status === 200) refresh();
  } catch (error: any) {
    reportError(error);
  }
};

const confirmDelete = (event: Event, movement: Movement) => {
  const lines = groupSize(movement);

  confirm.require({
    target: event.currentTarget as HTMLElement,
    message: lines > 1
      ? `Este lançamento tem ${lines} linhas e some por inteiro. Apagar?`
      : 'Apagar este lançamento?',
    header: 'Apagar lançamento',
    icon: 'pi pi-trash',
    rejectProps: { label: 'Cancelar', severity: 'secondary', outlined: true },
    acceptProps: { label: 'Apagar', severity: 'danger' },
    accept: () => deleteMovement(movement),
  });
};

// ── Roteiro do mês ────────────────────────────────────────────────────────────

// A ordem dos passos do negócio (docs/PRODUCT.md) segue valendo para como as
// porcentagens de transferência incidem — mas na tela ela deixou de ser 1:1
// com os botões: Saque/Gastos/Compra de TF2 dividem um só "Lançar movimento"
// (a categoria é o select lá dentro), e Reinvestimento/Emergência dividem a
// Transferência (o destino é o select lá dentro).
const routineSteps = computed(() => [
  { step: 1, label: 'Lançar movimento', icon: 'pi pi-wallet', run: () => openMovementDialog() },
  { step: 2, label: 'Verba de TF2', icon: 'pi pi-bullseye', run: () => openAllocationDialog() },
  { step: 3, label: 'Transferência', icon: 'pi pi-arrow-right-arrow-left', run: () => openTransferDialog() },
  { step: 4, label: 'Sacar sócios', icon: 'pi pi-users', run: () => openDistributionDialog() },
]);

// ── Fechar / Reabrir ──────────────────────────────────────────────────────────

const closeMonth = async () => {
  try {
    const res = await axiosInstance.post('/financial-months/close');
    showResponse(res, toast.add);
    if (res.status === 201) refresh();
  } catch (error: any) {
    reportError(error);
  }
};

const confirmClose = (event: Event) => {
  confirm.require({
    target: event.currentTarget as HTMLElement,
    message: 'Fechar o mês devolve a verba de TF2 não usada ao Principal e abre o mês seguinte. Continuar?',
    header: 'Fechar mês',
    icon: 'pi pi-lock',
    rejectProps: { label: 'Cancelar', severity: 'secondary', outlined: true },
    acceptProps: { label: 'Fechar', severity: 'contrast' },
    accept: closeMonth,
  });
};

const reopenMonth = async (month: FinancialMonth) => {
  try {
    const res = await axiosInstance.post(`/financial-months/${month.id}/reopen`);
    showResponse(res, toast.add);
    if (res.status === 200) refresh();
  } catch (error: any) {
    reportError(error);
  }
};

const confirmReopen = (event: Event, month: FinancialMonth) => {
  confirm.require({
    target: event.currentTarget as HTMLElement,
    message: `Reabrir ${monthLabel(month)} desfaz a devolução da verba e descarta o mês em aberto seguinte. Continuar?`,
    header: 'Reabrir mês',
    icon: 'pi pi-exclamation-triangle',
    rejectProps: { label: 'Cancelar', severity: 'secondary', outlined: true },
    acceptProps: { label: 'Reabrir', severity: 'danger' },
    accept: () => reopenMonth(month),
  });
};
</script>

<template>
  <Toast position="bottom-right" />
  <ConfirmPopup />

  <div class="container mb-5">
    <div class="text-center mb-4">
      <h1>Fechamento Mensal</h1>
      <p class="text-muted">Monte o mês lançamento a lançamento — em reais.</p>
    </div>

    <!-- ── Bootstrap: nenhum mês ainda ─────────────────────────────────────── -->
    <div v-if="!current && closed.length === 0" class="card mx-auto" style="max-width: 640px;">
      <div class="card-body">
        <h5 class="card-title mb-3">Abertura do primeiro mês</h5>
        <p class="text-muted small">Saldo inicial de cada conta e as porcentagens sugeridas nos lançamentos.</p>

        <div class="row g-3">
          <div class="col-6">
            <label class="form-label fw-bold">Ano</label>
            <InputNumber v-model="bootstrapForm.year" :min="2020" :max="2100" :useGrouping="false" fluid />
          </div>
          <div class="col-6">
            <label class="form-label fw-bold">Mês</label>
            <InputNumber v-model="bootstrapForm.month" :min="1" :max="12" fluid />
          </div>
          <div class="col-4">
            <label class="form-label fw-bold">Reinvest. (%)</label>
            <InputNumber v-model="bootstrapForm.reinvestment_percent" suffix=" %" :min="0" :max="100" fluid />
          </div>
          <div class="col-4">
            <label class="form-label fw-bold">Emergência (%)</label>
            <InputNumber v-model="bootstrapForm.emergency_percent" suffix=" %" :min="0" :max="100" fluid />
          </div>
          <div class="col-4">
            <label class="form-label fw-bold">Sócio 1 (%)</label>
            <InputNumber v-model="bootstrapForm.partner_one_share" suffix=" %" :min="0" :max="100" fluid />
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label fw-bold">Principal</label>
            <InputNumber v-model="bootstrapForm.opening_principal" mode="currency" currency="BRL" locale="pt-BR" :min="0" fluid />
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label fw-bold">Verba de TF2</label>
            <InputNumber v-model="bootstrapForm.opening_tf2" mode="currency" currency="BRL" locale="pt-BR" :min="0" fluid />
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label fw-bold">Reinvestimento</label>
            <InputNumber v-model="bootstrapForm.opening_reinvestment" mode="currency" currency="BRL" locale="pt-BR" :min="0" fluid />
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label fw-bold">Emergência</label>
            <InputNumber v-model="bootstrapForm.opening_emergency" mode="currency" currency="BRL" locale="pt-BR" :min="0" fluid />
          </div>
        </div>

        <div class="d-flex justify-content-end mt-4">
          <Button label="Abrir primeiro mês" icon="pi pi-play" :loading="savingBootstrap"
            :disabled="savingBootstrap" @click="submitBootstrap" />
        </div>
      </div>
    </div>

    <!-- ── Mês corrente ────────────────────────────────────────────────────── -->
    <div v-if="current" class="mb-5">
      <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
        <h4 class="mb-0">
          {{ monthLabel(current) }}
          <Tag value="Em aberto" severity="info" class="ms-2" />
        </h4>
        <Button label="Fechar mês" icon="pi pi-lock" severity="contrast" @click="confirmClose($event)" raised />
      </div>

      <!-- Roteiro: a ordem dos passos é o que reproduz as bases da cascata antiga -->
      <div class="card mb-4">
        <div class="card-body">
          <h6 class="card-title mb-1">Roteiro do mês</h6>
          <p class="text-muted small mb-3">
            Nada é lançado sozinho — cada passo abre um formulário que você confirma.
            As porcentagens vêm pré-preenchidas e incidem sobre o saldo da conta de origem no momento do lançamento.
          </p>
          <div class="d-flex flex-wrap gap-2">
            <Button v-for="item in routineSteps" :key="item.step" :icon="item.icon"
              :label="`${item.step}. ${item.label}`" size="small" severity="secondary" outlined
              class="text-nowrap flex-shrink-0" @click="item.run()" />
          </div>
        </div>
      </div>

      <!-- Saldos das 4 contas -->
      <div class="row g-3 mb-4" v-if="balances">
        <div class="col-6 col-md-3" v-for="account in ACCOUNTS" :key="account.value">
          <div class="card h-100">
            <div class="card-body text-center">
              <div class="text-muted small">{{ account.label }}</div>
              <div class="fs-5 fw-bold" :class="{ 'text-danger': balances[account.value] < 0 }">
                {{ brl(balances[account.value]) }}
              </div>
              <div v-if="account.value === 'tf2' && tf2AllocatedQuantity > 0" class="text-muted small">
                ({{ tf2AllocatedQuantity }})
              </div>
            </div>
          </div>
        </div>
        <div class="col-12">
          <div class="card border-primary">
            <div class="card-body d-flex justify-content-between align-items-center py-2">
              <span class="text-muted small">Total da empresa</span>
              <span class="fs-5 fw-bold" :class="{ 'text-danger': totalBalance < 0 }">{{ brl(totalBalance) }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Movimentos -->
      <DataTable :value="current.movements ?? []" showGridlines size="small" scrollable
        scrollHeight="min(55vh, 560px)" dataKey="id" tableStyle="min-width: 40rem;">
        <template #empty>Nenhum lançamento ainda.</template>
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
        <Column header="" :style="{ width: '4rem' }" frozen alignFrozen="right">
          <template #body="{ data }">
            <Button v-if="canDelete(data)" icon="pi pi-trash" severity="danger" text rounded size="small"
              :title="groupSize(data) > 1 ? `Apaga as ${groupSize(data)} linhas do lançamento` : 'Apagar lançamento'"
              @click="confirmDelete($event, data)" />
          </template>
        </Column>
      </DataTable>
    </div>

    <!-- ── Histórico ───────────────────────────────────────────────────────── -->
    <div v-if="closed.length">
      <h4 class="mb-3">Histórico</h4>
      <div v-for="month in closed" :key="month.id" class="card mb-2">
        <div class="card-body d-flex flex-wrap align-items-center justify-content-between py-3">
          <h6 class="mb-0">
            {{ monthLabel(month) }}
            <Tag value="Fechado" severity="success" class="ms-2" />
            <span v-if="month.closed_at" class="text-muted small ms-2">
              em {{ formatDateToBR(month.closed_at.slice(0, 10)) }}
            </span>
          </h6>
          <Button v-if="month.id === mostRecentClosedId" label="Reabrir" icon="pi pi-lock-open"
            severity="danger" outlined size="small" @click="confirmReopen($event, month)" />
        </div>
      </div>
    </div>
  </div>

  <!-- ── Dialog: transferência ─────────────────────────────────────────────── -->
  <Dialog v-model:visible="transferDialog" modal header="Transferência" :style="{ width: '460px' }">
    <div class="d-flex flex-column gap-3 mb-3">
      <div class="row g-2">
        <div class="col-6 d-flex flex-column gap-1">
          <label class="fw-bold">De</label>
          <Select v-model="transferForm.source" :options="ACCOUNTS" optionLabel="label" optionValue="value" />
        </div>
        <div class="col-6 d-flex flex-column gap-1">
          <label class="fw-bold">Para</label>
          <Select v-model="transferForm.destination" :options="ACCOUNTS" optionLabel="label" optionValue="value" />
        </div>
      </div>

      <div v-if="transferForm.source === transferForm.destination" class="alert alert-warning py-2 mb-0 small">
        Origem e destino precisam ser contas diferentes.
      </div>

      <div class="d-flex flex-column gap-1">
        <label class="fw-bold">Quanto</label>
        <div class="btn-group" role="group">
          <Button label="Valor" size="small" :severity="transferForm.mode === 'amount' ? 'primary' : 'secondary'"
            :outlined="transferForm.mode !== 'amount'" @click="transferForm.mode = 'amount'" />
          <Button label="Porcentagem" size="small" :disabled="!sourceHasPositiveBalance"
            :severity="transferForm.mode === 'percent' ? 'primary' : 'secondary'"
            :outlined="transferForm.mode !== 'percent'" @click="transferForm.mode = 'percent'" />
        </div>
        <small v-if="!sourceHasPositiveBalance" class="text-muted">
          {{ accountLabel(transferForm.source) }} não tem saldo positivo — só dá para transferir um valor fechado.
        </small>
      </div>

      <div v-if="transferForm.mode === 'amount'" class="d-flex flex-column gap-1">
        <label class="fw-bold">Valor</label>
        <InputNumber v-model="transferForm.amount" mode="currency" currency="BRL" locale="pt-BR" :min="0" fluid />
      </div>

      <div v-else class="d-flex flex-column gap-1">
        <label class="fw-bold">Porcentagem do saldo de {{ accountLabel(transferForm.source) }}</label>
        <InputNumber v-model="transferForm.percent" suffix=" %" :min="0" :max="100" fluid />
        <small class="text-muted">
          Saldo atual: <strong>{{ brl(balanceOf(transferForm.source)) }}</strong> →
          transfere <strong>{{ brl(transferPreview) }}</strong>
        </small>
      </div>

      <div class="d-flex flex-column gap-1">
        <label class="fw-bold">
          Descrição
          <span v-if="transferNeedsJustification" class="text-danger">*</span>
        </label>
        <InputText v-model="transferForm.description"
          :placeholder="transferNeedsJustification ? 'Justificativa obrigatória' : 'Opcional'" />
        <small v-if="transferNeedsJustification" class="text-muted">
          Tirar dinheiro de uma caixinha exige justificativa.
        </small>
      </div>

      <div class="d-flex flex-column gap-1">
        <label class="fw-bold">Data</label>
        <input type="date" class="form-control" v-model="transferForm.occurred_at" />
        <small class="text-muted">Em branco usa a data de hoje.</small>
      </div>
    </div>

    <div class="d-flex justify-content-end gap-2">
      <Button type="button" label="Cancelar" severity="secondary" @click="transferDialog = false" />
      <Button type="button" label="Transferir" :loading="savingTransfer"
        :disabled="savingTransfer || transferForm.source === transferForm.destination" @click="submitTransfer" />
    </div>
  </Dialog>

  <!-- ── Dialog: verba de TF2 ──────────────────────────────────────────────── -->
  <Dialog v-model:visible="allocationDialog" modal header="Definir a verba de TF2" :style="{ width: '460px' }">
    <div class="d-flex flex-column gap-3 mb-3">
      <p class="text-muted small mb-0">
        O dinheiro sai do Principal e passa a viver na conta de TF2. As compras do mês debitam dela, e a sobra
        volta ao Principal no fechamento.
      </p>

      <div v-if="hasTf2Prefill" class="alert alert-secondary py-2 mb-0 small">
        Pré-preenchido com o mês anterior. Ajuste à vontade — não há incremento automático.
      </div>

      <div class="d-flex flex-column gap-1">
        <label class="fw-bold">Quantidade de TF2</label>
        <InputNumber v-model="allocationForm.quantity" :min="0" :maxFractionDigits="2" fluid />
      </div>

      <div class="d-flex flex-column gap-1">
        <label class="fw-bold">Preço unitário</label>
        <InputNumber v-model="allocationForm.unit_price" mode="currency" currency="BRL" locale="pt-BR" :min="0" fluid />
      </div>

      <div class="alert alert-secondary py-2 mb-0 small">
        Verba: <strong>{{ brl(allocationTotal) }}</strong> — sai do Principal
        (saldo atual {{ brl(balanceOf('principal')) }}).
      </div>

      <div class="d-flex flex-column gap-1">
        <label class="fw-bold">Descrição</label>
        <InputText v-model="allocationForm.description" placeholder="Opcional" />
      </div>

      <div class="d-flex flex-column gap-1">
        <label class="fw-bold">Data</label>
        <input type="date" class="form-control" v-model="allocationForm.occurred_at" />
        <small class="text-muted">Em branco usa a data de hoje.</small>
      </div>
    </div>

    <div class="d-flex justify-content-end gap-2">
      <Button type="button" label="Cancelar" severity="secondary" @click="allocationDialog = false" />
      <Button type="button" label="Alocar verba" :loading="savingAllocation" :disabled="savingAllocation"
        @click="submitAllocation" />
    </div>
  </Dialog>

  <!-- ── Dialog: saque dos sócios ──────────────────────────────────────────── -->
  <Dialog v-model:visible="distributionDialog" modal header="Sacar para os sócios" :style="{ width: '460px' }">
    <div class="d-flex flex-column gap-3 mb-3">
      <div class="d-flex flex-column gap-1">
        <label class="fw-bold">Conta de origem</label>
        <Select v-model="distributionForm.source" :options="ACCOUNTS" optionLabel="label" optionValue="value" />
        <small class="text-muted">Saldo atual: {{ brl(balanceOf(distributionForm.source)) }}</small>
      </div>

      <div class="d-flex flex-column gap-1">
        <label class="fw-bold">Total a sacar</label>
        <InputNumber v-model="distributionForm.amount" mode="currency" currency="BRL" locale="pt-BR" :min="0" fluid />
      </div>

      <div class="d-flex flex-column gap-1">
        <label class="fw-bold">Fatia do Sócio 1</label>
        <InputNumber v-model="distributionForm.partner_one_share" suffix=" %" :min="0" :max="100" fluid />
      </div>

      <div class="alert alert-secondary py-2 mb-0 small">
        Sócio 1: <strong>{{ brl(partnerOneAmount) }}</strong> ·
        Sócio 2: <strong>{{ brl(partnerTwoAmount) }}</strong>
        <div class="text-muted mt-1">O Sócio 2 leva o resto exato; o centavo órfão fica com o Sócio 1.</div>
      </div>

      <div class="d-flex flex-column gap-1">
        <label class="fw-bold">
          Descrição
          <span v-if="distributionNeedsJustification" class="text-danger">*</span>
        </label>
        <InputText v-model="distributionForm.description"
          :placeholder="distributionNeedsJustification ? 'Justificativa obrigatória' : 'Opcional'" />
      </div>

      <div class="d-flex flex-column gap-1">
        <label class="fw-bold">Data</label>
        <input type="date" class="form-control" v-model="distributionForm.occurred_at" />
        <small class="text-muted">Em branco usa a data de hoje.</small>
      </div>
    </div>

    <div class="d-flex justify-content-end gap-2">
      <Button type="button" label="Cancelar" severity="secondary" @click="distributionDialog = false" />
      <Button type="button" label="Sacar" :loading="savingDistribution" :disabled="savingDistribution"
        @click="submitDistribution" />
    </div>
  </Dialog>

  <!-- ── Dialog: lançar movimento ──────────────────────────────────────────── -->
  <Dialog v-model:visible="movementDialog" modal header="Lançar movimento" :style="{ width: '460px' }">
    <div class="d-flex flex-column gap-3 mb-3">
      <div class="d-flex flex-column gap-1">
        <label class="fw-bold">Tipo</label>
        <Select v-model="movementForm.category" :options="MOVEMENT_CATEGORIES" optionLabel="label" optionValue="value" />
      </div>

      <template v-if="isTf2Purchase">
        <div class="d-flex flex-column gap-1">
          <label class="fw-bold">Quantidade de TF2</label>
          <InputNumber v-model="movementForm.quantity" :min="0" :maxFractionDigits="2" fluid />
        </div>
        <div class="d-flex flex-column gap-1">
          <label class="fw-bold">Preço unitário</label>
          <InputNumber v-model="movementForm.unit_price" mode="currency" currency="BRL" locale="pt-BR" :min="0" fluid />
        </div>
        <div class="alert alert-secondary py-2 mb-0 small">
          Total: <strong>{{ brl(derivedTf2Total) }}</strong> — debitado da verba de TF2.
        </div>
      </template>

      <template v-else>
        <div class="d-flex flex-column gap-1">
          <label class="fw-bold">Conta</label>
          <Select v-model="movementForm.account" :options="ACCOUNTS" optionLabel="label" optionValue="value" />
        </div>
        <div class="d-flex flex-column gap-1">
          <label class="fw-bold">Valor</label>
          <InputNumber v-model="movementForm.amount" mode="currency" currency="BRL" locale="pt-BR" :min="0" fluid />
        </div>
        <div v-if="isExpense" class="d-flex flex-column gap-1">
          <label class="fw-bold">Categoria</label>
          <Select v-model="movementForm.expense_category" :options="EXPENSE_CATEGORIES"
            optionLabel="label" optionValue="value" placeholder="Selecione" />
        </div>
        <div v-if="isIncome" class="d-flex flex-column gap-1">
          <label class="fw-bold">Categoria</label>
          <Select v-model="movementForm.income_category" :options="INCOME_CATEGORIES"
            optionLabel="label" optionValue="value" placeholder="Selecione" />
        </div>
      </template>

      <div class="d-flex flex-column gap-1">
        <label class="fw-bold">
          Descrição
          <span v-if="requiresJustification" class="text-danger">*</span>
        </label>
        <InputText v-model="movementForm.description"
          :placeholder="requiresJustification ? 'Justificativa obrigatória' : 'Opcional'" />
        <small v-if="requiresJustification" class="text-muted">
          Tirar dinheiro de uma caixinha exige justificativa.
        </small>
      </div>

      <div class="d-flex flex-column gap-1">
        <label class="fw-bold">Data</label>
        <input type="date" class="form-control" v-model="movementForm.occurred_at" />
        <small class="text-muted">Em branco usa a data de hoje.</small>
      </div>
    </div>

    <div class="d-flex justify-content-end gap-2">
      <Button type="button" label="Cancelar" severity="secondary" @click="movementDialog = false" />
      <Button type="button" label="Lançar" :loading="savingMovement" :disabled="savingMovement" @click="submitMovement" />
    </div>
  </Dialog>
</template>
