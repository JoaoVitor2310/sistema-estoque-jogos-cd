<script setup lang="ts">
/**
 * A entrega de uma trade, preenchida pelo próprio supplier (ver docs/adr/0008).
 *
 * Todo texto visível em inglês: o usuário aqui é um trader estrangeiro, não a
 * equipe. Enquanto não houver mecanismo de tradução no projeto, telas de
 * terceiros ficam em inglês literal.
 */
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import axiosInstance from '@/axios';
import DeliveryLayout from '@/components/core/DeliveryLayout.vue';

defineOptions({ layout: DeliveryLayout });

interface DeliveryLine {
  id: number;
  game_name: string | null;
  bundle: string | null;
  region: string | null;
  expires_at: string | null;
  key_code: string | null;
}

interface DeliveryTrade {
  tf2_qty: string | null;
  supplier_notes: string | null;
  delivered_at: string | null;
  lines: DeliveryLine[];
}

const props = defineProps<{
  state: 'locked' | 'open' | 'completed';
  // Quem decide se a entrega ainda aceita escrita é o servidor. A página não
  // deduz isso de `delivered_at`: a regra é de domínio e mora lá.
  editable?: boolean;
  trade?: DeliveryTrade;
}>();

// O uuid sai da própria URL: ele endereça a entrega e não é segredo. O token,
// que é, nunca fica em variável de página depois de conferido — a autorização
// passa a ser a sessão.
const uuid = window.location.pathname.split('/').filter(Boolean).pop() ?? '';

// ─── Destravar ───────────────────────────────────────────────────────────────

const token = ref('');
const unlocking = ref(false);
const unlockError = ref<string | null>(null);

async function unlock() {
  if (!token.value.trim() || unlocking.value) return;

  unlocking.value = true;
  unlockError.value = null;

  try {
    const { status, data } = await axiosInstance.post(`/deliveries/${uuid}/token`, {
      token: token.value,
    });

    if (status === 200) {
      // Recarrega em vez de pedir os dados por XHR: a mesma rota já devolve a
      // página no estado destravado, e não há um segundo formato para manter.
      window.location.reload();
      return;
    }

    unlockError.value = data?.message ?? 'That code is not right.';
  } catch {
    unlockError.value = 'Something went wrong. Please try again.';
  } finally {
    unlocking.value = false;
  }
}

// ─── Rolagem horizontal da tabela ────────────────────────────────────────────

/**
 * A tabela não cabe na largura de um celular, e a barra de rolagem do toque é
 * invisível até o dedo encostar: sem aviso, o supplier preenchia o que via —
 * `Game` e `Key` — e ia embora sem saber que existiam Region, Expires e Bundle.
 *
 * Medido, não presumido por breakpoint: quem decide se há aviso é o overflow
 * real do elemento, então o aviso não aparece na tela larga onde tudo já cabe,
 * e some assim que ele chega ao fim das colunas.
 */
const scroller = ref<HTMLElement | null>(null);
const scrollable = ref(false);
const atScrollEnd = ref(false);

// Folga de 1px: `scrollLeft` fracionário (zoom do navegador, tela com DPR não
// inteiro) nunca soma exatamente a largura, e sem a folga o aviso ficaria preso
// na tela mesmo com o fim das colunas à vista.
const SCROLL_END_TOLERANCE_PX = 1;

function measureScroll() {
  const el = scroller.value;
  if (!el) return;

  scrollable.value = el.scrollWidth - el.clientWidth > SCROLL_END_TOLERANCE_PX;
  atScrollEnd.value =
    el.scrollLeft + el.clientWidth >= el.scrollWidth - SCROLL_END_TOLERANCE_PX;
}

let observer: ResizeObserver | null = null;

onMounted(() => {
  measureScroll();

  // Girar o aparelho muda as duas larguras da conta — e o `resize` da janela
  // sozinho não cobre a tabela crescendo com o conteúdo.
  if (typeof ResizeObserver !== 'undefined' && scroller.value) {
    observer = new ResizeObserver(measureScroll);
    observer.observe(scroller.value);
  }

  window.addEventListener('resize', measureScroll);
});

onBeforeUnmount(() => {
  observer?.disconnect();
  window.removeEventListener('resize', measureScroll);
});

