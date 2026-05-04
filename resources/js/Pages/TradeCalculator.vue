<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue';
import axiosInstance from '@/axios';

const props = defineProps<{
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

interface Row {
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
  status: RowStatus;
  errorMsg: string;
}

// ─── Estado ───────────────────────────────────────────────────────────────────

const rows = ref<Row[]>([]);
const copiedKey = ref<string | null>(null);
const importing = ref(false);
const pasteZoneRef = ref<HTMLDivElement | null>(null);
const customTier = ref<number | null>(null);

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
  return parseFloat(row.marketPriceRaw.replace(',', '.').replace('€', '').trim()) || 0;
}

function getNetIncome(row: Row): number {
  return calcNetIncome(getMarketPrice(row));
}

function getOffer(row: Row, tier: number): number {
  return calcOffer(getNetIncome(row), tier);
}

// ─── Parse ────────────────────────────────────────────────────────────────────

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

// ─── Eventos de colagem ───────────────────────────────────────────────────────

function handlePaste(e: ClipboardEvent) {
  if (rows.value.length > 0) return;
  e.preventDefault();
  const text = e.clipboardData?.getData('text') ?? '';
  const parsed = parseText(text);
  if (parsed.length > 0) rows.value = parsed;
}

function clear() {
  rows.value = [];
}

onMounted(() => window.addEventListener('paste', handlePaste));
onUnmounted(() => window.removeEventListener('paste', handlePaste));

// ─── Cópia ────────────────────────────────────────────────────────────────────

async function copy(name: string, value: number, cellKey: string) {
  await navigator.clipboard.writeText(`${name}\t${formatTf2(value)}`);
  copiedKey.value = cellKey;
  setTimeout(() => { copiedKey.value = null; }, 1500);
}

async function copyTier(tier: number) {
  const text = rows.value
    .map(row => `${row.name}\t${formatTf2(getOffer(row, tier))}`)
    .join('\n');
  await navigator.clipboard.writeText(text);
  copiedKey.value = `tier-${tier}`;
  setTimeout(() => { copiedKey.value = null; }, 1500);
}

async function copyCustomTier() {
  if (customTier.value === null) return;
  const text = rows.value
    .map(row => `${row.name}\t${formatTf2(getOffer(row, customTier.value!))}`)
    .join('\n');
  await navigator.clipboard.writeText(text);
  copiedKey.value = 'tier-custom';
  setTimeout(() => { copiedKey.value = null; }, 1500);
}

// ─── Importação ───────────────────────────────────────────────────────────────

function convertDateToISO(date: string): string {
  // "27/04/2026" → "2026-04-27"
  const parts = date.split('/');
  if (parts.length === 3) return `${parts[2]}-${parts[1]}-${parts[0]}`;
  return date;
}

const missingKeyCodes = () => rows.value.some(r => !r.keyCode.trim());
const missingTf2 = () => rows.value.some(r => !(parseFloat(r.tf2Qty.replace(',', '.')) > 0));
const canImport = () => !missingKeyCodes() && !missingTf2();

