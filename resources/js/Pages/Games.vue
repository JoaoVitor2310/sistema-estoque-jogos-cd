<script setup lang="ts">
// Vue
import { reactive, ref } from 'vue';
import type { PropType } from 'vue';
import axiosInstance from '../axios';

// PrimeVue Components
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import { FilterMatchMode } from '@primevue/core/api';
import InputText from 'primevue/inputtext';
import 'primeicons/primeicons.css'
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import Toast from 'primevue/toast';
import { useToast } from "primevue/usetoast";
import ConfirmPopup from 'primevue/confirmpopup';
import { useConfirm } from "primevue/useconfirm";
import InputNumber from 'primevue/inputnumber';
import Paginator, { PageState } from 'primevue/paginator';

// Utilitários
import { showResponse } from '../helpers/showResponse';
import { Game } from '../types/Game';
import { formatDateToBR, identifyAndFormatDate } from '@/helpers/formatHelpers';

// Components
import BundleModal from '../components/BundleModal.vue';

// ====================================
// PROPS E DADOS INICIAIS
// ====================================

// Props recebidas do controller
const props = defineProps({
  games: Array as PropType<Game[]>,
  totalGames: Number,
  pagination: Object
});

// Dados reativos dos jogos
let rowData: Game[] = reactive([]);
// console.log('Jogos recebidos:', props.games);
Object.assign(rowData, props.games);

// ====================================
// ESTADOS E REFERENCIAS REATIVAS
// ====================================

// Filtros da DataTable
const filters = ref({
  searchField: { value: null, matchMode: FilterMatchMode.IN },
});


const pagination = ref(props.pagination!); // Informações da paginação
const currentFirst = ref((pagination.value.current_page - 1) * pagination.value.per_page);

// Composables do PrimeVue
const toast = useToast();
const confirm = useConfirm();


// Estados da UI
const selectedProduct = ref(); // Produtos selecionados na tabela
const DialogVisible = ref(false); // Controla visibilidade do modal
const isEdit = ref(false); // Define se é modo edição ou criação
const localTotalGames = ref(props.totalGames); // Total de jogos para paginação

// Estados para o modal de bundle
const BundleDialogVisible = ref(false); // Controla visibilidade do modal de bundle

// Objeto template para novos jogos
const selectedNewObject: Game = {
  id: 0,
  name: '',
  region: '',
  gamivo_id: '',
  id_steamcharts: '',
  popularity: null,
  price_tf2: null,
  price_euro: null,
  release_date: '',
};

// Jogos selecionados para edição/criação (array para permitir múltiplos)
const selected = reactive([selectedNewObject]);

// Estados de pesquisa
const searchFilter = reactive({
  name: '',
  region: '',
  gamivo_id: '',
  id_steamcharts: '',
});
const isSearching = ref(false);

// ====================================
// FUNÇÕES DE PAGINAÇÃO E PESQUISA
// ====================================

/**
 * Wrapper para mudança de página
 * Necessário para passar o evento corretamente
 */
const handlePageChange = (event: PageState) => {
  onPageChange(false, event);
};

/**
 * Função principal para mudança de página e pesquisa
 * @param search - Se é uma pesquisa ou mudança de página normal
 * @param event - Evento de paginação (null para primeira página)
 */
const onPageChange = async (search: boolean, event: PageState | null = null) => {
  // Ativa estado de pesquisa se necessário
  if (search) isSearching.value = true;

  // Configuração da paginação
  const limit = event ? event.rows : 100;
  const page = event ? event.page + 1 : 1; // Paginator começa em 0, API em 1

  // Configuração da URL e método baseado no tipo de operação
  let url = `/games/paginated?limit=${limit}&page=${page}`;
  let method = 'GET';

  // Se está em modo pesquisa, usa endpoint diferente
  if (isSearching.value) {
    url = `/games/search?page=${page}`;
    method = 'POST';
  }

  try {
    const res = await axiosInstance(url, {
      method,
      data: method === 'POST' ? searchFilter : null
    });

    // Atualiza dados se requisição bem-sucedida
    if (res.status === 200 || res.status === 201) {
      localTotalGames.value = res.data.data.totalGames;
      rowData.splice(0, rowData.length, ...res.data.data.games.data);
    }
  } catch (error) {
    // Exibe toast de erro
    toast.add({
      severity: 'error',
      summary: 'Erro Interno, tente novamente.',
      detail: error,
      life: 7000
    });
    console.error('Erro na paginação:', error);
  }
};

