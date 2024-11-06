<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import axiosInstance from '../../axios';
import { showResponse } from '../../helpers/showResponse';
import { useToast } from "primevue/usetoast";

const toast = useToast();
// @ts-ignore
let user = ref(usePage().props.auth.user);

const handleLogout = async () => {
  try {
    // Envia a requisição de logout
    const res = await axiosInstance.post('auth/logout');
    showResponse(res, toast.add);
    // @ts-ignore
    user.value = null;
  } catch (error) {
    console.error(error);
  }
};

</script>

<template>
  <main>
    <nav class="navbar navbar-expand-lg" style="background-color: #8009EF; color: white;">
      <div class="container-fluid">
        <Link class="navbar-brand" :href="route('venda-chave-troca')">
        <!-- O erro em "route" é normal, o typescript não reconhece pq ele faz parte do ziggy. -->
        <img src="@\assets\images\logo.jpg" width="45" height="45" alt="logo"></Link>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent"
          aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
          <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarSupportedContent">
          <ul class="navbar-nav me-auto mb-2 mb-lg-0">
            <li class="nav-item">
            </li>
            <li class="nav-item" v-if="user && user.email === 'joaovitormatosgouveia@gmail.com'">
              <Link class="nav-link" :href="route('acesso')">Acesso</Link>
            </li>
            <!-- <li><RouterLink class="nav-link" to="/venda-chave-troca">Venda-Chave-Troca</RouterLink></li> -->
            <li>
              <Link class="nav-link" :href="route('venda-chave-troca')">Venda-Chave-Troca</Link>
            </li>
            <li class="nav-item dropdown">
              <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"
                aria-expanded="false">
                Taxas
              </a>
              <ul class="dropdown-menu">
                <li>
                  <Link class="dropdown-item" :href="route('fees')">MarketPlaces</Link>
                </li>
                <li>
                  <Link class="dropdown-item" :href="route('ranges-taxa-G2A')">Ranges Taxa G2A</Link>
                </li>
                <!-- <li><Link class="dropdown-item" :href="route('fees')">Calculadora</Link></li> -->
              </ul>
            </li>
            <li>
              <Link class="nav-link" :href="route('resources')">Recursos</Link>
            </li>
          </ul>
        </div>
        <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
          <li class="nav-item dropdown" v-if="user">
            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown"
              aria-expanded="false">
              Olá, {{ user.name }}!
            </a>
            <ul class="dropdown-menu" aria-labelledby="userDropdown">
              <a href="#" class="dropdown-item px-3 w-auto" @click.prevent="handleLogout">
                <li class="d-flex justify-content-center align-items-center gap-3 text-center">
                  <!-- <Link class="nav-link" :href="route('auth.logout')">Logout</Link> -->
                  <i class="pi pi-user"></i>
                  Logout
                </li>
              </a>
            </ul>
          </li>
          <li class="d-flex align-items-center" v-else>
            <i class="pi pi-user"></i>
            <Link class="nav-link" :href="route('login')">Login</Link>
          </li>
        </ul>
      </div>
    </nav>
  </main>
</template>