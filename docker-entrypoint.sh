#!/bin/sh
# Railway assigns a different $PORT at runtime, so we can't bake it
# into the Apache config at build time — this substitutes the real
# value in every time the container starts, right before Apache runs.
set -e

PORT="${PORT:-8080}"

sed -i "s/Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