// ─── Preenchimento ───────────────────────────────────────────────────────────

// Salva conforme ele digita, para uma queda de conexão no celular não custar o
// que já foi preenchido. O intervalo é conforto de digitação, não regra de
// negócio — por isso vive aqui e não numa constante de domínio.
const AUTOSAVE_DEBOUNCE_MS = 2000;

type SaveStatus = 'idle' | 'saving' | 'saved' | 'error';

const form = reactive({
  lines: (props.trade?.lines ?? []).map((line) => ({ ...line })),
  tf2_qty: props.trade?.tf2_qty ?? '',
  supplier_notes: props.trade?.supplier_notes ?? '',
});

const status = ref<SaveStatus>('idle');
const deliveredAt = ref<string | null>(props.trade?.delivered_at ?? null);
const submitting = ref(false);

/**
 * Entregue é fechado: o servidor recusa toda gravação a partir do clique.
 * `readonly` aqui é cortesia — evita ele digitar num campo que não vai salvar
 * e achar que salvou. `deliveredAt` entra na conta porque o envio acontece sem
 * recarregar a página, e a partir dele o valor do servidor está velho.
 */
const locked = computed(() => props.editable === false || deliveredAt.value !== null);

const timers = new Map<string, number>();

function debounce(key: string, fn: () => void) {
  const pending = timers.get(key);
  if (pending) window.clearTimeout(pending);
  timers.set(key, window.setTimeout(fn, AUTOSAVE_DEBOUNCE_MS));
}

async function save(url: string, payload: Record<string, unknown>) {
  status.value = 'saving';

  try {
    const { status: code } = await axiosInstance.patch(url, payload);
    status.value = code === 200 ? 'saved' : 'error';
  } catch {
    status.value = 'error';
  }
}

function saveLine(line: DeliveryLine, field: 'key_code' | 'region' | 'bundle' | 'expires_at') {
  if (locked.value) return;

  debounce(`line-${line.id}-${field}`, () => {
    save(`/deliveries/${uuid}/lines/${line.id}`, { [field]: line[field] ?? '' });
  });
}

/**
 * A validade ganha as barras conforme ele digita.
 *
 * O servidor lê `mm/dd/aaaa` e devolve `null` para qualquer outra grafia, então
 * `02012026` viraria validade nenhuma — e a página ainda diria "Saved". A
 * máscara é o que impede o dado de sumir em silêncio; ele digita só os números.
 */
function maskExpiry(line: DeliveryLine) {
  const digits = (line.expires_at ?? '').replace(/\D/g, '').slice(0, 8);

  line.expires_at = [digits.slice(0, 2), digits.slice(2, 4), digits.slice(4)]
    .filter((part) => part !== '')
    .join('/');

  saveLine(line, 'expires_at');
}

/**
 * Uma validade pela metade não sai daqui.
 *
 * `02` é o que sobra de quem começou a digitar e se distraiu. O servidor
 * descarta o que não for `mm/dd/aaaa` **em silêncio**, então sem esta conferência
 * o campo voltaria vazio depois do envio e ninguém saberia que houve validade.
 * Só bloqueia o envio: enquanto ele digita, o autosave segue gravando o que der.
 */
function hasBadExpiry(line: DeliveryLine): boolean {
  const value = (line.expires_at ?? '').trim();

  if (value === '') return false;

  const parts = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(value);

  if (!parts) return true;

  const [month, day, year] = [Number(parts[1]), Number(parts[2]), Number(parts[3])];
  const date = new Date(year, month - 1, day);

  // Dia que não existe (02/31) o próprio Date empurra para o mês seguinte.
  return (
    date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day
  );
}

const badExpiry = computed(() => form.lines.some(hasBadExpiry));

// Enquanto o campo está em foco ele está no meio da digitação: apontar `02`
// como errado na segunda tecla seria ranzinza. A marca aparece quando ele sai.
const editingExpiry = ref<number | null>(null);

function expiryLooksWrong(line: DeliveryLine): boolean {
  return !locked.value && editingExpiry.value !== line.id && hasBadExpiry(line);
}

function saveTrade(field: 'tf2_qty' | 'supplier_notes') {
  if (locked.value) return;

  debounce(`trade-${field}`, () => {
    save(`/deliveries/${uuid}`, { [field]: form[field] ?? '' });
  });
}

