<script setup lang="ts">
import { reactive, ref } from 'vue';

// PrimeVue
import Dialog from 'primevue/dialog';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import RadioButton from 'primevue/radiobutton';

// Props
const props = defineProps({
  visible: {
    type: Boolean,
    required: true
  }
});

// Emits
const emit = defineEmits<{
  'update:visible': [value: boolean]
  'search': [searchData: any]
  'clear': []
}>();

// Dados do formulário de pesquisa
const searchData = reactive({
  name: '',
  type: '',
  description: '',
  minimum_price_tf2_min: null,
  minimum_price_tf2_max: null,
  price_dolar_min: null,
  price_dolar_max: null,
  release_date_start: '',
  release_date_end: '',
  game_name: '',
});

// Funções
const handleCancel = () => {
  emit('update:visible', false);
};

const handleSearch = () => {
  // Remove campos vazios do objeto de pesquisa
  const cleanSearchData = Object.fromEntries(
    Object.entries(searchData).filter(([_, value]) =>
      value !== '' && value !== null && value !== undefined
    )
  );

  emit('search', cleanSearchData);
  emit('update:visible', false);
};

const handleClear = () => {
  // Limpa todos os campos
  Object.assign(searchData, {
    name: '',
    type: '',
    description: '',
    minimum_price_tf2_min: null,
    minimum_price_tf2_max: null,
    price_dolar_min: null,
    price_dolar_max: null,
    release_date_start: '',
    release_date_end: '',
    game_name: '',
  });

  emit('clear');
};

</script>

<template>
  <Dialog :visible="visible" @update:visible="emit('update:visible', $event)" modal header="Pesquisar Bundles"
    :style="{ width: '70%' }">

    <!-- Formulário de pesquisa -->
    <div class="d-flex flex-column gap-3">
      <!-- Campo Nome e Tipo -->
      <div class="row">
        <div class="col-12 col-md-6 d-flex flex-column gap-2">
          <label for="search_name" class="fw-bold">Nome do Bundle</label>
          <InputText id="search_name" v-model="searchData.name" placeholder="Digite parte do nome do bundle" />
        </div>

        <div class="col-12 col-md-6 d-flex flex-column gap-2">
          <label class="fw-bold">Tipo:</label>
          <div class="d-flex gap-3">
            <div class="flex items-center gap-2">
              <RadioButton inputId="search_type_all" name="searchType" value="" v-model="searchData.type" />
              <label class="ms-1" for="search_type_all">Todos</label>
            </div>
            <div class="flex items-center gap-2">
              <RadioButton inputId="search_type_bundle" name="searchType" value="bundle" v-model="searchData.type" />
              <label class="ms-1" for="search_type_bundle">Bundle</label>
            </div>
            <div class="flex items-center gap-2">
              <RadioButton inputId="search_type_choice" name="searchType" value="choice" v-model="searchData.type" />
              <label class="ms-1" for="search_type_choice">Choice</label>
            </div>
          </div>
        </div>
      </div>

      <!-- Campo Descrição -->
      <div class="d-flex flex-column gap-2">
        <label for="search_description" class="fw-bold">Descrição</label>
        <InputText id="search_description" v-model="searchData.description"
          placeholder="Digite parte da descrição do bundle" />
      </div>

      <!-- Faixa de Preços TF2 -->
      <div class="d-flex flex-column gap-2">
        <label class="fw-semibold">Faixa de Preço Mínimo TF2</label>
        <div class="d-flex flex-column flex-md-row gap-3 align-items-center">
          <div>
            <label for="search_minimum_price_tf2_min" class="text-sm">Preço Mínimo</label>
            <InputNumber id="search_minimum_price_tf2_min" v-model="searchData.minimum_price_tf2_min" mode="decimal"
              :minFractionDigits="2" :maxFractionDigits="2" useGrouping placeholder="0.00" class="w-100" />
          </div>
          <span class="text-center">até</span>
          <div>
            <label for="search_minimum_price_tf2_max" class="text-sm">Preço Máximo</label>
            <InputNumber id="search_minimum_price_tf2_max" v-model="searchData.minimum_price_tf2_max" mode="decimal"
              :minFractionDigits="2" :maxFractionDigits="2" useGrouping placeholder="0.00" class="w-100" />
          </div>
        </div>
      </div>

      <!-- Faixa de Preços Dólar -->
      <div class="d-flex flex-column gap-2">
        <label class="fw-bold">Faixa de Preço (Dólar)</label>
        <div class="d-flex flex-column flex-md-row gap-3 align-items-center">
          <div class="flex-1">
            <label for="search_price_dolar_min" class="text-sm">Preço Mínimo</label>
            <InputNumber id="search_price_dolar_min" v-model="searchData.price_dolar_min" mode="decimal"
              :minFractionDigits="2" :maxFractionDigits="2" useGrouping placeholder="0.00" class="w-100" />
          </div>
          <span class="text-center">até</span>
          <div class="flex-1">
            <label for="search_price_dolar_max" class="text-sm">Preço Máximo</label>
            <InputNumber id="search_price_dolar_max" v-model="searchData.price_dolar_max" mode="decimal"
              :minFractionDigits="2" :maxFractionDigits="2" useGrouping placeholder="0.00" class="w-100" />
          </div>
        </div>
      </div>

      <!-- Faixa de Data de Lançamento -->
      <div class="d-flex flex-column gap-2">
        <label class="fw-semibold">Período de Lançamento</label>
        <div class="d-flex flex-column flex-sm-row gap-3 align-items-center">
          <div class="flex-1">
            <label for="search_release_date_start" class="text-sm">Data Inicial</label>
            <InputText id="search_release_date_start" v-model="searchData.release_date_start" type="date"
              class="w-100" />
          </div>
          <span class="text-center">até</span>
          <div class="flex-1">
            <label for="search_release_date_end" class="text-sm">Data Final</label>
            <InputText id="search_release_date_end" v-model="searchData.release_date_end" type="date" class="w-100" />
          </div>
        </div>
      </div>
      --------------------------------------------
      <div class="d-flex flex-column gap-2">
        <label class="fw-semibold">Jogos</label>
        <div class="d-flex flex-column flex-sm-row gap-3 align-items-center">
          <div class="flex-1">
            <label for="search_game_name" class="text-sm">Nome</label>
            <InputText id="search_game_name" v-model="searchData.game_name"
              class="w-100" />
          </div>
        </div>
      </div>
    </div>

    <!-- Botões de ação do modal -->
    <div class="d-flex flex-column flex-sm-row justify-content-between mt-4 gap-3">
      <Button type="button" label="Limpar Filtros" severity="secondary" icon="pi pi-times" @click="handleClear"
        outlined />
      <div class="d-flex gap-2">
        <Button type="button" label="Cancelar" severity="secondary" @click="handleCancel" />
        <Button type="button" label="Pesquisar" icon="pi pi-search" @click="handleSearch" />
      </div>
    </div>
  </Dialog>
</template>
