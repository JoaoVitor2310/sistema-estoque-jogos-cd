<script setup lang="ts">
// Do relógio do navegador: um ano escrito no código envelhece calado, e já
// envelheceu aqui uma vez.
const currentYear = new Date().getFullYear();
</script>

<template>
    <footer class="app-footer text-center mt-auto py-3">
        <p class="mb-0 small">© {{ currentYear }} CarcaDeals. Todos os direitos reservados.</p>
    </footer>
</template>

<style scoped>
.app-footer {
    background-color: #8009EF;
    color: rgba(255, 255, 255, 0.85);
}
</style>
