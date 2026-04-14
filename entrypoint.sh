#!/bin/bash
set -e

# Permissões de storage e cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Dependências PHP
composer install --no-interaction

# Dependências Node
npm install

APP_ENV="${APP_ENV:-local}"

if [ "$APP_ENV" = "production" ]; then
    echo "[entrypoint] Ambiente: production"
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