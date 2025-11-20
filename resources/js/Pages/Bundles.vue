<script setup lang="ts">
import { reactive, ref } from 'vue';
import type { PropType } from 'vue';
import axiosInstance from '../axios';

// PrimeVue
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import { FilterMatchMode } from '@primevue/core/api';
import InputText from 'primevue/inputtext';
import 'primeicons/primeicons.css'
import InputGroup from 'primevue/inputgroup';
import InputGroupAddon from 'primevue/inputgroupaddon';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import Toast from 'primevue/toast';
import { useToast } from "primevue/usetoast";
import ConfirmPopup from 'primevue/confirmpopup';
import { useConfirm } from "primevue/useconfirm";
import Menu from 'primevue/menu';
import Paginator from 'primevue/paginator';

// Inertia
import { showResponse } from '../helpers/showResponse';
import { Bundle } from '../types/Bundle';
import { formatDateToBR } from '@/helpers/formatHelpers';

// Components
import BundleModal from '../components/BundleModal.vue';
import BundleSearchModal from '../components/BundleSearchModal.vue';

// Props e dados iniciais
const props = defineProps({
  bundles: Array as PropType<Bundle[]>,
  pagination: Object as PropType<{
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number;
    to: number;
  }>
});

let rowData: Bundle[] = reactive([]);
const paginationData = reactive({
  currentPage: 1,
  totalRecords: 0,
  rows: 20,
  first: 0
});

// Filtros de pesquisa atuais (para manter na paginação)
const currentSearchParams = ref({});

console.log(props.bundles, props.pagination);

// Inicializa dados
Object.assign(rowData, props.bundles || []);
if (props.pagination) {
  Object.assign(paginationData, {
    currentPage: props.pagination.current_page,
    totalRecords: props.pagination.total,
    rows: props.pagination.per_page,
    first: (props.pagination.current_page - 1) * props.pagination.per_page
  });
}

const filters = ref({
  global: { value: null, matchMode: FilterMatchMode.CONTAINS },
  name: { value: null, matchMode: FilterMatchMode.CONTAINS },
  preco: { value: null, matchMode: FilterMatchMode.CONTAINS },
  action: { value: null, matchMode: FilterMatchMode.CONTAINS },
});

const toast = useToast();
const confirm = useConfirm();

const selectedProducts = ref({});
const BundleModalVisible = ref(false); // Nova visibilidade do modal de bundle
const BundleSearchModalVisible = ref(false); // Visibilidade do modal de pesquisa
const addGameModalVisible = ref(false); // Visibilidade do Dialog(modal) - será removido
const isEdit = ref(false); // Variável que define se é para criar ou editar no Dialog

// Estados para o modal de adicionar jogo
const searchTerm = ref('');
const searchResults = ref([]);
const isSearching = ref(false);
const currentBundle = ref(null);

const selected = reactive({
  id: 0,
  name: '',
  type: '',
  description: '',
  minimum_price_tf2: null,
  price_dolar: null,
  release_date: '',
  games: []
})

// Dados para edição do bundle
const bundleEditData = reactive({
  id: 0,
  name: '',
  type: '',
  description: '',
  minimum_price_tf2: null,
  price_dolar: null,
  release_date: ''
})

const handleEditBundle = (bundle: Bundle) => {
  isEdit.value = true;

  // Limpa os dados primeiro
  Object.assign(bundleEditData, {
    id: 0,
    name: '',
    type: '',
    description: '',
    minimum_price_tf2: null,
    price_dolar: null,
    release_date: ''
  });

  // Depois preenche com os dados do bundle
  Object.assign(bundleEditData, {
    id: bundle.id,
    name: bundle.name,
    type: bundle.type,
    description: bundle.description,
    minimum_price_tf2: bundle.minimum_price_tf2,
    price_dolar: bundle.price_dolar,
    release_date: bundle.release_date
  });

  BundleModalVisible.value = true;
}

