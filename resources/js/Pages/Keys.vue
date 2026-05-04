<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
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
import Dialog from 'primevue/dialog';
import Toast from 'primevue/toast';
import { useToast } from "primevue/usetoast";
import ConfirmPopup from 'primevue/confirmpopup';
import { useConfirm } from "primevue/useconfirm";
import InputNumber from 'primevue/inputnumber';
import RadioButton from 'primevue/radiobutton';
import Select from 'primevue/select';
import DatePicker from 'primevue/datepicker';
import Paginator, { PageState } from 'primevue/paginator';
import MultiSelect from 'primevue/multiselect';
import ColorPicker from 'primevue/colorpicker';
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
const DialogVisible = ref(false); // Visibilidade do Dialog(modal)
const isEdit = ref(false); // Variável que define se é para criar ou editar no Dialog
const localTotalGames = ref(props.totalGames);
const ImportDialogVisible = ref(false); // Visibilidade do Dialog de Importação
const selectedFile = ref<File | null>(null);
const selectedNewObject = {
  id: 0,
  color: '',
  claim_type: 'Nenhuma',
  dont_sell: false,
  steam_id: '',
  gamivo_id: '',
  key_format: 'RK',
  key_code: '',
  is_duplicate: false,
  game_name: '',
  region: '',
  notes: '',
  sell_platform: 'Gamivo',
  market_price: null,
  minimum_sale_price: null,
  total_paid: '',
  individual_cost: null,
  min_api: null,
  max_api: null,
  sold_price: null,
  sale_profit: null,
  sale_profit_percent: null,
  acquired_at: null,
  listed_at: '',
  sold_at: '',
  supplier_url: '',
  tf2_quantity: null,
};

const selected = reactive([selectedNewObject]);

const sharedQtdTF2 = ref(null);
const sharedDataAdquirida = ref(null);
const sharedPerfilOrigem = ref('');
const sharedValorPagoTotal = ref('');

// Sincroniza o valor de tf2_quantity em todos os itens
watch(sharedQtdTF2, (newValue) => {
  selected.forEach(item => {
    item.tf2_quantity = newValue;
  });
});

watch(sharedDataAdquirida, (newValue) => {
  selected.forEach(item => {
    item.acquired_at = newValue;
  });
});

watch(sharedPerfilOrigem, (newValue) => {
  selected.forEach(item => {
    item.supplier_url = newValue;
  });
});

watch(sharedValorPagoTotal, (newValue) => {
  selected.forEach(item => {
    item.total_paid = newValue;
  });
});

const handleEditButton = (data: any) => {
  DialogVisible.value = true;
  isEdit.value = true;
  selected.splice(0, selected.length, ...data);
  sharedDataAdquirida.value = data[0].acquired_at;
  sharedPerfilOrigem.value = data[0].supplier_url;
  sharedValorPagoTotal.value = data[0].total_paid;
};

