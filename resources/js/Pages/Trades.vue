<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import axiosInstance from '@/axios';
import Checkbox from 'primevue/checkbox';
import ConfirmPopup from 'primevue/confirmpopup';
import DatePicker from 'primevue/datepicker';
import Paginator from 'primevue/paginator';
import { useConfirm } from 'primevue/useconfirm';

// ─── Tipos vindos do backend ─────────────────────────────────────────────────

interface StoredGame {
  name: string;
  marketPriceRaw: string;
  bundle: string;
  expiry: string;
  popularity: string;
  regionLock: string;
  keyCode: string;
  gamivoId: string;
}

interface TradeFromServer {
  id: number;
  title: string | null;
  games: StoredGame[];
  date: string | null;
  tf2_qty: string | null;
  supplier: { url: string } | null;
  created_at: string;
  message_sent: boolean;
  is_imported: boolean;
}

interface PaginatorPayload<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

interface Filters {
  view: 'open' | 'imported' | 'all';
  date_from: string | null;
  date_to: string | null;
  tf2_min: string | null;
  tf2_max: string | null;
  sort: 'date' | 'tf2_qty';
  dir: 'asc' | 'desc';
}

const props = defineProps<{
  trades: PaginatorPayload<TradeFromServer>;
  filters: Filters;
  tf2Price: number;
  fees: {
    percentLow: number;
    fixedLow: number;
    percentHigh: number;
    fixedHigh: number;
  };
  profitTiers: number[];
}>();

// ─── Tipos internos ──────────────────────────────────────────────────────────

// Importação é atômica: ou o lote inteiro entra (e a trade sai da aba), ou nada
// entra e as linhas problemáticas ficam em 'error'.
type RowStatus = 'pending' | 'error';

/** StoredGame + estado de UI (não é enviado ao backend). */
interface Row extends StoredGame {
  status: RowStatus;
  errorMsg: string;
  customTf2Override: string;
}

type SaveStatus = 'idle' | 'saving' | 'saved' | 'error';

interface TradeEntry {
  id: number;
  title: string;
  date: string;
  supplierUrl: string;
  tf2Qty: string;
  rows: Row[];
  createdAt: string;
  messageSent: boolean;
  isImported: boolean;
  // Trades importadas nascem colapsadas; abertas nascem expandidas.
  // Clique na linha compacta alterna. Não persiste (F5 volta a colapsado).
  expanded: boolean;
  // UI-only
  importing: boolean;
  copiedKey: string | null;
  saveStatus: SaveStatus;
  lastSavedAt: string | null;
}

// ─── Estado global ────────────────────────────────────────────────────────────

const tradeList = ref<TradeEntry[]>(props.trades.data.map(toTradeEntry));

// Re-hidrata a lista sempre que Inertia troca de página / aplica filtro.
watch(() => props.trades, (next) => {
  tradeList.value = next.data.map(toTradeEntry);
});

const customTier = ref<number | null>(null);

/** Timers de debounce por trade ID (autosave). */
const saveTimers = new Map<number, ReturnType<typeof setTimeout>>();

// ─── Filtros (estado local do form, sincronizado com props.filters) ──────────

const localFilters = reactive<Filters>({ ...props.filters });

// DatePicker do PrimeVue trabalha com objetos Date; o backend recebe/emite
// strings YYYY-MM-DD. Mantemos refs separadas para o binding do widget e
// convertemos nos dois sentidos.
const dateFromPicker = ref<Date | null>(fromDbDate(props.filters.date_from));
const dateToPicker = ref<Date | null>(fromDbDate(props.filters.date_to));

watch(() => props.filters, (next) => {
  Object.assign(localFilters, next);
  dateFromPicker.value = fromDbDate(next.date_from);
  dateToPicker.value = fromDbDate(next.date_to);
});

/** YYYY-MM-DD → Date local (sem UTC drift). */
function fromDbDate(iso: string | null): Date | null {
  if (!iso) return null;
  const [y, m, d] = iso.split('-').map(Number);
  if (!y || !m || !d) return null;
  return new Date(y, m - 1, d);
}

/** Date local → YYYY-MM-DD (fuso local). */
function toDbDate(date: Date | null): string | null {
  if (!date) return null;
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

/** DatePicker do PrimeVue emite Date|Date[]|null; usamos só o modo single. */
function coerceSingleDate(value: unknown): Date | null {
  if (value instanceof Date) return value;
  if (Array.isArray(value) && value[0] instanceof Date) return value[0];
  return null;
}

function onDateFromChange(value: unknown) {
  const d = coerceSingleDate(value);
  dateFromPicker.value = d;
  localFilters.date_from = toDbDate(d);
  applyFilters();
}

function onDateToChange(value: unknown) {
  const d = coerceSingleDate(value);
  dateToPicker.value = d;
  localFilters.date_to = toDbDate(d);
  applyFilters();
}

/** Aplica filtros/sort ao servidor. Sempre reseta page=1 — filtrar na página 5
 * pode cair fora do total resultante. */
function applyFilters(overrides: Partial<Filters> = {}) {
  const payload = { ...localFilters, ...overrides };

  router.get(route('trades'), pruneEmpty(payload), {
    preserveState: true,
    preserveScroll: true,
    replace: false,
  });
}

/** Remove chaves vazias/nulas para não poluir a URL. */
function pruneEmpty(obj: Record<string, unknown>): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  for (const [k, v] of Object.entries(obj)) {
    if (v === null || v === undefined || v === '') continue;
    out[k] = v;
  }
  return out;
}

function setView(view: Filters['view']) {
  localFilters.view = view;
  applyFilters();
}

function setSort(field: Filters['sort']) {
  if (localFilters.sort === field) {
    localFilters.dir = localFilters.dir === 'asc' ? 'desc' : 'asc';
  } else {
    localFilters.sort = field;
    localFilters.dir = 'desc';
  }
  applyFilters();
}

function resetFilters() {
  Object.assign(localFilters, {
    view: 'open',
    date_from: null,
    date_to: null,
    tf2_min: null,
    tf2_max: null,
    sort: 'date',
    dir: 'desc',
  } satisfies Filters);
  applyFilters();
}

