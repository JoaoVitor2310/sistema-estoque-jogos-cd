<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import NavBar from './NavBar.vue';
import Footer from './Footer.vue';
import { Suspense } from 'vue';
import ProgressSpinner from 'primevue/progressspinner';
</script>

<template>
    <div class="d-flex flex-column min-vh-100">
        <NavBar />
        <Suspense>
            <article class="d-flex flex-grow-1">
                <slot />
            </article>
            <template #fallback>
                <ProgressSpinner />
              </template>
        </Suspense>
        <Footer />
    </div>
</template>