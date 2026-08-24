#!/bin/bash
set -e

# Permissões de storage e cache. Diretório e arquivo levam modos diferentes de
# propósito: `chmod -R 775` ligava o bit de execução também nos arquivos, e os
# dez `.gitignore` versionados dentro de storage/ e bootstrap/cache/ passavam de
# 100644 para 100755 aos olhos do git. O deploy então abortava por working tree
# suja na VPS, sem uma linha de conteúdo alterada (2026-08-24). 664 basta para o
# www-data escrever, e o git não distingue 664 de 644 — só o bit de execução.
find /var/www/html/storage /var/www/html/bootstrap/cache -type d -exec chmod 775 {} +
find /var/www/html/storage /var/www/html/bootstrap/cache -type f -exec chmod 664 {} +
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Dependências PHP
composer install --no-interaction

# Dependências Node
npm install

# Ambiente: resolvido a partir do `.env`, que é o que o Laravel lê em runtime —
# a variável de shell é só fallback. Ver docker/resolve-app-env.sh para o porquê.
APP_ENV="$(/usr/local/bin/resolve-app-env.sh /var/www/html/.env)"
echo "[entrypoint] APP_ENV resolvido: $APP_ENV"

if [ "$APP_ENV" = "production" ]; then
    echo "[entrypoint] Ambiente: production"

    # Marcador do Vite: se sobrou de uma subida anterior em modo dev, o Blade
    # aponta os assets para localhost:5173 e a página carrega sem nunca montar.
    rm -f /var/www/html/public/hot

    npm run build

    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
else
    echo "[entrypoint] Ambiente: local (Vite HMR ativo na porta 5173)"
    # Inicia o Vite dev server em background (HMR)
    npm run dev &
fi

# Inicia o PHP-FPM em foreground
exec php-fpm