// ─── Paginação ────────────────────────────────────────────────────────────────

const paginationFirst = computed(() =>
  (props.trades.current_page - 1) * props.trades.per_page,
);

function onPageChange(event: { page: number; rows: number }) {
  // PrimeVue emite `page` em 0-index; Laravel espera 1-index
  router.get(route('trades'), pruneEmpty({ ...localFilters, page: event.page + 1 }), {
    preserveState: true,
    preserveScroll: true,
  });
}

// ─── Helpers de conversão ─────────────────────────────────────────────────────

/** Garante que todos os campos de string nunca sejam null (vindo do JSON do banco). */
function toRow(r: any): Row {
  return {
    name: r.name ?? '',
    marketPriceRaw: r.marketPriceRaw ?? '',
    bundle: r.bundle ?? '',
    expiry: r.expiry ?? '',
    popularity: r.popularity ?? '',
    regionLock: r.regionLock ?? '',
    keyCode: r.keyCode ?? '',
    gamivoId: r.gamivoId ?? '',
    status: 'pending',
    errorMsg: '',
    customTf2Override: '',
  };
}

function emptyRow(): Row {
  return toRow({});
}

function toTradeEntry(t: TradeFromServer): TradeEntry {
  const isImported = t.is_imported ?? false;
  return {
    id: t.id,
    title: t.title ?? '',
    date: t.date ?? '',
    supplierUrl: t.supplier?.url ?? '',
    tf2Qty: t.tf2_qty ?? '',
    rows: (t.games ?? []).map(toRow),
    createdAt: t.created_at,
    messageSent: t.message_sent ?? false,
    isImported,
    // Importadas nascem colapsadas para permitir scan visual rápido.
    expanded: !isImported,
    importing: false,
    copiedKey: null,
    saveStatus: 'idle',
    lastSavedAt: null,
  };
}

function rowToGame(row: Row): StoredGame {
  const { status, errorMsg, customTf2Override, ...game } = row;
  return game;
}

function tradePayload(trade: TradeEntry) {
  return {
    supplierUrl: trade.supplierUrl,
    date: trade.date,
    tf2Qty: trade.tf2Qty.replace(',', '.'),
    games: trade.rows.map(rowToGame),
    message_sent: trade.messageSent,
  };
}

// ─── Cálculos (projeção client-side — fonte da verdade no Domain PHP) ─────────

// Projeção client-side de IncomeCalculator::forGamivo (PHP).
// A fonte da verdade e os testes unitários estão no Domain PHP.
// Se alterar a fórmula, atualize os dois lados.
const MICRO_THRESHOLD = 0.28;
const MICRO_FIXED_FEE = 0.11;
const TIER_THRESHOLD = 8.0;

function calcNetIncome(marketPrice: number): number {
  if (marketPrice < MICRO_THRESHOLD) return marketPrice - MICRO_FIXED_FEE;
  if (marketPrice < TIER_THRESHOLD) return marketPrice * (1 - props.fees.percentLow) - props.fees.fixedLow;
  return marketPrice * (1 - props.fees.percentHigh) - props.fees.fixedHigh;
}

// Projeção client-side de OfferCalculator::tf2Offer (PHP).
function calcOffer(netIncome: number, profitPct: number): number {
  if (props.tf2Price <= 0) return 0;
  return netIncome / (1 + profitPct / 100) / props.tf2Price;
}

function getMarketPrice(row: Row): number {
  return parseFloat((row.marketPriceRaw ?? '').replace(',', '.')) || 0;
}

function getNetIncome(row: Row): number {
  return calcNetIncome(getMarketPrice(row));
}

function getOffer(row: Row, tier: number): number {
  return calcOffer(getNetIncome(row), tier);
}

// ─── Override TF2 por linha ───────────────────────────────────────────────────

function getEffectiveCustomTf2(row: Row): number {
  const override = parseFloat(row.customTf2Override.replace(',', '.'));
  if (override > 0) return override;
  if (customTier.value !== null) return getOffer(row, customTier.value);
  return 0;
}

function getImpliedProfit(row: Row): number | null {
  const tf2Val = parseFloat(row.customTf2Override.replace(',', '.'));
  if (!tf2Val || tf2Val <= 0 || props.tf2Price <= 0) return null;
  const netIncome = getNetIncome(row);
  if (netIncome <= 0) return null;
  return (netIncome / (tf2Val * props.tf2Price) - 1) * 100;
}

// ─── Autosave (debounce por trade) ────────────────────────────────────────────

const savingInFlight = new Set<number>();
const dirtyDuringSave = new Set<number>();

function scheduleAutosave(trade: TradeEntry) {
  const existing = saveTimers.get(trade.id);
  if (existing) clearTimeout(existing);

  const timer = setTimeout(() => {
    saveTimers.delete(trade.id);
    triggerSave(trade);
  }, 800);

  saveTimers.set(trade.id, timer);
}

async function triggerSave(trade: TradeEntry) {
  if (savingInFlight.has(trade.id)) {
    dirtyDuringSave.add(trade.id);

    return;
  }

  savingInFlight.add(trade.id);
  trade.saveStatus = 'saving';

  try {
    await axiosInstance.put(route('trades.update', { trade: trade.id }), {
      title: trade.title,
      ...tradePayload(trade),
    });
    trade.saveStatus = 'saved';
    trade.lastSavedAt = new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
  } catch (err) {
    console.error('Erro ao salvar trade:', err);
    trade.saveStatus = 'error';
  } finally {
    savingInFlight.delete(trade.id);

    if (dirtyDuringSave.has(trade.id)) {
      dirtyDuringSave.delete(trade.id);
      triggerSave(trade);
    }
  }
}

/** Impede perda silenciosa de dados: avisa o navegador se houver save pendente/em erro. */
function handleBeforeUnload(e: BeforeUnloadEvent) {
  const hasPending = saveTimers.size > 0 || savingInFlight.size > 0;
  const hasError = tradeList.value.some(t => t.saveStatus === 'error');

  if (hasPending || hasError) {
    e.preventDefault();
    e.returnValue = '';
  }
}

onMounted(() => window.addEventListener('beforeunload', handleBeforeUnload));
onUnmounted(() => window.removeEventListener('beforeunload', handleBeforeUnload));