const onSaveBundle = async (bundleData: Partial<Bundle>) => {
  try {
    // Prepara os dados para a API (converte games para IDs se necessário)
    const bundlePayload = {
      ...bundleData,
      games: bundleData.games?.map(game => game.id) || []
    };

    if (isEdit.value) {
      // Editar bundle existente
      const res = await axiosInstance.put(`/bundles/${bundleData.id}`, bundlePayload);
      showResponse(res, toast.add);

      if (res.status === 200) {
        const itemToUpdate = rowData.find(item => item.id === bundleData.id);
        if (itemToUpdate) {
          Object.assign(itemToUpdate, res.data.data);
          BundleModalVisible.value = false;
        }
      }
      return;
    }

    // Criar novo bundle
    const res = await axiosInstance.post(`/bundles`, bundlePayload);
    showResponse(res, toast.add);

    if (res.status === 201 || res.status === 200) {
      rowData.unshift(res.data.data); // Aqui tem que colocar em primeiro
      BundleModalVisible.value = false;
    }
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: 'Erro Interno, tente novamente.',
      detail: error,
      life: 7000
    });
    console.log(error);
  }
}

const handleAddBundle = (): void => {
  isEdit.value = false;
  Object.assign(bundleEditData, {
    id: 0,
    name: '',
    type: 'bundle',
    description: '',
    minimum_price_tf2: null,
    price_dolar: null,
    release_date: ''
  });
  BundleModalVisible.value = true;
}

const handleDeleteBundle = (event: any, bundle: Bundle) => {
  confirm.require({
    target: event.currentTarget,
    message: 'Tem certeza que deseja excluir este bundle?',
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
      try {
        const res = await axiosInstance.delete(`/bundles/${bundle.id}`);
        showResponse(res, toast.add);

        if (res.status === 200) {
          const itemToDelete = rowData.findIndex(item => item.id === bundle.id);
          if (itemToDelete !== -1) {
            rowData.splice(itemToDelete, 1);
          }
        }
      } catch (error) {
        toast.add({
          severity: 'error',
          summary: 'Erro ao deletar bundle',
          detail: error,
          life: 7000
        });
      }
    }
  });
};

function handleAddGame(bundle: Bundle): void {
  currentBundle.value = bundle;
  addGameModalVisible.value = true;
  searchTerm.value = '';
  searchResults.value = [];
}

// Função para buscar jogos
const searchGames = async () => {
  if (searchTerm.value.length < 2) {
    searchResults.value = [];
    return;
  }

  isSearching.value = true;
  try {
    const res = await axiosInstance.post('/games/search', {
      name: searchTerm.value,
    });

    if (res.status === 200) {
      searchResults.value = res.data.data.games.data || [];
    }
  } catch (error) {
    console.error('Erro ao buscar jogos:', error);
    toast.add({
      severity: 'error',
      summary: 'Erro na busca',
      detail: 'Erro ao buscar jogos',
      life: 3000
    });
  } finally {
    isSearching.value = false;
  }
};

// Função para verificar se um jogo já está no bundle atual
const isGameInCurrentBundle = (game) => {
  if (!currentBundle.value || !currentBundle.value.games) {
    return false;
  }
  return currentBundle.value.games.some(bundleGame => bundleGame.id === game.id);
};

// Função para adicionar jogo ao bundle
const addGameToBundle = async (game) => {
  try {
    if (!currentBundle.value) {
      toast.add({
        severity: 'error',
        summary: 'Erro ao adicionar jogo ao bundle',
        detail: 'Nenhum bundle selecionado',
        life: 5000
      });
      return;
    }

    const res = await axiosInstance.post(`/bundles/${currentBundle.value.id}/games`, {
      games: [game.id]
    });
    showResponse(res, toast.add);

    if (res.status === 200 || res.status === 201) {
      currentBundle.value.games.push(game);
      // Atualiza a lista de jogos do bundle na interface
    }


  } catch (error) {
    console.error('Erro ao adicionar jogo:', error);
    toast.add({
      severity: 'error',
      summary: 'Erro',
      detail: error.response.data.message,
      life: 5000
    });
  }
};

function handleSearchBundle(): void {
  BundleSearchModalVisible.value = true;
}

