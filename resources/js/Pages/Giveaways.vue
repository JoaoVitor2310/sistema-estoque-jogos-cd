<script setup lang="ts">
/**
 * Os giveaways de jogo Steam registrados do GamerPower.
 *
 * O aviso "Giveaway" na linha da trade diz só que a regra agiu; esta tela diz
 * qual giveaway, de quando, e até quando o jogo ainda conta como em giveaway.
 * Só leitura: os dados vêm da sincronização, não de cadastro.
 */
import { computed, ref } from 'vue';

/** Um jogo do sistema (uma linha por região) que o giveaway atinge. */
interface LinkedGame {
  id: number;
  name: string;
  /** null = global; no sistema inteiro, a região global não é escrita. */
  region: string | null;
}

/** Um giveaway como o servidor manda (App\Services\Games\GiveawayRepository). */
interface Giveaway {
  id: number;
  title: string;
  /** null quando o título veio fora do padrão e o jogo não pôde ser extraído. */
  game_name: string | null;
  store: string | null;
  worth: string | null;
  gamerpower_url: string;
  published_date: string;
  /** Fim anunciado pelo site; só informativo, e muitas vezes ausente. */
  end_date: string | null;
  status: 'active' | 'ended';
  last_seen_at: string;
  duration_days: number;
  is_flagged: boolean;
  /** null enquanto ativo: não há prazo enquanto o giveaway está no feed. */
  flagged_until: string | null;
  games: LinkedGame[];
}

/** Espelho das constantes de App\Domain\Games\GiveawayPolicy. */
interface GiveawayRule {
  short_max_days: number;
  short_window_days: number;
  medium_max_days: number;
  medium_window_days: number;
  long_window_days: number;
}

const props = defineProps<{
  giveaways: Giveaway[];
  rule: GiveawayRule;
}>();

// O padrão é o que importa para negociar: o que ainda conta como giveaway hoje.
const showHistory = ref(false);

const flaggedCount = computed(() => props.giveaways.filter((giveaway) => giveaway.is_flagged).length);

const visibleGiveaways = computed(() =>
  showHistory.value ? props.giveaways : props.giveaways.filter((giveaway) => giveaway.is_flagged),
);

/**
 * As regiões restritas em que o jogo está cadastrado em Jogos. A global não se
 * escreve, como no resto do sistema: cadastro só global sai como lista vazia.
 */
function restrictedRegions(giveaway: Giveaway): string[] {
  return [...new Set(giveaway.games.map((game) => game.region).filter((region): region is string => !!region))];
}
</script>

<template>
  <div class="container py-3">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
      <h1 class="mb-0">Giveaways</h1>
      <span class="badge text-bg-warning">{{ flaggedCount }} em giveaway hoje</span>
    </div>

    <!-- Curto de propósito: a regra cabe em duas linhas, e os números vêm do
         servidor (GiveawayPolicy), nunca escritos aqui. -->
    <p class="text-muted small mb-1">
      Jogo Steam que saiu de graça tem o preço de mercado no chão, e ele não volta quando o giveaway
      acaba. O jogo conta como <strong>em giveaway</strong> enquanto o giveaway está ativo e depois
      por mais <strong>{{ rule.short_window_days }} dias</strong> (giveaway de até {{ rule.short_max_days }} dias),
      <strong>{{ rule.medium_window_days }} dias</strong> (até {{ rule.medium_max_days }} dias) ou
      <strong>{{ rule.long_window_days }} dias</strong> (mais longo).
    </p>
    <p class="text-muted small mb-3">
      Esses jogos ficam fora do comentário ao fornecedor e aparecem na trade com o aviso "Giveaway".
      Só contam os giveaways registrados desde o início da integração.
    </p>

    <div class="form-check form-switch mb-3">
      <input id="show-history" v-model="showHistory" class="form-check-input" type="checkbox" role="switch" />
      <label class="form-check-label" for="show-history">
        Mostrar também os que já saíram da janela ({{ giveaways.length }} no total)
      </label>
    </div>

    <p v-if="visibleGiveaways.length === 0" class="text-center text-muted py-5 mb-0">
      {{ showHistory ? 'Nenhum giveaway registrado ainda.' : 'Nenhum jogo está em giveaway hoje.' }}
    </p>

    <div v-else class="table-responsive">
      <table class="table table-sm align-middle">
        <thead>
          <tr class="text-muted small">
            <th>Jogo</th>
            <th>Origem</th>
            <th class="text-end">Valor</th>
            <th>Publicado</th>
            <th title="Fim anunciado pelo site. O que encerra o giveaway para nós é ele sair do feed.">Fim anunciado</th>
            <th>Situação</th>
            <th>Em giveaway até</th>
            <th title="Se o jogo está cadastrado na aba Jogos, e em quais regiões restritas. A trade sinaliza o giveaway com ou sem cadastro.">
              Cadastrado em Jogos
            </th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr v-for="giveaway in visibleGiveaways" :key="giveaway.id" :class="{ 'table-warning': giveaway.is_flagged }">
            <td>
              <span v-if="giveaway.game_name" class="fw-semibold">{{ giveaway.game_name }}</span>
              <!-- Título fora do padrão: sem jogo extraído, não casa com nada. -->
              <span v-else class="text-muted" title="Título fora do padrão: o jogo não pôde ser extraído e não casa com nenhum jogo.">
                {{ giveaway.title }}
              </span>
            </td>
            <td>{{ giveaway.store ?? '—' }}</td>
            <td class="text-end">{{ giveaway.worth ? `$${giveaway.worth}` : '—' }}</td>
            <td>{{ giveaway.published_date }}</td>
            <td>{{ giveaway.end_date ?? '—' }}</td>
            <td>
              <span v-if="giveaway.status === 'active'" class="badge text-bg-success">Ativo</span>
              <span v-else class="badge text-bg-secondary" :title="`Visto ativo pela última vez em ${giveaway.last_seen_at}`">
                Encerrado
              </span>
              <span class="text-muted small ms-1">{{ giveaway.duration_days }} dias</span>
            </td>
            <td>
              <span v-if="giveaway.flagged_until === null">enquanto ativo</span>
              <span v-else :class="{ 'text-muted': !giveaway.is_flagged }">{{ giveaway.flagged_until }}</span>
            </td>
            <td>
              <span v-if="giveaway.games.length === 0" class="text-muted"
                title="Nenhum jogo cadastrado com esse nome. A trade sinaliza o jogo mesmo assim.">
                Não
              </span>
              <span v-else>
                Sim<span v-if="restrictedRegions(giveaway).length" class="text-muted"> · {{ restrictedRegions(giveaway).join(', ') }}</span>
              </span>
            </td>
            <td class="text-end">
              <a :href="giveaway.gamerpower_url" target="_blank" rel="noopener noreferrer" title="Abrir no GamerPower">
                <i class="pi pi-external-link" />
              </a>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Exigido pelos termos da API: atribuição com link ativo onde os dados aparecem. -->
    <p class="text-muted small mt-3 mb-0">
      Dados de giveaways por
      <a href="https://www.gamerpower.com" target="_blank" rel="noopener noreferrer">GamerPower.com</a>.
    </p>
  </div>
</template>