// ─── Linhas ───────────────────────────────────────────────────────────────────

function addRow(trade: TradeEntry) {
  trade.rows.push(emptyRow());
  scheduleAutosave(trade);
}

function deleteRow(trade: TradeEntry, rowIdx: number) {
  trade.rows.splice(rowIdx, 1);
  scheduleAutosave(trade);
}

function duplicateRow(trade: TradeEntry, rowIdx: number) {
  const duplicate = toRow({ ...rowToGame(trade.rows[rowIdx]), keyCode: '' });
  trade.rows.splice(rowIdx + 1, 0, duplicate);
  scheduleAutosave(trade);
}

// ─── Criação ──────────────────────────────────────────────────────────────────

const creatingTrade = ref(false);
const confirm = useConfirm();

async function createTrade() {
  creatingTrade.value = true;
  try {
    await axiosInstance.post(route('trades.store'));
    // Recarrega apenas o paginator para exibir a nova trade no topo (respeitando
    // filtros/página atuais — se o filtro exclui a nova, aparece só ao navegar).
    router.reload({ only: ['trades'] });
  } catch (err) {
    console.error('Erro ao criar trade:', err);
  } finally {
    creatingTrade.value = false;
  }
}

// ─── Exclusão ─────────────────────────────────────────────────────────────────

function deleteTrade(event: Event, trade: TradeEntry) {
  confirm.require({
    target: event.currentTarget as HTMLElement,
    message: 'Excluir esta trade?',
    rejectProps: { label: 'Cancelar', severity: 'secondary', outlined: true },
    acceptProps: { label: 'Excluir', severity: 'danger' },
    accept: async () => {
      await axiosInstance.delete(route('trades.destroy', { trade: trade.id }));
      router.reload({ only: ['trades'] });
    },
  });
}

// ─── Mensagem enviada ─────────────────────────────────────────────────────────

async function toggleMessageSent(trade: TradeEntry) {
  trade.messageSent = !trade.messageSent;
  await axiosInstance.put(route('trades.update', { trade: trade.id }), tradePayload(trade));
}

// ─── Importação de keys (por trade) ───────────────────────────────────────────

function convertDateToISO(date: string): string {
  const parts = date.split('/');
  if (parts.length === 3) return `${parts[2]}-${parts[1]}-${parts[0]}`;
  return date;
}

const isRowMeaningful = (r: Row) => !!(r.name?.trim() || (r.marketPriceRaw ?? '').trim());

const hasMissingMarketPrice = (r: Row) => !(getMarketPrice(r) > 0);
const hasMissingName = (r: Row) => !(r.name ?? '').trim();
const hasMissingKeyCodes = (trade: TradeEntry) =>
  trade.rows.some(r => isRowMeaningful(r) && !(r.keyCode ?? '').trim());
const hasMissingMarketPrices = (trade: TradeEntry) =>
  trade.rows.some(r => isRowMeaningful(r) && hasMissingMarketPrice(r));
const hasMissingNames = (trade: TradeEntry) =>
  trade.rows.some(r => isRowMeaningful(r) && hasMissingName(r));
const hasMissingTf2 = (trade: TradeEntry) =>
  !(parseFloat((trade.tf2Qty ?? '').replace(',', '.')) > 0);
const hasMissingSupplierUrl = (trade: TradeEntry) =>
  !(trade.supplierUrl ?? '').trim();
const canImport = (trade: TradeEntry) =>
  trade.rows.some(isRowMeaningful)
  && !hasMissingKeyCodes(trade)
  && !hasMissingMarketPrices(trade)
  && !hasMissingNames(trade)
  && !hasMissingTf2(trade)
  && !hasMissingSupplierUrl(trade);

function importTrade(event: Event, trade: TradeEntry) {
  if (!canImport(trade)) return;

  const rowCount = trade.rows.filter(isRowMeaningful).length;
  confirm.require({
    target: event.currentTarget as HTMLElement,
    message: `Importar ${rowCount} key${rowCount !== 1 ? 's' : ''} desta trade?`,
    rejectProps: { label: 'Cancelar', severity: 'secondary', outlined: true },
    acceptProps: { label: 'Importar', severity: 'primary' },
    accept: () => runImport(trade),
  });
}

async function runImport(trade: TradeEntry) {
  trade.importing = true;
  trade.rows.forEach(r => { r.status = 'pending'; r.errorMsg = ''; });

  const meaningfulEntries = trade.rows
    .map((row, originalIdx) => ({ row, originalIdx }))
    .filter(({ row }) => isRowMeaningful(row));

  const tf2Quantity = parseFloat((trade.tf2Qty ?? '').replace(',', '.')) || 0;
  const supplierUrl = (trade.supplierUrl ?? '').trim();
  const date = convertDateToISO(trade.date ?? '');

  const games = meaningfulEntries.map(({ row }) => ({
    game_name: row.name ?? '',
    market_price: getMarketPrice(row),
    tf2_quantity: tf2Quantity,
    key_code: (row.keyCode ?? '').trim(),
    supplier_url: supplierUrl,
    acquired_at: date,
    region: (row.regionLock ?? '').trim() || null,
    expires_at: (row.expiry ?? '').trim() ? convertDateToISO((row.expiry ?? '').trim()) : null,
    gamivo_id: (row.gamivoId ?? '').trim() || null,
  }));

  try {
    await axiosInstance.post(
      route('trades.import', { trade: trade.id }),
      { games },
    );

    // 201 — a importação é atômica, então chegar aqui significa que o lote inteiro
    // entrou. A trade agora é `is_imported=true`: no default view (Abertas) some;
    // em Todas/Importadas continua visível como card colapsado.
    router.reload({ only: ['trades'] });
  } catch (e: any) {
    if (e?.response?.status === 422) {
      const payload = e.response.data.errors ?? {};

      if (Array.isArray(payload)) {
        const errorsByGameIdx = new Map(
          (payload as { line: number; error: string }[]).map(err => [err.line - 1, err.error]),
        );

        meaningfulEntries.forEach(({ originalIdx }, gameIdx) => {
          const row = trade.rows[originalIdx];
          row.status = errorsByGameIdx.has(gameIdx) ? 'error' : 'pending';
          row.errorMsg = errorsByGameIdx.get(gameIdx) ?? '';
        });

        return;
      }

      const validationErrors: Record<string, string[]> = payload;
      const fieldLabels: Record<string, string> = {
        tf2_quantity: 'Qtd TF2',
        key_code: 'Key Code',
        game_name: 'Nome',
        market_price: 'Preço de Mercado',
        supplier_url: 'URL Fornecedor',
        acquired_at: 'Data',
      };

      meaningfulEntries.forEach(({ originalIdx }, gameIdx) => {
        const row = trade.rows[originalIdx];
        const msgs = Object.entries(validationErrors)
          .filter(([key]) => key.startsWith(`games.${gameIdx}.`))
          .map(([key, errs]) => {
            const field = key.replace(`games.${gameIdx}.`, '');
            const label = fieldLabels[field] ?? field;
            return `${label}: ${errs[0]}`;
          });

        if (msgs.length > 0) {
          row.status = 'error';
          row.errorMsg = msgs.join(' | ');
        }
      });
    } else {
      trade.rows.forEach(r => {
        r.status = 'error';
        r.errorMsg = e?.response?.data?.message ?? 'Erro desconhecido';
      });
    }
  } finally {
    trade.importing = false;
  }
}