// ====================================
// FUNÇÕES DE CRUD (CRIAR, EDITAR)
// ====================================

/**
 * Edita um jogo existente
 * @param selected - Dados do jogo selecionado para edição
 */
async function onEdit(selected: any) {
  isEdit.value = true;

  try {
    const res = await axiosInstance.put(`/games/${selected.id}`, {
      name: selected.name,
      region: selected.region,
      gamivo_id: selected.gamivo_id,
      id_steamcharts: selected.id_steamcharts,
      release_date: selected.release_date,
      price_tf2: selected.price_tf2,
      price_euro: selected.price_euro,
      popularity: selected.popularity
    });

    // Mostra resultado da operação
    showResponse(res, toast.add);

    // Atualiza item na tabela se edição bem-sucedida
    if (res.status === 200) {
      const itemToUpdate = rowData.find(item => item.id === selected.id);
      if (itemToUpdate) {
        Object.assign(itemToUpdate, res.data.data);
      }
    }

    DialogVisible.value = false;
  } catch (error) {
    console.error('Erro na edição:', error);
  }
}

/**
 * Prepara o modal para criação de novo jogo
 */
async function handleAddButton(): Promise<void> {
  isEdit.value = false;
  selected.splice(0, selected.length, { ...selectedNewObject }); // Zera o valor para criar um novo
  DialogVisible.value = true;
}

/**
 * Cria novos jogos
 * Suporta criação múltipla
 */
async function onAdd(): Promise<void> {
  // Formata as datas de lançamento antes de enviar
  selected.forEach(item => {
    if (item.release_date) {
      item.release_date = identifyAndFormatDate(item.release_date);
    }
  });

  try {
    const res = await axiosInstance.post(`/games`, { games: selected });

    showResponse(res, toast.add);

    if (res.status === 200 || res.status === 201) {
      DialogVisible.value = false;
      // Adiciona novos jogos no início da tabela (mantém ordem DESC por ID)
      rowData.unshift(...res.data.data.reverse());
    }
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: 'Erro Interno, tente novamente.',
      detail: error,
      life: 7000
    });
    console.error('Erro na criação:', error);
  }
}

// ====================================
// FUNÇÕES DE EXCLUSÃO
// ====================================

/**
 * Manipula o botão de exclusão com confirmação
 * @param event - Evento do botão (para posicionar popup)
 * @param qtd - Quantidade de itens (1 = único, >1 = múltiplos)
 */
function handleDeleteButton(event: any, qtd: number) {
  confirm.require({
    target: event.currentTarget,
    message: qtd === 1 ? 'Tem certeza que deseja excluir este item?' : 'Tem certeza que deseja excluir esses itens?',
    rejectProps: {
      label: 'Cancelar',
      severity: 'secondary',
      outlined: true
    },
    acceptProps: {
      label: 'Excluir',
      severity: 'danger'
    },
    accept: async () => {
      await performDelete(qtd);
    }
  });
}

/**
 * Executa a exclusão baseada na quantidade
 * @param qtd - Quantidade de itens a excluir
 */
async function performDelete(qtd: number) {
  try {
    if (qtd === 1) {
      // Exclusão de item único (do modal)
      const res = await axiosInstance.delete(`/games/${selected[0].id}`);
      showResponse(res, toast.add);

      // Remove da tabela
      const itemToDelete = rowData.findIndex(item => item.id === selected[0].id);
      if (itemToDelete !== -1) {
        rowData.splice(itemToDelete, 1);
      }
      DialogVisible.value = false;
    } else {
      // Exclusão múltipla (da tabela)
      const res = await axiosInstance.delete(`/games`, {
        params: {
          games: selectedProduct.value
        }
      });
      showResponse(res, toast.add);

      // Remove itens da tabela
      const selectedProductIds = selectedProduct.value.map(item => item.id);
      const filteredRowData = rowData.filter(item => !selectedProductIds.includes(item.id));
      rowData.splice(0, rowData.length, ...filteredRowData);
      selectedProduct.value = null;
    }
  } catch (error) {
    console.error('Erro na exclusão:', error);
  }
}

