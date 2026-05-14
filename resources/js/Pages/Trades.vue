<script setup lang="ts">
import { ref, nextTick } from 'vue';
import axiosInstance from '@/axios';

const props = defineProps<{
  trades: Array<{ id: number; title: string | null; rows: StoredRow[]; created_at: string }>;
  tf2Price: number;
  fees: {
    percentLow: number;
    fixedLow: number;
    percentHigh: number;
    fixedHigh: number;
  };
  profitTiers: number[];
}>();

// ─── Tipos ────────────────────────────────────────────────────────────────────

type RowStatus = 'pending' | 'success' | 'error';

/** Campos persistidos no banco (sem estado de UI). */
interface StoredRow {
  date: string;
  name: string;
  marketPriceRaw: string;
  tf2Qty: string;
  bundle: string;
  expiry: string;
  popularity: string;
  regionLock: string;
  keyCode: string;
  supplierUrl: string;
}

/** StoredRow + estado de UI (não é enviado ao backend). */
interface Row extends StoredRow {
  status: RowStatus;
  errorMsg: string;
}

interface TradeEntry {
  id: number;
  title: string;
  rows: Row[];
  createdAt: string;
  // UI-only
  importing: boolean;
  copiedKey: string | null;
}

// ─── Estado global ────────────────────────────────────────────────────────────

const tradeList = ref<TradeEntry[]>(
  props.trades.map(t => toTradeEntry(t)),
);

const customTier = ref<number | null>(null);

/** Timers de debounce por trade ID (autosave). */
const saveTimers = new Map<number, ReturnType<typeof setTimeout>>();

// ─── Helpers de conversão ─────────────────────────────────────────────────────

/** Garante que todos os campos de string nunca sejam null (vindo do JSON do banco). */
function toRow(r: any): Row {
  return {
    date: r.date ?? '',
    name: r.name ?? '',
    marketPriceRaw: r.marketPriceRaw ?? '',
    tf2Qty: r.tf2Qty ?? '',
    bundle: r.bundle ?? '',
    expiry: r.expiry ?? '',
    popularity: r.popularity ?? '',
    regionLock: r.regionLock ?? '',
    keyCode: r.keyCode ?? '',
    supplierUrl: r.supplierUrl ?? '',
    status: 'pending',
    errorMsg: '',
  };
}

function emptyRow(): Row {
  return toRow({});
}

function toTradeEntry(t: { id: number; title: string | null; rows: StoredRow[]; created_at: string }): TradeEntry {
  return {
    id: t.id,
    title: t.title ?? '',
    rows: (t.rows ?? []).map(toRow),
    createdAt: t.created_at,
    importing: false,
    copiedKey: null,
  };
}