// ─── Cópia ────────────────────────────────────────────────────────────────────

async function copyToClipboard(text: string): Promise<void> {
  if (navigator.clipboard) {
    await navigator.clipboard.writeText(text);
    return;
  }

  const textarea = document.createElement('textarea');
  textarea.value = text;
  textarea.style.position = 'fixed';
  textarea.style.opacity = '0';
  document.body.appendChild(textarea);
  textarea.focus();
  textarea.select();
  document.execCommand('copy');
  document.body.removeChild(textarea);
}

async function copyCell(trade: TradeEntry, name: string, value: number, cellKey: string) {
  await copyToClipboard(`${name}\t${formatTf2(value)}`);
  trade.copiedKey = cellKey;
  setTimeout(() => { trade.copiedKey = null; }, 1500);
}

async function copyTier(trade: TradeEntry, tier: number) {
  const lines = trade.rows.map(row => `${row.name}\t${formatTf2(getOffer(row, tier))}`);
  const total = getTierTotal(trade, tier);
  lines.push(`total ${formatTf2(total)} tf2`);
  await copyToClipboard(lines.join('\n'));
  trade.copiedKey = `tier-${tier}`;
  setTimeout(() => { trade.copiedKey = null; }, 1500);
}

async function copyCustomTier(trade: TradeEntry) {
  const lines = trade.rows
    .map(row => {
      const val = getEffectiveCustomTf2(row);
      return val > 0 ? `${row.name}\t${formatTf2(val)}` : null;
    })
    .filter((line): line is string => line !== null);
  if (lines.length === 0) return;
  const total = getCustomTierTotal(trade);
  lines.push(`total ${formatTf2(total)} tf2`);
  await copyToClipboard(lines.join('\n'));
  trade.copiedKey = 'tier-custom';
  setTimeout(() => { trade.copiedKey = null; }, 1500);
}

// ─── Formatação ───────────────────────────────────────────────────────────────

function formatEur(val: number): string {
  return '€ ' + val.toFixed(2).replace('.', ',');
}

function formatTf2(val: number): string {
  return val.toFixed(2).replace('.', ',');
}

function tierBadgeClass(tier: number): string {
  if (tier === 100) return 'bg-success';
  if (tier === 80) return 'bg-primary';
  if (tier === 60) return 'bg-warning text-dark';
  return 'bg-danger';
}

function rowClass(row: Row): string {
  if (row.status === 'error') return 'table-danger';
  return '';
}

// ─── Ordenação intra-trade (linhas) ──────────────────────────────────────────

const sortField = ref<string | null>(null);
const sortDir = ref<'asc' | 'desc'>('asc');

function getSortValue(row: Row, field: string): number | string {
  switch (field) {
    case 'expiry':     return row.expiry ?? '';
    case 'marketPrice': return getMarketPrice(row);
    case 'netIncome':  return getNetIncome(row);
    default:
      if (field.startsWith('tier-')) {
        const tier = parseFloat(field.slice(5));
        return isNaN(tier) ? 0 : getOffer(row, isNaN(tier) ? 0 : tier);
      }
      return '';
  }
}

function sortRowsBy(field: string) {
  sortDir.value = sortField.value === field && sortDir.value === 'asc' ? 'desc' : 'asc';
  sortField.value = field;

  const dir = sortDir.value === 'asc' ? 1 : -1;

  tradeList.value.forEach(trade => {
    trade.rows.sort((a, b) => {
      const av = getSortValue(a, field);
      const bv = getSortValue(b, field);
      if (typeof av === 'number' && typeof bv === 'number') return (av - bv) * dir;
      return String(av).localeCompare(String(bv)) * dir;
    });
  });
}

function sortIcon(field: string): string {
  if (sortField.value !== field) return 'pi pi-sort-alt';
  return sortDir.value === 'asc' ? 'pi pi-sort-up' : 'pi pi-sort-down';
}

// ─── Totais por tier ──────────────────────────────────────────────────────────

function getTierTotal(trade: TradeEntry, tier: number): number {
  return trade.rows.reduce((sum, row) => sum + getOffer(row, tier), 0);
}

function getCustomTierTotal(trade: TradeEntry): number {
  return trade.rows.reduce((sum, row) => sum + getEffectiveCustomTf2(row), 0);
}

// ─── Colapso de importadas ───────────────────────────────────────────────────

function toggleExpanded(trade: TradeEntry) {
  trade.expanded = !trade.expanded;
}

function shortSupplier(url: string): string {
  if (!url) return '';
  try {
    return url.replace(/^https?:\/\/(?:www\.)?/, '').replace(/\/$/, '');
  } catch {
    return url;
  }
}

// Icon do sort do header — global (não confundir com sortIcon() das linhas).
function headerSortIcon(field: Filters['sort']): string {
  if (localFilters.sort !== field) return 'pi pi-sort-alt';
  return localFilters.dir === 'asc' ? 'pi pi-sort-up' : 'pi pi-sort-down';
}
</script>

