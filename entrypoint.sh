#!/bin/bash

# Comandos que serão executados depois do container iniciado
# Define permissões para os diretórios de armazenamento e cache
chmod -R 775 /var/www/html/storage
chown -R www-data:www-data /var/www/html/storage
chmod -R 775 /var/www/html/bootstrap/cache
chown -R www-data:www-data /var/www/html/bootstrap/cache

# Instala as dependências do Composer
composer install
# composer install --optimize-autoloader --no-dev # Para produção

npm install
npm run build

# Executa as migrações e seeds
composer migrate

# Cacheia as configurações, rotas e views
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Inicia o PHP-FPM
exec php-fpm