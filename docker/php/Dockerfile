FROM php:8.4-fpm

RUN apt update && apt install -y \
    build-essential \
    libpq-dev \
    libzip-dev \
    libpng-dev \
    git \
    unzip \
    htop \
    procps \
    supervisor \
    librabbitmq-dev \
    libssl-dev \
    rsyslog \
    logrotate \
    && rm -rf /var/lib/apt/lists/*

# oniguruma-dev \
# icu-dev \

# pcov provides fast code coverage for `composer test:coverage`; it is inert unless pcov.enabled=1.
RUN pecl install pcov && docker-php-ext-enable pcov \
    && echo 'pcov.enabled=0' > /usr/local/etc/php/conf.d/zz-pcov.ini

RUN docker-php-ext-install \
    pdo_pgsql \
    pgsql \
    opcache \
    zip \
    gd

# intl \
#     mbstring \

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY php.ini /usr/local/etc/php/conf.d/custom.ini

WORKDIR /var/www/html