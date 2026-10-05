#!/bin/bash
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Academic notification scheduler daemon started (PID $$)" >> /var/log/check_deadlines.log

while true; do
    php /var/www/html/cron/check_deadlines.php >> /var/log/check_deadlines.log 2>&1 || true
    sleep 30
done