// ====================================
// FUNÇÕES AUXILIARES DO MODAL
// ====================================

/**
 * Adiciona ou remove jogos do formulário de criação múltipla
 * @param add - true para adicionar, false para remover
 */
function addOrRemove(add: boolean) {
  if (add) {
    // Adiciona novo jogo ao formulário
    selected.push({
      id: 0,
      name: '',
      region: '',
      gamivo_id: '',
      id_steamcharts: '',
      popularity: null,
      price_tf2: null,
      price_euro: null,
      release_date: '',
    });
  } else {
    // Remove último jogo (mínimo de 1)
    if (selected.length > 1) {
      selected.pop();
    }
  }
}

// ====================================
// FUNÇÕES DO BUNDLE
// ====================================

/**
 * Abre o modal para criar bundle com os jogos selecionados
 */
function handleCreateBundle() {
  if (!selectedProduct.value || selectedProduct.value.length === 0) {
    toast.add({
      severity: 'warn',
      summary: 'Atenção',
      detail: 'Selecione pelo menos um jogo para criar um bundle.',
      life: 5000
    });
    return;
  }

  BundleDialogVisible.value = true;
}

/**
 * Cria o bundle com os jogos selecionados usando o novo componente
 */
async function onCreateBundle(bundleFormData: any) {
  try {
    bundleFormData.release_date = identifyAndFormatDate(bundleFormData.release_date);
    const bundlePayload = {
      ...bundleFormData,
      games: bundleFormData.games?.map(game => game.id) || []
    };

    const res = await axiosInstance.post('/bundles', bundlePayload);

    showResponse(res, toast.add);

    if (res.status === 200 || res.status === 201) {
      BundleDialogVisible.value = false;
      selectedProduct.value = null; // Limpa a seleção
    }
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: 'Erro Interno, tente novamente.',
      detail: error,
      life: 7000
    });
    console.error('Erro na criação do bundle:', error);
  }
}

</script>

