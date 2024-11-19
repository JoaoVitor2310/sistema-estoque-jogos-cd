FROM php:8.3-fpm

RUN apt-get update

# Install useful tools
RUN apt-get -y install apt-utils nano wget dialog

# Install system and laravel dependencies
RUN apt-get -y install --fix-missing \
    apt-utils \
    build-essential \
    git \
    curl \
    libcurl4 \
    libcurl4-openssl-dev \
    zlib1g-dev \
    libzip-dev \
    zip \
    locales \
    libicu-dev \
    libonig-dev \
    libxml2-dev

RUN docker-php-ext-install \
    pcntl \
    bcmath \
    ctype \
    curl \
    zip 

RUN apt-get install -y libpq-dev \
    && docker-php-ext-configure pgsql -with-pgsql=/usr/local/pgsql \
    && docker-php-ext-install pdo pdo_pgsql pgsql

RUN curl -fsSL https://deb.nodesource.com/setup_lts.x | bash -
RUN apt-get install -y nodejs

RUN apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:lts /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . /var/www/html

# Copia o script de entrypoint para o container
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

CMD ["php-fpm"]