#!/bin/bash
set -e

# Support dynamic port assignment from Render ($PORT)
if [ -n "$PORT" ]; then
    sed -i "s/Listen 80/Listen $PORT/" /etc/apache2/ports.conf
    sed -i "s/<VirtualHost \*:80>/<VirtualHost \*:$PORT>/" /etc/apache2/sites-available/000-default.conf
fi

# Ensure uploads directory exists and has correct permissions
mkdir -p /var/www/html/public/uploads
chown -R www-data:www-data /var/www/html/public/uploads

# Run idempotent database migration if DB credentials are provided
if [ -n "$DB_HOST" ] && [ "$DB_HOST" != "localhost" ]; then
    echo "Executing safe idempotent database migrations against $DB_HOST..."
    php /var/www/html/database/migrate.php || echo "Migration notice: continuing startup..."
fi

# Start background notification scheduler daemon (runs every 5 minutes)
(
    while true; do
        sleep 300
        php /var/www/html/cron/notification_scheduler.php >/dev/null 2>&1 || true
    done
) &

exec "$@"

