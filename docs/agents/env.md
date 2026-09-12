# Variáveis de ambiente

```env
# Serviço externo de pesquisa de preços
API_PRICE_RESEARCHER=
DEV_API_PRICE_RESEARCHER=
# Token exigido pelo price_researcher nos disparos assíncronos (pesquisa de bundle).
# Ausente ou errado, ele não recusa: responde 200 em modo demo e nunca chama o
# callback. Ver docs/PRICE_RESEARCHER.md, endpoint 6.
INTERNAL_SECRET=

# API Gamivo — chamada diretamente pelo Laravel
# API_GAMIVO_URL = base URL da API (ex: https://backend.gamivo.com)
# API_KEY_GAMIVO = Bearer JWT (expira — precisa rotacionar manualmente)
# Quando expirar: o sistema detecta UNAUTHORIZED_EXPIRED_TOKEN e envia e-mail de alerta
# ⚠️  PRODUÇÃO REAL — ver docs/agents/security-and-guardrails.md
API_GAMIVO_URL=https://backend.gamivo.com
API_KEY_GAMIVO=

# Cotação de moeda (AwesomeAPI) — aba Recursos, conversão entre BRL/USD/EUR
# Lida por config('services.awesome_api.key'), nunca por env() em runtime: o
# deploy roda `config:cache` e a partir daí env() devolve null. Em branco, a
# chamada cai no tier público, limitado por IP, e o servidor leva 429 — a tela
# grava o preço digitado sem converter os outros dois.
API_KEY_AWESOME_API=

# Google OAuth
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=

# Sistema
# ADMIN_EMAIL alimenta DUAS configs, ambas SEM fallback:
#   config('app.admin_email')      → destinatário de todos os alertas
#   config('app.admin_gate_email') → identidade do admin (Gate 'is-admin')
# Em branco: ninguém é admin e nenhum alerta é entregue (o envio lança e cai no
# log). Obrigatória em todo ambiente, inclusive no .env.example que o CI copia.
ADMIN_EMAIL=carcadeals@gmail.com
EXTERNAL_SECRET=            # Bearer token exigido de serviços externos que chamam o Sistema Estoque (sentido inverso do INTERNAL_SECRET)
```

Ver também [`security-and-guardrails.md`](security-and-guardrails.md) para o raciocínio por trás da separação `admin_email`/`admin_gate_email` e da ausência de fallback.
