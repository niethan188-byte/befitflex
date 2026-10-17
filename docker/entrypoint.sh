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

for module in /etc/apache2/mods-enabled/mpm_*.load; do
    [ -e "$module" ] || continue
    mpm="$(basename "$module" .load)"
    if [ "$mpm" != "mpm_prefork" ]; then
        a2dismod "$mpm"
    fi
done
a2enmod mpm_prefork

mpm_count="$(apache2ctl -M 2>&1 | grep -Ec ' mpm_(event|worker|prefork)_module' || true)"
if [ "$mpm_count" -ne 1 ] || ! apache2ctl -M 2>&1 | grep -q ' mpm_prefork_module'; then
    echo "Apache must have only mpm_prefork enabled; found $mpm_count MPM modules." >&2
    apache2ctl -M >&2
    exit 1
fi

mkdir -p /var/www/html/assets/uploads/announcements /var/www/html/cache
chown www-data:www-data /var/www/html/assets/uploads /var/www/html/assets/uploads/announcements /var/www/html/cache

exec "$@"