<template>
  <ConfirmPopup />
  <div class="container-fluid py-4 px-4 w-100">

    <!-- Cabeçalho global -->
    <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
      <h4 class="mb-0 fw-bold">Trades</h4>
      <button
        type="button"
        class="btn btn-sm btn-outline-primary"
        :disabled="creatingTrade"
        @click="createTrade"
      >
        <i class="pi pi-plus me-1" />Nova trade
      </button>
      <span class="badge bg-secondary">TF2 {{ formatEur(tf2Price) }}</span>
      <span class="text-muted small">
        Taxas Gamivo:
        {{ (fees.percentLow * 100).toFixed(0) }}% + {{ formatEur(fees.fixedLow) }}
        / {{ (fees.percentHigh * 100).toFixed(0) }}% + {{ formatEur(fees.fixedHigh) }}
      </span>

      <div class="d-flex align-items-center gap-1 ms-auto">
        <span class="text-muted small">Lucro personalizado:</span>
        <input
          v-model.number="customTier"
          type="number"
          min="0"
          max="999"
          placeholder="0"
          class="custom-tier-input"
          title="Digite o % de lucro desejado para a coluna extra"
        />
        <span class="text-muted small">%</span>
      </div>
    </div>

    <!-- Barra de filtros -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body py-2 d-flex align-items-center gap-3 flex-wrap">

        <!-- View: Abertas ▸ Importadas ▸ Todas -->
        <div class="btn-group btn-group-sm" role="group" aria-label="Filtro por status">
          <button
            type="button"
            class="btn"
            :class="localFilters.view === 'open' ? 'btn-primary' : 'btn-outline-primary'"
            @click="setView('open')"
          >
            Abertas
          </button>
          <button
            type="button"
            class="btn"
            :class="localFilters.view === 'imported' ? 'btn-primary' : 'btn-outline-primary'"
            @click="setView('imported')"
          >
            Importadas
          </button>
          <button
            type="button"
            class="btn"
            :class="localFilters.view === 'all' ? 'btn-primary' : 'btn-outline-primary'"
            @click="setView('all')"
          >
            Todas
          </button>
        </div>

        <div class="vr opacity-25 align-self-stretch" />

        <!-- Range de data -->
        <div class="d-flex align-items-center gap-1">
          <span class="text-muted small">Data:</span>
          <DatePicker
            :modelValue="dateFromPicker"
            @update:modelValue="onDateFromChange"
            dateFormat="dd/mm/yy"
            placeholder="De"
            showButtonBar
            showIcon
            class="filter-date"
          />
          <span class="text-muted small">até</span>
          <DatePicker
            :modelValue="dateToPicker"
            @update:modelValue="onDateToChange"
            dateFormat="dd/mm/yy"
            placeholder="Até"
            showButtonBar
            showIcon
            class="filter-date"
          />
        </div>

        <div class="vr opacity-25 align-self-stretch" />

        <!-- Range de TF2 -->
        <div class="d-flex align-items-center gap-1">
          <span class="text-muted small">TF2:</span>
          <input
            v-model="localFilters.tf2_min"
            type="number"
            step="0.01"
            min="0"
            placeholder="min"
            class="form-control form-control-sm filter-tf2"
            @change="applyFilters()"
          />
          <span class="text-muted small">–</span>
          <input
            v-model="localFilters.tf2_max"
            type="number"
            step="0.01"
            min="0"
            placeholder="max"
            class="form-control form-control-sm filter-tf2"
            @change="applyFilters()"
          />
        </div>

        <div class="vr opacity-25 align-self-stretch" />

        <!-- Sort -->
        <div class="d-flex align-items-center gap-1">
          <span class="text-muted small">Ordenar:</span>
          <button
            type="button"
            class="btn btn-sm"
            :class="localFilters.sort === 'date' ? 'btn-secondary' : 'btn-outline-secondary'"
            @click="setSort('date')"
          >
            Data <i :class="headerSortIcon('date')" class="ms-1 sort-icon" />
          </button>
          <button
            type="button"
            class="btn btn-sm"
            :class="localFilters.sort === 'tf2_qty' ? 'btn-secondary' : 'btn-outline-secondary'"
            @click="setSort('tf2_qty')"
          >
            Qtd TF2 <i :class="headerSortIcon('tf2_qty')" class="ms-1 sort-icon" />
          </button>
        </div>

        <button
          type="button"
          class="btn btn-sm btn-link text-secondary ms-auto"
          @click="resetFilters"
        >
          <i class="pi pi-refresh me-1" />Limpar filtros
        </button>

        <span class="text-muted small">
          {{ trades.total }} trade{{ trades.total !== 1 ? 's' : '' }}
        </span>
      </div>
    </div>

    <!-- Lista de trades -->
    <div
      v-for="trade in tradeList"
      :key="trade.id"
      class="card trade-card mb-4"
      :class="{
        'trade-card--collapsed': !trade.expanded,
        'trade-card--imported': trade.isImported,
        'trade-card--open': !trade.isImported,
      }"
    >

      <!-- Linha compacta (apenas para importadas colapsadas) -->
      <div
        v-if="trade.isImported && !trade.expanded"
        class="trade-collapsed-row d-flex align-items-center gap-3 px-3 py-2"
        @click="toggleExpanded(trade)"
      >
        <i class="pi pi-chevron-right text-secondary" />
        <span class="fw-semibold trade-collapsed-title">
          {{ trade.title || 'sem título' }}
        </span>
        <span class="text-muted small">{{ trade.date || '—' }}</span>
        <span class="text-muted small trade-collapsed-supplier">{{ shortSupplier(trade.supplierUrl) || '—' }}</span>
        <span class="text-muted small">{{ trade.tf2Qty || '0' }} TF2</span>
        <span class="text-muted small">
          {{ trade.rows.length }} jogo{{ trade.rows.length !== 1 ? 's' : '' }}
        </span>
        <span class="badge bg-success ms-auto">
          <i class="pi pi-check-circle me-1" />Importada
        </span>
      </div>

      <!-- Card completo (abertas sempre; importadas quando expandidas) -->
      <template v-else>
        <!-- Cabeçalho da trade -->
        <div class="card-header bg-white d-flex align-items-center justify-content-between flex-wrap gap-2 py-2">
          <div class="d-flex align-items-center gap-2 flex-wrap flex-grow-1">

            <button
              v-if="trade.isImported"
              type="button"
              class="btn btn-sm btn-link text-secondary p-0"
              @click="toggleExpanded(trade)"
              title="Colapsar"
            >
              <i class="pi pi-chevron-down" />
            </button>

            <input
              v-model="trade.title"
              class="trade-title-input"
              placeholder="Identificação da trade..."
              @input="scheduleAutosave(trade)"
            />

            <div class="vr opacity-25 align-self-stretch" />

            <div class="d-flex align-items-center gap-3">
              <div class="trade-meta-field">
                <span class="trade-meta-label">Data</span>
                <input
                  v-model="trade.date"
                  class="cell-input trade-meta-input"
                  :class="{ 'is-missing': !(trade.date ?? '').trim() }"
                  placeholder="dd/mm/aaaa"
                  @input="scheduleAutosave(trade)"
                />
              </div>
              <div class="trade-meta-field">
                <span class="trade-meta-label">Fornecedor</span>
                <input
                  v-model="trade.supplierUrl"
                  class="cell-input trade-meta-input trade-meta-input--url"
                  :class="{ 'is-missing': hasMissingSupplierUrl(trade) }"
                  placeholder="URL do perfil"
                  @input="scheduleAutosave(trade)"
                />
              </div>
              <div class="trade-meta-field">
                <span class="trade-meta-label">Qtd TF2</span>
                <input
                  v-model="trade.tf2Qty"
                  class="cell-input trade-meta-input trade-meta-input--tf2"
                  :class="{ 'is-missing': hasMissingTf2(trade) }"
                  placeholder="0,00"
                  @input="scheduleAutosave(trade)"
                />
              </div>
            </div>

            <div class="vr opacity-25 align-self-stretch" />

            <span class="badge bg-light text-secondary border">
              {{ trade.rows.length }} jogo{{ trade.rows.length !== 1 ? 's' : '' }}
            </span>
            <span v-if="trade.isImported" class="badge bg-success">
              <i class="pi pi-check-circle me-1" />Importada
            </span>
            <span v-if="trade.saveStatus === 'saving'" class="badge bg-warning-subtle text-warning-emphasis">
              <i class="pi pi-spinner pi-spin me-1" />Salvando...
            </span>
            <span v-else-if="trade.saveStatus === 'error'" class="badge bg-danger d-inline-flex align-items-center gap-2">
              <i class="pi pi-exclamation-triangle" />
              Erro ao salvar — não recarregue a página
              <button type="button" class="btn btn-sm btn-light py-0 px-2" @click="triggerSave(trade)">
                Tentar novamente
              </button>
            </span>
            <span v-else-if="trade.saveStatus === 'saved'" class="badge bg-light text-success border">
              <i class="pi pi-check me-1" />Salvo às {{ trade.lastSavedAt }}
            </span>
            <div class="d-flex align-items-center gap-2">
              <Checkbox
                :modelValue="trade.messageSent"
                :binary="true"
                inputId="`msg-sent-${trade.id}`"
                @update:modelValue="toggleMessageSent(trade)"
              />
              <label :for="`msg-sent-${trade.id}`" class="small text-muted mb-0" style="cursor: pointer;">Mensagem enviada</label>
            </div>
          </div>
          <div class="d-flex gap-2">
            <button
              type="button"
              class="btn btn-sm btn-outline-secondary"
              :disabled="trade.importing"
              @click="addRow(trade)"
            >
              <i class="pi pi-plus me-1" />
              Linha
            </button>
            <button
              v-if="!trade.isImported"
              type="button"
              class="btn btn-sm btn-primary"
              :disabled="trade.importing || !canImport(trade)"
              @click="importTrade($event, trade)"
            >
              <i class="pi pi-upload me-1" />
              {{ trade.importing ? 'Importando...' : 'Importar keys' }}
            </button>
            <button
              type="button"
              class="btn btn-sm btn-outline-danger"
              :disabled="trade.importing"
              @click="deleteTrade($event, trade)"
            >
              <i class="pi pi-trash me-1" />
              Excluir
            </button>
          </div>
        </div>

        <!-- Tabela editável -->
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th class="sort-th" style="min-width: 110px;" @click="sortRowsBy('marketPrice')">
                    Preço Mercado <span class="text-muted fw-normal">(€)</span>
                    <i :class="sortIcon('marketPrice')" class="sort-icon" />
                  </th>
                  <th style="min-width: 100px;">Bundle</th>
                  <th class="sort-th" style="min-width: 100px;" @click="sortRowsBy('expiry')">
                    Expiração <i :class="sortIcon('expiry')" class="sort-icon" />
                  </th>
                  <th style="min-width: 90px;">Popularidade</th>
                  <th style="min-width: 90px;">Region</th>
                  <th style="min-width: 200px;">
                    <span class="text-primary fw-bold">Key Code</span>
                  </th>
                  <th style="min-width: 180px;">Nome do Jogo</th>
                  <th style="min-width: 100px;">Gamivo ID</th>
                  <th class="text-end sort-th" style="min-width: 100px;" @click="sortRowsBy('netIncome')">
                    Income líq. <span class="text-muted fw-normal">(€)</span>
                    <i :class="sortIcon('netIncome')" class="sort-icon" />
                  </th>

                  <th
                    v-for="tier in profitTiers"
                    :key="tier"
                    class="text-center sort-th"
                    style="min-width: 100px;"
                    @click="sortRowsBy(`tier-${tier}`)"
                  >
                    <div class="d-flex flex-column align-items-center gap-1">
                      <span class="badge" :class="tierBadgeClass(tier)">
                        {{ tier }}% <i :class="sortIcon(`tier-${tier}`)" class="sort-icon" />
                      </span>
                      <button
                        type="button"
                        class="btn btn-sm"
                        :class="trade.copiedKey === `tier-${tier}` ? 'btn-success' : 'btn-outline-secondary'"
                        :title="`Copiar todos (${tier}%)`"
                        @click.stop="copyTier(trade, tier)"
                      >
                        <i :class="trade.copiedKey === `tier-${tier}` ? 'pi pi-check' : 'pi pi-copy'" />
                      </button>
                    </div>
                  </th>

                  <th
                    class="text-center sort-th"
                    style="min-width: 110px;"
                    @click="customTier !== null && sortRowsBy('tier-custom')"
                  >
                    <div class="d-flex flex-column align-items-center gap-1">
                      <div class="d-flex align-items-center gap-1">
                        <span class="badge bg-secondary" v-if="customTier === null">—%</span>
                        <span class="badge bg-secondary" v-else>
                          {{ customTier }}% <i :class="sortIcon('tier-custom')" class="sort-icon" />
                        </span>
                      </div>
                      <button
                        type="button"
                        class="btn btn-sm"
                        :class="trade.copiedKey === 'tier-custom' ? 'btn-success' : 'btn-outline-secondary'"
                        :disabled="customTier === null && !trade.rows.some(r => r.customTf2Override)"
                        title="Copiar todos"
                        @click.stop="copyCustomTier(trade)"
                      >
                        <i :class="trade.copiedKey === 'tier-custom' ? 'pi pi-check' : 'pi pi-copy'" />
                      </button>
                    </div>
                  </th>
                  <th style="width: 36px;"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, rowIdx) in trade.rows" :key="rowIdx" :class="rowClass(row)">

                  <td>
                    <input
                      :value="row.marketPriceRaw.replace('.', ',')"
                      class="cell-input ps-2"
                      :class="{ 'is-missing': isRowMeaningful(row) && hasMissingMarketPrice(row) }"
                      placeholder="0,00"
                      @change="(e) => { row.marketPriceRaw = (parseFloat((e.target as HTMLInputElement).value.replace(',', '.')) || 0).toFixed(2); scheduleAutosave(trade); }"
                    />
                  </td>

                  <td>
                    <input v-model="row.bundle" class="cell-input text-muted" @input="scheduleAutosave(trade)" />
                  </td>

                  <td>
                    <input v-model="row.expiry" class="cell-input" @input="scheduleAutosave(trade)" />
                  </td>

                  <td>
                    <input v-model="row.popularity" class="cell-input text-muted" @input="scheduleAutosave(trade)" />
                  </td>

                  <td>
                    <input v-model="row.regionLock" class="cell-input" @input="scheduleAutosave(trade)" />
                  </td>

                  <td>
                    <input
                      v-model="row.keyCode"
                      class="cell-input font-monospace"
                      :class="{ 'is-missing': !(row.keyCode ?? '').trim() }"
                      placeholder="XXXXX-XXXXX-XXXXX"
                      @input="scheduleAutosave(trade)"
                    />
                    <div v-if="row.status === 'error'" class="text-danger" style="font-size: 0.7rem;">
                      {{ row.errorMsg }}
                    </div>
                  </td>

                  <td>
                    <input
                      v-model="row.name"
                      class="cell-input fw-semibold"
                      :class="{ 'is-missing': isRowMeaningful(row) && hasMissingName(row) }"
                      placeholder="Nome do jogo"
                      @input="scheduleAutosave(trade)"
                    />
                  </td>

                  <td>
                    <input v-model="row.gamivoId" class="cell-input text-muted" @input="scheduleAutosave(trade)" />
                  </td>

                  <td class="text-end text-muted small">
                    {{ formatEur(getNetIncome(row)) }}
                  </td>

                  <td
                    v-for="tier in profitTiers"
                    :key="tier"
                    class="text-center"
                  >
                    <button
                      type="button"
                      class="btn btn-sm w-100"
                      :class="trade.copiedKey === `${rowIdx}-${tier}` ? 'btn-success' : 'btn-outline-secondary'"
                      :title="`Copiar: ${row.name} + ${formatTf2(getOffer(row, tier))} TF2`"
                      @click="copyCell(trade, row.name, getOffer(row, tier), `${rowIdx}-${tier}`)"
                    >
                      <i v-if="trade.copiedKey === `${rowIdx}-${tier}`" class="pi pi-check me-1" />
                      {{ formatTf2(getOffer(row, tier)) }}
                    </button>
                  </td>

                  <td class="text-center">
                    <div class="d-flex flex-column align-items-center gap-1">
                      <div class="d-flex align-items-center gap-1">
                        <input
                          v-model="row.customTf2Override"
                          class="custom-tf2-row-input"
                          :placeholder="customTier !== null ? formatTf2(getOffer(row, customTier)) : '—'"
                          @click.stop
                        />
                        <button
                          v-if="getEffectiveCustomTf2(row) > 0"
                          type="button"
                          class="btn btn-sm px-1"
                          :class="trade.copiedKey === `${rowIdx}-custom` ? 'btn-success' : 'btn-outline-purple'"
                          :title="`Copiar: ${row.name} + ${formatTf2(getEffectiveCustomTf2(row))} TF2`"
                          @click="copyCell(trade, row.name, getEffectiveCustomTf2(row), `${rowIdx}-custom`)"
                        >
                          <i
                            :class="trade.copiedKey === `${rowIdx}-custom` ? 'pi pi-check' : 'pi pi-copy'"
                            style="font-size: 0.75rem;"
                          />
                        </button>
                      </div>
                      <span
                        v-if="getImpliedProfit(row) !== null"
                        class="text-muted"
                        style="font-size: 0.7rem;"
                      >
                        ≈ {{ getImpliedProfit(row)!.toFixed(1) }}%
                      </span>
                    </div>
                  </td>

                  <td class="text-center p-1">
                    <div class="d-flex flex-column align-items-center gap-1">
                      <button
                        type="button"
                        class="btn btn-sm btn-link text-secondary p-0"
                        title="Duplicar linha (sem key code)"
                        @click="duplicateRow(trade, rowIdx)"
                      >
                        <i class="pi pi-clone" style="font-size: 0.75rem;" />
                      </button>
                      <button
                        type="button"
                        class="btn btn-sm btn-link text-danger p-0"
                        title="Excluir linha"
                        @click="deleteRow(trade, rowIdx)"
                      >
                        <i class="pi pi-times" style="font-size: 0.75rem;" />
                      </button>
                    </div>
                  </td>

                </tr>
              </tbody>
              <tfoot class="table-light">
                <tr>
                  <td colspan="9" class="text-end text-muted small fw-semibold pe-3">Total</td>

                  <td
                    v-for="tier in profitTiers"
                    :key="tier"
                    class="text-center fw-bold small"
                  >
                    {{ formatTf2(getTierTotal(trade, tier)) }}
                  </td>

                  <td class="text-center fw-bold small">
                    <span v-if="getCustomTierTotal(trade) > 0">
                      {{ formatTf2(getCustomTierTotal(trade)) }}
                    </span>
                    <span v-else class="text-muted">—</span>
                  </td>

                  <td></td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      </template>

    </div>

    <!-- Paginação -->
    <div v-if="trades.total > trades.per_page" class="d-flex justify-content-center mt-3">
      <Paginator
        :first="paginationFirst"
        :rows="trades.per_page"
        :totalRecords="trades.total"
        @page="onPageChange"
        template="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport"
      />
    </div>

    <div v-if="trades.total === 0" class="text-center text-muted py-5">
      Nenhuma trade encontrada com os filtros atuais.
    </div>

  </div>
