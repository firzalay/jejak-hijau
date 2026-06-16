#!/bin/sh
set -e

echo "==> Starting GreenMile container..."

# -------------------------------------------------------------------------
# 1. Ensure writable directories exist
# -------------------------------------------------------------------------
mkdir -p /var/www/html/storage/framework/{sessions,views,cache}
mkdir -p /var/www/html/storage/logs
mkdir -p /var/www/html/bootstrap/cache
chown -R nginx:nginx /var/www/html/storage /var/www/html/bootstrap/cache

# -------------------------------------------------------------------------
# 2. Write APP_KEY if provided via env, or generate one
# -------------------------------------------------------------------------
if [ -z "$APP_KEY" ]; then
    echo "==> Generating APP_KEY..."
    php /var/www/html/artisan key:generate --force
fi

# -------------------------------------------------------------------------
# 3. Handle Railway's dynamic PORT
#    Nginx listens on 80 by default; Railway proxies from $PORT to 80.
#    If Railway exposes $PORT directly, update nginx to listen on it.
# -------------------------------------------------------------------------
if [ -n "$PORT" ] && [ "$PORT" != "80" ]; then
    echo "==> Updating nginx to listen on PORT=$PORT..."
    sed -i "s/listen 80;/listen $PORT;/g" /etc/nginx/http.d/default.conf
    sed -i "s/listen \[::\]:80;/listen [::]:$PORT;/g" /etc/nginx/http.d/default.conf
fi

# -------------------------------------------------------------------------
# 4. Run database migrations (non-destructive, safe for production)
# -------------------------------------------------------------------------
echo "==> Running migrations..."
php /var/www/html/artisan migrate --force --no-interaction

# -------------------------------------------------------------------------
# 5. Cache configuration for performance
# -------------------------------------------------------------------------
echo "==> Optimizing application..."
php /var/www/html/artisan config:cache
php /var/www/html/artisan route:cache
php /var/www/html/artisan view:cache
php /var/www/html/artisan event:cache

# -------------------------------------------------------------------------
# 6. Start all services via Supervisor
# -------------------------------------------------------------------------
echo "==> Launching services..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
