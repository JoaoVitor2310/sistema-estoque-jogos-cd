import { defineConfig } from 'vitest/config';

// Separado do vite.config.js: os testes são de funções puras e não precisam
// do plugin do Laravel nem do Vue.
export default defineConfig({
    test: {
        include: ['resources/js/**/*.test.ts'],
    },
});
