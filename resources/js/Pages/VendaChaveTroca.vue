<script setup lang="ts">
import { reactive, ref } from 'vue';
import axiosInstance from '../axios';
import { GameLine } from '../types/GameLine';
import { formatDateToBR, formatDateToDB } from '../helpers/formatHelpers';

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

// onMouted {
let rowData: GameLine[] = reactive([]);
const props = defineProps({ games: Array, totalGames: Number, pagination: Object, tiposFormato: Array, tiposLeilao: Array, plataformas: Array, tiposReclamacao: Array });
console.log(props.tiposFormato);
Object.assign(rowData, props.games);
// }

// const columns = ref([ // Será importante para criar a tabela programaticamente
//   { field: 'id', header: 'ID' },
//   { field: 'fornecedor.quantidade_reclamacoes', header: 'Reclamações Anteriores' },
//   { field: 'tipo_reclamacao.name', header: 'Reclamação?' },
//   { field: 'steamId', header: 'SteamID?' },
//   { field: 'tipo_formato.name', header: 'Formato' },
//   { field: 'chaveRecebida', header: 'Chave Recebida' },
//   { field: 'nomeJogo', header: 'Nome do Jogo' },
//   { field: 'precoJogo', header: 'Preço do jogo' },
//   { field: 'notaMetacritic', header: 'Nota Metacritic' },
//   { field: 'isSteam', header: 'É Steam?' },
//   { field: 'randomClassificationG2A', header: 'Classificação G2A' },
//   { field: 'randomClassificationKinguin', header: 'Classificação Kinguin' },
//   { field: 'observacao', header: 'Observação' },
//   { field: 'leilao_g2_a.name', header: 'Leilão G2A' },
//   { field: 'leilao_gamivo.name', header: 'Leilão Gamivo' },
//   { field: 'leilao_kinguin.name', header: 'Leilão Kinguin' },
//   { field: 'plataforma.name', header: 'Plataforma' },
//   { field: 'precoCliente', header: 'Preço Cliente' },
//   { field: 'precoVenda', header: 'Preço Venda' },
//   { field: 'incomeReal', header: 'Income Real' },
//   { field: 'incomeSimulado', header: 'Income Simulado' },
//   { field: 'chaveEntregue', header: 'Chave Entregue' },
//   { field: 'valorPagoTotal', header: 'Valor Pago Total' },
//   { field: 'valorPagoIndividual', header: 'Valor Pago Individual' },
//   { field: 'vendido', header: 'Vendido' },
//   { field: 'leiloes', header: 'Leilões' },
//   { field: 'quantidade', header: 'Quantidade' },
//   { field: 'devolucoes', header: 'Devoluções' },
//   { field: 'lucroRS', header: 'Lucro(€)' },
//   { field: 'lucroPercentual', header: 'Lucro(%)' },
//   { field: 'dataAdquirida', header: 'Data Adquirida' },
//   { field: 'dataVenda', header: 'Data Venda' },
//   { field: 'dataVendida', header: 'Data Vendida' },
//   { field: 'perfilOrigem', header: 'Perfil/Origem' },
//   { field: 'email', header: 'Email' },
//   { field: 'incomeReal', header: 'Income Real' },
// ]);

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
const selectedNewObject = {
  id: 0,
  color: '',
  tipo_reclamacao_id: 1,
  steamId: '',
  tipo_formato_id: 1,
  chaveRecebida: '',
  repetido: false,
  nomeJogo: '',
  precoJogo: null,
  notaMetacritic: 0,
  isSteam: false,
  observacao: '',
  id_leilao_g2a: 1,
  id_leilao_gamivo: 1,
  id_leilao_kinguin: 1,
  id_plataforma: 3,
  precoCliente: null,
  chaveEntregue: '',
  valorPagoTotal: '',
  valorPagoIndividual: null,
  vendido: false,
  leiloes: 1,
  quantidade: 1,
  devolucoes: false,
  valorVendido: null,
  lucroVendaRS: null,
  lucroVendaPercentual: null,
  dataAdquirida: '',
  dataVenda: '',
  dataVendida: '',
  perfilOrigem: '',
  email: '',
  qtdTF2: null,
};

const selected = reactive([selectedNewObject]);

