# Deploy

Deploy é **automático ao mergear na `main`**, via GitHub Actions (`.github/workflows/`):

| Workflow | Trigger | Jobs |
|----------|---------|------|
| `ci.yml` | push/PR em `main` | Pint · PHPStan · Pest (paralelos) |
| `deploy.yml` | `ci.yml` conclui com sucesso em `main` | Build frontend → SSH deploy → SCP `public/build` |

Fluxo: merge na `main` → `ci.yml` (Pint + PHPStan + Pest) → `deploy.yml` (build do frontend no runner → SSH na VPS: `git pull` + `composer install` se o lock mudou + `migrate` + caches → SCP do `public/build` para a VPS).

**Secrets** (GitHub → Settings → Secrets and variables → Actions): `VPS_HOST`, `VPS_USER`, `VPS_SSH_KEY`, `VPS_PORT`.

## O que o deploy **não** faz

- **Não recria container nem reconstrói imagem.** Roda `git pull` e `docker exec`. Mudança em `docker-compose.yml` (porta, volume, variável de ambiente) só vale depois de `docker compose up -d <serviço>` na VPS, à mão; mudança em `Dockerfile`, `entrypoint.sh` ou `docker/resolve-app-env.sh` exige `docker compose up -d --build <serviço>`, porque esses arquivos são copiados **para dentro da imagem** — o bind mount do projeto não os alcança em `/usr/local/bin`. Um container pode continuar rodando com o comportamento de meses atrás enquanto o repositório na VPS já está atualizado. *(Já aconteceu: em 2026-08-24 o `app-cd` ainda rodava a imagem anterior à correção do `APP_ENV` de 20/08 — quatro dias de `git pull` verdes sobre um container que nunca recebeu a correção. Ver `docs/IMPROVEMENTS.md`.)*
- **Não sobrescreve arquivo alterado no servidor.** Editar `.conf`, `backup.sh` ou qualquer arquivo versionado direto na VPS faz o `git pull` abortar. Desde 2026-08-19 o deploy **falha** nesse caso, listando os arquivos: antes ele seguia em frente e terminava verde sobre o código antigo, porque o `appleboy/ssh-action` só olha o código de saída do último comando. É o que `script_stop: true` e a checagem de working tree resolvem.
- **Não reverte migration.** `migrate --force` só avança.

Quando o pull travar por arquivo alterado na VPS, **olhe o diff antes de descartar** — `.conf` de nginx costuma ter ajuste feito na emergência que nunca voltou para o repositório:

```bash
cd /var/www/sistema-estoque-jogos-cd
git diff > /root/vps-local-changes-$(date +%F).patch   # guarda o que existe hoje
git diff --stat                                        # o que diverge
git checkout -- <arquivo>                              # descarta só o que não importa
git pull origin main
```

> Todas as pendências do sistema (qualidade de código, features, dívida técnica) estão centralizadas em [`docs/IMPROVEMENTS.md`](../IMPROVEMENTS.md).