</template>

<style scoped>
.trade-meta-field {
  display: flex;
  flex-direction: column;
  gap: 1px;
}

.trade-meta-label {
  font-size: 0.6rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: #8009EF;
  line-height: 1;
}

.trade-meta-input {
  width: auto;
  font-size: 0.85rem;
}

.trade-meta-input--url {
  min-width: 170px;
  font-size: 0.75rem;
  color: #6c757d;
}

.trade-meta-input--tf2 {
  width: 70px;
}

/* ── Título da trade ─────────────────────────────────────────────────────────── */

.trade-title-input {
  border: none;
  border-bottom: 1px dashed #dee2e6;
  background: transparent;
  font-size: 0.95rem;
  font-weight: 600;
  color: #212529;
  padding: 1px 4px;
  min-width: 180px;
  max-width: 320px;
}

.trade-title-input:focus {
  outline: none;
  border-bottom: 2px solid #8009EF;
  background: #f9f4ff;
}

.trade-title-input::placeholder {
  color: #adb5bd;
  font-weight: 400;
  font-style: italic;
}

/* ── Inputs da tabela ────────────────────────────────────────────────────────── */

.cell-input {
  border: none;
  background: transparent;
  width: 100%;
  padding: 0;
  font-size: inherit;
  font-family: inherit;
  color: inherit;
}

