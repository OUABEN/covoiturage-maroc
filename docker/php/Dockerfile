FROM php:8.3-fpm-alpine

# Dépendances système et extensions PHP requises par Symfony 7.4
RUN apk add --no-cache \
        git \
        unzip \
        icu-dev \
        libzip-dev \
        $PHPIZE_DEPS \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        intl \
        zip \
        opcache \
        bcmath \
    && git config --global --add safe.directory /var/www/html

# Installation de Composer depuis l'image officielle
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh \
    && chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