const onSearchBundle = async (searchData: any, page: number = 1): Promise<void> => {
  try {
    // Armazena os parâmetros de pesquisa para reutilizar na paginação
    currentSearchParams.value = searchData;

    // Faz a requisição para o backend com os filtros e página
    const res = await axiosInstance.get('/bundles', {
      params: {
        ...searchData,
        page: page
      }
    });

    showResponse(res, toast.add);

    if (res.status === 200) {
      // Atualiza os dados na tela
      rowData.splice(0, rowData.length, ...res.data.data.bundles);

      // Atualiza dados de paginação
      Object.assign(paginationData, {
        currentPage: res.data.data.pagination.current_page,
        totalRecords: res.data.data.pagination.total,
        rows: res.data.data.pagination.per_page,
        first: (res.data.data.pagination.current_page - 1) * res.data.data.pagination.per_page
      });
    }
  } catch (error) {
    console.error('Erro na pesquisa:', error);
    toast.add({
      severity: 'error',
      summary: 'Erro na pesquisa',
      detail: 'Erro ao buscar bundles',
      life: 5000
    });
  }
}

// Função para navegar entre páginas
const onPageChange = async (event: any): Promise<void> => {
  const page = event.page + 1; // PrimeVue usa índice 0, Laravel usa 1

  // Se há filtros ativos, mantém eles na navegação
  if (Object.keys(currentSearchParams.value).length > 0) {
    await onSearchBundle(currentSearchParams.value, page);
  } else {
    // Se não há filtros, faz uma busca simples
    await loadBundles(page);
  }
}

// Função para carregar bundles sem filtros (para paginação inicial)
const loadBundles = async (page: number = 1): Promise<void> => {
  try {
    const res = await axiosInstance.get('/bundles', {
      params: { page: page }
    });

    if (res.status === 200) {
      rowData.splice(0, rowData.length, ...res.data.data.bundles);

      Object.assign(paginationData, {
        currentPage: res.data.data.pagination.current_page,
        totalRecords: res.data.data.pagination.total,
        rows: res.data.data.pagination.per_page,
        first: (res.data.data.pagination.current_page - 1) * res.data.data.pagination.per_page
      });
    }
  } catch (error) {
    console.error('Erro ao carregar bundles:', error);
    toast.add({
      severity: 'error',
      summary: 'Erro ao carregar bundles',
      detail: 'Erro ao buscar dados',
      life: 5000
    });
  }
}

function handleExportBundle(): void {
}

const handleDeleteSelectedGames = (event: any, bundleId: number) => {
  confirm.require({
    target: event.currentTarget,
    message: 'Tem certeza que deseja excluir esses jogos?',
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
      try {
        const selectedGames = selectedProducts.value[bundleId].map(item => item.id);
        const res = await axiosInstance.delete(`/bundles/${bundleId}/games`, {
          params: {
            games: selectedGames
          }
        });
        showResponse(res, toast.add);

        if (res.status === 200) {
          // Remove os jogos dos bundles
          rowData.forEach(bundle => {
            bundle.games = bundle.games.filter(game => !selectedGames.includes(game.id));
          });
          selectedProducts.value[bundleId] = [];
        }
      } catch (error) {
        toast.add({
          severity: 'error',
          summary: 'Erro ao deletar jogos',
          detail: error,
          life: 7000
        });
      }
    }
  });
};

// Opções de menu do bundle
const menuRefs = ref({});
const getBundleOptions = (bundle: Bundle) => [
  {
    label: 'Opções',
    items: [
      {
        label: 'Editar Bundle',
        icon: 'pi pi-pencil',
        command: () => handleEditBundle(bundle)
      },
      {
        label: 'Deletar Bundle',
        icon: 'pi pi-trash',
        command: (event) => handleDeleteBundle(event.originalEvent, bundle)
      }
    ]
  }
];

const toggleMenu = (event, bundleId) => {
  const menuRef = menuRefs.value[`menu_${bundleId}`];
  if (menuRef) {
    menuRef.toggle(event);
  }
};

// Função para capitalizar a primeira letra
const capitalize = (str: string): string => {
  if (!str) return '';
  return str.charAt(0).toUpperCase() + str.slice(1).toLowerCase();
};

</script>