.cell-input:focus {
  outline: none;
  background: #fff;
  border-bottom: 2px solid #8009EF;
}

.cell-input.is-missing {
  border-bottom: 2px solid #dc3545;
  background-color: #fff5f5;
}

.cell-input::placeholder {
  color: #adb5bd;
  font-size: 0.8rem;
}

/* ── Tier customizável ───────────────────────────────────────────────────────── */

.custom-tier-input {
  width: 52px;
  border: none;
  border-bottom: 2px solid #8009EF;
  background: transparent;
  text-align: center;
  font-size: 0.85rem;
  font-weight: 600;
  color: #8009EF;
  padding: 0 2px;
}

.custom-tier-input:focus {
  outline: none;
  background: #f9f4ff;
}

.custom-tier-input::placeholder {
  color: #c8a0f5;
}

.custom-tier-input::-webkit-outer-spin-button,
.custom-tier-input::-webkit-inner-spin-button {
  -webkit-appearance: none;
  margin: 0;
}

.custom-tier-input[type=number] {
  -moz-appearance: textfield;
}

/* ── Ordenação intra-trade ───────────────────────────────────────────────────── */

.sort-th {
  cursor: pointer;
  user-select: none;
}

.sort-th:hover {
  background-color: #e9ecef;
}

.sort-icon {
  font-size: 0.65rem;
  opacity: 0.5;
  margin-left: 2px;
  vertical-align: middle;
}

