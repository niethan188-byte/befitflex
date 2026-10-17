FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev libpng-dev libxml2-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" curl dom gd mbstring mysqli pdo_mysql zip \
    && for mpm in event worker; do \
        if [ -e "/etc/apache2/mods-enabled/mpm_${mpm}.load" ]; then a2dismod "mpm_${mpm}"; fi; \
    done \
    && a2enmod mpm_prefork \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

COPY . .
COPY docker/apache-ports.conf /etc/apache2/ports.conf
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/railway.ini
COPY docker/entrypoint.sh /usr/local/bin/railway-entrypoint

RUN chmod +x /usr/local/bin/railway-entrypoint \
    && mkdir -p assets/uploads/announcements cache \
    && chown -R www-data:www-data assets/uploads cache

EXPOSE 8080

ENTRYPOINT ["railway-entrypoint"]
CMD ["apache2-foreground"]
