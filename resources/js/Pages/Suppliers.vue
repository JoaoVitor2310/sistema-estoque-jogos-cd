<script setup lang="ts">
import { reactive, ref } from 'vue';

// PrimeVue
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import { FilterMatchMode } from '@primevue/core/api';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import 'primeicons/primeicons.css';
import InputGroup from 'primevue/inputgroup';
import InputGroupAddon from 'primevue/inputgroupaddon';
import Button from 'primevue/button';
import MultiSelect from 'primevue/multiselect';
import Tag from 'primevue/tag';
import Select from 'primevue/select';
import Dialog from 'primevue/dialog';
import Toast from 'primevue/toast';
import { useToast } from 'primevue/usetoast';
import ConfirmPopup from 'primevue/confirmpopup';
import { useConfirm } from 'primevue/useconfirm';

// Inertia / Helpers
import axiosInstance from '../axios';
import { showResponse } from '../helpers/showResponse';

interface Supplier {
    id: number;
    name: string | null;
    steam_id: string | null;
    url: string | null;
    region: string | null;
    initial_offer_pct: number | null;
    category: 'vip' | 'blocked' | null;
    is_added: boolean;
    has_traded: boolean;
}

const props = defineProps<{ suppliers: Supplier[] }>();

const rowData: Supplier[] = reactive([...props.suppliers]);

const toast = useToast();
const confirm = useConfirm();

const filters = ref({
    global: { value: null, matchMode: FilterMatchMode.CONTAINS },
    category: { value: null, matchMode: FilterMatchMode.IN },
});

const categoryFilterOptions = [
    { label: 'Normal', value: null },
    { label: 'VIP', value: 'vip' },
    { label: 'Bloqueado', value: 'blocked' },
];

const DialogVisible = ref(false);
const isEdit = ref(false);
const selectedSuppliers = ref<Supplier[]>([]);

const categoryOptions = [
    { label: 'Normal', value: null },
    { label: 'VIP', value: 'vip' },
    { label: 'Bloqueado', value: 'blocked' },
];

const isAddedOptions = [
    { label: 'Sim', value: true },
    { label: 'Não', value: false },
];

const emptySupplier: Supplier = {
    id: 0,
    name: '',
    steam_id: '',
    url: '',
    region: null,
    initial_offer_pct: null,
    category: null,
    is_added: false,
    has_traded: false,
};

const selected = reactive<Supplier>({ ...emptySupplier });

const openCreate = () => {
    isEdit.value = false;
    Object.assign(selected, emptySupplier);
    DialogVisible.value = true;
};

const openEdit = (item: Supplier) => {
    isEdit.value = true;
    Object.assign(selected, item);
    DialogVisible.value = true;
};

const onSave = async () => {
    const payload = {
        name: selected.name,
        steam_id: selected.steam_id,
        url: selected.url,
        region: selected.region,
        initial_offer_pct: selected.initial_offer_pct,
        category: selected.category,
        is_added: selected.is_added,
    };

    try {
        if (isEdit.value) {
            const res = await axiosInstance.put(`/suppliers/${selected.id}`, payload);
            showResponse(res, toast.add);
            if (res.status === 200) {
                const idx = rowData.findIndex(s => s.id === selected.id);
                if (idx !== -1) Object.assign(rowData[idx], res.data);
                DialogVisible.value = false;
            }
        } else {
            const res = await axiosInstance.post('/suppliers', payload);
            showResponse(res, toast.add);
            if (res.status === 201 || res.status === 200) {
                rowData.push(res.data);
                DialogVisible.value = false;
            }
        }
    } catch (error: any) {
        if (error.response) {
            showResponse(error.response, toast.add);
        } else {
            toast.add({ severity: 'error', summary: 'Erro interno', detail: String(error), life: 7000 });
        }
    }
};

const categoryLabel = (category: Supplier['category']) => {
    if (category === 'vip') return 'VIP';
    if (category === 'blocked') return 'Bloqueado';
    return null;
};

const categorySeverity = (category: Supplier['category']): 'contrast' | 'danger' | undefined => {
    if (category === 'vip') return 'contrast';
    if (category === 'blocked') return 'danger';
    return undefined;
};

const executeListRequest = async (item: Supplier) => {
    try {
        const res = await axiosInstance.post(`/suppliers/execute/${item.id}`);
        showResponse(res, toast.add);
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Erro interno, tente novamente.', detail: error, life: 7000 });
    }
};

const confirmExecuteList = (event: Event, item: Supplier) => {
    const name = item.name ?? item.steam_id ?? String(item.id);
    confirm.require({
        target: event.currentTarget as HTMLElement,
        message: `Executar a lista de preços para "${name}"?`,
        header: 'Confirmar execução',
        icon: 'pi pi-exclamation-triangle',
        rejectProps: { label: 'Cancelar', severity: 'secondary', outlined: true },
        acceptProps: { label: 'Executar', severity: 'contrast' },
        accept: () => executeListRequest(item),
    });
};

