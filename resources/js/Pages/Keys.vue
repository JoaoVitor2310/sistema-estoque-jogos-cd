<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import axiosInstance from '../axios';
import { GameLine } from '../types/GameLine';
import { convertToDbDate, formatDateToBR, formatDateToDB, identifyAndFormatDate } from '../helpers/formatHelpers';

// Inertia
import { showResponse } from '../helpers/showResponse';

// PrimeVue
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import { FilterMatchMode } from '@primevue/core/api';
import InputText from 'primevue/inputtext';
import 'primeicons/primeicons.css'
import Button from 'primevue/button';
import Toast from 'primevue/toast';
import { useToast } from "primevue/usetoast";
import ConfirmPopup from 'primevue/confirmpopup';
import { useConfirm } from "primevue/useconfirm";
import InputNumber from 'primevue/inputnumber';
import Select from 'primevue/select';
import DatePicker from 'primevue/datepicker';
import Paginator, { PageState } from 'primevue/paginator';
import MultiSelect from 'primevue/multiselect';
import { usePage } from '@inertiajs/vue3';

// onMouted {
let rowData: GameLine[] = reactive([]);
const props = defineProps({
  games: Array,
  totalGames: Number,
  pagination: Object,
  keyFormats: Array as () => string[],
  claimTypes: Array as () => string[],
  sellPlatforms: Array as () => string[],
});
// console.log(props.tiposFormato);
Object.assign(rowData, props.games);
// @ts-ignore
let user = ref(usePage().props.auth.user);
// @ts-ignore
const canEdit = computed(() => usePage().props.auth.canEdit as boolean);
// }


// const selectedColumns = ref(columns.value);

// const onToggle = (val) => {
//   selectedColumns.value = columns.value.filter(col => val.includes(col));
// };

const filters = ref({
  searchField: { value: null, matchMode: FilterMatchMode.IN },
});

const toast = useToast();
const confirm = useConfirm();

const selectedProduct = ref();
const localTotalGames = ref(props.totalGames);

