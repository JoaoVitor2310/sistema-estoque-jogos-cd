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
import InputNumber from 'primevue/inputnumber';

// Inertia
import { showResponse } from '../helpers/showResponse';
import { Resource } from '../types/Resource';

// onMouted {
let rowData: Resource[] = reactive([]);
const props = defineProps({ resources: Array as PropType<Resource[]> });
console.log(props.resources)
Object.assign(rowData, props.resources);
// }

const filters = ref({
  global: { value: null, matchMode: FilterMatchMode.CONTAINS },
  name: { value: null, matchMode: FilterMatchMode.CONTAINS },
  preco: { value: null, matchMode: FilterMatchMode.CONTAINS },
  action: { value: null, matchMode: FilterMatchMode.CONTAINS },
});

const toast = useToast();
const confirm = useConfirm();

const selectedProduct = ref();
const DialogVisible = ref(false); // Visibilidade do Dialog(modal)
const isEdit = ref(false); // Variável que define se é para criar ou editar no Dialog

const selected = reactive({
  id: 0,
  name: '',
  preco_euro: 0,
  preco_dolar: 0,
  preco_real: 0,
})

const onEdit = async (product: Resource) => {
  isEdit.value = true;
  const res = await axiosInstance.put(`/resources/${product.id}`, {
    preco_euro: product.preco_euro,
    preco_dolar: product.preco_dolar,
    preco_real: product.preco_real
  });
  showResponse(res, toast.add);

  if (res.status === 200) {
    const itemToUpdate = rowData.find(item => item.id === product.id);
    if (itemToUpdate) {
      Object.assign(itemToUpdate, res.data.data);
    }
  }
  DialogVisible.value = false;
}

const handleAddButton = async (): Promise<void> => { // Mostra o dialog com o elemento clicado
  isEdit.value = false;
  Object.assign(selected, { // Zera o valor de selected para criar um novo
    id: 0,
    name: '',
    preco_euro: null,
    preco_dolar: null,
    preco_real: null,
  });
  DialogVisible.value = true;
}

const onAdd = async (newResource: Resource): Promise<void> => { // Faz a req pra api add o elemento
  try {
    const res = await axiosInstance.post(`/resources`, {
      name: newResource.name,
      preco_euro: newResource.preco_euro,
      preco_dolar: newResource.preco_dolar,
      preco_real: newResource.preco_real,
    });
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
        const res = await axiosInstance.delete(`/resources/${selected.id}`);
        showResponse(res, toast.add);
        const itemToDelete = rowData.findIndex(item => item.id === selected.id);
        console.log(itemToDelete);
        rowData.splice(itemToDelete, 1);
        DialogVisible.value = false;
      } else {
        const res = await axiosInstance.delete(`/resources`, {
          params: {
            resources: selectedProduct.value
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

</script>

<template>
  <!-- {{ selected }} -->
  <Toast position="bottom-right" />
  <ConfirmPopup />
  <Dialog v-model:visible="DialogVisible" modal :header="isEdit ? 'Editar' : 'Criar'" :style="{ width: '90%' }">
    <span class=" d-block mb-3" v-if="!isEdit">Insira os dados para criar.</span>
    <span class=" d-block mb-3" v-if="isEdit">Edite os dados.</span>
    <div class="d-flex items-center gap-5 mb-2">
      <label for="nome" class="font-semibold w-24">Nome</label>
      <InputText id="name" class="flex-auto" :disabled="isEdit ? true : false" v-model="selected.name" />
    </div>
    <div class="d-flex items-center gap-2 mb-8">
      <label class="font-semibold w-24">Preço(euro)</label>
      <InputNumber id="preco" class="flex-auto" v-model="selected.preco_euro" mode="decimal" :minFractionDigits="3"
        :maxFractionDigits="3" useGrouping />
    </div>
    <div class="d-flex items-center gap-1 mb-8">
      <label class="font-semibold w-24">Preço(dólar)</label>
      <InputNumber id="preco" class="flex-auto" v-model="selected.preco_dolar" mode="decimal" :minFractionDigits="3"
        :maxFractionDigits="3" useGrouping />
    </div>
    <div class="d-flex items-center gap-3 mb-8">
      <label class="font-semibold w-24">Preço(real)</label>
      <InputNumber id="preco" class="flex-auto" v-model="selected.preco_real" mode="decimal" :minFractionDigits="3"
        :maxFractionDigits="3" useGrouping />
    </div>
    <div class="d-flex justify-content-end gap-2">
      <Button type="button" label="Cancelar" severity="secondary" @click="DialogVisible = false"></Button>
      <Button type="button" label="Salvar" @click="isEdit ? onEdit(selected) : onAdd(selected)"></Button>
    </div>
  </Dialog>

  <div class="container text-center">

    <h1>Recursos</h1>
    <div class="w-50 m-auto">
      <p>Recursos utilizados para trade.</p>
    </div>
    <DataTable :value="rowData" showGridlines sortMode="multiple" removableSort :globalFilterFields="['name', 'preco']"
      v-model:filters="filters" v-model:selection="selectedProduct" selectionMode="multiple" scrollable
      scrollHeight="100vh" editMode="cell" dataKey="id" size="large" tableStyle="min-width: 50rem">
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
      <Column field="name" header="Nome" sortable>
      </Column>
      <Column field="preco_euro" header="Preço(euro)" sortable>
        <template #editor="{ data, field }">
          <InputNumber v-model="data[field]" @blur="onEdit(data)" mode="decimal" :minFractionDigits="3"
            :maxFractionDigits="3" useGrouping autofocus fluid />
        </template>
      </Column>
      <Column field="preco_dolar" header="Preço(dólar)" sortable>
        <template #editor="{ data, field }">
          <InputNumber v-model="data[field]" @blur="onEdit(data)" mode="decimal" :minFractionDigits="3"
            :maxFractionDigits="3" useGrouping autofocus fluid />
        </template>
      </Column>
      <Column field="preco_real" header="Preço(real)" sortable>
        <template #editor="{ data, field }">
          <InputNumber v-model="data[field]" @blur="onEdit(data)" mode="decimal" :minFractionDigits="3"
            :maxFractionDigits="3" useGrouping autofocus fluid />
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