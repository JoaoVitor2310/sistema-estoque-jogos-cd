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
import { AuthorizedUsers } from '../types/AuthorizedUsers';
import RadioButton from 'primevue/radiobutton';
import Select from 'primevue/select';

// onMouted {
let rowData: AuthorizedUsers[] = reactive([]);
const props = defineProps({ emails: Array as PropType<AuthorizedUsers[]> });
console.log(props.emails)
Object.assign(rowData, props.emails);
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

const selectedNewObject = {
  id: 0,
  name: '',
  email: '',
  status: true,
}

const selected = reactive(selectedNewObject);

const onEdit = async (item: AuthorizedUsers) => {
  isEdit.value = true;
  try {
    const res = await axiosInstance.put(`/authorize/${item.id}`, {
      name: item.name,
      email: item.email,
      status: item.status
    });
    // console.log(res.data.data);
    showResponse(res, toast.add);
    if (res.status === 200) {
      const itemToUpdate = rowData.find(rowItem => rowItem.id === item.id);
      console.log(itemToUpdate);
      if (itemToUpdate) {
        Object.assign(itemToUpdate, res.data.data);
      }
    }
    DialogVisible.value = false;
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
  Object.assign(selected, selectedNewObject); // Zera o valor de selected para criar um novo
  DialogVisible.value = true;
}

const onAdd = async (newUser: AuthorizedUsers): Promise<void> => { // Faz a req pra api add o elemento
  const form = {
    name: newUser.name,
    email: newUser.email,
    status: newUser.status,
  };
  try {
    const res = await axiosInstance.post(`/authorize`, form);
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
        const res = await axiosInstance.delete(`/authorize/${selected.id}`);
        showResponse(res, toast.add);
        if (res.status === 200) {
          const itemToDelete = rowData.findIndex(item => item.id === selected.id);
          console.log(itemToDelete);
          rowData.splice(itemToDelete, 1);
          DialogVisible.value = false;
        }
      } else {
        const res = await axiosInstance.delete(`/authorize`, {
          params: {
            items: selectedProduct.value
          }
        });
        showResponse(res, toast.add);
        if (res.status === 200) {
          const selectedProductIds = selectedProduct.value.map(item => item.id);
          const filteredRowData = rowData.filter(item => !selectedProductIds.includes(item.id));
          rowData.splice(0, rowData.length, ...filteredRowData);
          selectedProduct.value = null;
        }
      }
    }
  });
};

</script>

<template>
  <Toast position="bottom-right" />
  <ConfirmPopup />
  <Dialog v-model:visible="DialogVisible" modal :header="isEdit ? 'Editar' : 'Criar'" :style="{ width: '90%' }">
    <span class=" d-block mb-3" v-if="!isEdit">Insira os dados para criar.</span>
    <span class=" d-block mb-3" v-if="isEdit">Edite os dados.</span>
    <div class="d-flex flex-row gap-2">
      <div class="d-flex flex-column">
        <label class="fw-bold me-2">Nome</label>
        <div class="d-flex gap-5 mb-3">
          <InputText v-model="selected.name" />
        </div>
      </div>
      <div class="d-flex flex-column">
        <label class="fw-bold me-2">Email</label>
        <div class="d-flex gap-5 mb-3">
          <InputText v-model="selected.email" />
        </div>
      </div>
      <div class="d-flex flex-column">
        <label class="fw-bold">Status</label>
        <div class="d-flex gap-2 mb-3">
          <label>Ativo</label>
          <RadioButton v-model="selected.status" :value="true" />
          <label>Inativo</label>
          <RadioButton v-model="selected.status" :value="false" />
        </div>
      </div>
    </div>
    <div class="d-flex justify-content-end gap-2">
      <Button type="button" label="Cancelar" severity="secondary" @click="DialogVisible = false"></Button>
      <Button type="button" label="Salvar" @click="isEdit ? onEdit(selected) : onAdd(selected)"></Button>
    </div>
  </Dialog>

  <div class="container text-center">
    <h1>Acesso</h1>
    <div class="w-50 m-auto">
      <p>Gerencie quem terá acesso ao sistema.</p>
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
        <template #editor="{ data, field }">
          <InputText v-model="data[field]" @change="onEdit(data)"></InputText>
        </template>
      </Column>
      <Column field="email" header="Email" sortable>
        <template #editor="{ data, field }">
          <InputText v-model="data[field]" @change="onEdit(data)"></InputText>
        </template>
      </Column>
      <Column field="status" header="Status" sortable>
        <template #body="{ data }">
          <i class="pi m-1 fw-bold" :class="[
            data.status === true ? 'pi-check-circle' :
              data.status === false ? 'pi-times-circle' : 'pi-times-circle',
            data.status === true ? 'text-primary' :
              data.status === false ? 'text-danger' : ''
          ]">
          </i>
        </template>
        <template #editor="{ data, field }">
          <Select v-model="data.status" :options="[{ label: 'Ativo', value: true }, { label: 'Inativo', value: false }]"
            @change="onEdit(data)" optionLabel="label" optionValue="value" />
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