const batchExecuteLists = async (items: Supplier[]) => {
    let ok = 0;
    let fail = 0;
    for (const item of items) {
        try {
            const res = await axiosInstance.post(`/suppliers/execute/${item.id}`);
            res.status >= 200 && res.status < 300 ? ok++ : fail++;
        } catch {
            fail++;
        }
    }
    const severity = fail === 0 ? 'success' : ok === 0 ? 'error' : 'warn';
    toast.add({ severity, summary: 'Execução em lote', detail: `${ok} enviada(s) com sucesso, ${fail} falha(s).`, life: 6000 });
};

const confirmBatchExecuteLists = (event: Event) => {
    const items = selectedSuppliers.value;
    confirm.require({
        target: event.currentTarget as HTMLElement,
        message: `Executar lista de preços para ${items.length} fornecedor(es) selecionado(s)?`,
        header: 'Confirmar execução em lote',
        icon: 'pi pi-exclamation-triangle',
        rejectProps: { label: 'Cancelar', severity: 'secondary', outlined: true },
        acceptProps: { label: 'Executar todos', severity: 'contrast' },
        accept: () => batchExecuteLists(items),
    });
};

const findingNewSuppliers = ref(false);

const findNewSuppliers = async () => {
    findingNewSuppliers.value = true;
    try {
        const res = await axiosInstance.post('/suppliers/find-new');
        showResponse(res, toast.add);
    } catch (error: any) {
        if (error.response) {
            showResponse(error.response, toast.add);
        } else {
            toast.add({ severity: 'error', summary: 'Erro interno', detail: String(error), life: 7000 });
        }
    } finally {
        findingNewSuppliers.value = false;
    }
};

const confirmFindNewSuppliers = (event: Event) => {
    confirm.require({
        target: event.currentTarget as HTMLElement,
        message: 'Isso irá pesquisar novos fornecedores no price-researcher. A operação pode demorar alguns minutos. Deseja continuar?',
        header: 'Procurar novos fornecedores',
        icon: 'pi pi-exclamation-triangle',
        rejectProps: { label: 'Cancelar', severity: 'secondary', outlined: true },
        acceptProps: { label: 'Confirmar', severity: 'contrast' },
        accept: findNewSuppliers,
    });
};