async function importKeys() {
  if (missingKeyCodes()) return;

  importing.value = true;
  rows.value.forEach(r => { r.status = 'pending'; r.errorMsg = ''; });

  const games = rows.value.map(row => ({
    game_name: row.name,
    market_price: getMarketPrice(row),
    tf2_quantity: parseFloat(row.tf2Qty.replace(',', '.')) || 0,
    key_code: row.keyCode.trim(),
    supplier_url: row.supplierUrl.trim(),
    acquired_at: convertDateToISO(row.date),
    region: row.regionLock.trim() || null,
    expires_at: row.expiry.trim() ? convertDateToISO(row.expiry.trim()) : null,
  }));

  try {
    const res = await axiosInstance.post(route('trade-calculator.import'), { games });
    const errors: { linha: number; erro: string }[] = res.data.errors ?? [];

    const errorsByIndex = new Map(errors.map(e => [e.linha - 1, e.erro]));

    rows.value.forEach((row, i) => {
      if (errorsByIndex.has(i)) {
        row.status = 'error';
        row.errorMsg = errorsByIndex.get(i)!;
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

      rows.value.forEach((row, i) => {
        const msgs = Object.entries(validationErrors)
          .filter(([key]) => key.startsWith(`games.${i}.`))
          .map(([key, errors]) => {
            const field = key.replace(`games.${i}.`, '');
            const label = fieldLabels[field] ?? field;
            return `${label}: ${errors[0]}`;
          });

        if (msgs.length > 0) {
          row.status = 'error';
          row.errorMsg = msgs.join(' | ');
        }
      });
    } else {
      rows.value.forEach(r => {
        r.status = 'error';
        r.errorMsg = e?.response?.data?.message ?? 'Erro desconhecido';
      });
    }
  } finally {
    importing.value = false;
  }
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
  if (row.status === 'success') return 'table-success';
  if (row.status === 'error') return 'table-danger';
  return '';
}
</script>

<template>
  <div class="container-fluid py-4 px-4 w-100">

    <!-- Cabeçalho -->
    <div class="d-flex align-items-center gap-3 mb-4">
      <h4 class="mb-0 fw-bold">Calculadora de Trades</h4>
      <span class="badge bg-secondary">TF2 {{ formatEur(tf2Price) }}</span>
      <span class="text-muted small">
        Taxas Gamivo:
        {{ (fees.percentLow * 100).toFixed(0) }}% + {{ formatEur(fees.fixedLow) }}
        / {{ (fees.percentHigh * 100).toFixed(0) }}% + {{ formatEur(fees.fixedHigh) }}
      </span>
    </div>

    <!-- Zona de colagem -->
    <div
      v-if="rows.length === 0"
      class="paste-zone d-flex flex-column align-items-center justify-content-center gap-3 rounded"
    >
      <i class="pi pi-clipboard" style="font-size: 2.5rem; color: #8009EF;" />
      <div class="text-center">
        <p class="fw-semibold mb-1">Clique aqui e cole os dados</p>
        <p class="text-muted small mb-0">Ctrl+V após copiar as linhas da planilha ou do price researcher</p>
      </div>
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
    </div>

    <!-- Tabela editável -->
    <div v-else class="card border-0 shadow-sm">

      <!-- Barra de ações -->
      <div class="card-header bg-white d-flex align-items-center justify-content-between py-2">
        <div class="d-flex align-items-center gap-2">
          <span class="text-muted small">
            {{ rows.length }} jogo{{ rows.length !== 1 ? 's' : '' }}
          </span>
          <span v-if="missingKeyCodes()" class="badge bg-warning text-dark me-1">
            <i class="pi pi-exclamation-triangle me-1" />
            Preencha todos os key codes
          </span>
          <span v-if="missingTf2()" class="badge bg-warning text-dark">
            <i class="pi pi-exclamation-triangle me-1" />
            Preencha a Qtd TF2
          </span>
        </div>
        <div class="d-flex gap-2">
          <button
            type="button"
            class="btn btn-sm btn-primary"
            :disabled="importing || !canImport()"
            @click="importKeys"
          >
            <i class="pi pi-upload me-1" />
            {{ importing ? 'Importando...' : 'Importar keys' }}
          </button>
          <button
            type="button"
            class="btn btn-sm btn-outline-danger"
            :disabled="importing"
            @click="clear"
          >
            <i class="pi pi-trash me-1" />
            Limpar
          </button>
        </div>
      </div>

      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th class="ps-3" style="min-width: 90px;">Data</th>
                <th style="min-width: 110px;">Preço de Mercado <span class="text-muted fw-normal">(€)</span></th>
                <th style="min-width: 120px;">URL Fornecedor</th>
                <th style="min-width: 90px;">Qtd TF2</th>
                <th style="min-width: 100px;">Bundle</th>
                <th style="min-width: 100px;">Expiração</th>
                <th style="min-width: 90px;">Popularidade</th>
                <th style="min-width: 90px;">Region</th>
                <th style="min-width: 200px;">
                  <span class="text-primary fw-bold">Key Code</span>
                </th>
                <th style="min-width: 180px;">Nome do Jogo</th>
                <th class="text-end" style="min-width: 100px;">
                  Income líq. <span class="text-muted fw-normal">(€)</span>
                </th>
                <th
                  v-for="tier in profitTiers"
                  :key="tier"
                  class="text-center"
                  style="min-width: 100px;"
                >
                  <div class="d-flex flex-column align-items-center gap-1">
                    <span class="badge" :class="tierBadgeClass(tier)">{{ tier }}%</span>
                    <button
                      type="button"
                      class="btn btn-sm"
                      :class="copiedKey === `tier-${tier}` ? 'btn-success' : 'btn-outline-secondary'"
                      :title="`Copiar todos (${tier}%)`"
                      @click="copyTier(tier)"
                    >
                      <i :class="copiedKey === `tier-${tier}` ? 'pi pi-check' : 'pi pi-copy'" />
                    </button>
                  </div>
                </th>

                <!-- Coluna com tier customizável -->
                <th class="text-center" style="min-width: 120px;">
                  <div class="d-flex flex-column align-items-center gap-1">
                    <div class="d-flex align-items-center gap-1">
                      <input
                        v-model.number="customTier"
                        type="number"
                        min="0"
                        max="999"
                        placeholder="0"
                        class="custom-tier-input"
                        title="Digite o % de lucro desejado"
                      />
                      <span class="text-muted fw-normal" style="font-size: 0.75rem;">%</span>
                    </div>
                    <button
                      type="button"
                      class="btn btn-sm"
                      :class="copiedKey === 'tier-custom' ? 'btn-success' : 'btn-outline-secondary'"
                      :disabled="customTier === null"
                      title="Copiar todos"
                      @click="copyCustomTier"
                    >
                      <i :class="copiedKey === 'tier-custom' ? 'pi pi-check' : 'pi pi-copy'" />
                    </button>
                  </div>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, rowIdx) in rows" :key="rowIdx" :class="rowClass(row)">

                <!-- Data -->
                <td class="ps-2">
                  <input v-model="row.date" class="cell-input" />
                </td>

                <!-- Preço de Mercado -->
                <td>
                  <input v-model="row.marketPriceRaw" class="cell-input" />
                </td>

                <!-- URL Fornecedor -->
                <td>
                  <input v-model="row.supplierUrl" class="cell-input text-muted" style="font-size: 0.75rem;" />
                </td>

                <!-- Qtd TF2 -->
                <td>
                  <input
                    v-model="row.tf2Qty"
                    class="cell-input"
                    :class="{ 'is-missing': !(parseFloat(row.tf2Qty.replace(',', '.')) > 0) }"
                    placeholder="0,00"
                  />
                </td>

                <!-- Bundle -->
                <td>
                  <input v-model="row.bundle" class="cell-input text-muted" />
                </td>

                <!-- Expiração -->
                <td>
                  <input v-model="row.expiry" class="cell-input" />
                </td>

                <!-- Popularidade -->
                <td>
                  <input v-model="row.popularity" class="cell-input text-muted" />
                </td>

                <!-- Region -->
                <td>
                  <input v-model="row.regionLock" class="cell-input" />
                </td>

                <!-- Key Code -->
                <td>
                  <input
                    v-model="row.keyCode"
                    class="cell-input font-monospace"
                    :class="{ 'is-missing': !row.keyCode.trim() }"
                    placeholder="XXXXX-XXXXX-XXXXX"
                  />
                  <div v-if="row.status === 'error'" class="text-danger" style="font-size: 0.7rem;">
                    {{ row.errorMsg }}
                  </div>
                  <div v-if="row.status === 'success'" class="text-success" style="font-size: 0.7rem;">
                    <i class="pi pi-check me-1" />Importada com sucesso
                  </div>
                </td>

                <!-- Nome do Jogo -->
                <td>
                  <input v-model="row.name" class="cell-input fw-semibold" />
                </td>

                <!-- Income líquido (calculado) -->
                <td class="text-end text-muted small">
                  {{ formatEur(getNetIncome(row)) }}
                </td>

                <!-- Ofertas por tier -->
                <td
                  v-for="tier in profitTiers"
                  :key="tier"
                  class="text-center"
                >
                  <button
                    type="button"
                    class="btn btn-sm w-100"
                    :class="copiedKey === `${rowIdx}-${tier}` ? 'btn-success' : 'btn-outline-secondary'"
                    :title="`Copiar: ${row.name} + ${formatTf2(getOffer(row, tier))} TF2`"
                    @click="copy(row.name, getOffer(row, tier), `${rowIdx}-${tier}`)"
                  >
                    <i v-if="copiedKey === `${rowIdx}-${tier}`" class="pi pi-check me-1" />
                    {{ formatTf2(getOffer(row, tier)) }}
                  </button>
                </td>

                <!-- Oferta tier customizável -->
                <td class="text-center">
                  <template v-if="customTier !== null">
                    <button
                      type="button"
                      class="btn btn-sm w-100 btn-outline-purple"
                      :class="copiedKey === `${rowIdx}-custom` ? 'btn-success' : ''"
                      :title="`Copiar: ${row.name} + ${formatTf2(getOffer(row, customTier))} TF2`"
                      @click="copy(row.name, getOffer(row, customTier), `${rowIdx}-custom`)"
                    >
                      <i v-if="copiedKey === `${rowIdx}-custom`" class="pi pi-check me-1" />
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
.paste-zone {
  min-height: 220px;
  padding: 2rem;
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

/* Remove setas do input type=number */
.custom-tier-input::-webkit-outer-spin-button,
.custom-tier-input::-webkit-inner-spin-button {
  -webkit-appearance: none;
  margin: 0;
}
.custom-tier-input[type=number] {
  -moz-appearance: textfield;
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
