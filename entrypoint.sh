#!/bin/bash

# Comandos que serão executados depois do container iniciado
# Instala as dependências do Composer
composer install
# composer install --optimize-autoloader --no-dev # Para produção

# Executa as migrações e seeds
composer migrate

# Cacheia as configurações, rotas e views
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Inicia o PHP-FPM
exec "$@"