/** Salva a edição inline de uma célula do DataTable (editMode="cell"). */
const onEdit = async (selected: any) => {
  const product = { ...selected };

  if (selected.acquired_at) {
    selected.acquired_at = identifyAndFormatDate(selected.acquired_at);
  }
  if (selected.listed_at) {
    selected.listed_at = identifyAndFormatDate(selected.listed_at);
  }
  if (selected.sold_at) {
    selected.sold_at = identifyAndFormatDate(selected.sold_at);
  }

  try {
    const res = await axiosInstance.put(`/keys/${product.id}`, product);
    // console.log(res.data);
    showResponse(res, toast.add);

    if (res.status === 200) {
      // Editar o market_price recalcula o lucro de todo o lote no backend, que
      // devolve todas as keys afetadas. Atualiza cada linha na tela (não só a
      // editada) para as irmãs refletirem os novos valores sem recarregar.
      const affected = res.data.data ?? [];
      affected.forEach((updated: any) => {
        const row = rowData.find(item => item.id === updated.id);
        if (row) {
          Object.assign(row, updated);
        }
      });
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

const handleDeleteButton = (event: any) => {
  confirm.require({
    target: event.currentTarget,
    message: 'Tem certeza que deseja excluir esses itens?',
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
        const res = await axiosInstance.delete(`/keys`, {
          params: {
            games: selectedProduct.value
          }
        });
        showResponse(res, toast.add);
        if (res.status === 200 || res.status === 201) {
          const selectedProductIds = selectedProduct.value.map(item => item.id);
          const filteredRowData = rowData.filter(item => !selectedProductIds.includes(item.id));
          rowData.splice(0, rowData.length, ...filteredRowData);
          selectedProduct.value = null;
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
  });
};

const pagination = ref(props.pagination!); // Informações da paginação
const currentFirst = ref((pagination.value.current_page - 1) * pagination.value.per_page);
const isSearching = ref(false);

const searchFilter = reactive({
  claim_type: [],
  steam_id: '',
  key_format: [],
  dont_sell: false,
  key_code: '',
  identified_platform: '',
  game_name: '',
  region: '',
  gamivo_id: '',
  hasIdGamivo: '',
  notes: '',
  notes_filled: '',
  sell_platform: [],
  total_paid: '',
  acquired_at_from: null as Date | null,
  acquired_at_to: null as Date | null,
  listed_at: '',
  listed_at_from: null as Date | null,
  listed_at_to: null as Date | null,
  sold_at: '',
  sold_at_from: null as Date | null,
  sold_at_to: null as Date | null,
  expires_at: '',
  expires_at_from: null as Date | null,
  expires_at_to: null as Date | null,
  supplier_url: '',
})

// Converte um objeto Date para o formato YYYY-MM-DD esperado pelo backend.
// Usa data local (não UTC) para evitar deslocamento de fuso horário.
const toDbDate = (date: Date | null): string | null => {
  if (!date) return null;
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
};

// Monta o payload de busca convertendo os campos de data (Date → string YYYY-MM-DD).
const buildSearchPayload = () => ({
  ...searchFilter,
  acquired_at_from: toDbDate(searchFilter.acquired_at_from),
  acquired_at_to: toDbDate(searchFilter.acquired_at_to),
  listed_at_from: toDbDate(searchFilter.listed_at_from),
  listed_at_to: toDbDate(searchFilter.listed_at_to),
  sold_at_from: toDbDate(searchFilter.sold_at_from),
  sold_at_to: toDbDate(searchFilter.sold_at_to),
  expires_at_from: toDbDate(searchFilter.expires_at_from),
  expires_at_to: toDbDate(searchFilter.expires_at_to),
});

const handlePageChange = (event: PageState) => { // Teve que ser criada por que o evento não pode ser passado com outro argumento junto
  onPageChange(false, event);
};

// Função chamada ao mudar de página
const onPageChange = async (search: boolean, event: PageState | null = null) => {
  if (search) isSearching.value = true;
  const limit = event ? event.rows : 100;
  const page = event ? event.page + 1 : 1; // Paginator começa em 0. 1 como página padrão

  let url = `/keys/paginated?limit=${limit}&page=${page}`;
  let method = 'GET';

  if (isSearching.value) {
    url = `/keys/search?page=${page}`;
    method = 'POST';
  }

  try {
    const res = await axiosInstance(url, {
      method,
      data: method === 'POST' ? buildSearchPayload() : null
    });
    console.log(res.data);
    // return;
    if (res.status === 200 || res.status === 201) {
      localTotalGames.value = res.data.data.totalGames;
      rowData.splice(0, rowData.length, ...res.data.data.games.data);
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
};

const getRowStyle = (data: GameLine) => {
  const styleMap: Record<string, string> = {
    Dup: '#ffcccc', // Vermelho claro
    Rev: '#ffcccc', // Vermelho claro
    Reg: '#FFE066', // Amarelo claro
  };

  return data.color
    ? { backgroundColor: `#${data.color}` }
    : data.claim_type && styleMap[data.claim_type]
      ? { backgroundColor: styleMap[data.claim_type] }
      : null;
};

const getKeyCodeStyle = (data: GameLine) => {
  if (data.is_duplicate) {
    return {
      backgroundColor: '#ff0000', // Vermelho para duplicado
      color: '#ffffff', // Texto branco
    };
  }
  // Caso padrão, sem estilo
  return {};
};

const getStyleByPercent = (data: GameLine, field: keyof GameLine) => {
  const value = data[field];

  // Verifica se o valor é um número ou uma string que pode ser convertida para número
  const percentual = typeof value === 'number' ? value : parseFloat(value as string);

  // Se não for um número válido ou for 0, retorna estilo vazio
  if (isNaN(percentual) || percentual === 0) {
    return {};
  }

  const ranges = [
    { min: -Infinity, max: 0, backgroundColor: '#ff0000', color: '#ffffff' }, // Vermelho para valores abaixo de 0.01
    { min: 0, max: 50, backgroundColor: '#FFA500', color: '#000000' }, // Laranja entre 0 e 50
    { min: 50, max: 80, backgroundColor: '#FFFF00', color: '#000000' }, // Amarelo entre 50 e 80
    { min: 80, max: Infinity, backgroundColor: '#008000', color: '#ffffff' }, // Verde acima de 80
  ];

  const style = ranges.find(range => percentual > range.min && percentual <= range.max);
  return style ? { backgroundColor: style.backgroundColor, color: style.color } : {};
};

const dt = ref();
const exportCSV = () => {
  dt.value.exportCSV();
};


</script>

<template>
  <div class="w-100">
    <Toast position="bottom-right" />
    <ConfirmPopup />
    <div class="text-center mb-3 mx-5">
      <h1>Keys</h1>
      <div class="w-50 m-auto">
        <p>Gerenciamento de Keys adquiridas.</p>
      </div>
      <DataTable :value="rowData" showGridlines resizableColumns reorderableColumns sortMode="multiple" removableSort
        v-model:filters="filters" filterDisplay="menu" v-model:selection="selectedProduct" selectionMode="multiple"
        scrollable scrollHeight="95vh" editMode="cell" dataKey="id" size="small" tableStyle="min-width: 50rem"
        :rowStyle="getRowStyle" ref="dt">
        <template #header>
          <div class="d-flex justify-content-between">
            <div class="d-flex gap-2 flex-column flex-md-row" v-if="canEdit">
              <Button label="Deletar" :disabled="!selectedProduct || selectedProduct.length === 0" aria-label="Deletar"
                severity="danger" icon="pi pi-plus" @click="handleDeleteButton($event)" raised />
            </div>
            <div class="d-flex gap-2 flex-column flex-md-row ms-auto">
              <Button label="Pesquisar" aria-label="Pesquisar" severity="info" icon="pi pi-search"
                @click="onPageChange(true)" raised />
              <Button v-if="canEdit" icon="pi pi-external-link" label="Exportar CSV" @click="exportCSV()" />
            </div>
          </div>
        </template>
        <template #empty>
          <h4>
            Nenhum item encontrado.
          </h4>
        </template>
        <!-- <Column selectionMode="multiple" headerStyle="width: 3rem"></Column> -->
        <Column field="id" header="ID" sortable v-if="canEdit"></Column>
        <Column field="claim_type" header="Reclamação?" filterField="searchField" :showFilterMenu="true"
          v-if="canEdit"
          :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0">
          <template #filter>
            <MultiSelect placeholder="Pesquisar" v-model="searchFilter.claim_type"
              :options="props.claimTypes" style="min-width: 14rem">
            </MultiSelect>
          </template>
          <template #editor="{ data, field }">
            <Select v-model="data.claim_type" :options="props.claimTypes"
              @change="onEdit(data)" />
          </template>
        </Column>
        <Column field="key_format" header="Formato" filterField="searchField" :showFilterMenu="true"
          v-if="canEdit"
          :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0">
          <template #filter>
            <MultiSelect v-model="searchFilter.key_format" placeholder="Pesquisar"
              :options="props.keyFormats" style="min-width: 14rem">
            </MultiSelect>
          </template>
          <template #editor="{ data, field }">
            <Select v-model="data.key_format" :options="props.keyFormats"
              @change="onEdit(data)" />
          </template>
        </Column>
        <Column field="identified_platform" header="Plat. Identificada" filterField="searchField"
          :showFilterMenu="true" :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false"
          class="text-center p-0">
          <template #filter>
            <InputText v-model="searchFilter.identified_platform" type="text" placeholder="Pesquisar" />
          </template>
        </Column>
        <Column field="key_code" header="Chave Recebida" filterField="searchField" :showFilterMenu="true"
          :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0"
          v-if="user && user.email === 'carcadeals@gmail.com'">
          <template #filter>
            <InputText v-model="searchFilter.key_code" type="text" placeholder="Pesquisar" />
          </template>
          <template #body="{ data }">
            <div :style="getKeyCodeStyle(data)" style="width: 100%; height: 100%;">
              {{ data.key_code }}
            </div>
          </template>
          <template #editor="{ data, field }">
            <InputText v-model="data[field]" @change="onEdit(data)"></InputText>
          </template>
        </Column>
        <Column field="game_name" header="Nome do Jogo" filterField="searchField" :showFilterMenu="true"
          :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0">
          <template #filter>
            <InputText v-model="searchFilter.game_name" type="text" placeholder="Pesquisar" />
          </template>
          <template #editor="{ data, field }">
            <InputText v-model="data[field]" @change="onEdit(data)"></InputText>
          </template>
        </Column>
        <Column field="region" header="Região" filterField="searchField" :showFilterMenu="true"
          :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0">
          <template #filter>
            <InputText v-model="searchFilter.region" type="text" placeholder="Pesquisar" />
          </template>
          <template #editor="{ data, field }">
            <InputText v-model="data[field]" @change="onEdit(data)"></InputText>
          </template>
        </Column>
        <Column field="gamivo_id" header="Id Gamivo" filterField="searchField" :showFilterMenu="true"
          v-if="canEdit"
          :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0">
          <template #filter>
            <InputText v-model="searchFilter.gamivo_id" type="text" placeholder="Pesquisar por ID" />
            <Select v-model="searchFilter.hasIdGamivo" :options="[
              { name: 'Sim', value: 'sim' },
              { name: 'Não', value: 'nao' }
            ]" placeholder="Possui id Gamivo?" optionLabel="name" optionValue="value" style="min-width: 14rem">
            </Select>
          </template>
          <template #editor="{ data, field }">
            <InputText v-model="data[field]" @change="onEdit(data)"></InputText>
          </template>
        </Column>
        <Column field="notes" header="Observação" filterField="searchField" :showFilterMenu="true"
          v-if="canEdit"
          :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0">
          <template #filter>
            <div class="d-flex flex-column gap-1" style="min-width: 14rem">
              <InputText v-model="searchFilter.notes" type="text" placeholder="Pesquisar" />
              <Select v-model="searchFilter.notes_filled" :options="[
                { name: 'Todos', value: '' },
                { name: 'Sim', value: 'sim' },
                { name: 'Não', value: 'nao' }
              ]" placeholder="Preenchido?" optionLabel="name" optionValue="value" />
            </div>
          </template>
          <template #editor="{ data, field }">
            <InputText v-model="data[field]" @change="onEdit(data)"></InputText>
          </template>
        </Column>
        <Column field="market_price" header="Preço Mercado" sortable class="text-center p-0">
          <template #body="slotProps">
            € {{ slotProps.data.market_price }}
          </template>
          <template #editor="{ data, field }">
            <InputNumber v-model="data[field]" @update:modelValue="onEdit(data)" mode="decimal" :minFractionDigits="2"
              :maxFractionDigits="2" useGrouping autofocus fluid />
          </template>
        </Column>
        <Column field="total_paid" header="Valor Pago Total" filterField="searchField" :showFilterMenu="true"
          v-if="canEdit"
          :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0">
          <template #filter>
            <InputText v-model="searchFilter.total_paid" type="text" placeholder="Pesquisar" />
          </template>
          <template #editor="{ data, field }">
            <InputText v-model="data[field]" @change="onEdit(data)"></InputText>
          </template>
        </Column>
        <Column field="individual_cost" header="Custo" sortable class="text-center p-0">
          <template #body="slotProps">
            € {{ slotProps.data.individual_cost }}
          </template>
        </Column>
        <Column field="min_api" header="Min. API" sortable class="text-center p-0">
          <template #body="slotProps">
            € {{ slotProps.data.min_api }}
          </template>
          <template #editor="{ data, field }">
            <InputNumber v-model="data[field]" @update:modelValue="onEdit(data)" mode="decimal" :minFractionDigits="2"
              :maxFractionDigits="2" useGrouping autofocus fluid />
          </template>
        </Column>
        <Column field="max_api" header="Max. API" sortable class="text-center p-0">
          <template #body="slotProps">
            € {{ slotProps.data.max_api }}
          </template>
          <template #editor="{ data, field }">
            <InputNumber v-model="data[field]" @update:modelValue="onEdit(data)" mode="decimal" :minFractionDigits="2"
              :maxFractionDigits="2" useGrouping autofocus fluid />
          </template>
        </Column>
        <Column field="purchase_profit" header="Lucro Compra(€)" sortable class="text-center p-0">
          <template #body="slotProps">
            € {{ slotProps.data.purchase_profit }}
          </template>
        </Column>
        <Column field="purchase_profit_percent" header="Lucro Compra(%)" sortable class="text-center p-0">
          <template #body="slotProps">
            <div :style="getStyleByPercent(slotProps.data, 'purchase_profit_percent')" style="width: 100%; height: 100%;">
              {{ slotProps.data.purchase_profit_percent }}%
            </div>
          </template>
        </Column>
        <Column field="sold_price" header="Valor Vendido" sortable class="text-center p-0" v-if="canEdit">
          <template #body="slotProps">
            <span v-if="slotProps.data.sold_price">€ {{ slotProps.data.sold_price }}</span>
          </template>
          <template #editor="{ data, field }">
            <InputNumber v-model="data[field]" @update:modelValue="onEdit(data)" mode="decimal" :minFractionDigits="2"
              :maxFractionDigits="2" :min="-Infinity" useGrouping autofocus fluid />
          </template>
        </Column>
        <Column field="sale_profit" header="Lucro Venda(€)" sortable class="text-center p-0" v-if="canEdit">
          <template #body="slotProps">
            <span v-if="slotProps.data.sold_price">€ {{ slotProps.data.sale_profit }}</span>
          </template>
        </Column>
        <Column field="sale_profit_percent" header="Lucro Venda(%)" sortable class="text-center p-0" v-if="canEdit">
          <template #body="slotProps">
            <div :style="getStyleByPercent(slotProps.data, 'sale_profit_percent')"
              style="width: 100%; height: 100%;">
              {{ slotProps.data.sale_profit_percent }}%
            </div>
          </template>
        </Column>
        <Column field="acquired_at" header="Data Adquirida" sortable filterField="searchField" :showFilterMenu="true"
          :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0">
          <template #filter>
            <div class="d-flex flex-column gap-1" style="min-width: 14rem">
              <DatePicker v-model="searchFilter.acquired_at_from" dateFormat="dd/mm/yy" placeholder="De"
                showButtonBar showIcon fluid />
              <DatePicker v-model="searchFilter.acquired_at_to" dateFormat="dd/mm/yy" placeholder="Até"
                showButtonBar showIcon fluid />
            </div>
          </template>
          <template #body="slotProps">
            {{ formatDateToBR(slotProps.data.acquired_at) }}
          </template>
          <template #editor="{ data, field }">
            <InputText class="flex-auto" v-model="data[field]" @change="onEdit(data)" />
          </template>
        </Column>
        <Column field="listed_at" header="Data Listada" sortable filterField="searchField" :showFilterMenu="true"
          v-if="canEdit"
          :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0">
          <template #filter>
            <div class="d-flex flex-column gap-1" style="min-width: 14rem">
              <Select v-model="searchFilter.listed_at" :options="[
                { name: 'Todos', value: '' },
                { name: 'Sim', value: 'sim' },
                { name: 'Não', value: 'nao' }
              ]" placeholder="Já posto a venda?" optionLabel="name" optionValue="value" />
              <DatePicker v-model="searchFilter.listed_at_from" dateFormat="dd/mm/yy" placeholder="De"
                showButtonBar showIcon fluid />
              <DatePicker v-model="searchFilter.listed_at_to" dateFormat="dd/mm/yy" placeholder="Até"
                showButtonBar showIcon fluid />
            </div>
          </template>
          <template #body="slotProps">
            {{ formatDateToBR(slotProps.data.listed_at) }}
          </template>
          <template #editor="{ data, field }">
            <InputText class="flex-auto" v-model="data[field]" @change="onEdit(data)" />
          </template>
        </Column>
        <Column field="sold_at" header="Data Vendida" sortable filterField="searchField" :showFilterMenu="true"
          :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0">
          <template #filter>
            <div class="d-flex flex-column gap-1" style="min-width: 14rem">
              <Select v-model="searchFilter.sold_at" :options="[
                { name: 'Todos', value: '' },
                { name: 'Sim', value: 'sim' },
                { name: 'Não', value: 'nao' }
              ]" placeholder="Já vendido?" optionLabel="name" optionValue="value" />
              <DatePicker v-model="searchFilter.sold_at_from" dateFormat="dd/mm/yy" placeholder="De"
                showButtonBar showIcon fluid />
              <DatePicker v-model="searchFilter.sold_at_to" dateFormat="dd/mm/yy" placeholder="Até"
                showButtonBar showIcon fluid />
            </div>
          </template>
          <template #body="slotProps">
            {{ formatDateToBR(slotProps.data.sold_at) }}
          </template>
          <template #editor="{ data, field }">
            <InputText class="flex-auto" v-model="data[field]" @change="onEdit(data)" />
          </template>
        </Column>
        <Column field="expires_at" header="Data Expiração" sortable filterField="searchField" :showFilterMenu="true"
          :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0">
          <template #filter>
            <div class="d-flex flex-column gap-1" style="min-width: 14rem">
              <Select v-model="searchFilter.expires_at" :options="[
                { name: 'Todos', value: '' },
                { name: 'Sim', value: 'sim' },
                { name: 'Não', value: 'nao' }
              ]" placeholder="Expira?" optionLabel="name" optionValue="value" />
              <DatePicker v-model="searchFilter.expires_at_from" dateFormat="dd/mm/yy" placeholder="De"
                showButtonBar showIcon fluid />
              <DatePicker v-model="searchFilter.expires_at_to" dateFormat="dd/mm/yy" placeholder="Até"
                showButtonBar showIcon fluid />
            </div>
          </template>
          <template #body="slotProps">
            {{ formatDateToBR(slotProps.data.expires_at) }}
          </template>
          <template #editor="{ data, field }">
            <InputText class="flex-auto" v-model="data[field]" @change="onEdit(data)" />
          </template>
        </Column>
        <Column field="supplier_url" header="URL Fornecedor" filterField="searchField" :showFilterMenu="true"
          :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0"
          v-if="user && user.email === 'carcadeals@gmail.com'">
          <template #filter>
            <InputText v-model="searchFilter.supplier_url" type="text" placeholder="Pesquisar" />
          </template>
          <template #editor="{ data, field }">
            <InputText v-model="data[field]" @change="onEdit(data)"></InputText>
          </template>
        </Column>
      </DataTable>
      <Paginator :totalRecords="localTotalGames" :first="currentFirst"
        template="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink JumpToPageDropdown"
        :rows="pagination!.per_page" @page="handlePageChange"></Paginator>
      <p>Total: {{ localTotalGames }}</p>
    </div>
  </div>
</template>

<style scoped>
</style>