.sort-th:hover .sort-icon,
.sort-icon.pi-sort-up,
.sort-icon.pi-sort-down {
  opacity: 1;
}

.btn-outline-purple {
  color: #8009EF;
  border-color: #8009EF;
}

.btn-outline-purple:hover {
  background-color: #8009EF;
  color: #fff;
}

/* ── Override TF2 por linha ──────────────────────────────────────────────────── */

.custom-tf2-row-input {
  width: 52px;
  border: none;
  border-bottom: 1px solid #dee2e6;
  background: transparent;
  text-align: center;
  font-size: 0.85rem;
  color: #212529;
  padding: 0 2px;
}

.custom-tf2-row-input:focus {
  outline: none;
  border-bottom: 2px solid #8009EF;
  background: #f9f4ff;
}

.custom-tf2-row-input::placeholder {
  color: #adb5bd;
  font-size: 0.8rem;
}

/* ── Filtros ─────────────────────────────────────────────────────────────────── */

.filter-date {
  width: 140px;
  font-size: 0.8rem;
}

.filter-tf2 {
  width: 80px;
  font-size: 0.8rem;
}

/* ── Card da trade ───────────────────────────────────────────────────────────── */

.trade-card {
  border: 1px solid #dee2e6;
  border-left-width: 4px;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.06);
  transition: box-shadow 0.15s ease, border-color 0.15s ease;
}

.trade-card:hover {
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
}

/* Trade aberta — barra roxa (mesmo tom dos acentos do app) */
.trade-card--open {
  border-left-color: #8009EF;
  background: #ffffff;
}

/* Trade importada — barra verde, fundo levemente esmaecido para leitura de arquivo */
.trade-card--imported {
  border-left-color: #198754;
}

.trade-card--imported.trade-card--collapsed {
  background: #f5f7fa;
}

.trade-collapsed-row {
  cursor: pointer;
  transition: background-color 0.15s ease;
}

.trade-collapsed-row:hover {
  background-color: #eaeef3;
}

.trade-collapsed-title {
  min-width: 180px;
  max-width: 320px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.trade-collapsed-supplier {
  max-width: 260px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
