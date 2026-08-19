<script setup lang="ts">
/**
 * Moldura da página de entrega — a única do sistema cujo usuário não é a equipe.
 *
 * Não usa o `Layout` padrão de propósito: nada de NavBar, que só tem links que o
 * supplier não pode abrir. O que ela precisa é do oposto, **identidade visível**:
 * um formulário anônimo pedindo `key_code` é indistinguível de phishing, e
 * normalizar isso com os suppliers os torna alvo fácil de quem se passar por nós.
 * Daí a barra roxa e o logo, os mesmos da NavBar interna — a página tem de ser
 * reconhecível como nossa à primeira vista.
 *
 * A marca vem escrita, não de `VITE_APP_NAME`: aquilo é o nome do sistema
 * interno, e quem recebe o link nos conhece por CarcaDeals — mesmo critério do
 * `Footer` da aplicação.
 * 
 * 
 *
 * Em inglês, como toda tela de terceiros (ver docs/adr/0008).
 */
import logo from '@/assets/images/logo.jpg';

// Do relógio do navegador: um ano escrito no código envelhece calado, e já
// envelheceu uma vez no rodapé da aplicação.
const currentYear = new Date().getFullYear();
</script>

<template>
  <div class="delivery-shell d-flex flex-column min-vh-100">
    <header class="delivery-header">
      <div class="container py-3 d-flex align-items-center gap-3">
        <img :src="logo" width="42" height="42" alt="CarcaDeals" class="delivery-logo" />
        <div class="lh-1">
          <div class="fw-bold delivery-brand">CarcaDeals</div>
          <div class="delivery-tagline">Key delivery</div>
        </div>
      </div>
    </header>

    <main class="flex-grow-1">
      <div class="container py-4 delivery-container">
        <slot />
      </div>
    </main>

    <footer class="delivery-footer text-center">
      <div class="container py-3 small">
        © {{ currentYear }} CarcaDeals. All rights reserved.
      </div>
    </footer>
  </div>
</template>

<style scoped>
.delivery-shell {
  background: #f5f7fa;
}

/* Estreito por padrão — é um formulário, e linha longa demais cansa de ler.
   Em tela larga alarga o suficiente para a tabela de keys caber sem rolagem
   horizontal, com o nome do bundle inteiro. */
.delivery-container {
  max-width: 860px;
}

@media (min-width: 1080px) {
  .delivery-container {
    max-width: 1000px;
  }
}

.delivery-header {
  background-color: #8009EF;
  color: #fff;
}

.delivery-logo {
  border-radius: 6px;
  object-fit: cover;
}

.delivery-brand {
  font-size: 1.05rem;
  letter-spacing: 0.2px;
}

.delivery-tagline {
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 1px;
  opacity: 0.75;
  margin-top: 3px;
}

.delivery-footer {
  background-color: #8009EF;
  color: rgba(255, 255, 255, 0.85);
}
</style>
