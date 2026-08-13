# Variáveis de ambiente

```env
# Serviço externo de pesquisa de preços
API_PRICE_RESEARCHER=
DEV_API_PRICE_RESEARCHER=

# API Gamivo — chamada diretamente pelo Laravel
# API_GAMIVO_URL = base URL da API (ex: https://backend.gamivo.com)
# API_KEY_GAMIVO = Bearer JWT (expira — precisa rotacionar manualmente)
# Quando expirar: o sistema detecta UNAUTHORIZED_EXPIRED_TOKEN e envia e-mail de alerta
# ⚠️  PRODUÇÃO REAL — ver docs/agents/security-and-guardrails.md
API_GAMIVO_URL=https://backend.gamivo.com
API_KEY_GAMIVO=

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
EXTERNAL_SECRET=            # Bearer token exigido de serviços externos que chamam o Sistema Estoque
```

Ver também [`security-and-guardrails.md`](security-and-guardrails.md) para o raciocínio por trás da separação `admin_email`/`admin_gate_email` e da ausência de fallback.
