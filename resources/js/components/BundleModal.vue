<script setup lang="ts">
import { reactive, watch } from 'vue';
import type { PropType } from 'vue';

// PrimeVue
import Dialog from 'primevue/dialog';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import RadioButton from 'primevue/radiobutton';

// Types
import type { Bundle } from '../types/Bundle';
import type { Game } from '../types/Game';

// Props
const props = defineProps({
  visible: {
    type: Boolean,
    required: true
  },
  isEdit: {
    type: Boolean,
    default: false
  },
  selectedGames: {
    type: Array as PropType<Game[]>,
    default: () => []
  },
  bundleData: {
    type: Object as PropType<Partial<Bundle>>,
    default: () => ({})
  }
});

// Emits
const emit = defineEmits<{
  'update:visible': [value: boolean]
  'save': [bundleData: Partial<Bundle>]
  'cancel': []
}>();

// Data reativo local
const localBundleData = reactive({
  id: 0,
  name: '',
  type: 'bundle' as string,
  description: '',
  price_tf2: null,
  price_euro: null,
  release_date: '',
  games: [] as Game[]
});

// Watch para sincronizar dados quando o modal abre
watch(() => props.bundleData, (newData) => {
  if (newData) {
    Object.assign(localBundleData, {
      id: newData.id || 0,
      name: newData.name || '',
      type: newData.type || 'bundle',
      description: newData.description || '',
      price_tf2: newData.price_tf2 || null,
      price_euro: newData.price_euro || null,
      release_date: newData.release_date || '',
      games: []
    });
  }
}, { immediate: true });

// Watch adicional para quando o modal abre
watch(() => props.visible, (isVisible) => {
  if (isVisible && props.bundleData) {
    Object.assign(localBundleData, {
      id: props.bundleData.id || 0,
      name: props.bundleData.name || '',
      type: props.bundleData.type || 'bundle',
      description: props.bundleData.description || '',
      price_tf2: props.bundleData.price_tf2 || null,
      price_euro: props.bundleData.price_euro || null,
      release_date: props.bundleData.release_date || '',
      games: []
    });
  }
});

// Funções
const handleCancel = () => {
  emit('cancel');
  emit('update:visible', false);
};

const handleSave = () => {
  const bundleToSave = { ...localBundleData };

  // Se há jogos selecionados, adiciona os objetos Game ao bundle
  if (props.selectedGames.length > 0) {
    bundleToSave.games = props.selectedGames;
  } else {
    delete bundleToSave.games; // Remove a propriedade games se não há jogos
  }

  emit('save', bundleToSave);
};

const resetForm = () => {
  Object.assign(localBundleData, {
    id: 0,
    name: '',
    type: 'bundle',
    description: '',
    price_tf2: null,
    price_euro: null,
    release_date: '',
    games: [] as Game[]
  });
};

// Reset form when modal closes (mas não quando abre)
watch(() => props.visible, (isVisible, oldValue) => {
  if (!isVisible && oldValue) {
    resetForm();
  }
});
</script>

<template>
  <Dialog :visible="visible" @update:visible="emit('update:visible', $event)" modal
    :header="isEdit ? 'Editar Bundle' : 'Criar Bundle'" :style="{ width: '70%' }">
    <!-- Instruções do modal -->
    <span class="d-block mb-3">
      {{ isEdit ? 'Edite os dados do bundle.' : 'Criar um novo bundle.' }}
    </span>

    <!-- Lista de jogos selecionados (apenas se houver jogos) -->
    <div v-if="selectedGames && selectedGames.length > 0" class="mb-4">
      <h5>Jogos Selecionados ({{ selectedGames.length }}):</h5>
      <div class="border rounded p-3 bg-light">
        <div v-for="game in selectedGames" :key="game.id"
          class="d-flex justify-content-between align-items-center mb-2">
          <span>
            <strong>{{ game.name }} - {{ game.region || 'Global' }}</strong>
          </span>
        </div>
      </div>
    </div>

    <!-- Formulário dos dados do bundle -->
    <div class="d-flex flex-column gap-3">
      <!-- Campo Nome e Tipo -->
      <div class="row">
        <div class="col-12 col-md-6 d-flex flex-column gap-2">
          <label for="bundle_name" class="fw-semibold">Nome do Bundle*</label>
          <InputText id="bundle_name" v-model="localBundleData.name" placeholder="Digite o nome do bundle" />
        </div>

        <div class="col-12 col-md-6 d-flex flex-column gap-2">
          <label>Tipo:</label>
          <div class="flex items-center gap-2">
            <RadioButton inputId="bundle_type_bundle" name="bundleType" value="bundle" v-model="localBundleData.type" />
            <label class="ms-1" for="bundle_type_bundle">Bundle</label>
          </div>
          <div class="flex items-center gap-2">
            <RadioButton inputId="bundle_type_choice" name="bundleType" value="choice" v-model="localBundleData.type" />
            <label class="ms-1" for="bundle_type_choice">Choice</label>
          </div>
        </div>
      </div>

      <!-- Campo Descrição -->
      <div class="d-flex flex-column gap-2">
        <label for="bundle_description" class="fw-semibold">Descrição</label>
        <InputText id="bundle_description" v-model="localBundleData.description"
          placeholder="Digite uma descrição para o bundle" />
      </div>

      <!-- Preços -->
      <div class="d-flex gap-3">
        <!-- Campo Preço TF2 -->
        <div class="d-flex flex-column gap-2 flex-1">
          <label for="bundle_price_tf2" class="fw-semibold">Preço TF2</label>
          <InputNumber id="bundle_price_tf2" v-model="localBundleData.price_tf2" mode="decimal" :minFractionDigits="2"
            :maxFractionDigits="2" useGrouping placeholder="0.00" />
        </div>

        <!-- Campo Preço Euro -->
        <div class="d-flex flex-column gap-2 flex-1">
          <label for="bundle_price_euro" class="fw-semibold">Preço (Euro)</label>
          <InputNumber id="bundle_price_euro" v-model="localBundleData.price_euro" mode="decimal" :minFractionDigits="2"
            :maxFractionDigits="2" useGrouping placeholder="0.00" />
        </div>
      </div>

      <!-- Campo Data de Lançamento -->
      <div class="d-flex flex-column gap-2">
        <label for="bundle_release_date" class="fw-semibold">Data de Lançamento*</label>
        <InputText id="bundle_release_date" v-model="localBundleData.release_date" type="date" />
      </div>
    </div>

    <!-- Botões de ação do modal -->
    <div class="d-flex justify-content-end gap-2 mt-4">
      <Button type="button" label="Cancelar" severity="secondary" @click="handleCancel" />
      <Button type="button" :label="isEdit ? 'Salvar Alterações' : 'Criar Bundle'" @click="handleSave" />
    </div>
  </Dialog>
</template>
