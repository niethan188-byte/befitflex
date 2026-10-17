FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev libpng-dev libxml2-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" curl dom gd mbstring mysqli pdo_mysql zip \
    && for module in /etc/apache2/mods-enabled/mpm_*.load; do \
        [ -e "$module" ] || continue; \
        mpm="$(basename "$module" .load)"; \
        if [ "$mpm" != "mpm_prefork" ]; then a2dismod "$mpm"; fi; \
    done \
    && a2enmod mpm_prefork \
    && mpm_count="$(apache2ctl -M 2>&1 | grep -Ec ' mpm_(event|worker|prefork)_module' || true)" \
    && if [ "$mpm_count" -ne 1 ]; then \
        echo "Expected exactly one Apache MPM, found $mpm_count." >&2; \
        apache2ctl -M >&2; \
        exit 1; \
    fi \
    && apache2ctl -M 2>&1 | grep -q ' mpm_prefork_module' \
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