/**
 * Linha em branco significa "I don't have this one" e é estado legítimo — a
 * contagem existe só para ele saber onde está, não para bloquear o envio.
 */
const filledCount = computed(
  () => form.lines.filter((line) => (line.key_code ?? '').trim() !== '').length,
);

/**
 * O total de TF2 acertado é o único campo obrigatório da página.
 *
 * Zero conta como vazio, mesma régua do servidor: é o número que o rateio de
 * custo do lote usa no import, e tê-lo aqui é o que faz a trade chegar pronta —
 * a equipe sabe quanto foi acertado, mas reler a conversa para recuperá-lo é a
 * transcrição que esta página existe para eliminar.
 */
const tf2Missing = computed(() => {
  const value = (form.tf2_qty ?? '').trim();

  return value === '' || !(Number(value) > 0);
});

// Entregar põe a trade na fila de conferência da equipe e **fecha a página**:
// depois dele nada mais é gravado por aqui. É o botão grande no fim de um
// formulário longo, então clicar sem querer manda um trabalho pela metade sem
// volta — daí a confirmação.
const confirmingSubmit = ref(false);
const submitError = ref<string | null>(null);

async function submitDelivery() {
  if (submitting.value || tf2Missing.value || badExpiry.value) return;

  confirmingSubmit.value = false;
  submitError.value = null;
  submitting.value = true;

  // Descarrega o que estiver em debounce antes de marcar como entregue.
  timers.forEach((id) => window.clearTimeout(id));
  timers.clear();

  try {
    await Promise.all([
      ...form.lines.map((line) =>
        axiosInstance.patch(`/deliveries/${uuid}/lines/${line.id}`, {
          key_code: line.key_code ?? '',
          region: line.region ?? '',
          bundle: line.bundle ?? '',
          expires_at: line.expires_at ?? '',
        }),
      ),
      axiosInstance.patch(`/deliveries/${uuid}`, {
        tf2_qty: form.tf2_qty ?? '',
        supplier_notes: form.supplier_notes ?? '',
      }),
    ]);

    const { data, status: code } = await axiosInstance.post(`/deliveries/${uuid}/deliver`);

    if (code === 200) {
      deliveredAt.value = data.delivered_at;
      status.value = 'saved';
    } else {
      // O servidor tem a última palavra sobre o que falta: a página repete o
      // motivo dele em vez de adivinhar um.
      submitError.value = data?.message ?? 'We could not send this delivery. Please try again.';
      status.value = 'error';
    }
  } catch {
    submitError.value = 'Something went wrong. Please try again.';
    status.value = 'error';
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <!-- Concluída: a credencial morreu no import. Mensagem neutra, sem dado
       nenhum — um 404 aqui só geraria "your link is broken". -->
  <div v-if="props.state === 'completed'" class="card delivery-card delivery-card--done">
    <div class="card-body text-center py-5">
      <i class="pi pi-check-circle text-success" style="font-size: 2.25rem;" />
      <h5 class="mt-3 mb-1 fw-bold">This delivery is already complete</h5>
      <p class="text-muted mb-0">Nothing else is needed here. Thanks!</p>
    </div>
  </div>

  <!-- Travada: sem o código não vai dado nenhum, nem a contagem de jogos. -->
  <div v-else-if="props.state === 'locked'" class="delivery-lock mx-auto">
    <div class="card delivery-card">
      <div class="card-body p-4 p-md-5">
        <div class="text-center mb-4">
          <span class="delivery-lock-icon"><i class="pi pi-lock" /></span>
          <h5 class="mt-3 mb-1 fw-bold">Enter your code</h5>
          <p class="text-muted small mb-0">
            We sent it to you together with this link. Upper or lower case, dashes optional.
          </p>
        </div>

        <form @submit.prevent="unlock">
          <span class="field-label">Access code</span>
          <input
            v-model="token"
            type="text"
            class="form-control form-control-lg font-monospace text-uppercase text-center delivery-code-input"
            placeholder="XXXX-XXXX-XXXX-XXXX"
            autocomplete="off"
            autocapitalize="characters"
            spellcheck="false"
          />

          <div v-if="unlockError" class="text-danger small mt-2 text-center">
            <i class="pi pi-exclamation-triangle me-1" />{{ unlockError }}
          </div>

          <button type="submit" class="btn btn-purple btn-lg w-100 mt-3" :disabled="unlocking">
            <i class="pi me-2" :class="unlocking ? 'pi-spinner pi-spin' : 'pi-arrow-right'" />
            {{ unlocking ? 'Checking...' : 'Continue' }}
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- Aberta -->
  <div v-else>
    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
      <h5 class="mb-0 fw-bold">Your trade</h5>
      <span class="badge bg-light text-secondary border">
        {{ form.lines.length }} game{{ form.lines.length === 1 ? '' : 's' }}
      </span>

      <!-- Reserva o espaço dos três estados: o selo troca a cada tecla, e antes
           a borda de um e a falta dela no outro mudavam a altura da linha —
           a página inteira pulava enquanto ele digitava. -->
      <span class="ms-auto small save-status">
        <span v-if="status === 'saving'" class="badge save-badge bg-warning-subtle text-warning-emphasis">
          <i class="pi pi-spinner pi-spin me-1" />Saving...
        </span>
        <span v-else-if="status === 'saved'" class="badge save-badge save-badge--saved bg-light text-success">
          <i class="pi pi-check me-1" />Saved
        </span>
        <span v-else-if="status === 'error'" class="badge save-badge save-badge--error bg-danger">
          <i class="pi pi-exclamation-triangle me-1" />Could not save — check your connection
        </span>
      </span>
    </div>

    <div v-if="locked" class="alert alert-success py-2 small">
      <i class="pi pi-check-circle me-1" />
      <strong>We got it.</strong> Someone will go through it before the keys are processed.
      This page is now read-only — message us in the chat if anything needs to change.
    </div>

    <div class="card delivery-card mb-3">
      <div class="card-header bg-white d-flex align-items-center justify-content-between py-2">
        <span class="fw-semibold small">Your keys</span>
        <span class="small text-muted">{{ filledCount }} of {{ form.lines.length }} filled</span>
      </div>

      <div v-if="!locked" class="missing-note">
        <i class="pi pi-exclamation-circle" />
        <span>
          <strong>Don't have a game anymore?</strong>
          Leave its row empty — don't delete it, and don't put anything in the key field.
        </span>
      </div>

      <!-- O aviso vem antes da tabela, não depois: quem chega ao fim da página
           já preencheu o que viu. -->
      <div v-if="scrollable" class="scroll-hint" :class="{ 'scroll-hint--end': atScrollEnd }">
        <i class="pi" :class="atScrollEnd ? 'pi-check' : 'pi-arrow-right'" />
        <span v-if="atScrollEnd">That's every column.</span>
        <span v-else>
          <strong>Swipe the table sideways</strong> — there are more columns
          (Region, Expires, Bundle) past the edge.
        </span>
      </div>

      <div
        class="table-scroll-frame"
        :class="{ 'table-scroll-frame--more': scrollable && !atScrollEnd }"
      >
        <div ref="scroller" class="table-responsive" @scroll.passive="measureScroll">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <!-- As larguras mínimas somam menos que o container (860px): a
                     barra de rolagem horizontal é para o celular, e aparecer numa
                     tela larga só sugere que existe coluna escondida. -->
                <th style="min-width: 170px;">Game</th>
                <th style="min-width: 210px;"><span class="text-purple fw-bold">Key</span></th>
                <th style="min-width: 140px;">
                  Region
                  <span class="th-hint">anything you know — blank if none</span>
                </th>
                <th style="min-width: 110px;">Expires</th>
                <th class="col-bundle">
                  Bundle
                  <span class="th-hint">anything you know — blank if none</span>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="line in form.lines" :key="line.id">
                <td class="fw-semibold">{{ line.game_name || '—' }}</td>
                <td>
                  <input
                    v-model="line.key_code"
                    :readonly="locked"
                    type="text"
                    class="cell-input font-monospace"
                    placeholder="XXXXX-XXXXX-XXXXX"
                    autocomplete="off"
                    spellcheck="false"
                    @input="saveLine(line, 'key_code')"
                  />
                </td>
                <td>
                  <input
                    v-model="line.region"
                    :readonly="locked"
                    type="text"
                    class="cell-input"
                    autocomplete="off"
                    @input="saveLine(line, 'region')"
                  />
                </td>
                <td>
                  <input
                    v-model="line.expires_at"
                    :readonly="locked"
                    type="text"
                    inputmode="numeric"
                    maxlength="10"
                    class="cell-input"
                    :class="{ 'cell-input--invalid': expiryLooksWrong(line) }"
                    placeholder="mm/dd/yyyy"
                    autocomplete="off"
                    @input="maskExpiry(line)"
                    @focus="editingExpiry = line.id"
                    @blur="editingExpiry = null"
                  />
                  <span v-if="expiryLooksWrong(line)" class="cell-error">use mm/dd/yyyy</span>
                </td>
                <!-- Chega pré-preenchido pela nossa busca de bundle, e ele
                     corrige: quem teve a key na mão sabe melhor de onde ela veio,
                     e a origem é o que costuma explicar o region lock. -->
                <td>
                  <input
                    v-model="line.bundle"
                    :readonly="locked"
                    type="text"
                    class="cell-input"
                    autocomplete="off"
                    @input="saveLine(line, 'bundle')"
                  />
                </td>
              </tr>
              </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="card delivery-card mb-3">
      <div class="card-header bg-white py-2">
        <span class="fw-semibold small">Finishing up</span>
      </div>

      <div class="card-body">
        <!-- O total acertado ocupa a linha inteira: é o número que a equipe
             confere primeiro, e dividir espaço com o recado o achatava. Sem
             placeholder de propósito: um `0` cinza dentro do campo se lê como
             valor já preenchido, e este é o único campo que ele não pode
             deixar em branco. -->
        <span class="field-label">
          Total TF2 keys agreed
          <span class="field-required">required</span>
        </span>
        <input
          v-model="form.tf2_qty"
          :readonly="locked"
          type="text"
          inputmode="decimal"
          class="form-control form-control-lg tf2-input"
          :class="{ 'tf2-input--missing': !locked && tf2Missing }"
          @input="saveTrade('tf2_qty')"
        />

        <span class="field-label mt-3">Notes, suggestions and feedback</span>
        <textarea
          v-model="form.supplier_notes"
          :readonly="locked"
          class="form-control"
          rows="3"
          placeholder="Anything about this trade — or about how we could do better."
          @input="saveTrade('supplier_notes')"
        />
      </div>
    </div>

    <!-- Entregar acontece **uma vez**, e fecha a página. Não há reenviar nem
         reabrir: dali em diante quem corrige é a equipe, pelo chat. -->
    <template v-if="!locked">
      <button
        type="button"
        class="btn btn-purple btn-lg w-100"
        :disabled="submitting || tf2Missing || badExpiry"
        @click="confirmingSubmit = true"
      >
        <i class="pi me-2" :class="submitting ? 'pi-spinner pi-spin' : 'pi-send'" />
        {{ submitting ? 'Submitting...' : 'Submit delivery' }}
        <span class="opacity-75">({{ filledCount }} of {{ form.lines.length }} filled)</span>
      </button>

      <!-- Botão desabilitado sem motivo à vista vira "o site não funciona".
           O que falta fica escrito logo abaixo dele. -->
      <p v-if="tf2Missing" class="submit-blocked small mt-2 mb-0">
        <i class="pi pi-exclamation-circle me-1" />
        Fill in the total TF2 keys we agreed on to submit this delivery.
      </p>

      <p v-if="badExpiry" class="submit-blocked small mt-2 mb-0">
        <i class="pi pi-exclamation-circle me-1" />
        One expiry date is incomplete. Write it as mm/dd/yyyy — like 12/31/2026 — or
        leave it empty.
      </p>

      <p v-if="!tf2Missing && !badExpiry && submitError" class="submit-blocked small mt-2 mb-0">
        <i class="pi pi-exclamation-circle me-1" />{{ submitError }}
      </p>
    </template>

    <div v-if="locked" class="card delivery-card delivery-card--done">
      <div class="card-body text-center py-4">
        <i class="pi pi-check-circle text-success" style="font-size: 1.75rem;" />
        <h6 class="fw-bold mt-2 mb-1">Delivery sent</h6>
        <p class="text-muted small mb-0">
          Everything above is exactly what reached us. Spotted a mistake? Message us in
          the chat and we will fix it on our side.
        </p>
      </div>
    </div>

    <!-- Confirmação do envio. Diz quantas linhas vão em branco, porque é o que
         a pessoa não vê ao olhar só para o botão — e linha em branco é estado
         legítimo, então o aviso informa em vez de bloquear. -->
    <div v-if="confirmingSubmit" class="delivery-confirm" @click.self="confirmingSubmit = false">
      <div class="card delivery-card delivery-confirm-card">
        <div class="card-body p-4">
          <h6 class="fw-bold mb-2">Send this delivery to us?</h6>

          <p class="text-muted small mb-3">
            You filled in <strong>{{ filledCount }}</strong> of
            <strong>{{ form.lines.length }}</strong> game{{ form.lines.length === 1 ? '' : 's' }}.
            <template v-if="filledCount < form.lines.length">
              The empty ones will be read as games you no longer have.
            </template>
          </p>

          <p class="small mb-4 delivery-confirm-warning">
            <i class="pi pi-lock me-1" />
            This closes the page: after sending, you will not be able to edit it.
            Anything else goes through the chat.
          </p>

          <div class="d-flex justify-content-end gap-2">
            <button type="button" class="btn btn-outline-secondary" @click="confirmingSubmit = false">
              Not yet
            </button>
            <button type="button" class="btn btn-purple" @click="submitDelivery">
              <i class="pi pi-send me-2" />Yes, submit
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Mesma linguagem visual da aba de Trades: cartão com barra roxa à esquerda,
   rótulo em versalete roxo e input que só ganha borda no foco. */

.delivery-card {
  border: 1px solid #dee2e6;
  border-left: 4px solid #8009EF;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.06);
  background: #fff;
}

.delivery-card--done {
  border-left-color: #198754;
}

.delivery-lock {
  max-width: 460px;
}

/* O aviso da linha em branco: era rodapé da tabela e passava despercebido —
   quem já rolou até o fim já preencheu. Fica acima das linhas, com cor, porque
   linha vazia é resposta legítima e ele precisa saber disso antes de digitar. */
.missing-note {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  padding: 0.625rem 1rem;
  font-size: 0.85rem;
  line-height: 1.35;
  color: #6a4a00;
  background: #fff8e1;
  border-top: 1px solid #ffe08a;
  border-bottom: 1px solid #ffe08a;
}

.missing-note .pi {
  margin-top: 0.12rem;
  color: #b8860b;
}

/* ── Aviso e sombra da rolagem horizontal ────────────────────────────────── */

.scroll-hint {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 1rem;
  font-size: 0.8rem;
  line-height: 1.3;
  color: #5b1e8c;
  background: #f4ecfd;
  border-top: 1px solid #e0cdf7;
  border-bottom: 1px solid #e0cdf7;
}

.scroll-hint--end {
  color: #1a6c47;
  background: #eaf7f0;
  border-color: #c6e8d5;
}

/* A seta acompanha o gesto que ele precisa fazer; para quando ele chega ao fim
   junto com o resto do aviso. */
.scroll-hint:not(.scroll-hint--end) .pi {
  animation: scroll-nudge 1.4s ease-in-out infinite;
}

@keyframes scroll-nudge {
  0%, 100% { transform: translateX(0); }
  50% { transform: translateX(4px); }
}

@media (prefers-reduced-motion: reduce) {
  .scroll-hint .pi {
    animation: none;
  }
}

/* A borda sombreada é o segundo sinal, o que continua visível enquanto ele
   rola: diz que a tabela segue à direita, e some no fim. O gradiente mora num
   wrapper e não no próprio scroller — um absoluto dentro do elemento que rola
   se ancora no conteúdo e andaria para fora da tela junto com ele. */
.table-scroll-frame {
  position: relative;
}

.table-scroll-frame--more::after {
  content: '';
  position: absolute;
  top: 0;
  right: 0;
  bottom: 0;
  width: 28px;
  pointer-events: none;
  background: linear-gradient(to right, rgba(255, 255, 255, 0), rgba(0, 0, 0, 0.12));
}

.th-hint {
  display: block;
  font-size: 0.68rem;
  font-weight: 400;
  color: #6c757d;
  margin-top: 1px;
}

/* Campo fechado ainda mostra o que ele mandou — some a cara de campo editável,
   não o conteúdo: depois da entrega ele ainda quer conferir o que enviou. */
.cell-input[readonly],
.form-control[readonly] {
  background-color: #f6f6f7;
  border-color: transparent;
  color: #495057;
  cursor: default;
}

.delivery-confirm-warning {
  color: #6a4a00;
  background: #fff8e1;
  border: 1px solid #ffe08a;
  border-radius: 0.375rem;
  padding: 0.5rem 0.75rem;
}

/* Nome de bundle é longo ("Humble Indie Bundle 12: The Sequel"): em tela larga
   a coluna cresce junto com o container, em vez de cortar. Estreita, o texto
   corta mesmo — antes de espremer as outras colunas. */
.col-bundle {
  min-width: 140px;
}

@media (min-width: 1080px) {
  .col-bundle {
    min-width: 260px;
  }
}

.save-status {
  display: inline-flex;
  align-items: center;
  justify-content: flex-end;
  min-height: 1.5rem;
}

.save-badge {
  border: 1px solid transparent;
  white-space: nowrap;
}

.save-badge--saved {
  border-color: #dee2e6;
}

/* O único longo: no celular ele quebra em vez de esticar a linha. */
.save-badge--error {
  white-space: normal;
  text-align: left;
}

.tf2-input {
  font-weight: 600;
}

/* Único campo obrigatório da página: vazio ele fica marcado, e a marca sai
   assim que ele digita um número. */
.tf2-input--missing {
  border-color: #ffc107;
  background: #fffdf5;
}

.field-required {
  margin-left: 0.35rem;
  color: #b8860b;
}

.submit-blocked {
  color: #6a4a00;
}

.tf2-input:focus {
  border-color: #8009EF;
  box-shadow: 0 0 0 0.2rem rgba(128, 9, 239, 0.15);
}

/* ── Confirmação do envio ────────────────────────────────────────────────── */

.delivery-confirm {
  position: fixed;
  inset: 0;
  z-index: 1050;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  background: rgba(33, 37, 41, 0.5);
}

.delivery-confirm-card {
  width: 100%;
  max-width: 420px;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
}

.delivery-lock-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 56px;
  height: 56px;
  border-radius: 50%;
  background: #f3e8ff;
  color: #8009EF;
  font-size: 1.4rem;
}

.delivery-code-input {
  letter-spacing: 2px;
}

.delivery-code-input:focus {
  border-color: #8009EF;
  box-shadow: 0 0 0 0.2rem rgba(128, 9, 239, 0.15);
}

.text-purple {
  color: #8009EF;
}

/* ── Rótulo de campo ─────────────────────────────────────────────────────── */

.field-label {
  display: block;
  font-size: 0.6rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: #8009EF;
  margin-bottom: 3px;
}

/* ── Inputs da tabela ────────────────────────────────────────────────────── */

.cell-input {
  border: none;
  border-bottom: 1px dashed #dee2e6;
  background: transparent;
  width: 100%;
  padding: 2px 4px;
  font-size: 0.9rem;
  color: inherit;
}

.cell-input:focus {
  outline: none;
  background: #f9f4ff;
  border-bottom: 2px solid #8009EF;
}

/* A validade incompleta fica marcada na própria linha: a mensagem do botão diz
   que existe uma, e a marca diz qual. */
.cell-input--invalid {
  border-bottom: 1px solid #dc3545;
  color: #dc3545;
}

.cell-error {
  display: block;
  font-size: 0.7rem;
  color: #dc3545;
  margin-top: 2px;
}

.cell-input::placeholder {
  color: #adb5bd;
  font-size: 0.8rem;
}

.form-control:focus {
  border-color: #8009EF;
  box-shadow: 0 0 0 0.2rem rgba(128, 9, 239, 0.15);
}

/* ── Botão ───────────────────────────────────────────────────────────────── */

.btn-purple {
  background-color: #8009EF;
  border-color: #8009EF;
  color: #fff;
}

.btn-purple:hover:not(:disabled),
.btn-purple:focus:not(:disabled) {
  background-color: #6a07c8;
  border-color: #6a07c8;
  color: #fff;
}

.btn-purple:disabled {
  background-color: #8009EF;
  border-color: #8009EF;
  color: #fff;
  opacity: 0.65;
}
</style>
