<script setup lang="ts">
import { reactive, ref } from 'vue';

// PrimeVue
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import { FilterMatchMode } from '@primevue/core/api';
import InputText from 'primevue/inputtext';
import Textarea from 'primevue/textarea';
import 'primeicons/primeicons.css';
import InputGroup from 'primevue/inputgroup';
import InputGroupAddon from 'primevue/inputgroupaddon';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import Toast from 'primevue/toast';
import { useToast } from 'primevue/usetoast';
import ConfirmPopup from 'primevue/confirmpopup';
import { useConfirm } from 'primevue/useconfirm';

// Inertia / Helpers
import axiosInstance from '../axios';
import { showResponse } from '../helpers/showResponse';

interface Vip {
    id: number;
    name: string;
    first_link: string | null;
    second_link: string | null;
    third_link: string | null;
    steam_link: string | null;
    result: string | null;
    result_at: string | null;
}

const props = defineProps<{ vips: Vip[] }>();

const rowData: Vip[] = reactive([...props.vips]);

const toast = useToast();
const confirm = useConfirm();

const filters = ref({
    global: { value: null, matchMode: FilterMatchMode.CONTAINS },
});

const DialogVisible = ref(false);
const isEdit = ref(false);

const ResultDialogVisible = ref(false);
const resultVipName = ref('');
const resultContent = ref('');

const emptyVip: Vip = { id: 0, name: '', first_link: '', second_link: '', third_link: '', steam_link: '', result: null, result_at: null };
const selected = reactive<Vip>({ ...emptyVip });

const openCreate = () => {
    isEdit.value = false;
    Object.assign(selected, emptyVip);
    DialogVisible.value = true;
};

const openEdit = (item: Vip) => {
    isEdit.value = true;
    Object.assign(selected, item);
    DialogVisible.value = true;
};



const onSave = async () => {
    if (isEdit.value) {
        try {
            const res = await axiosInstance.put(`/vips/${selected.id}`, {
                name: selected.name,
                first_link: selected.first_link,
                second_link: selected.second_link,
                third_link: selected.third_link,
                steam_link: selected.steam_link,
            });
            showResponse(res, toast.add);
            if (res.status === 200) {
                const idx = rowData.findIndex(v => v.id === selected.id);
                if (idx !== -1) Object.assign(rowData[idx], res.data.data);
                DialogVisible.value = false;
            }
        } catch (error) {
            toast.add({ severity: 'error', summary: 'Erro interno, tente novamente.', detail: error, life: 7000 });
        }
    } else {
        try {
            const res = await axiosInstance.post(`/vips`, {
                name: selected.name,
                first_link: selected.first_link,
                second_link: selected.second_link,
                third_link: selected.third_link,
                steam_link: selected.steam_link,
            });
            showResponse(res, toast.add);
            if (res.status === 201 || res.status === 200) {
                rowData.push(res.data.data);
                DialogVisible.value = false;
            }
        } catch (error) {
            toast.add({ severity: 'error', summary: 'Erro interno, tente novamente.', detail: error, life: 7000 });
        }
    }
};

const handleViewResult = (item: Vip) => {
    resultVipName.value = item.name;
    resultContent.value = item.result ?? '';
    ResultDialogVisible.value = true;
};

const copyResult = async () => {
    if (!resultContent.value) return;
    try {
        await navigator.clipboard.writeText(resultContent.value);
        toast.add({ severity: 'success', summary: 'Copiado!', detail: 'Resultado copiado para a área de transferência.', life: 3000 });
    } catch {
        toast.add({ severity: 'error', summary: 'Erro ao copiar', detail: 'Não foi possível copiar o resultado.', life: 4000 });
    }
};

const handleRunVipLists = async (item: Vip) => {
    // TODO: implementar busca de preços
    toast.add({ severity: 'info', summary: 'Em breve', detail: 'Funcionalidade ainda não implementada.', life: 3000 });
};

const handleDelete = (event: any, item: Vip) => {
    confirm.require({
        target: event.currentTarget,
        message: 'Tem certeza que deseja excluir este VIP?',
        rejectProps: {
            label: 'Cancelar',
            severity: 'secondary',
            outlined: true,
        },
        acceptProps: {
            label: 'Excluir',
            severity: 'danger',
        },
        accept: async () => {
            try {
                const res = await axiosInstance.delete(`/vips/${item.id}`);
                showResponse(res, toast.add);
                if (res.status === 200) {
                    const idx = rowData.findIndex(v => v.id === item.id);
                    if (idx !== -1) rowData.splice(idx, 1);
                }
            } catch (error) {
                toast.add({ severity: 'error', summary: 'Erro interno, tente novamente.', detail: error, life: 7000 });
            }
        },
    });
};
</script>