const handleEditButton = (data: any) => {
  DialogVisible.value = true;
  isEdit.value = true;
  selected.splice(0, selected.length, ...data);
  selected[0].tipo_formato_id = data[0].tipo_formato.id;
  selected[0].tipo_reclamacao_id = data[0].tipo_reclamacao.id;
  selected[0].id_leilao_g2a = data[0].leilao_g2_a.id;
  selected[0].id_leilao_gamivo = data[0].leilao_gamivo.id;
  selected[0].id_leilao_kinguin = data[0].leilao_kinguin.id;
  selected[0].id_plataforma = data[0].plataforma.id;
};

const onEdit = async (selected: any) => {
  isEdit.value = true;
  let product;
  if (Array.isArray(selected)) {
    product = { ...selected[0] };
    product['dataAdquirida'] = formatDateToDB(product.dataAdquirida);
    product['dataVenda'] = formatDateToDB(product.dataVenda);
    product['dataVendida'] = formatDateToDB(product.dataVendida);
  } else {
    product = { ...selected };
    product.tipo_reclamacao_id = selected.tipo_reclamacao.id;
    product.tipo_formato_id = selected.tipo_formato.id;
    product.id_plataforma = selected.plataforma.id;
    product.id_leilao_g2a = selected.leilao_g2_a.id;
    product.id_leilao_gamivo = selected.leilao_gamivo.id;
    product.id_leilao_kinguin = selected.leilao_kinguin.id;
  }
  try {
    const res = await axiosInstance.put(`/venda-chave-troca/${product.id}`, product);
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
  isEdit.value = false;
  selected.splice(0, selected.length, { ...selectedNewObject }); // Zera o valor para criar um novo
  DialogVisible.value = true;
}

const onAdd = async (): Promise<void> => { // Faz a req pra api add o elemento
  try {
    const res = await axiosInstance.post(`/venda-chave-troca`, { games: selected });
    // console.log(res.data);
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
        const res = await axiosInstance.delete(`/venda-chave-troca/${selected[0].id}`);
        showResponse(res, toast.add);
        const itemToDelete = rowData.findIndex(item => item.id === selected[0].id);
        console.log(itemToDelete);
        rowData.splice(itemToDelete, 1);
        DialogVisible.value = false;
      } else {
        const res = await axiosInstance.delete(`/venda-chave-troca`, {
          params: {
            games: selectedProduct.value
          }
        });
        showResponse(res, toast.add);
        const selectedProductIds = selectedProduct.value.map(item => item.id);
        const filteredRowData = rowData.filter(item => !selectedProductIds.includes(item.id));
        rowData.splice(0, rowData.length, ...filteredRowData);
        selectedProduct.value = null;
      }
    }
  });
};

const pagination = ref(props.pagination!); // Informações da paginação
const currentFirst = ref((pagination.value.current_page - 1) * pagination.value.per_page);
const isSearching = ref(false);

const searchFilter = reactive({
  tipo_reclamacao_id: [],
  steamId: '',
  tipo_formato_id: [],
  chaveRecebida: '',
  plataformaIdentificada: '',
  nomeJogo: '',
  isSteam: [],
  randomClassificationG2A: '',
  randomClassificationKinguin: '',
  id_plataforma: [],
  chaveEntregue: '',
  valorPagoTotal: '',
  vendido: [],
  devolucoes: [],
  dataAdquirida: '',
  dataVenda: '',
  dataVendida: '',
  perfilOrigem: '',
  email: '',
})

const handlePageChange = (event: PageState) => { // Teve que ser criada por que o evento não pode ser passado com outro argumento junto
  onPageChange(false, event);
};