<template>
  <!-- Toast e ConfirmPopup -->
  <Toast position="bottom-right" />
  <ConfirmPopup />

  <Dialog v-model:visible="addGameModalVisible" modal header="Adicionar Jogo ao Bundle" :style="{ width: '80%' }">
    <!-- Campo de pesquisa -->
    <div class="mb-4 d-flex flex-column">
      <label for="game_search" class="fw-semibold block">Pesquisar Jogo</label>
      <InputText id="game_search" v-model="searchTerm" @keyup="searchGames"
        placeholder="Digite o nome do jogo para pesquisar..." class="w-full" />
      <small class="mt-0 text-gray-500">Digite pelo menos 2 caracteres para buscar</small>
    </div>

    <!-- Indicador de carregamento -->
    <div v-if="isSearching" class="text-center mb-4">
      <i class="pi pi-spinner pi-spin mr-2"></i>
      Buscando jogos...
    </div>

    <!-- Resultados da busca -->
    <div v-if="searchResults.length > 0" class="mb-4">
      <h5 class="mb-3">Resultados da busca ({{ searchResults.length }})</h5>
      <div class="max-h-96 overflow-y-auto">
        <div v-for="game in searchResults" :key="game.id"
          class="border border-gray-200 rounded p-3 mb-2 hover:bg-gray-50 transition-colors">
          <div class="d-flex justify-content-between align-items-center">
            <div class="flex-1">
              <h6 class="mb-1 fw-semibold">{{ game.name }} ({{ game.region || 'Global' }})</h6>
              <div class="text-sm d-flex gap-2">
                <span><strong>Preço TF2:</strong> {{ game.minimum_price_tf2 }}</span>
                <span><strong>Preço Euro:</strong> €{{ game.price_dolar }}</span>
              </div>
            </div>
            <Button :label="isGameInCurrentBundle(game) ? 'Já adicionado' : 'Adicionar'"
              :icon="isGameInCurrentBundle(game) ? 'pi pi-check' : 'pi pi-plus'" :disabled="isGameInCurrentBundle(game)"
              size="small" @click="addGameToBundle(game)" class="ml-3" />
          </div>
        </div>
      </div>
    </div>

    <!-- Mensagem quando não há resultados -->
    <div v-else-if="searchTerm.length >= 2 && !isSearching" class="text-center text-gray-500 mb-4">
      <i class="pi pi-search mr-2"></i>
      Nenhum jogo encontrado para "{{ searchTerm }}"
    </div>

    <div v-else-if="searchTerm.length < 2" class="text-center text-gray-400 mb-4">
      <i class="pi pi-info-circle mr-2"></i>
      Use o campo de pesquisa acima para encontrar jogos
    </div>

    <!-- Botões do modal -->
    <div class="d-flex justify-content-end gap-2 mt-4">
      <Button type="button" label="Fechar" severity="secondary" @click="addGameModalVisible = false" />
    </div>
  </Dialog>

  <BundleModal v-model:visible="BundleModalVisible" :is-edit="isEdit" :bundle-data="bundleEditData" @save="onSaveBundle"
    @cancel="BundleModalVisible = false" />

  <BundleSearchModal v-model:visible="BundleSearchModalVisible" @search="onSearchBundle" />

  <div class="container">

    <div class="w-50 m-auto text-center ">
      <h1>Bundles</h1>
      <p>Gerencie os Bundles/Choices de jogos.</p>
    </div>
    <div class="d-flex justify-content-between">
      <Button class="mb-2" label="Novo Bundle" aria-label="Novo Bundle" icon="pi pi-plus" @click="handleAddBundle()"
        raised />
      <div class="d-flex gap-2">
        <Button class="mb-2" severity="info" label="Pesquisar Bundle" aria-label="Pesquisar Bundle" icon="pi pi-search"
          @click="handleSearchBundle()" raised />
        <Button class="mb-2" severity="contrast" label="Exportar Bundles" aria-label="Exportar Bundles"
          icon="pi pi-file-excel" @click="handleExportBundle()" raised />
      </div>
    </div>

    <div v-for="bundle in rowData" :key="bundle.id" class="card mb-4">
      <div class="card-header">
        <div class="card-title d-flex justify-content-between">
          <h3>{{ capitalize(bundle.type) + ' - ' + bundle.name }}</h3>
          <div class="d-flex gap-2">
            <Button type="button" severity="contrast" icon="pi pi-ellipsis-v" @click="toggleMenu($event, bundle.id)"
              aria-haspopup="true" :aria-controls="`overlay_menu_${bundle.id}`" raised />
            <Menu :ref="(el) => menuRefs[`menu_${bundle.id}`] = el" :id="`overlay_menu_${bundle.id}`"
              :model="getBundleOptions(bundle)" :popup="true" />
          </div>
        </div>
        <div class="row">
          <div class="col-12 col-md-3">
            <strong>Data de Lançamento:</strong> {{ formatDateToBR(bundle.release_date) ?? 'Não informado' }}
          </div>
          <div class="col-12 col-md-3">
            <strong>Descrição:</strong> {{ bundle.description ?? 'Nenhuma' }}
          </div>
          <div class="col-12 col-md-3">
            <strong>Preço Mínimo TF2:</strong> {{ bundle.minimum_price_tf2 ?? 'Não informado' }}
          </div>
          <div class="col-12 col-md-3">
            <strong>Preço Dólar:</strong> {{ bundle.price_dolar ?? 'Não informado' }}
          </div>
        </div>
      </div>

      <div class="card-body">
        <DataTable :value="bundle.games" sortMode="multiple" removableSort v-model:filters="filters"
          filterDisplay="menu" v-model:selection="selectedProducts[bundle.id]" selectionMode="multiple" scrollable
          scrollHeight="95vh" editMode="cell" dataKey="id" size="small" tableStyle="min-width: 50rem" ref="dt">
          <template #header>
            <div class="d-flex justify-content-between">
              <!-- Botões do lado esquerdo -->
              <div class="d-flex gap-2 flex-column flex-md-row">
                <Button label="Adicionar Jogo" aria-label="Adicionar Jogo" icon="pi pi-plus"
                  @click="handleAddGame(bundle)" raised />
                <Button label="Deletar"
                  :disabled="!selectedProducts[bundle.id] || selectedProducts[bundle.id].length === 0"
                  aria-label="Deletar" severity="danger" icon="pi pi-trash"
                  @click="handleDeleteSelectedGames($event, bundle.id)" raised />
              </div>
              <!-- Botões do lado direito -->
              <div class="d-flex gap-2 flex-column flex-md-row">
                <!-- <Button label="Pesquisar" aria-label="Pesquisar" severity="info" icon="pi pi-search"
                @click="onPageChange(true)" raised /> -->
              </div>
            </div>
          </template>
          <template #empty>
            <h4>
              Nenhum jogo.
            </h4>
          </template>
          <Column field="id" header="ID" sortable></Column>
          <Column field="name" header="Nome" sortable></Column>
          <Column field="region" header="Região" sortable></Column>
          <Column field="popularity" header="Popularidade" sortable></Column>
          <Column field="pivot.bundle_launch_price" header="Preço lançamento(€)" sortable></Column>
          <Column field="minimum_price_tf2" header="Preço Mín.(TF2)" sortable></Column>
          <!-- <Column field="price_dolar" header="Preço(dólar)" sortable></Column> -->
          <!-- <Column field="release_date" header="Data de Lançamento" sortable>
            <template #body="slotProps">
              {{ formatDateToBR(slotProps.data.release_date) }}
            </template>

          </Column> -->
          <Column field="id_gamivo" header="ID Gamivo" sortable></Column>
          <Column field="id_steamcharts" header="ID Steam" sortable></Column>
        </DataTable>

      </div>
    </div>

    <!-- Paginação -->
    <div class="mt-4 d-flex justify-content-center" v-if="paginationData.totalRecords > 0">
      <Paginator v-model:first="paginationData.first" :rows="paginationData.rows"
        :totalRecords="paginationData.totalRecords" @page="onPageChange"
        template="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink RowsPerPageDropdown JumpToPageDropdown" />
    </div>
    <p class="text-center">Total: {{ paginationData.totalRecords }}</p>
  </div>
</template>