const handleDelete = (event: any, item: Supplier) => {
    confirm.require({
        target: event.currentTarget,
        message: 'Tem certeza que deseja excluir este supplier?',
        rejectProps: { label: 'Cancelar', severity: 'secondary', outlined: true },
        acceptProps: { label: 'Excluir', severity: 'danger' },
        accept: async () => {
            try {
                const res = await axiosInstance.delete(`/suppliers/${item.id}`);
                showResponse(res, toast.add);
                if (res.status === 200) {
                    const idx = rowData.findIndex(s => s.id === item.id);
                    if (idx !== -1) rowData.splice(idx, 1);
                    selectedSuppliers.value = selectedSuppliers.value.filter(s => s.id !== item.id);
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

    <Dialog v-model:visible="DialogVisible" modal :header="isEdit ? 'Editar Fornecedor' : 'Novo Fornecedor'"
        :style="{ width: '500px' }">
        <div class="d-flex flex-column gap-3 mb-3">
            <div class="d-flex flex-column gap-1">
                <label class="fw-bold">Nome</label>
                <InputText v-model="selected.name" placeholder="Nome do supplier" />
            </div>
            <div class="d-flex flex-column gap-1">
                <label class="fw-bold">Região</label>
                <InputText v-model="selected.region" />
            </div>
            <div class="d-flex flex-column gap-1">
                <label class="fw-bold">ID Steam</label>
                <InputText v-model="selected.steam_id" />
            </div>
            <div class="d-flex flex-column gap-1">
                <label class="fw-bold">% Lucro inicial</label>
                <InputNumber v-model="selected.initial_offer_pct" :min="1" :max="1000" placeholder="100" />
            </div>
            <div class="d-flex flex-column gap-1">
                <label class="fw-bold">Categoria</label>
                <Select v-model="selected.category" :options="categoryOptions" optionLabel="label" optionValue="value"
                    placeholder="Normal (sem categoria)" />
            </div>
            <div class="d-flex flex-column gap-1">
                <label class="fw-bold">Adicionado no Steam</label>
                <Select v-model="selected.is_added" :options="isAddedOptions" optionLabel="label" optionValue="value" />
            </div>
            <div class="d-flex flex-column gap-1">
                <label class="fw-bold">URL</label>
                <InputText v-model="selected.url" placeholder="https://steamcommunity.com/profiles/..." />
            </div>
        </div>
        <div class="d-flex justify-content-end gap-2">
            <Button type="button" label="Cancelar" severity="secondary" @click="DialogVisible = false" />
            <Button type="button" label="Salvar" @click="onSave" />
        </div>
    </Dialog>

    <div class="container text-center mb-3">
        <h1>Fornecedores</h1>
        <div class="w-50 m-auto">
            <p>Gerencie os fornecedores e suas listas de jogos.</p>
        </div>
        <div class="table-responsive supplier-datatable-wrap mx-auto text-start" style="max-width: 100%;">
            <DataTable v-model:selection="selectedSuppliers" :value="rowData" showGridlines sortMode="multiple"
                removableSort :globalFilterFields="['name', 'steam_id', 'url']" v-model:filters="filters" scrollable
                scrollHeight="min(70vh, 720px)" dataKey="id" size="small" class="supplier-datatable"
                filterDisplay="menu" tableStyle="width: 100%; min-width: 0;">
                <template #header>
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <Button label="Novo" icon="pi pi-plus" @click="openCreate" raised />
                            <Button label="Procurar novos" icon="pi pi-search" severity="secondary"
                                :loading="findingNewSuppliers" :disabled="findingNewSuppliers"
                                @click="confirmFindNewSuppliers($event)" raised />
                            <template v-if="selectedSuppliers.length">
                                <Button label="Executar listas" icon="pi pi-play" severity="contrast" size="small"
                                    @click="confirmBatchExecuteLists($event)"
                                    title="Executa os fornecedores selecionados" />
                                <span class="text-muted small">{{ selectedSuppliers.length }} selecionado(s)</span>
                            </template>
                        </div>
                        <div style="min-width: 12rem; max-width: 22rem; flex: 1;">
                            <InputGroup>
                                <InputGroupAddon>
                                    <i class="pi pi-search" />
                                </InputGroupAddon>
                                <InputText v-model="filters['global'].value" placeholder="Pesquisar" />
                            </InputGroup>
                        </div>
                    </div>
                </template>
                <template #empty>Nenhum supplier encontrado.</template>

                <Column selectionMode="multiple" headerStyle="width: 2.75rem" />
                <Column field="id" header="ID" sortable :style="{ width: '4rem' }" />
                <Column field="name" header="Nome" sortable />
                <Column field="region" header="Região" sortable :style="{ width: '6rem' }" />
                <Column field="steam_id" header="ID Steam" sortable />
                <Column field="category" header="Categoria" sortable :style="{ width: '8rem' }" :showFilterMatchModes="false">
                    <template #body="{ data }">
                        <Tag v-if="data.category" :value="categoryLabel(data.category)"
                            :severity="categorySeverity(data.category)" />
                        <span v-else class="text-muted">Normal</span>
                    </template>
                    <template #filter="{ filterModel }">
                        <MultiSelect v-model="filterModel.value" :options="categoryFilterOptions"
                            optionLabel="label" optionValue="value" placeholder="Todas" />
                    </template>
                </Column>
                <Column field="is_added" header="Adicionado" sortable :style="{ width: '7rem' }">
                    <template #body="{ data }">
                        <Tag v-if="data.is_added" value="Sim" severity="success" />
                        <span v-else class="text-muted">Não</span>
                    </template>
                </Column>
                <Column field="url" header="URL" :style="{ minWidth: '14rem' }">
                    <template #body="{ data }">
                        <a v-if="data.url" :href="data.url" target="_blank" rel="noopener" class="text-truncate d-block" style="max-width: 260px;">{{ data.url }}</a>
                    </template>
                </Column>
                <Column header="Ações" :style="{ minWidth: '8rem' }">
                    <template #body="{ data }">
                        <div class="d-flex gap-1 flex-wrap">
                            <Button icon="pi pi-play" aria-label="Executar lista"
                                severity="contrast" @click="confirmExecuteList($event, data)" outlined size="small" />
                            <Button icon="pi pi-pencil" aria-label="Editar" @click="openEdit(data)" outlined
                                size="small" />
                            <Button icon="pi pi-trash" aria-label="Excluir" severity="danger"
                                @click="handleDelete($event, data)" outlined size="small" />
                        </div>
                    </template>
                </Column>
            </DataTable>
        </div>
    </div>
</template>

<style scoped>
.supplier-datatable-wrap :deep(.supplier-datatable table thead th),
.supplier-datatable-wrap :deep(.supplier-datatable table tbody td) {
    font-size: 0.8125rem;
    padding-top: 0.4rem;
    padding-bottom: 0.4rem;
}

.supplier-datatable-wrap :deep(.supplier-datatable table thead th) {
    white-space: nowrap;
}
</style>