// Função chamada ao mudar de página
const onPageChange = async (search: boolean, event: PageState | null = null) => {
  if (search) isSearching.value = true;
  const limit = event ? event.rows : 100;
  const page = event ? event.page + 1 : 1; // Paginator começa em 0. 1 como página padrão

  let url = `/venda-chave-troca/paginated?limit=${limit}&page=${page}`;
  let method = 'GET';

  if (isSearching.value) {
    searchFilter.dataAdquirida = formatDateToDB(searchFilter.dataAdquirida);
    searchFilter.dataVenda = formatDateToDB(searchFilter.dataVenda);
    searchFilter.dataVendida = formatDateToDB(searchFilter.dataVendida);
    url = `/venda-chave-troca/search?page=${page}`;
    method = 'POST';
  }

  try {
    const res = await axiosInstance(url, {
      method,
      data: method === 'POST' ? searchFilter : null
    });
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
  const styleMap = {
    2: '#ffcccc', // Vermelho claro
    3: '#ffcccc', // Vermelho claro 
    4: '#FFE066', // Amarelo claro
  };

  return data.color
    ? { backgroundColor: `#${data.color}` }
    : data.tipo_reclamacao && styleMap[data.tipo_reclamacao.id]
      ? { backgroundColor: styleMap[data.tipo_reclamacao.id] }
      : null;
};

const getChaveRecebidaStyle = (data: GameLine) => {
  if (data.repetido) {
    return {
      backgroundColor: '#ff0000', // Vermelho para repetido
      color: '#ffffff', // Texto branco
    };
  }
  // Caso padrão, sem estilo
  return {};
};

const getLucroPercentualStyle = (data: GameLine) => {
  const lucroPercentual = data.lucroPercentual ?? null;
  if (lucroPercentual === null) return {};

  const ranges = [
    { min: -Infinity, max: 0, backgroundColor: '#ff0000', color: '#ffffff' }, // Vermelho para valores abaixo de 0
    { min: 0, max: 50, backgroundColor: '#FFA500', color: '#000000' }, // Laranja entre 0 e 50
    { min: 50, max: 80, backgroundColor: '#FFFF00', color: '#000000' }, // Amarelo entre 50 e 80
    { min: 80, max: Infinity, backgroundColor: '#008000', color: '#ffffff' }, // Verde acima de 80
  ];

  const style = ranges.find(range => lucroPercentual >= range.min && lucroPercentual <= range.max);
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

</script>

<template>
  <div class="w-100">
    <Toast position="bottom-right" />
    <ConfirmPopup />
    <Dialog v-model:visible="DialogVisible" modal :header="isEdit ? 'Editar' : 'Criar'"
      :style="{ width: '80rem', paddingBottom: '5rem' }" maximizable>
      <span class="d-block mb-3" v-if="!isEdit">Insira os dados para criar.</span>
      <span class="d-block mb-3" v-if="isEdit">Edite os dados.</span>

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
            <Select v-model="item.tipo_reclamacao_id" :options="props.tiposReclamacao" optionLabel="name"
              optionValue="id" class="w-full md:w-56" />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold">SteamID</label>
          <div class="d-flex gap-5 mb-3">
            <InputText class="flex-auto" v-model="item.steamId" />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold">Formato</label>
          <div class="d-flex gap-5 mb-3">
            <Select v-model="item.tipo_formato_id" :options="props.tiposFormato" optionValue="id" optionLabel="name"
              placeholder="Formato do Jogo" class="w-full md:w-56" />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold">Chave Recebida*</label>
          <div class="d-flex gap-5 mb-3">
            <InputText class="flex-auto" v-model="item.chaveRecebida" />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold">Nome do jogo*</label>
          <div class="d-flex gap-5 mb-3">
            <InputText class="flex-auto" v-model="item.nomeJogo" />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold">Preço do Jogo*</label>
          <div class="d-flex gap-5 mb-3">
            <InputNumber class="flex-auto" v-model="item.precoJogo" mode="decimal" showButtons :minFractionDigits="2"
              :maxFractionDigits="2" useGrouping />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold">Nota Metacritic</label>
          <div class="d-flex gap-5 mb-3">
            <InputNumber class="flex-auto" v-model.number="item.notaMetacritic" showButtons :min="0" :max="100" />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold">É Steam?</label>
          <div class="d-flex  gap-2 mb-3">
            <label for="ingredient1" class="">Sim</label>
            <RadioButton v-model="item.isSteam" :value="true" />
            <label for="ingredient1" class="">Não</label>
            <RadioButton v-model="item.isSteam" :value="false" />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold">Observação</label>
          <div class="d-flex gap-5 mb-3">
            <InputText class="flex-auto" v-model="item.observacao" />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold text-nowrap">Leilão G2A</label>
          <div class="d-flex gap-5 mb-3">
            <Select v-model="item.id_leilao_g2a" :options="props.tiposLeilao" optionLabel="name" optionValue="id"
              class="w-full md:w-56" />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold text-nowrap">Leilão Gamivo</label>
          <div class="d-flex gap-5 mb-3">
            <Select v-model="item.id_leilao_gamivo" :options="props.tiposLeilao" optionLabel="name" optionValue="id"
              class="w-full" />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold text-nowrap">Leilão Kinguin</label>
          <div class="d-flex gap-5 mb-3">
            <Select v-model="item.id_leilao_kinguin" :options="props.tiposLeilao" optionLabel="name" optionValue="id"
              class="w-full md:w-56" />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold">Plataforma</label>
          <div class="d-flex gap-5 mb-3">
            <Select v-model="item.id_plataforma" :options="props.plataformas" optionLabel="name" optionValue="id"
              class="w-full md:w-56" />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold">Preço Cliente*</label>
          <div class="d-flex gap-5 mb-3">
            <InputNumber class="flex-auto" v-model="item.precoCliente" mode="decimal" showButtons :minFractionDigits="2"
              :maxFractionDigits="2" :min="0" useGrouping />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold">Quantidade de TF2*</label>
          <div class="d-flex gap-5 mb-3">
            <InputNumber class="flex-auto" v-model="item.qtdTF2" mode="decimal" showButtons :minFractionDigits="2"
              :maxFractionDigits="2" useGrouping />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold">Chave Entregue</label>
          <div class="d-flex gap-5 mb-3">
            <InputText class="flex-auto" v-model="item.chaveEntregue" />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold">Valor Pago Total</label>
          <div class="d-flex gap-5 mb-3">
            <InputText class="flex-auto" v-model="item.valorPagoTotal" />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold">Vendido</label>
          <div class="d-flex gap-2 mb-3">
            <!-- <InputText  class="flex-auto" v-model="item.name" /> -->
            <label for="ingredient1">Sim</label>
            <RadioButton v-model="item.vendido" :value="true" />
            <label for="ingredient1">Não</label>
            <RadioButton v-model="item.vendido" :value="false" />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold">Leilões</label>
          <div class="d-flex gap-5 mb-3">
            <InputNumber class="flex-auto" v-model="item.leiloes" showButtons :min="0" />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold">Quantidade</label>
          <div class="d-flex gap-5 mb-3">
            <InputNumber class="flex-auto" v-model="item.quantidade" showButtons :min="0" />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold">Devoluções</label>
          <div class="d-flex gap-2 mb-3">
            <label>Sim</label>
            <RadioButton v-model="item.devolucoes" :value="true" />
            <label>Não</label>
            <RadioButton v-model="item.devolucoes" :value="false" />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold">Valor Vendido</label>
          <div class="d-flex gap-5 mb-3">
            <InputNumber class="flex-auto" v-model="item.valorVendido" mode="decimal" showButtons :minFractionDigits="2"
              :maxFractionDigits="2" useGrouping />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold text-nowrap">Data Adquirida*</label>
          <div class="d-flex gap-5 mb-3">
            <DatePicker v-model="item.dataAdquirida" dateFormat="dd/mm/yy" showIcon fluid :showOnFocus="false"
              showButtonBar style="min-width: 10rem" />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold">Data Venda</label>
          <div class="d-flex gap-5 mb-3">
            <DatePicker v-model="item.dataVenda" dateFormat="dd/mm/yy" showIcon fluid :showOnFocus="false" showButtonBar
              style="min-width: 10rem" />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold text-nowrap">Data Vendida</label>
          <div class="d-flex gap-5 mb-3">
            <DatePicker v-model="item.dataVendida" dateFormat="dd/mm/yy" showIcon fluid :showOnFocus="false"
              showButtonBar style="min-width: 10rem" />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold text-nowrap">Perfil/Origem*</label>
          <div class="d-flex gap-5 mb-3">
            <InputText class="flex-auto" v-model="item.perfilOrigem" />
          </div>
        </div>
        <div class="d-flex flex-column">
          <label class="fw-bold me-2">Email</label>
          <div class="d-flex gap-5 mb-3">
            <InputText class="flex-auto" v-model="item.email" />
          </div>
        </div>
      </div>

      <div class="d-flex justify-content-end gap-2 position-absolute bottom-0 end-0 p-3 botao-rodape">
        <Button type="button" label="Cancelar" severity="secondary" @click="DialogVisible = false"></Button>
        <Button type="button" label="Salvar" @click="isEdit ? onEdit(selected) : onAdd()"></Button>
      </div>
    </Dialog>

    <div class="text-center mb-3 mx-5">
      <h1>Venda-Chave-Troca</h1>
      <div class="w-50 m-auto">
        <p>Lista de jogos(chaves) vendidos, para vender e para trocar.</p>
      </div>
      <DataTable :value="rowData" showGridlines resizableColumns reorderableColumns sortMode="multiple" removableSort
        v-model:filters="filters" filterDisplay="menu" v-model:selection="selectedProduct" selectionMode="multiple"
        scrollable scrollHeight="95vh" editMode="cell" dataKey="id" size="small" tableStyle="min-width: 50rem"
        :rowStyle="getRowStyle" ref="dt">
        <template #header>
          <div class="d-flex justify-content-between">
            <div class="d-flex gap-2 flex-column flex-md-row">
              <Button label="Novo" aria-label="Novo" icon="pi pi-plus" @click="handleAddButton()" raised />
              <Button label="Deletar" :disabled="!selectedProduct || selectedProduct.length === 0" aria-label="Deletar"
                severity="danger" icon="pi pi-plus" @click="handleDeleteButton($event, 2)" raised />
            </div>
            <div class="d-flex gap-2 flex-column flex-md-row">
              <!-- <MultiSelect :modelValue="selectedColumns" :options="columns" optionLabel="header"
              @update:modelValue="onToggle" placeholder="Selecione Colunas" :maxSelectedLabels="3" /> -->
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
        <Column field="fornecedor.quantidade_reclamacoes" header="Reclamações Anteriores">
        </Column>
        <Column field="tipo_reclamacao.name" header="Reclamação?" filterField="searchField" :showFilterMenu="true"
          :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0">
          <template #filter>
            <MultiSelect v-model="searchFilter.tipo_reclamacao_id" :options="props.tiposReclamacao" optionLabel="name"
              optionValue="id" style="min-width: 14rem">
            </MultiSelect>
          </template>
          <template #editor="{ data, field }">
            <Select v-model="data.tipo_reclamacao.id" :options="props.tiposReclamacao" @change="onEdit(data)"
              optionLabel="name" optionValue="id" />
          </template>
        </Column>
        <Column field="steamId" header="SteamID" filterField="searchField" :showFilterMenu="true"
          :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0">
          <template #filter>
            <InputText v-model="searchFilter.steamId" type="text" placeholder="Pesquisar" />
          </template>
          <template #editor="{ data, field }">
            <InputText v-model="data[field]" @change="onEdit(data)"></InputText>
          </template>
        </Column>
        <Column field="tipo_formato.name" header="Formato" filterField="searchField" :showFilterMenu="true"
          :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0">
          <template #filter>
            <MultiSelect v-model="searchFilter.tipo_formato_id" :options="props.tiposFormato" optionLabel="name"
              optionValue="id" style="min-width: 14rem">
            </MultiSelect>
          </template>
          <template #editor="{ data, field }">
            <Select v-model="data.tipo_formato.id" :options="props.tiposFormato" @change="onEdit(data)"
              optionLabel="name" optionValue="id" />
          </template>
        </Column>
        <Column field="chaveRecebida" header="Chave Recebida" filterField="searchField" :showFilterMenu="true"
          :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0">
          <template #filter>
            <InputText v-model="searchFilter.chaveRecebida" type="text" placeholder="Pesquisar" />
          </template>
          <template #body="{ data }">
            <div :style="getChaveRecebidaStyle(data)" style="width: 100%; height: 100%;">
              {{ data.chaveRecebida }}
            </div>
          </template>
          <template #editor="{ data, field }">
            <InputText v-model="data[field]" @change="onEdit(data)"></InputText>
          </template>
        </Column>
        <Column field="plataformaIdentificada" header="Plataforma Identificada" filterField="searchField"
          :showFilterMenu="true" :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false"
          class="text-center p-0">
          <template #filter>
            <InputText v-model="searchFilter.plataformaIdentificada" type="text" placeholder="Pesquisar" />
          </template>
        </Column>
        <Column field="nomeJogo" header="Nome do Jogo" filterField="searchField" :showFilterMenu="true"
          :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0">
          <template #filter>
            <InputText v-model="searchFilter.nomeJogo" type="text" placeholder="Pesquisar" />
          </template>
          <template #editor="{ data, field }">
            <InputText v-model="data[field]" @change="onEdit(data)"></InputText>
          </template>
        </Column>
        <Column field="precoJogo" header="Preço do jogo" sortable class="text-center p-0">
          <template #body="slotProps">
            € {{ slotProps.data.precoJogo }}
          </template>
          <template #editor="{ data, field }">
            <InputNumber v-model="data[field]" @change="onEdit(data)" mode="decimal" :minFractionDigits="2"
              :maxFractionDigits="2" useGrouping autofocus fluid />
          </template>
        </Column>
        <Column field="notaMetacritic" header="Nota Metacritic" sortable class="text-center p-0">
          <template #editor="{ data, field }">
            <InputNumber v-model="data[field]" @change="onEdit(data)" mode="decimal" :max="100" useGrouping autofocus
              fluid />
          </template>
        </Column>
        <Column field="isSteam" header="É Steam?" filterField="searchField" :showFilterMenu="true"
          :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0">
          <template #filter>
            <MultiSelect v-model="searchFilter.isSteam" :options="[{ name: true }, { name: false }]" optionLabel="name"
              optionValue="name" style="min-width: 14rem">
            </MultiSelect>
          </template>
          <template #body="{ data }">
            <i class="pi m-1 fw-bold" :class="[
              data.isSteam === true ? 'pi-check-circle' :
                data.isSteam === false ? 'pi-times-circle' : 'pi-question',
              data.isSteam === true ? 'text-primary' :
                data.isSteam === false ? 'text-danger' : ''
            ]">
            </i>
          </template>
          <template #editor="{ data, field }">
            <Select v-model="data.isSteam" :options="[{ label: 'Sim', value: true }, { label: 'Não', value: false }]"
              @change="onEdit(data)" optionLabel="label" optionValue="value" />
          </template>
        </Column>
        <Column field="randomClassificationG2A" header="Classificação G2A" filterField="searchField"
          :showFilterMenu="true" :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false"
          class="text-center p-0">
          <template #filter>
            <InputText v-model="searchFilter.randomClassificationG2A" type="text" placeholder="Pesquisar" />
          </template>
        </Column>
        <Column field="randomClassificationKinguin" header="Classificação Kinguin" filterField="searchField"
          :showFilterMenu="true" :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false"
          class="text-center p-0">
          <template #filter>
            <InputText v-model="searchFilter.randomClassificationKinguin" type="text" placeholder="Pesquisar" />
          </template>
        </Column>
        <Column field="observacao" header="Observação" class="text-center p-0">
          <template #editor="{ data, field }">
            <InputText v-model="data[field]" @change="onEdit(data)"></InputText>
          </template>
        </Column>
        <Column field="leilao_g2_a.name" header="Leilão G2A" class="text-center p-0">
          <template #body="{ data }">
            <i class="pi m-1 fw-bold" :class="[
              data.leilao_g2_a.id === 1 ? 'pi-check-circle' :
                data.leilao_g2_a.id === 2 ? 'pi-check-circle' :
                  data.leilao_g2_a.id === 3 ? 'pi-times-circle' : 'pi-question',
              data.leilao_g2_a.id === 2 ? 'text-primary' :
                data.leilao_g2_a.id === 3 ? 'text-danger' : ''
            ]">
            </i>
          </template>
          <template #editor="{ data, field }">
            <Select v-model="data.leilao_g2_a.id" :options="props.tiposLeilao" @change="onEdit(data)" optionLabel="name"
              optionValue="id" />
          </template>
        </Column>
        <Column field="leilao_gamivo.name" header="Leilão Gamivo" class="text-center p-0">
          <template #body="{ data }">
            <i class="pi m-1 fw-bold" :class="[
              data.leilao_gamivo.id === 1 ? 'pi-check-circle' :
                data.leilao_gamivo.id === 2 ? 'pi-check-circle' :
                  data.leilao_gamivo.id === 3 ? 'pi-times-circle' : 'pi-question',
              data.leilao_gamivo.id === 2 ? 'text-primary' :
                data.leilao_gamivo.id === 3 ? 'text-danger' : ''
            ]">
            </i>
          </template>
          <template #editor="{ data, field }">
            <Select v-model="data.leilao_gamivo.id" :options="props.tiposLeilao" @change="onEdit(data)"
              optionLabel="name" optionValue="id" />
          </template>
        </Column>
        <Column field="leilao_kinguin.name" header="Leilão Kinguin" class="text-center p-0">
          <template #body="{ data }">
            <i class="pi m-1 fw-bold" :class="[
              data.leilao_kinguin.id === 1 ? 'pi-check-circle' :
                data.leilao_kinguin.id === 2 ? 'pi-check-circle' :
                  data.leilao_kinguin.id === 3 ? 'pi-times-circle' : 'pi-question',
              data.leilao_kinguin.id === 2 ? 'text-primary' :
                data.leilao_kinguin.id === 3 ? 'text-danger' : ''
            ]">
            </i>
          </template>
          <template #editor="{ data, field }">
            <Select v-model="data.leilao_kinguin.id" :options="props.tiposLeilao" @change="onEdit(data)"
              optionLabel="name" optionValue="id" />
          </template>
        </Column>
        <Column field="plataforma.name" header="Plataforma" filterField="searchField" :showFilterMenu="true"
          :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0">
          <template #filter>
            <MultiSelect v-model="searchFilter.id_plataforma" :options="props.plataformas" optionLabel="name"
              optionValue="id" style="min-width: 14rem">
            </MultiSelect>
          </template>
          <template #editor="{ data, field }">
            <Select v-model="data.plataforma.id" :options="props.plataformas" @change="onEdit(data)" optionLabel="name"
              optionValue="id" />
          </template>
        </Column>
        <Column field="precoCliente" header="Preço Cliente" sortable class="text-center p-0">
          <template #body="slotProps">
            € {{ slotProps.data.precoCliente }}
          </template>
          <template #editor="{ data, field }">
            <InputNumber v-model="data[field]" @change="onEdit(data)" mode="decimal" :minFractionDigits="2"
              :maxFractionDigits="2" useGrouping autofocus fluid />
          </template>
        </Column>
        <Column field="precoVenda" header="Preço Venda" sortable class="text-center p-0">
          <template #body="slotProps">
            € {{ slotProps.data.precoVenda }}
          </template>
        </Column>
        <Column field="incomeReal" header="Income Real" sortable class="text-center p-0">
          <template #body="slotProps">
            € {{ slotProps.data.incomeReal }}
          </template>
        </Column>
        <Column field="incomeSimulado" header="Income Simulado" sortable class="text-center p-0">
          <template #body="slotProps">
            € {{ slotProps.data.incomeSimulado }}
          </template>
        </Column>
        <Column field="chaveEntregue" header="Chave Entregue" filterField="searchField" :showFilterMenu="true"
          :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0">
          <template #filter>
            <InputText v-model="searchFilter.chaveEntregue" type="text" placeholder="Pesquisar" />
          </template>
          <template #editor="{ data, field }">
            <InputText v-model="data[field]" @change="onEdit(data)"></InputText>
          </template>
        </Column>
        <Column field="valorPagoTotal" header="Valor Pago Total" filterField="searchField" :showFilterMenu="true"
          :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0">
          <template #filter>
            <InputText v-model="searchFilter.valorPagoTotal" type="text" placeholder="Pesquisar" />
          </template>
          <template #editor="{ data, field }">
            <InputText v-model="data[field]" @change="onEdit(data)"></InputText>
          </template>
        </Column>
        <Column field="valorPagoIndividual" header="Valor Pago Individual" sortable class="text-center p-0">
          <template #body="slotProps">
            € {{ slotProps.data.valorPagoIndividual }}
          </template>
        </Column>
        <Column field="vendido" header="Vendido" filterField="searchField" :showFilterMenu="true"
          :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0">
          <template #filter>
            <MultiSelect v-model="searchFilter.vendido" :options="[{ name: true }, { name: false }]" optionLabel="name"
              optionValue="name" style="min-width: 14rem">
            </MultiSelect>
          </template>
          <template #body="{ data }">
            <i class="pi m-1 fw-bold" :class="[
              data.vendido === true ? 'pi-check-circle' :
                data.vendido === false ? 'pi-times-circle' : 'pi-question',
              data.vendido === true ? 'text-primary' :
                data.vendido === false ? 'text-danger' : ''
            ]">
            </i>
          </template>
          <template #editor="{ data, field }">
            <Select v-model="data[field]" :options="[{ label: 'Sim', value: true }, { label: 'Não', value: false }]"
              @change="onEdit(data)" optionLabel="label" optionValue="value" />
          </template>
        </Column>
        <Column field="leiloes" header="Leilões" sortable class="text-center p-0">
          <template #editor="{ data, field }">
            <InputNumber v-model="data[field]" @change="onEdit(data)" mode="decimal" :min="0" useGrouping autofocus
              fluid />
          </template>
        </Column>
        <Column field="quantidade" header="Quantidade" sortable class="text-center p-0">
          <template #editor="{ data, field }">
            <InputNumber v-model="data[field]" @change="onEdit(data)" mode="decimal" :min="0" useGrouping autofocus
              fluid />
          </template>
        </Column>
        <Column field="devolucoes" header="Devoluções" filterField="searchField" :showFilterMenu="true"
          :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0">
          <template #filter>
            <MultiSelect v-model="searchFilter.devolucoes" :options="[{ name: true }, { name: false }]"
              optionLabel="name" optionValue="name" style="min-width: 14rem">
            </MultiSelect>
          </template>
          <template #body="{ data }">
            <i class="pi m-1 fw-bold" :class="[
              data.devolucoes === true ? 'pi-check-circle' :
                data.devolucoes === false ? 'pi-times-circle' : 'pi-question',
              data.devolucoes === true ? 'text-primary' :
                data.devolucoes === false ? 'text-danger' : ''
            ]">
            </i>
          </template>
          <template #editor="{ data, field }">
            <Select v-model="data[field]" :options="[{ label: 'Sim', value: true }, { label: 'Não', value: false }]"
              @change="onEdit(data)" optionLabel="label" optionValue="value" />
          </template>
        </Column>
        <Column field="lucroRS" header="Lucro(€)" sortable class="text-center p-0">
          <template #body="slotProps">
            € {{ slotProps.data.lucroRS }}
          </template>
        </Column>
        <Column field="lucroPercentual" header="Lucro(%)" sortable class="text-center p-0">
          <template #body="slotProps">
            <div :style="getLucroPercentualStyle(slotProps.data)" style="width: 100%; height: 100%;">
              {{ slotProps.data.lucroPercentual }}%
            </div>
          </template>
        </Column>
        <Column field="valorVendido" header="Valor Vendido" sortable class="text-center p-0">
          <template #body="slotProps">
            € {{ slotProps.data.valorVendido }}
          </template>
        </Column>
        <Column field="lucroVendaRS" header="Lucro Venda(€)" sortable class="text-center p-0">
          <template #body="slotProps">
            € {{ slotProps.data.lucroVendaRS }}
          </template>
        </Column>
        <Column field="lucroVendaPercentual" header="Lucro Venda(%)" sortable class="text-center p-0">
          <template #body="slotProps">
            <div :style="getLucroPercentualStyle(slotProps.data)" style="width: 100%; height: 100%;">
              {{ slotProps.data.lucroVendaPercentual }}%
            </div>
          </template>
        </Column>
        <Column field="dataAdquirida" header="Data Adquirida" filterField="searchField" :showFilterMenu="true"
          :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0">
          <template #filter>
            <DatePicker v-model="searchFilter.dataAdquirida" dateFormat="dd/mm/yy" showIcon fluid :showOnFocus="false"
              showButtonBar />
          </template>
          <template #body="slotProps">
            {{ formatDateToBR(slotProps.data.dataAdquirida) }}
          </template>
          <template #editor="{ data, field }">
            <DatePicker v-model="data[field]" @change="onEdit(data)" dateFormat="dd/mm/yy" showIcon fluid
              :showOnFocus="false" showButtonBar />
          </template>
        </Column>
        <Column field="dataVenda" header="Data Venda" filterField="searchField" :showFilterMenu="true"
          :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0">
          <template #filter>
            <DatePicker v-model="searchFilter.dataVenda" dateFormat="dd/mm/yy" showIcon fluid :showOnFocus="false"
              showButtonBar />
          </template>
          <template #body="slotProps">
            {{ formatDateToBR(slotProps.data.dataVenda) }}
          </template>
          <template #editor="{ data, field }">
            <DatePicker v-model="data[field]" @change="onEdit(data)" dateFormat="dd/mm/yy" showIcon fluid
              :showOnFocus="false" showButtonBar />
          </template>
        </Column>
        <Column field="dataVendida" header="Data Vendida" filterField="searchField" :showFilterMenu="true"
          :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0">
          <template #filter>
            <DatePicker v-model="searchFilter.dataVendida" dateFormat="dd/mm/yy" showIcon fluid :showOnFocus="false"
              showButtonBar />
          </template>
          <template #body="slotProps">
            {{ formatDateToBR(slotProps.data.dataVendida) }}
          </template>
          <template #editor="{ data, field }">
            <DatePicker v-model="data[field]" @change="onEdit(data)" dateFormat="dd/mm/yy" showIcon fluid
              :showOnFocus="false" showButtonBar />
          </template>
        </Column>
        <Column field="perfilOrigem" header="Perfil/Origem" filterField="searchField" :showFilterMenu="true"
          :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0">
          <template #filter>
            <InputText v-model="searchFilter.perfilOrigem" type="text" placeholder="Pesquisar" />
          </template>
          <template #editor="{ data, field }">
            <InputText v-model="data[field]" @change="onEdit(data)"></InputText>
          </template>
        </Column>
        <Column field="email" header="Email" filterField="searchField" :showFilterMenu="true"
          :showFilterMatchModes="false" :showApplyButton="false" :showClearButton="false" class="text-center p-0">
          <template #filter>
            <InputText v-model="searchFilter.email" type="text" placeholder="Pesquisar" />
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
                @click="handleDeleteButton($event, 1); Object.assign(selected, slotProps.data);" severity="danger"
                outlined />
            </div>
          </template>
        </Column>
      </DataTable>
      <Paginator :totalRecords="localTotalGames" :first="currentFirst" :rowsPerPageOptions="[100, 200, 300]"
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