<template>
    <Toast position="bottom-right" />
    <ConfirmPopup />

    <Dialog v-model:visible="ResultDialogVisible" modal :header="`Resultado — ${resultVipName}`"
        :style="{ width: '600px' }">
        <div class="d-flex flex-column gap-3">
            <Textarea :value="resultContent" readonly rows="12" class="w-100" style="font-size: 0.85rem; font-family: monospace;" />
            <div class="d-flex justify-content-end gap-2">
                <Button type="button" label="Fechar" severity="secondary" @click="ResultDialogVisible = false" />
                <Button type="button" label="Copiar" icon="pi pi-copy" @click="copyResult" :disabled="!resultContent" />
            </div>
        </div>
    </Dialog>

    <Dialog v-model:visible="DialogVisible" modal :header="isEdit ? 'Editar VIP' : 'Novo VIP'"
        :style="{ width: '500px' }">
        <div class="d-flex flex-column gap-3 mb-3">
            <div class="d-flex flex-column gap-1">
                <label class="fw-bold">Nome</label>
                <InputText v-model="selected.name" placeholder="Nome do VIP" />
            </div>
            <div class="d-flex flex-column gap-1">
                <label class="fw-bold">Primeiro Link</label>
                <InputText v-model="selected.first_link" placeholder="https://..." />
            </div>
            <div class="d-flex flex-column gap-1">
                <label class="fw-bold">Segundo Link</label>
                <InputText v-model="selected.second_link" placeholder="https://..." />
            </div>
            <div class="d-flex flex-column gap-1">
                <label class="fw-bold">Terceiro Link</label>
                <InputText v-model="selected.third_link" placeholder="https://..." />
            </div>
            <div class="d-flex flex-column gap-1">
                <label class="fw-bold">Link Steam</label>
                <InputText v-model="selected.steam_link" placeholder="https://..." />
            </div>
        </div>
        <div class="d-flex justify-content-end gap-2">
            <Button type="button" label="Cancelar" severity="secondary" @click="DialogVisible = false" />
            <Button type="button" label="Salvar" @click="onSave" />
        </div>
    </Dialog>

    <div class="container text-center mb-3">
        <h1>VIP's</h1>
        <div class="w-50 m-auto">
            <p>Gerencie os usuários VIP e seus links de lista de jogos.</p>
        </div>
        <DataTable :value="rowData" showGridlines sortMode="multiple" removableSort
            :globalFilterFields="['name', 'first_link', 'second_link', 'third_link', 'steam_link']"
            v-model:filters="filters" scrollable scrollHeight="100vh" dataKey="id" size="large"
            tableStyle="min-width: 50rem">
            <template #header>
                <div class="d-flex justify-content-between">
                    <Button label="Novo" icon="pi pi-plus" @click="openCreate" raised />
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
            <template #empty>Nenhum VIP encontrado.</template>

            <Column field="name" header="Nome" sortable />
            <Column field="first_link" header="Primeiro Link" sortable>
                <template #body="{ data }">
                    <a v-if="data.first_link" :href="data.first_link" target="_blank" rel="noopener noreferrer">
                        {{ data.first_link.slice(0, 30) }}<span v-if="data.first_link.length > 30">...</span>
                    </a>
                    <span v-else class="text-muted">—</span>
                </template>
            </Column>
            <Column field="second_link" header="Segundo Link" sortable>
                <template #body="{ data }">
                    <a v-if="data.second_link" :href="data.second_link" target="_blank" rel="noopener noreferrer">
                        {{ data.second_link.slice(0, 30) }}<span v-if="data.second_link.length > 30">...</span>
                    </a>
                    <span v-else class="text-muted">—</span>
                </template>
            </Column>
            <Column field="third_link" header="Terceiro Link" sortable>
                <template #body="{ data }">
                    <a v-if="data.third_link" :href="data.third_link" target="_blank" rel="noopener noreferrer">
                        {{ data.third_link.slice(0, 30) }}<span v-if="data.third_link.length > 30">...</span>
                    </a>
                    <span v-else class="text-muted">—</span>
                </template>
            </Column>
            <Column field="steam_link" header="Link Steam" sortable>
                <template #body="{ data }">
                    <a v-if="data.steam_link" :href="data.steam_link" target="_blank" rel="noopener noreferrer">
                        {{ data.steam_link.slice(0, 30) }}<span v-if="data.steam_link.length > 30">...</span>
                    </a>
                    <span v-else class="text-muted">—</span>
                </template>
            </Column>
            <Column field="result" header="Resultado">
                <template #body="{ data }">
                    <span v-if="data.result" class="text-truncate d-inline-block" style="max-width: 180px;" :title="data.result">
                        {{ data.result.slice(0, 40) }}<span v-if="data.result.length > 40">...</span>
                    </span>
                    <span v-else class="text-muted">—</span>
                </template>
            </Column>
            <Column field="result_at" header="Última execução" sortable>
                <template #body="{ data }">
                    <span v-if="data.result_at">
                        {{ new Date(data.result_at).toLocaleString('pt-BR') }}
                    </span>
                    <span v-else class="text-muted">—</span>
                </template>
            </Column>
            <Column header="Ações">
                <template #body="{ data }">
                    <div class="d-flex gap-1">
                        <Button icon="pi pi-eye" aria-label="Rodar lista" severity="info" @click="handleViewResult(data)" outlined />
                        <Button icon="pi pi-search" aria-label="Rodar lista" severity="contrast" @click="handleRunVipLists(data)" outlined />
                        <Button icon="pi pi-pencil" aria-label="Editar" @click="openEdit(data)" outlined />
                        <Button icon="pi pi-trash" aria-label="Excluir" severity="danger"
                            @click="handleDelete($event, data)" outlined />
                    </div>
                </template>
            </Column>
        </DataTable>
    </div>
</template>