function rowToStored(row: Row): StoredRow {
  const { status, errorMsg, ...stored } = row;
  return stored;
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
// A fonte da verdade e os testes unitários estão no Domain PHP.
// Se alterar a fórmula, atualize os dois lados.
function calcOffer(netIncome: number, profitPct: number): number {
  if (props.tf2Price <= 0) return 0;
  return netIncome / (1 + profitPct / 100) / props.tf2Price;
}

function getMarketPrice(row: Row): number {
  return parseFloat((row.marketPriceRaw ?? '').replace(',', '.').replace('€', '').trim()) || 0;
}

function getNetIncome(row: Row): number {
  return calcNetIncome(getMarketPrice(row));
}

function getOffer(row: Row, tier: number): number {
  return calcOffer(getNetIncome(row), tier);
}

// ─── Parse de TSV ─────────────────────────────────────────────────────────────

function parseText(text: string): Row[] {
  return text
    .split('\n')
    .map(l => l.trim())
    .filter(l => l.length > 0)
    .flatMap(line => {
      const cols = line.split('\t');
      if (cols.length < 10) return [];

      const rawPrice = cols[1].replace('€', '').replace(',', '.').trim();
      const marketPrice = parseFloat(rawPrice);
      const name = cols[9].trim();

      if (isNaN(marketPrice) || marketPrice <= 0 || !name) return [];

      return [{
        date: cols[0].trim(),
        marketPriceRaw: cols[1].trim(),
        supplierUrl: cols[2].trim(),
        tf2Qty: cols[3].trim(),
        bundle: cols[4].trim(),
        expiry: cols[5].trim(),
        popularity: cols[6].trim(),
        regionLock: cols[7].trim(),
        keyCode: cols[8].trim(),
        name,
        status: 'pending' as RowStatus,
        errorMsg: '',
      }];
    });
}

// ─── Paste → nova trade ───────────────────────────────────────────────────────

async function handlePaste(e: ClipboardEvent) {
  e.preventDefault();
  const text = e.clipboardData?.getData('text') ?? '';
  const rows = parseText(text);
  if (rows.length === 0) return;

  try {
    const res = await axiosInstance.post(route('trades.store'), {
      rows: rows.map(rowToStored),
    });

    tradeList.value.push({
      id: res.data.id,
      title: '',
      rows,
      createdAt: res.data.created_at,
      importing: false,
      copiedKey: null,
    });

    // Rola até a nova trade
    await nextTick();
    window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
  } catch (err) {
    console.error('Erro ao salvar trade:', err);
  }
}

// Paste escopado na zona de colagem — sem listener global.
// Ver @paste na div.paste-zone no template.

// ─── Autosave (debounce por trade) ────────────────────────────────────────────

function scheduleAutosave(trade: TradeEntry) {
  const existing = saveTimers.get(trade.id);
  if (existing) clearTimeout(existing);

  const timer = setTimeout(async () => {
    saveTimers.delete(trade.id);
    try {
      await axiosInstance.put(route('trades.update', { trade: trade.id }), {
        title: trade.title,
        rows: trade.rows.map(rowToStored),
      });
    } catch (err) {
      console.error('Erro ao salvar trade:', err);
    }
  }, 800);

  saveTimers.set(trade.id, timer);
}

// ─── Linhas ───────────────────────────────────────────────────────────────────

function addRow(trade: TradeEntry) {
  trade.rows.push(emptyRow());
  scheduleAutosave(trade);
}

function deleteRow(trade: TradeEntry, rowIdx: number) {
  trade.rows.splice(rowIdx, 1);
  scheduleAutosave(trade);
}

// ─── Exclusão ─────────────────────────────────────────────────────────────────

async function deleteTrade(trade: TradeEntry) {
  await axiosInstance.delete(route('trades.destroy', { trade: trade.id }));
  tradeList.value = tradeList.value.filter(t => t.id !== trade.id);
}

// ─── Importação de keys (por trade) ───────────────────────────────────────────

function convertDateToISO(date: string): string {
  const parts = date.split('/');
  if (parts.length === 3) return `${parts[2]}-${parts[1]}-${parts[0]}`;
  return date;
}

/** Linha com pelo menos nome ou preço preenchido — linhas totalmente em branco são ignoradas. */
const isRowMeaningful = (r: Row) => !!(r.name?.trim() || (r.marketPriceRaw ?? '').trim());

const hasMissingKeyCodes = (trade: TradeEntry) =>
  trade.rows.some(r => isRowMeaningful(r) && !(r.keyCode ?? '').trim());
const hasMissingTf2 = (trade: TradeEntry) =>
  trade.rows.some(r => isRowMeaningful(r) && !(parseFloat((r.tf2Qty ?? '').replace(',', '.')) > 0));
const hasMissingSupplierUrl = (trade: TradeEntry) =>
  trade.rows.some(r => isRowMeaningful(r) && !(r.supplierUrl ?? '').trim());
const canImport = (trade: TradeEntry) =>
  trade.rows.some(isRowMeaningful)
  && !hasMissingKeyCodes(trade)
  && !hasMissingTf2(trade)
  && !hasMissingSupplierUrl(trade);

async function importTrade(trade: TradeEntry) {
  if (!canImport(trade)) return;

  trade.importing = true;
  trade.rows.forEach(r => { r.status = 'pending'; r.errorMsg = ''; });

  // Preserva o mapeamento: índice em `games` (enviado ao backend) → índice original em trade.rows.
  // Necessário porque trade.rows pode conter linhas em branco que são filtradas antes do envio,
  // deslocando os índices e fazendo as mensagens de erro do backend caírem na linha errada.
  const meaningfulEntries = trade.rows
    .map((row, originalIdx) => ({ row, originalIdx }))
    .filter(({ row }) => isRowMeaningful(row));

  const games = meaningfulEntries.map(({ row }) => ({
    game_name: row.name ?? '',
    market_price: getMarketPrice(row),
    tf2_quantity: parseFloat((row.tf2Qty ?? '').replace(',', '.')) || 0,
    key_code: (row.keyCode ?? '').trim(),
    supplier_url: (row.supplierUrl ?? '').trim(),
    acquired_at: convertDateToISO(row.date ?? ''),
    region: (row.regionLock ?? '').trim() || null,
    expires_at: (row.expiry ?? '').trim() ? convertDateToISO((row.expiry ?? '').trim()) : null,
  }));

  try {
    const res = await axiosInstance.post(
      route('trades.import', { trade: trade.id }),
      { games },
    );

    const errors: { linha: number; erro: string }[] = res.data.errors ?? [];
    const errorsByGameIdx = new Map(errors.map(e => [e.linha - 1, e.erro]));

    meaningfulEntries.forEach(({ originalIdx }, gameIdx) => {
      const row = trade.rows[originalIdx];
      if (errorsByGameIdx.has(gameIdx)) {
        row.status = 'error';
        row.errorMsg = errorsByGameIdx.get(gameIdx)!;
      } else {
        row.status = 'success';
      }
    });
  } catch (e: any) {
    if (e?.response?.status === 422) {
      const validationErrors: Record<string, string[]> = e.response.data.errors ?? {};
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

/**
 * Copia texto para a área de transferência.
 * navigator.clipboard só existe em contextos seguros (HTTPS/localhost).
 * Em HTTP, usa o fallback via execCommand para manter compatibilidade.
 */
async function copyToClipboard(text: string): Promise<void> {
  if (navigator.clipboard) {
    await navigator.clipboard.writeText(text);
    return;
  }

  // Fallback para HTTP
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
  const text = trade.rows
    .map(row => `${row.name}\t${formatTf2(getOffer(row, tier))}`)
    .join('\n');
  await copyToClipboard(text);
  trade.copiedKey = `tier-${tier}`;
  setTimeout(() => { trade.copiedKey = null; }, 1500);
}

async function copyCustomTier(trade: TradeEntry) {
  if (customTier.value === null) return;
  const text = trade.rows
    .map(row => `${row.name}\t${formatTf2(getOffer(row, customTier.value!))}`)
    .join('\n');
  await copyToClipboard(text);
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

function formatCreatedAt(dateStr: string): string {
  const d = new Date(dateStr);
  const day = String(d.getDate()).padStart(2, '0');
  const month = String(d.getMonth() + 1).padStart(2, '0');
  const year = d.getFullYear();
  const hours = String(d.getHours()).padStart(2, '0');
  const mins = String(d.getMinutes()).padStart(2, '0');
  return `${day}/${month}/${year} ${hours}:${mins}`;
}

function tierBadgeClass(tier: number): string {
  if (tier === 100) return 'bg-success';
  if (tier === 80) return 'bg-primary';
  if (tier === 60) return 'bg-warning text-dark';
  return 'bg-danger';
}

function rowClass(row: Row): string {
  if (row.status === 'success') return 'table-success';
  if (row.status === 'error') return 'table-danger';
  return '';
}

// ─── Ordenação ────────────────────────────────────────────────────────────────

const sortField = ref<string | null>(null);
const sortDir = ref<'asc' | 'desc'>('asc');

function getSortValue(row: Row, field: string): number | string {
  switch (field) {
    case 'date':       return row.date ?? '';
    case 'expiry':     return row.expiry ?? '';
    case 'marketPrice': return getMarketPrice(row);
    case 'tf2Qty':     return parseFloat((row.tf2Qty ?? '').replace(',', '.')) || 0;
    case 'netIncome':  return getNetIncome(row);
    default:
      if (field.startsWith('tier-')) {
        const tier = parseFloat(field.slice(5));
        return isNaN(tier) ? 0 : getOffer(row, isNaN(tier) ? 0 : tier);
      }
      return '';
  }
}

function sortBy(field: string) {
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
</script>

<template>
  <div class="container-fluid py-4 px-4 w-100">

    <!-- Cabeçalho global -->
    <div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
      <h4 class="mb-0 fw-bold">Trades</h4>
      <span class="badge bg-secondary">TF2 {{ formatEur(tf2Price) }}</span>
      <span class="text-muted small">
        Taxas Gamivo:
        {{ (fees.percentLow * 100).toFixed(0) }}% + {{ formatEur(fees.fixedLow) }}
        / {{ (fees.percentHigh * 100).toFixed(0) }}% + {{ formatEur(fees.fixedHigh) }}
      </span>

      <!-- Tier customizável — global, compartilhado por todas as trades -->
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

    <!-- Zona de colagem (escopada — clique para focar, depois Ctrl+V) -->
    <div
      :class="tradeList.length === 0 ? 'paste-zone-full' : 'paste-zone-compact'"
      class="paste-zone d-flex align-items-center justify-content-center gap-3 rounded mb-4"
      tabindex="0"
      @paste="handlePaste"
      @click="($event.currentTarget as HTMLElement).focus()"
    >
      <i class="pi pi-clipboard" :style="tradeList.length === 0 ? 'font-size: 2.5rem; color: #8009EF;' : 'color: #8009EF;'" />
      <div :class="tradeList.length === 0 ? 'text-center' : ''">
        <p class="fw-semibold mb-0">
          {{ tradeList.length === 0 ? 'Clique aqui e cole os dados' : 'Cole para adicionar nova trade' }}
        </p>
        <p v-if="tradeList.length === 0" class="text-muted small mb-0">
          Ctrl+V após copiar as linhas da planilha ou do price researcher
        </p>
      </div>
      <template v-if="tradeList.length === 0">
        <div class="d-flex flex-wrap justify-content-center gap-1" style="max-width: 600px;">
          <span class="badge bg-light text-secondary border">Data</span>
          <span class="badge bg-warning text-dark border">Preço de Mercado</span>
          <span class="badge bg-light text-secondary border">URL Fornecedor</span>
          <span class="badge bg-warning text-dark border">Qtd TF2</span>
          <span class="badge bg-light text-secondary border">Bundle</span>
          <span class="badge bg-warning text-dark border">Expiração</span>
          <span class="badge bg-light text-secondary border">Popularidade</span>
          <span class="badge bg-warning text-dark border">Region Lock</span>
          <span class="badge bg-warning text-dark border">Chave</span>
          <span class="badge bg-warning text-dark border">Nome do Jogo</span>
        </div>
      </template>
    </div>

    <!-- Lista de trades (mais antigas em cima, mais novas em baixo) -->
    <div
      v-for="(trade, tradeIdx) in tradeList"
      :key="trade.id"
      class="card border-0 shadow-sm mb-4"
    >
      <!-- Cabeçalho da trade -->
      <div class="card-header bg-white d-flex align-items-center justify-content-between flex-wrap gap-2 py-2">
        <div class="d-flex align-items-center gap-2 flex-wrap flex-grow-1">
          <input
            v-model="trade.title"
            class="trade-title-input"
            placeholder="Identificação da trade..."
            @input="scheduleAutosave(trade)"
          />
          <span class="text-muted" style="font-size: 0.75rem;">|</span>
          <span class="text-muted small">
            <i class="pi pi-calendar me-1" />{{ formatCreatedAt(trade.createdAt) }}
          </span>
          <span class="badge bg-light text-secondary border">
            {{ trade.rows.length }} jogo{{ trade.rows.length !== 1 ? 's' : '' }}
          </span>
          <span v-if="hasMissingKeyCodes(trade)" class="badge bg-warning text-dark">
            <i class="pi pi-exclamation-triangle me-1" />Key codes em falta
          </span>
          <span v-if="hasMissingTf2(trade)" class="badge bg-warning text-dark">
            <i class="pi pi-exclamation-triangle me-1" />Qtd TF2 em falta
          </span>
          <span v-if="hasMissingSupplierUrl(trade)" class="badge bg-warning text-dark">
            <i class="pi pi-exclamation-triangle me-1" />URL Fornecedor em falta
          </span>
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
            type="button"
            class="btn btn-sm btn-primary"
            :disabled="trade.importing || !canImport(trade)"
            @click="importTrade(trade)"
          >
            <i class="pi pi-upload me-1" />
            {{ trade.importing ? 'Importando...' : 'Importar keys' }}
          </button>
          <button
            type="button"
            class="btn btn-sm btn-outline-danger"
            :disabled="trade.importing"
            @click="deleteTrade(trade)"
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
                <th style="width: 36px;"></th>
                <th class="ps-3 sort-th" style="min-width: 90px;" @click="sortBy('date')">
                  Data <i :class="sortIcon('date')" class="sort-icon" />
                </th>
                <th class="sort-th" style="min-width: 110px;" @click="sortBy('marketPrice')">
                  Preço Mercado <span class="text-muted fw-normal">(€)</span>
                  <i :class="sortIcon('marketPrice')" class="sort-icon" />
                </th>
                <th style="min-width: 120px;">URL Fornecedor</th>
                <th class="sort-th" style="min-width: 90px;" @click="sortBy('tf2Qty')">
                  Qtd TF2 <i :class="sortIcon('tf2Qty')" class="sort-icon" />
                </th>
                <th style="min-width: 100px;">Bundle</th>
                <th class="sort-th" style="min-width: 100px;" @click="sortBy('expiry')">
                  Expiração <i :class="sortIcon('expiry')" class="sort-icon" />
                </th>
                <th style="min-width: 90px;">Popularidade</th>
                <th style="min-width: 90px;">Region</th>
                <th style="min-width: 200px;">
                  <span class="text-primary fw-bold">Key Code</span>
                </th>
                <th style="min-width: 180px;">Nome do Jogo</th>
                <th class="text-end sort-th" style="min-width: 100px;" @click="sortBy('netIncome')">
                  Income líq. <span class="text-muted fw-normal">(€)</span>
                  <i :class="sortIcon('netIncome')" class="sort-icon" />
                </th>

                <!-- Tiers fixos -->
                <th
                  v-for="tier in profitTiers"
                  :key="tier"
                  class="text-center sort-th"
                  style="min-width: 100px;"
                  @click="sortBy(`tier-${tier}`)"
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

                <!-- Tier customizável -->
                <th
                  class="text-center sort-th"
                  style="min-width: 110px;"
                  @click="customTier !== null && sortBy('tier-custom')"
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
                      :disabled="customTier === null"
                      title="Copiar todos"
                      @click.stop="copyCustomTier(trade)"
                    >
                      <i :class="trade.copiedKey === 'tier-custom' ? 'pi pi-check' : 'pi pi-copy'" />
                    </button>
                  </div>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, rowIdx) in trade.rows" :key="rowIdx" :class="rowClass(row)">

                <td class="text-center p-1">
                  <button
                    type="button"
                    class="btn btn-sm btn-link text-danger p-0"
                    title="Excluir linha"
                    @click="deleteRow(trade, rowIdx)"
                  >
                    <i class="pi pi-times" style="font-size: 0.75rem;" />
                  </button>
                </td>

                <td class="ps-2">
                  <input v-model="row.date" class="cell-input" @input="scheduleAutosave(trade)" />
                </td>

                <td>
                  <input v-model="row.marketPriceRaw" class="cell-input" @input="scheduleAutosave(trade)" />
                </td>

                <td>
                  <input v-model="row.supplierUrl" class="cell-input text-muted" style="font-size: 0.75rem;" @input="scheduleAutosave(trade)" />
                </td>

                <td>
                  <input
                    v-model="row.tf2Qty"
                    class="cell-input"
                    :class="{ 'is-missing': !(parseFloat((row.tf2Qty ?? '').replace(',', '.')) > 0) }"
                    placeholder="0,00"
                    @input="scheduleAutosave(trade)"
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
                  <div v-if="row.status === 'success'" class="text-success" style="font-size: 0.7rem;">
                    <i class="pi pi-check me-1" />Importada com sucesso
                  </div>
                </td>

                <td>
                  <input v-model="row.name" class="cell-input fw-semibold" @input="scheduleAutosave(trade)" />
                </td>

                <!-- Income líquido -->
                <td class="text-end text-muted small">
                  {{ formatEur(getNetIncome(row)) }}
                </td>

                <!-- Tiers fixos -->
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

                <!-- Tier customizável -->
                <td class="text-center">
                  <template v-if="customTier !== null">
                    <button
                      type="button"
                      class="btn btn-sm w-100 btn-outline-purple"
                      :class="trade.copiedKey === `${rowIdx}-custom` ? 'btn-success' : ''"
                      :title="`Copiar: ${row.name} + ${formatTf2(getOffer(row, customTier))} TF2`"
                      @click="copyCell(trade, row.name, getOffer(row, customTier), `${rowIdx}-custom`)"
                    >
                      <i v-if="trade.copiedKey === `${rowIdx}-custom`" class="pi pi-check me-1" />
                      {{ formatTf2(getOffer(row, customTier)) }}
                    </button>
                  </template>
                  <span v-else class="text-muted small">—</span>
                </td>

              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div>
</template>

<style scoped>
/* ── Zona de colagem ─────────────────────────────────────────────────────────── */

.paste-zone {
  cursor: pointer;
  border: 2px dashed #dee2e6;
  transition: border-color 0.2s, background-color 0.2s;
}

.paste-zone:hover,
.paste-zone:focus {
  border-color: #8009EF;
  background-color: #f9f4ff;
  outline: none;
}

.paste-zone-full {
  min-height: 220px;
  padding: 2rem;
  flex-direction: column;
}

.paste-zone-compact {
  padding: 0.6rem 1.2rem;
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

/* ── Ordenação ───────────────────────────────────────────────────────────────── */

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
</style>
