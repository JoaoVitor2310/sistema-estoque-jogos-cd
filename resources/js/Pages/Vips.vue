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
import Tag from 'primevue/tag';
import Dialog from 'primevue/dialog';
import Toast from 'primevue/toast';
import { useToast } from 'primevue/usetoast';
import ConfirmPopup from 'primevue/confirmpopup';
import { useConfirm } from 'primevue/useconfirm';

// Inertia / Helpers
import axiosInstance from '../axios';
import { showResponse } from '../helpers/showResponse';

interface VipList {
    id: number;
    vip_id: number;
    status: 'queued' | 'completed' | 'failed';
    result: string | null;
    created_at: string;
    updated_at: string;
}

interface Vip {
    id: number;
    name: string;
    id_steam: string | null;
    result: string | null;
    result_at: string | null;
    list: VipList | null;
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

const selectedVips = ref<Vip[]>([]);

const ResultDialogVisible = ref(false);
const resultVipName = ref('');
const resultVipList = ref<VipList | null>(null);

const emptyVip: Vip = { id: 0, name: '', id_steam: '', result: null, result_at: null, list: null };
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
                id_steam: selected.id_steam,
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
                id_steam: selected.id_steam,
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

const listStatusLabel = (status: VipList['status']) => {
    const map: Record<VipList['status'], string> = {
        queued: 'Na fila',
        completed: 'Concluída',
        failed: 'Falhou',
    };
    return map[status] ?? status;
};

const listStatusSeverity = (status: VipList['status']): 'info' | 'success' | 'danger' | 'secondary' => {
    const map: Record<VipList['status'], 'info' | 'success' | 'danger'> = {
        queued: 'info',
        completed: 'success',
        failed: 'danger',
    };
    return map[status] ?? 'secondary';
};

const handleViewResult = (item: Vip) => {
    resultVipName.value = item.name;
    resultVipList.value = item.list ?? null;
    ResultDialogVisible.value = true;
};

/** Clipboard API exige contexto seguro (HTTPS); em HTTP navigator.clipboard pode ser undefined. */
const copyTextToClipboard = async (text: string): Promise<void> => {
    if (typeof navigator !== 'undefined' && navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(text);
        return;
    }
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;opacity:0;pointer-events:none;';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    ta.setSelectionRange(0, text.length);
    const ok = document.execCommand('copy');
    document.body.removeChild(ta);
    if (!ok) {
        throw new Error('Clipboard indisponível (use HTTPS ou copie manualmente).');
    }
};

const copyResult = async () => {
    const text = resultVipList.value?.result;
    if (!text) return;
    try {
        await copyTextToClipboard(text);
        toast.add({ severity: 'success', summary: 'Copiado!', detail: 'Resultado da lista copiado para a área de transferência.', life: 3000 });
    } catch (error) {
        console.error(error);
        toast.add({ severity: 'error', summary: 'Erro ao copiar', detail: 'Não foi possível copiar o resultado', life: 4000 });
    }
};

const copyRowListResult = async (item: Vip) => {
    const text = item.list?.result?.trim();
    if (!text) {
        toast.add({
            severity: 'warn',
            summary: 'Sem resultado',
            detail: 'Este VIP ainda não tem resultado de lista para copiar.',
            life: 4000,
        });
        return;
    }
    try {
        await copyTextToClipboard(text);
        toast.add({ severity: 'success', summary: 'Copiado!', detail: `Resultado de "${item.name}" copiado.`, life: 3000 });
    } catch (error) {
        console.error(error);
        toast.add({ severity: 'error', summary: 'Erro ao copiar', detail: 'Não foi possível copiar o resultado.', life: 4000 });
    }
};

const runVipListRequest = async (item: Vip) => {
    try {
        const res = await axiosInstance.post(`/vips/run/${item.id}`);
        showResponse(res, toast.add);
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Erro interno, tente novamente.', detail: error, life: 7000 });
    }
};

const confirmRunVipList = (event: Event, item: Vip) => {
    confirm.require({
        target: event.currentTarget as HTMLElement,
        message: `Executar a lista de preços para "${item.name}"?`,
        header: 'Confirmar execução',
        icon: 'pi pi-exclamation-triangle',
        rejectProps: {
            label: 'Cancelar',
            severity: 'secondary',
            outlined: true,
        },
        acceptProps: {
            label: 'Executar',
            severity: 'contrast',
        },
        accept: () => runVipListRequest(item),
    });
};

const copyBatchListResults = async () => {
    const items = selectedVips.value;
    if (items.length === 0) {
        toast.add({ severity: 'warn', summary: 'Seleção vazia', detail: 'Selecione ao menos um VIP.', life: 3000 });
        return;
    }
    const parts: string[] = [];
    for (const v of items) {
        const r = v.list?.result?.trim();
        if (r) parts.push(r);
    }
    if (parts.length === 0) {
        toast.add({
            severity: 'warn',
            summary: 'Nada para copiar',
            detail: 'Nenhum VIP selecionado possui resultado de lista.',
            life: 4000,
        });
        return;
    }
    const text = parts.join('\n\n');
    try {
        await copyTextToClipboard(text);
        const skipped = items.length - parts.length;
        const detail =
            skipped > 0
                ? `Copiados ${parts.length} resultado(s). ${skipped} VIP(s) sem resultado foram ignorados.`
                : `${parts.length} resultado(s) copiados (separados por linha em branco).`;
        toast.add({ severity: 'success', summary: 'Copiado!', detail, life: 4500 });
    } catch {
        toast.add({ severity: 'error', summary: 'Erro ao copiar', detail: 'Não foi possível copiar.', life: 4000 });
    }
};

const confirmBatchRunVipLists = (event: Event) => {
    const items = selectedVips.value;
    if (items.length === 0) {
        toast.add({ severity: 'warn', summary: 'Seleção vazia', detail: 'Selecione ao menos um VIP.', life: 3000 });
        return;
    }
    confirm.require({
        target: event.currentTarget as HTMLElement,
        message: `Executar lista de preços para ${items.length} VIP(s) selecionado(s)? As execuções serão disparadas em sequência.`,
        header: 'Confirmar execução em lote',
        icon: 'pi pi-exclamation-triangle',
        rejectProps: {
            label: 'Cancelar',
            severity: 'secondary',
            outlined: true,
        },
        acceptProps: {
            label: 'Executar todas',
            severity: 'contrast',
        },
        accept: () => batchRunVipLists(items),
    });
};

const batchRunVipLists = async (items: Vip[]) => {
    let ok = 0;
    let fail = 0;
    for (const item of items) {
        try {
            const res = await axiosInstance.post(`/vips/run/${item.id}`);
            if (res.status >= 200 && res.status < 300) {
                ok++;
            } else {
                fail++;
            }
        } catch {
            fail++;
        }
    }
    const severity = fail === 0 ? 'success' : ok === 0 ? 'error' : 'warn';
    toast.add({
        severity,
        summary: 'Execução em lote',
        detail: `${ok} enviada(s) com sucesso, ${fail} falha(s).`,
        life: 6000,
    });
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
                    selectedVips.value = selectedVips.value.filter(v => v.id !== item.id);
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

    <Dialog v-model:visible="ResultDialogVisible" modal :header="`Lista — ${resultVipName}`"
        :style="{ width: '600px' }">
        <div class="d-flex flex-column gap-3">
            <template v-if="resultVipList">
                <div class="d-flex flex-wrap align-items-center gap-2 text-muted small">
                    <Tag :value="listStatusLabel(resultVipList.status)"
                        :severity="listStatusSeverity(resultVipList.status)" />
                    <span v-if="resultVipList.updated_at">
                        Atualizado em {{ new Date(resultVipList.updated_at).toLocaleString('pt-BR') }}
                    </span>
                </div>
                <Textarea :value="resultVipList.result ?? ''" readonly rows="14" class="w-100"
                    style="font-size: 0.85rem; font-family: monospace;"
                    placeholder="Aguardando resultado da execução…" />
            </template>
            <p v-else class="text-muted small mb-0">Este VIP ainda não possui lista executada. Use a ação de rodar
                lista.</p>
            <div class="d-flex justify-content-end gap-2">
                <Button type="button" label="Fechar" severity="secondary" @click="ResultDialogVisible = false" />
                <Button type="button" label="Copiar" icon="pi pi-copy" @click="copyResult"
                    :disabled="!resultVipList?.result" />
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
                <label class="fw-bold">Id Steam</label>
                <InputText v-model="selected.id_steam" placeholder="1234567890" />
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
            <p>Gerencie os usuários VIP e suas listas de jogos.</p>
        </div>
        <div class="table-responsive vip-datatable-wrap mx-auto text-start" style="max-width: 100%;">
            <DataTable v-model:selection="selectedVips" :value="rowData" showGridlines sortMode="multiple"
                removableSort :globalFilterFields="['name', 'id_steam']" v-model:filters="filters" scrollable
                scrollHeight="min(70vh, 720px)" dataKey="id" size="small" class="vip-datatable"
                tableStyle="width: 100%; min-width: 0;">
                <template #header>
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <Button label="Novo" icon="pi pi-plus" @click="openCreate" raised />
                            <template v-if="selectedVips.length">
                                <Button label="Copiar resultados" icon="pi pi-copy" severity="secondary" outlined
                                    size="small" @click="copyBatchListResults"
                                    title="Concatena o resultado da lista de cada VIP (linha em branco entre um e outro)" />
                                <Button label="Executar listas" icon="pi pi-play" severity="contrast" size="small"
                                    @click="confirmBatchRunVipLists($event)" />
                                <span class="text-muted small">{{ selectedVips.length }} selecionado(s)</span>
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
                <template #empty>Nenhum VIP encontrado.</template>

                <Column selectionMode="multiple" headerStyle="width: 2.75rem" />
                <Column field="name" header="Nome" sortable />
                <Column field="id_steam" header="ID Steam" sortable>
                    <template #body="{ data }">
                        {{ data.id_steam }}
                    </template>
                </Column>
                <Column header="Status (lista)" :style="{ width: '7.5rem' }">
                    <template #body="{ data }">
                        <Tag v-if="data.list" :value="listStatusLabel(data.list.status)"
                            :severity="listStatusSeverity(data.list.status)" />
                        <span v-else class="text-muted">—</span>
                    </template>
                </Column>
                <Column header="Resultado (lista)" :style="{ maxWidth: '10rem' }">
                    <template #body="{ data }">
                        <span v-if="data.list?.result" class="text-truncate d-inline-block"
                            style="max-width: 10rem; font-size: 0.8125rem;" :title="data.list.result">
                            {{ data.list.result.slice(0, 28) }}<span v-if="data.list.result.length > 28">...</span>
                        </span>
                        <span v-else class="text-muted">—</span>
                    </template>
                </Column>
                <Column header="Ações" :style="{ minWidth: '11.5rem' }">
                    <template #body="{ data }">
                        <div class="d-flex gap-1 flex-wrap">
                            <Button icon="pi pi-copy" aria-label="Copiar resultado da lista" severity="secondary"
                                :disabled="!data.list?.result" @click="copyRowListResult(data)" outlined size="small"
                                title="Copiar resultado da lista" />
                            <Button icon="pi pi-eye" aria-label="Visualizar lista" severity="info"
                                @click="handleViewResult(data)" outlined size="small" />
                            <Button icon="pi pi-play" aria-label="Executar lista" severity="contrast"
                                @click="confirmRunVipList($event, data)" outlined size="small" />
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
.vip-datatable-wrap :deep(.vip-datatable table thead th),
.vip-datatable-wrap :deep(.vip-datatable table tbody td) {
    font-size: 0.8125rem;
    padding-top: 0.4rem;
    padding-bottom: 0.4rem;
}

.vip-datatable-wrap :deep(.vip-datatable table thead th) {
    white-space: nowrap;
}
</style>