<template>
  <!-- ====================================
       COMPONENTES GLOBAIS (TOAST, POPUP)
       ==================================== -->
  <Toast position="bottom-right" />
  <ConfirmPopup />

  <!-- ====================================
       MODAL DE CRIAÇÃO/EDIÇÃO
       ==================================== -->
  <Dialog v-model:visible="DialogVisible" modal :header="isEdit ? 'Editar Jogo' : 'Criar Jogo'"
    :style="{ width: '90%' }">

    <!-- Instruções do modal -->
    <span class="d-block mb-3" v-if="!isEdit">Insira os dados para criar um novo jogo.</span>
    <span class="d-block mb-3" v-if="isEdit">Edite os dados do jogo.</span>

    <!-- Controles para criação múltipla (apenas em modo criação) -->
    <div v-if="!isEdit" class="mb-3">
      <Button class="flex-auto me-2" @click="addOrRemove(true)" label="Adicionar jogo" icon="pi pi-plus" />
      <Button class="flex-auto" @click="addOrRemove(false)" label="Remover jogo" icon="pi pi-minus" severity="danger" />
    </div>

    <!-- Formulários dos jogos (um para cada jogo sendo criado/editado) -->
    <div v-for="(item, index) in selected" :key="index" class="d-flex flex-row gap-2">

      <!-- Campo Nome (obrigatório) -->
      <div class="d-flex flex-column items-center gap-3 mb-3">
        <label :for="`name_${index}`" class="fw-semibold w-32">Nome*</label>
        <InputText :id="`name_${index}`" class="flex-auto" v-model="item.name" />
      </div>

      <div class="d-flex flex-column items-center gap-3 mb-3">
        <label :for="`region_${index}`" class="fw-semibold w-32">Região</label>
        <InputText :id="`region_${index}`" class="flex-auto" v-model="item.region" />
      </div>

      <!-- Campo ID Gamivo -->
      <div class="d-flex flex-column items-center gap-3 mb-3">
        <label :for="`gamivo_id_${index}`" class="fw-semibold w-32">ID Gamivo</label>
        <InputText :id="`gamivo_id_${index}`" class="flex-auto" v-model="item.gamivo_id" />
      </div>

      <div class="d-flex flex-column items-center gap-3 mb-3">
        <label :for="`id_steamchart_${index}`" class="fw-semibold w-32">ID SteamCharts</label>
        <InputText :id="`id_steamcharts_${index}`" class="flex-auto" v-model="item.id_steamcharts" />
      </div>

      <!-- Campo Preço TF2 -->
      <div class="d-flex flex-column items-center gap-3 mb-3">
        <label :for="`price_tf2_${index}`" class="fw-semibold w-32">Preço TF2</label>
        <InputNumber :id="`price_tf2_${index}`" class="flex-auto" v-model="item.price_tf2" mode="decimal"
          :minFractionDigits="2" :maxFractionDigits="2" useGrouping />
      </div>

      <!-- Campo Preço Euro -->
      <div class="d-flex flex-column items-center gap-3 mb-3">
        <label :for="`price_euro_${index}`" class="fw-semibold w-32">Preço (Euro)</label>
        <InputNumber :id="`price_euro_${index}`" class="flex-auto" v-model="item.price_euro" mode="decimal"
          :minFractionDigits="2" :maxFractionDigits="2" useGrouping />
      </div>

      <!-- Campo Popularidade -->
      <div class="d-flex flex-column items-center gap-3 mb-5">
        <label :for="`popularity_${index}`" class="fw-semibold w-32">Popularidade</label>
        <InputNumber :id="`popularity_${index}`" class="flex-auto" v-model="item.popularity" mode="decimal"
          :minFractionDigits="0" :maxFractionDigits="2" useGrouping />
      </div>

      <!-- Campo Data de Lançamento -->
      <div class="d-flex flex-column items-center gap-3 mb-3">
        <label :for="`release_date_${index}`" class="fw-semibold w-32">Data de Lançamento</label>
        <InputText :id="`release_date_${index}`" class="flex-auto" v-model="item.release_date" />
      </div>

    </div>

    <!-- Botões de ação do modal -->
    <div class="d-flex justify-content-end gap-2">
      <Button type="button" label="Cancelar" severity="secondary" @click="DialogVisible = false"></Button>
      <Button type="button" label="Salvar" @click="isEdit ? onEdit(selected) : onAdd()"></Button>
    </div>
  </Dialog>

  <!-- ====================================
       MODAL DE CRIAÇÃO DE BUNDLE
       ==================================== -->
  <BundleModal v-model:visible="BundleDialogVisible" :is-edit="false" :selected-games="selectedProduct"
    @save="onCreateBundle" @cancel="BundleDialogVisible = false" />

  <!-- ====================================
       PÁGINA PRINCIPAL
       ==================================== -->
  <div class="container text-center">

    <!-- Cabeçalho da página -->
    <h1>Jogos</h1>
    <div class="w-50 m-auto">
      <p>Dados dos jogos.</p>
    </div>

    <!-- ====================================
         TABELA DE DADOS
         ==================================== -->
    <DataTable :value="rowData" showGridlines resizableColumns reorderableColumns sortMode="multiple" removableSort
      v-model:filters="filters" filterDisplay="menu" v-model:selection="selectedProduct" selectionMode="multiple"
      scrollable scrollHeight="95vh" editMode="cell" dataKey="id" size="small" tableStyle="min-width: 50rem" ref="dt">
      <!-- Cabeçalho da tabela com botões de ação -->
      <template #header>
        <div class="d-flex justify-content-between">
          <!-- Botões do lado esquerdo -->
          <div class="d-flex gap-2 flex-column flex-md-row">
            <Button label="Novo" aria-label="Novo" icon="pi pi-plus" @click="handleAddButton()" raised />
            <Button label="Deletar" :disabled="!selectedProduct || selectedProduct.length === 0" aria-label="Deletar"
              severity="danger" icon="pi pi-trash" @click="handleDeleteButton($event, 2)" raised />
          </div>
          <!-- Botões do lado direito -->
          <div class="d-flex gap-2 flex-column flex-md-row">
            <Button label="Pesquisar" aria-label="Pesquisar" severity="info" icon="pi pi-search"
              @click="onPageChange(true)" raised />
            <Button label="Criar Bundle" :disabled="!selectedProduct || selectedProduct.length === 0"
              aria-label="Criar Bundle" severity="success" icon="pi pi-sitemap" @click="handleCreateBundle()" raised />
          </div>
        </div>
      </template>

      <!-- Mensagem quando não há dados -->
      <template #empty>
        <h4>Nenhum item encontrado.</h4>
      </template>
      <!-- ====================================
           COLUNAS DA TABELA
           ==================================== -->

      <!-- Coluna ID (somente leitura) -->
      <Column field="id" header="ID" sortable></Column>

      <!-- <Column field="nomeJogo" header="Nome do Jogo" filterField="searchField" :showFilterMenu="true"
      :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0"> -->

      <!-- Coluna Nome (editável, com filtro) -->
      <Column field="name" header="Nome" filterField="searchField" :showFilterMenu="true" :showFilterMatchModes="false"
        :showApplyButton="false" :showClearButton="false">
        <template #filter>
          <InputText v-model="searchFilter.name" type="text" placeholder="Pesquisar" />
        </template>
        <template #editor="{ data, field }">
          <InputText v-model="data[field]" @change="onEdit(data)"></InputText>
        </template>
      </Column>

      <Column field="region" header="Região" filterField="searchField" :showFilterMenu="true"
        :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false">
        <template #filter>
          <InputText v-model="searchFilter.region" type="text" placeholder="Pesquisar" />
        </template>
        <template #editor="{ data, field }">
          <InputText v-model="data[field]" @change="onEdit(data)"></InputText>
        </template>
      </Column>

      <!-- Coluna Popularidade (editável, numérico) -->
      <Column field="popularity" header="Popularidade" sortable>
        <template #editor="{ data, field }">
          <InputNumber v-model="data[field]" @update:modelValue="onEdit(data)" mode="decimal" useGrouping autofocus
            fluid />
        </template>
      </Column>

      <!-- Coluna ID Gamivo (editável) -->
      <Column field="gamivo_id" header="ID Gamivo" filterField="searchField" :showFilterMenu="true"
        :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false">
        <template #editor="{ data, field }">
          <InputText v-model="data[field]" @change="onEdit(data)"></InputText>
        </template>
        <template #filter>
          <InputText v-model="searchFilter.gamivo_id" type="text" placeholder="Pesquisar" />
        </template>
      </Column>

      <!-- Coluna ID SteamCharts (editável) -->
      <Column field="id_steamcharts" header="ID SteamCharts" filterField="searchField" :showFilterMenu="true"
        :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false">
        <template #editor="{ data, field }">
          <InputText v-model="data[field]" @change="onEdit(data)"></InputText>
        </template>
        <template #filter>
          <InputText v-model="searchFilter.id_steamcharts" type="text" placeholder="Pesquisar" />
        </template>
      </Column>


      <!-- Coluna Preço TF2 (editável, numérico com decimais) -->
      <!-- <Column field="price_tf2" header="Preço(TF2)" sortable>
        <template #editor="{ data, field }">
          <InputNumber v-model="data[field]" @update:modelValue="onEdit(data)" mode="decimal" :minFractionDigits="2"
            :maxFractionDigits="2" useGrouping autofocus fluid />
        </template>
      </Column> -->

      <!-- Coluna Preço Euro (editável, numérico com decimais) -->
      <!-- <Column field="price_euro" header="Preço(euro)" sortable>
        <template #editor="{ data, field }">
          <InputNumber v-model="data[field]" @update:modelValue="onEdit(data)" mode="decimal" :minFractionDigits="2"
            :maxFractionDigits="2" useGrouping autofocus fluid />
        </template>
      </Column> -->

      <!-- Coluna Data de Lançamento (editável, com formatação BR) -->
      <!-- <Column field="release_date" header="Data de Lançamento" sortable>
        <template #editor="{ data, field }">
          <InputText v-model="data[field]" @change="onEdit(data)"></InputText>
        </template>
        <template #body="slotProps">
          {{ formatDateToBR(slotProps.data.release_date) }}
        </template>
      </Column> -->

    </DataTable>
    <Paginator :totalRecords="localTotalGames" :first="currentFirst" :rowsPerPageOptions="[100, 200, 300]"
      template="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink JumpToPageDropdown"
      :rows="pagination!.per_page" @page="handlePageChange"></Paginator>
    <p>Total: {{ localTotalGames }}</p>
  </div>
</template>