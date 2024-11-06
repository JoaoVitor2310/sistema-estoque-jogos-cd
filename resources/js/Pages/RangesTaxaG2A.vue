<script setup lang="ts">
import { reactive, ref } from 'vue';
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
import InputNumber from 'primevue/inputnumber';

// Inertia
import { showResponse } from '../helpers/showResponse';

// onMouted {
let rowData: any[] = reactive([]);
const props = defineProps({ taxas: Array });
console.log(props.taxas)
Object.assign(rowData, props.taxas);
// }

const filters = ref({
  global: { value: null, matchMode: FilterMatchMode.CONTAINS },
  minimo: { value: null, matchMode: FilterMatchMode.CONTAINS },
  maximo: { value: null, matchMode: FilterMatchMode.CONTAINS },
  taxa: { value: null, matchMode: FilterMatchMode.CONTAINS },
});

const toast = useToast();
const confirm = useConfirm();

const selectedProduct = ref();
const DialogVisible = ref(false); // Visibilidade do Dialog(modal)
const isEdit = ref(false); // Variável que define se é para criar ou editar no Dialog

const selected = reactive({
  id: null,
  minimo: null,
  maximo: null,
  taxa: null,
})

const onEdit = async (product: any) => {
  isEdit.value = true;
  const res = await axiosInstance.put(`/ranges-g2a/${product.id}`, {
    minimo: product.minimo,
    maximo: product.maximo,
    taxa: product.taxa
  });
  showResponse(res, toast.add);
  console.log(res.data);

  if (res.status === 200) {
    const itemToUpdate = rowData.find(item => item.id === product.id);
    console.log(itemToUpdate);
    if (itemToUpdate) {
      Object.assign(itemToUpdate, res.data.data);
    }
    console.log(rowData);
  }
  DialogVisible.value = false;

}

const handleAddButton = async (): Promise<void> => { // Mostra o dialog com o elemento clicado
  isEdit.value = false;
  Object.assign(selected, { // Zera o valor de selected para criar
    id: null,
    minimo: null,
    maximo: null,
    taxa: null,
  });
  DialogVisible.value = true;
}

const onAdd = async (newFee: any): Promise<void> => { // Faz a req pra api add o elemento
  try {
    const res = await axiosInstance.post(`/ranges-g2a`, newFee);
    showResponse(res, toast.add);
    DialogVisible.value = false;
    rowData.push(res.data.data);
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
        const res = await axiosInstance.delete(`/ranges-g2a/${selected.id}`);
        showResponse(res, toast.add);
        const itemToDelete = rowData.findIndex(item => item.id === selected.id);
        console.log(itemToDelete);
        rowData.splice(itemToDelete, 1);
        DialogVisible.value = false;
      } else {
        const res = await axiosInstance.delete(`/ranges-g2a`, {
          params: {
            taxas: selectedProduct.value
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

// const handleDeleteSelected = async (): Promise<void> => {

// };

</script>

<template>
  <Toast position="bottom-right" />
  <ConfirmPopup />
  <Dialog v-model:visible="DialogVisible" modal :header="isEdit ? 'Editar' : 'Criar'" :style="{ width: '50rem' }">
    <span class="d-block mb-3" v-if="!isEdit">Insira os dados para criar.</span>
    <span class="d-block mb-3" v-if="isEdit">Edite os dados.</span>
    <div class="d-flex flex-column gap-2">

      <div class="d-flex  align-items-center gap-4 mb-auto">
        <label class="font-semibold w-24">Mínimo</label>
        <InputNumber v-model="selected.minimo" mode="decimal" :minFractionDigits="3" :maxFractionDigits="3"
          useGrouping />
      </div>

      <div class="d-flex align-items-center gap-4 mb-auto">
        <label>Máximo</label>
        <InputNumber v-model="selected.maximo" mode="decimal" :minFractionDigits="3" :maxFractionDigits="3"
          useGrouping />
      </div>

      <div class="d-flex align-items-center gap-5 mb-auto">
        <label>Taxa</label>
        <InputNumber v-model="selected.taxa" mode="decimal" :minFractionDigits="3" :maxFractionDigits="3" useGrouping />
      </div>
    </div>
    <div class="d-flex justify-content-end gap-2">
      <Button type="button" label="Cancelar" severity="secondary" @click="DialogVisible = false"></Button>
      <Button type="button" label="Salvar" @click="isEdit ? onEdit(selected) : onAdd(selected)"></Button>
    </div>
  </Dialog>

  <div class="container text-center mb-3">

    <h1>Ranges G2A</h1>
    <div class="w-50 m-auto">
      <p>Faixas de taxas na G2A. Mínimo e Máximo são sobre o valor do jogo, já a taxa será quantos % será acrecentado no
        valor. Obs: a taxa está em decimal, para saber em percentual, basta multiplicar por 100.</p>
    </div>

    <DataTable :value="rowData" showGridlines sortMode="multiple" removableSort
      :globalFilterFields="['minimo', 'maximo', 'taxa']" v-model:filters="filters" v-model:selection="selectedProduct"
      selectionMode="multiple" scrollable scrollHeight="100vh" editMode="cell" dataKey="id" size="large"
      tableStyle="min-width: 50rem">
      <template #header>
        <div class="d-flex justify-content-between">
          <div class="d-flex gap-2">
            <Button label="Novo" aria-label="Novo" icon="pi pi-plus" @click="handleAddButton()" raised />
            <Button label="Deletar" :disabled="!selectedProduct || selectedProduct.length === 0" aria-label="Deletar"
              severity="danger" icon="pi pi-plus" @click="handleDeleteButton($event, 2)" raised />
          </div>
          <div class="w-25">
            <InputGroup>
              <InputGroupAddon>
                <i class="pi pi-search" />
              </InputGroupAddon>
              <InputText v-model="filters['global'].value" placeholder="Pesquisar" />
            </InputGroup>
          </div>
        </div>
      </template>
      <template #empty> Nenhum item encontrado. </template>
      <Column field="id" header="ID" sortable></Column>
      <Column field="minimo" header="Minimo" sortable>
        <template #editor="{ data, field }">
          <InputNumber v-model="data[field]" @blur="onEdit(data)" mode="decimal" :minFractionDigits="2"
            :maxFractionDigits="2" useGrouping autofocus fluid />
        </template>
      </Column>
      <Column field="maximo" header="Máximo" sortable>
        <template #editor="{ data, field }">
          <InputNumber v-model="data[field]" @blur="onEdit(data)" mode="decimal" :minFractionDigits="2"
            :maxFractionDigits="2" useGrouping autofocus fluid />
        </template>
      </Column>
      <Column field="taxa" header="Taxa" sortable>
        <template #editor="{ data, field }">
          <InputNumber v-model="data[field]" @blur="onEdit(data)" mode="decimal" :minFractionDigits="2"
            :maxFractionDigits="2" useGrouping autofocus fluid />
        </template>
      </Column>
      <Column header="Ação">
        <template #body="slotProps">
          <div class="d-flex gap-1">
            <Button label="Editar" aria-label="Editar" icon="pi pi-pencil"
              @click="DialogVisible = true; Object.assign(selected, slotProps.data); isEdit = true" outlined />
            <Button label="Excluir" aria-label="Excluir" icon="pi pi-times"
              @click="handleDeleteButton($event, 1); Object.assign(selected, slotProps.data);" severity="danger"
              outlined />
          </div>
        </template>
      </Column>
    </DataTable>
  </div>
</template>