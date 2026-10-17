#!/bin/sh
set -eu

port="${PORT:-8080}"
case "$port" in
    ''|*[!0-9]*)
        echo "PORT must be a numeric TCP port." >&2
        exit 1
        ;;
esac

sed -i "s/@@PORT@@/${port}/g" /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf

mkdir -p /var/www/html/assets/uploads/announcements /var/www/html/cache
chown www-data:www-data /var/www/html/assets/uploads /var/www/html/assets/uploads/announcements /var/www/html/cache

exec "$@"