const onEdit = async (selected: any) => {
  isEdit.value = true;
  let product;
  if (Array.isArray(selected)) {
    product = { ...selected[0] };
    if (sharedDataAdquirida.value) {
      product.acquired_at = sharedDataAdquirida.value;
    }
    if (sharedPerfilOrigem.value !== '') {
      product.supplier_url = sharedPerfilOrigem.value;
    }
    if (sharedValorPagoTotal.value !== '') {
      product.total_paid = sharedValorPagoTotal.value;
    }
  } else {
    product = { ...selected };
    if (selected.acquired_at) {
      selected.acquired_at = identifyAndFormatDate(selected.acquired_at);
    }
    if (selected.listed_at) {
      selected.listed_at = identifyAndFormatDate(selected.listed_at);
    }
    if (selected.sold_at) {
      selected.sold_at = identifyAndFormatDate(selected.sold_at);
    }
    console.log(selected);
  }
  try {
    const res = await axiosInstance.put(`/keys/${product.id}`, product);
    // console.log(res.data);
    showResponse(res, toast.add);

    if (res.status === 200) {
      const itemToUpdate = rowData.find(item => item.id === product.id);
      if (itemToUpdate) {
        Object.assign(itemToUpdate, res.data.data);
      }
      DialogVisible.value = false;
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

const handleAddButton = async (): Promise<void> => { // Mostra o dialog com o elemento clicado
  try {
    const res = await axiosInstance.get(`/auth/logged`);
    // console.log(res.data.data);
    if (res.status === 400 || res.status === 401) {
      showResponse(res, toast.add);
      return;
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
  toast.add({
    severity: 'warn',
    summary: 'Atenção!',
    detail: 'Lembre-se de atualizar o valor da chave antes de adicionar os jogos.',
    life: 7000
  });
  isEdit.value = false;
  selected.splice(0, selected.length, { ...selectedNewObject }); // Zera o valor para criar um novo
  sharedQtdTF2.value = null;
  sharedDataAdquirida.value = new Date().toLocaleDateString('pt-BR');
  sharedPerfilOrigem.value = '';
  sharedValorPagoTotal.value = '';
  DialogVisible.value = true;
}

const onAdd = async (): Promise<void> => { // Faz a req pra api add o elemento
  selected.forEach(item => {
    if (item.acquired_at) {
      item.acquired_at = identifyAndFormatDate(item.acquired_at);
    }
    if (item.listed_at) {
      item.listed_at = identifyAndFormatDate(item.listed_at);
    }
    if (item.sold_at) {
      item.sold_at = identifyAndFormatDate(item.sold_at);
    }
  });

  try {
    // console.log(selected)
    const res = await axiosInstance.post(`/keys`, { games: selected });
    // console.log(res.data.data);
    showResponse(res, toast.add);
    if (res.status === 200 || res.status === 201) {
      DialogVisible.value = false;
      rowData.unshift(...res.data.data.reverse()); // Adiciona no início do array na ordem inversa da resposta enviada pelo servidor, para manter o DESC de id
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

const handleDeleteButton = (event: any, qtd: number) => {
  confirm.require({
    target: event.currentTarget,
    message: qtd === 1 ? 'Tem certeza que deseja excluir este item?' : 'Tem certeza que deseja excluir esses itens?',
    // icon: 'pi pi-info-circle',
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
      if (qtd === 1) {
        try {
          const res = await axiosInstance.delete(`/keys/${selected[0].id}`);
          showResponse(res, toast.add);
          if (res.status === 200 || res.status === 201) {
            const itemToDelete = rowData.findIndex(item => item.id === selected[0].id);
            // console.log(itemToDelete);
            rowData.splice(itemToDelete, 1);
            DialogVisible.value = false;
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
      } else {
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
  sell_platform: [],
  total_paid: '',
  acquired_at: '',
  listed_at: '',
  listed_at_range: null,
  sold_at: '',
  expires_at: '',
  supplier_url: '',
})

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
      data: method === 'POST' ? searchFilter : null
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

const getChaveRecebidaStyle = (data: GameLine) => {
  if (data.is_duplicate) {
    return {
      backgroundColor: '#ff0000', // Vermelho para duplicado
      color: '#ffffff', // Texto branco
    };
  }
  // Caso padrão, sem estilo
  return {};
};

const getStyleByPercentual = (data: GameLine, field: keyof GameLine) => {
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

const addOrRemove = (add: boolean) => {
  if (add) {
    selected.push({ ...selectedNewObject });
  } else {
    if (selected.length > 1) {
      selected.pop();
    }
  }
};

const handleImportButton = (): void => {
  ImportDialogVisible.value = true;
  selectedFile.value = null;
};

const downloadExampleFile = (): void => {
  window.location.href = '/keys/download-example_keys';
};

const handleFileSelect = (event: Event): void => {
  const target = event.target as HTMLInputElement;
  if (target.files && target.files.length > 0) {
    const file = target.files[0];
    // Verificar se é um arquivo XLSX
    if (file.name.endsWith('.xlsx') || file.name.endsWith('.xls')) {
      selectedFile.value = file;
    } else {
      toast.add({
        severity: 'error',
        summary: 'Erro',
        detail: 'Por favor, selecione um arquivo Excel (.xlsx ou .xls)',
        life: 5000
      });
      selectedFile.value = null;
    }
  }
};

const handleImportSubmit = async (): Promise<void> => {
  if (!selectedFile.value) {
    toast.add({
      severity: 'warn',
      summary: 'Atenção',
      detail: 'Por favor, selecione um arquivo para importar',
      life: 5000
    });
    return;
  }

  const formData = new FormData();
  formData.append('file', selectedFile.value);

  try {
    const res = await axiosInstance.post('/keys/import', formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });

    showResponse(res, toast.add);

    if (res.status === 200 || res.status === 201) {
      ImportDialogVisible.value = false;
      selectedFile.value = null;
      // Recarregar os dados da tabela
      await onPageChange(false);
    }
  } catch (error: any) {
    toast.add({
      severity: 'error',
      summary: 'Erro Interno, tente novamente.',
      detail: error.response?.data?.message || error.message,
      life: 7000
    });
    console.log(error);
  }
};

</script>

<template>
  <div class="w-100">
    <Toast position="bottom-right" />
    <ConfirmPopup />
    <Dialog v-model:visible="DialogVisible" modal :header="isEdit ? 'Editar' : 'Criar'"
      :style="{ width: '90%', paddingBottom: '5rem' }" maximizable>
      <span class="d-block mb-3" v-if="!isEdit">Insira os dados para criar.</span>
      <span class="d-block mb-3" v-if="isEdit">Edite os dados.</span>
      <span class="d-block mb-3"><strong>Dica:</strong> Utilize "shift + scroll" para navegar horizontalmente.</span>

      <Button class="flex-auto mb-3 me-2" v-if="!isEdit" @click="addOrRemove(true)" label="Adicionar jogo"
        icon="pi pi-plus" />
      <Button class="flex-auto mb-3" v-if="!isEdit" @click="addOrRemove(false)" label="Remover jogo" icon="pi pi-minus"
        severity="danger" />


      <div v-for="(item, index) in selected" class="d-flex flex-row gap-2">
        <div class="d-flex flex-column">
          <label class="fw-bold me-2">Cor</label>
          <div class="d-flex gap-5 mb-3">
            <ColorPicker v-model="item.color" format="hex" />
            <InputText v-model="item.color" placeholder="#000000" />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold">Reclamação</label>
          <div class="d-flex gap-5 mb-3">
            <Select v-model="item.claim_type" :options="props.claimTypes"
              class="w-full md:w-56" />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold">Formato</label>
          <div class="d-flex gap-5 mb-3">
            <Select v-model="item.key_format" :options="props.keyFormats"
              placeholder="Formato do Jogo" class="w-full md:w-56" />
          </div>
        </div>
        <div class="d-flex flex-column" v-if="user && user.email === 'carcadeals@gmail.com'">
          <label class="fw-bold">Chave Recebida*</label>
          <div class="d-flex gap-5 mb-3">
            <InputText class="flex-auto" v-model="item.key_code" />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold">Nome do jogo*</label>
          <div class="d-flex gap-5 mb-3">
            <InputText class="flex-auto" v-model="item.game_name" />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold">Região</label>
          <div class="d-flex gap-5 mb-3">
            <InputText class="flex-auto" v-model="item.region" />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold">Observação</label>
          <div class="d-flex gap-5 mb-3">
            <InputText class="flex-auto" v-model="item.notes" />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold">Plataforma</label>
          <div class="d-flex gap-5 mb-3">
            <Select v-model="item.sell_platform" :options="props.sellPlatforms"
              class="w-full md:w-56" />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold">Preço Mercado*</label>
          <div class="d-flex gap-5 mb-3">
            <InputNumber class="flex-auto" v-model="item.market_price" mode="decimal" showButtons :minFractionDigits="2"
              :maxFractionDigits="2" :min="0" useGrouping />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold">Preço Mínimo para Venda</label>
          <div class="d-flex gap-5 mb-3">
            <InputNumber class="flex-auto" v-model="item.minimum_sale_price" mode="decimal" showButtons
              :minFractionDigits="2" :maxFractionDigits="2" :min="0" useGrouping />
          </div>
        </div>
        <div v-if="!isEdit" class="d-flex flex-column">
          <label class="fw-bold">Quantidade de TF2*</label>
          <div class="d-flex gap-5 mb-3">
            <InputNumber class="flex-auto" v-model="sharedQtdTF2" mode="decimal" showButtons :minFractionDigits="2"
              :maxFractionDigits="2" useGrouping />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold">Valor Pago Total</label>
          <div class="d-flex gap-5 mb-3">
            <InputText class="flex-auto" v-model="sharedValorPagoTotal" />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold">Valor Vendido</label>
          <div class="d-flex gap-5 mb-3">
            <InputNumber class="flex-auto" v-model="item.sold_price" mode="decimal" showButtons :minFractionDigits="2"
              :maxFractionDigits="2" useGrouping />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold text-nowrap">Data Adquirida*</label>
          <div class="d-flex gap-5 mb-3">
            <InputText class="flex-auto" v-model="sharedDataAdquirida" />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold">Data posto a Venda</label>
          <div class="d-flex gap-5 mb-3">
            <InputText class="flex-auto" v-model="item.listed_at" />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold text-nowrap">Data Vendida</label>
          <div class="d-flex gap-5 mb-3">
            <InputText class="flex-auto" v-model="item.sold_at" />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold text-nowrap">Perfil/Origem*</label>
          <div class="d-flex gap-5 mb-3">
            <InputText class="flex-auto" v-model="sharedPerfilOrigem" />
          </div>
        </div>
      </div>

      <div class="d-flex justify-content-end gap-2 position-absolute bottom-0 end-0 p-3 botao-rodape">
        <Button type="button" label="Cancelar" severity="secondary" @click="DialogVisible = false"></Button>
        <Button type="button" label="Salvar" @click="isEdit ? onEdit(selected) : onAdd()"></Button>
      </div>
    </Dialog>

    <Dialog v-model:visible="ImportDialogVisible" modal header="Importar Jogos"
      :style="{ width: '50%' }">
      <div class="d-flex flex-column gap-3">
        <div class="alert alert-info d-flex align-items-center justify-content-between" role="alert">
          <div>
            <i class="pi pi-info-circle me-2"></i>
            <span>Use o arquivo de exemplo como referência para o formato correto.</span>
          </div>
          <Button
            type="button"
            label="Baixar Exemplo"
            icon="pi pi-download"
            severity="info"
            size="small"
            @click="downloadExampleFile"
          />
        </div>

        <span class="d-block mb-2">Selecione um arquivo Excel (.xlsx) para importar os jogos.</span>

        <div class="d-flex flex-column">
          <label class="fw-bold mb-2">Arquivo Excel</label>
          <input
            type="file"
            accept=".xlsx,.xls"
            @change="handleFileSelect"
            class="form-control"
          />
          <small class="text-muted mt-1" v-if="selectedFile">
            Arquivo selecionado: {{ selectedFile.name }}
          </small>
        </div>
      </div>

      <template #footer>
        <div class="d-flex justify-content-end gap-2">
          <Button type="button" label="Cancelar" severity="secondary" @click="ImportDialogVisible = false"></Button>
          <Button type="button" label="Importar" @click="handleImportSubmit" :disabled="!selectedFile"></Button>
        </div>
      </template>
    </Dialog>

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
            <div class="d-flex gap-2 flex-column flex-md-row">
              <Button label="Novo" aria-label="Novo" icon="pi pi-plus" @click="handleAddButton()" raised />
              <Button label="Importar" aria-label="Importar" icon="pi pi-file-import" @click="handleImportButton()" raised />
              <Button label="Deletar" :disabled="!selectedProduct || selectedProduct.length === 0" aria-label="Deletar"
                severity="danger" icon="pi pi-plus" @click="handleDeleteButton($event, 2)" raised />
            </div>
            <div class="d-flex gap-2 flex-column flex-md-row">
              <Button label="Pesquisar" aria-label="Pesquisar" severity="info" icon="pi pi-search"
                @click="onPageChange(true)" raised />
              <Button icon="pi pi-external-link" label="Exportar CSV" @click="exportCSV()" />

            </div>
          </div>
        </template>
        <template #empty>
          <h4>
            Nenhum item encontrado.
          </h4>
        </template>
        <!-- <Column selectionMode="multiple" headerStyle="width: 3rem"></Column> -->
        <Column field="id" header="ID" sortable></Column>
        <Column field="claim_type" header="Reclamação?" filterField="searchField" :showFilterMenu="true"
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
            <div :style="getChaveRecebidaStyle(data)" style="width: 100%; height: 100%;">
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
          :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0">
          <template #filter>
            <InputText v-model="searchFilter.notes" type="text" placeholder="Pesquisar" />
          </template>
          <template #editor="{ data, field }">
            <InputText v-model="data[field]" @change="onEdit(data)"></InputText>
          </template>
        </Column>
        <Column field="sell_platform" header="Plataforma" filterField="searchField" :showFilterMenu="true"
          :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0">
          <template #filter>
            <MultiSelect v-model="searchFilter.sell_platform"
              :options="props.sellPlatforms" placeholder="Pesquisar" style="min-width: 14rem">
            </MultiSelect>
          </template>
          <template #editor="{ data, field }">
            <Select v-model="data.sell_platform" :options="props.sellPlatforms"
              @change="onEdit(data)" />
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
        <Column field="minimum_sale_price" header="Preço Min. Venda" sortable class="text-center p-0">
          <template #body="slotProps">
            <span v-if="slotProps.data.minimum_sale_price">€ {{ slotProps.data.minimum_sale_price }}</span>
          </template>
          <template #editor="{ data, field }">
            <InputNumber v-model="data[field]" @update:modelValue="onEdit(data)" mode="decimal" :minFractionDigits="2"
              :maxFractionDigits="2" useGrouping autofocus fluid />
          </template>
        </Column>
        <Column field="total_paid" header="Valor Pago Total" filterField="searchField" :showFilterMenu="true"
          :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0">
          <template #filter>
            <InputText v-model="searchFilter.total_paid" type="text" placeholder="Pesquisar" />
          </template>
          <template #editor="{ data, field }">
            <InputText v-model="data[field]" @change="onEdit(data)"></InputText>
          </template>
        </Column>
        <Column field="individual_cost" header="Valor Pago Indiv." sortable class="text-center p-0">
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
            <div :style="getStyleByPercentual(slotProps.data, 'purchase_profit_percent')" style="width: 100%; height: 100%;">
              {{ slotProps.data.purchase_profit_percent }}%
            </div>
          </template>
        </Column>
        <Column field="sold_price" header="Valor Vendido" sortable class="text-center p-0">
          <template #body="slotProps">
            <span v-if="slotProps.data.sold_price">€ {{ slotProps.data.sold_price }}</span>
          </template>
          <template #editor="{ data, field }">
            <InputNumber v-model="data[field]" @update:modelValue="onEdit(data)" mode="decimal" :minFractionDigits="2"
              :maxFractionDigits="2" useGrouping autofocus fluid />
          </template>
        </Column>
        <Column field="sale_profit" header="Lucro Venda(€)" sortable class="text-center p-0">
          <template #body="slotProps">
            <span v-if="slotProps.data.sold_price">€ {{ slotProps.data.sale_profit }}</span>
          </template>
        </Column>
        <Column field="sale_profit_percent" header="Lucro Venda(%)" sortable class="text-center p-0">
          <template #body="slotProps">
            <div :style="getStyleByPercentual(slotProps.data, 'sale_profit_percent')"
              style="width: 100%; height: 100%;">
              {{ slotProps.data.sale_profit_percent }}%
            </div>
          </template>
        </Column>
        <Column field="acquired_at" header="Data Adquirida" filterField="searchField" :showFilterMenu="true"
          :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0">
          <template #filter>
            <!-- <DatePicker v-model="searchFilter.acquired_at" dateFormat="dd/mm/yy" showIcon fluid :showOnFocus="false"
              showButtonBar /> -->
          </template>
          <template #body="slotProps">
            {{ formatDateToBR(slotProps.data.acquired_at) }}
          </template>
          <template #editor="{ data, field }">
            <InputText class="flex-auto" v-model="data[field]" @change="onEdit(data)" />
          </template>
        </Column>
        <Column field="listed_at" header="Data posto a Venda" filterField="searchField" :showFilterMenu="true"
          :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0">
          <template #filter>
            <Select v-model="searchFilter.listed_at" :options="[
              { name: 'Sim', value: 'sim' },
              { name: 'Não', value: 'nao' }
            ]" placeholder="Já posto a venda?" optionLabel="name" optionValue="value" style="min-width: 14rem">
            </Select>
          </template>
          <template #body="slotProps">
            {{ formatDateToBR(slotProps.data.listed_at) }}
          </template>
          <template #editor="{ data, field }">
            <InputText class="flex-auto" v-model="data[field]" @change="onEdit(data)" />
          </template>
        </Column>
        <Column field="sold_at" header="Data Vendida" filterField="searchField" :showFilterMenu="true"
          :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0">
          <template #filter>
            <Select v-model="searchFilter.sold_at" :options="[
              { name: 'Sim', value: 'sim' },
              { name: 'Não', value: 'nao' }
            ]" placeholder="Já vendido?" optionLabel="name" optionValue="value" style="min-width: 14rem">
            </Select>
          </template>
          <template #body="slotProps">
            {{ formatDateToBR(slotProps.data.sold_at) }}
          </template>
          <template #editor="{ data, field }">
            <InputText class="flex-auto" v-model="data[field]" @change="onEdit(data)" />
          </template>
        </Column>
        <Column field="expires_at" header="Data Expiração" filterField="searchField" :showFilterMenu="true"
          :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0">
          <template #filter>
            <Select v-model="searchFilter.expires_at" :options="[
              { name: 'Sim', value: 'sim' },
              { name: 'Não', value: 'nao' }
            ]" placeholder="Expira?" optionLabel="name" optionValue="value" style="min-width: 14rem">
            </Select>
          </template>
          <template #body="slotProps">
            {{ formatDateToBR(slotProps.data.expires_at) }}
          </template>
          <template #editor="{ data, field }">
            <InputText class="flex-auto" v-model="data[field]" @change="onEdit(data)" />
          </template>
        </Column>
        <Column field="supplier_url" header="Perfil/Origem" filterField="searchField" :showFilterMenu="true"
          :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0"
          v-if="user && user.email === 'carcadeals@gmail.com'">
          <template #filter>
            <InputText v-model="searchFilter.supplier_url" type="text" placeholder="Pesquisar" />
          </template>
          <template #editor="{ data, field }">
            <InputText v-model="data[field]" @change="onEdit(data)"></InputText>
          </template>
        </Column>
        <Column header="Ação">
          <template #body="slotProps">
            <div class="d-flex gap-1">
              <Button label="Editar" aria-label="Editar" icon="pi pi-pencil" @click="handleEditButton([slotProps.data])"
                outlined />
              <Button label="Excluir" aria-label="Excluir" icon="pi pi-times"
                @click="handleDeleteButton($event, 1); Object.assign(selected, slotProps.data); selected[0].id = slotProps.data.id"
                severity="danger" outlined />
            </div>
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
.p-datatable {
  font-size: 0.90rem;
}

/* Reduz o espaçamento interno das células */
.p-datatable .p-datatable-tbody>tr>td {
  padding: 0.5rem;
}

/* Reduz o espaçamento interno no cabeçalho */
.p-datatable .p-datatable-thead>tr>th {
  padding: 0.5rem;
}
</style>
