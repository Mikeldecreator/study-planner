#!/bin/bash
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Decoupled notification scheduler daemon started (PID $$)" >> /var/log/check_deadlines.log

# Ensure log files exist
touch /var/log/dispatch_pushes.log /var/log/check_deadlines.log

# Trap termination signals to cleanly shut down child background loops
trap 'kill $(jobs -p) 2>/dev/null' SIGTERM SIGINT EXIT

# 1. Fast Dispatch Loop: lightweight pending push queue processor (every 5 seconds)
(
    while true; do
        php /var/www/html/cron/dispatch_pushes.php >> /var/log/dispatch_pushes.log 2>&1 || true
        sleep 5
    done
) &
FAST_PID=$!

# 2. Heavy Generation Loop: global academic reminder evaluation (every 180 seconds = 3 minutes)
(
    while true; do
        php /var/www/html/cron/generate_reminders.php >> /var/log/check_deadlines.log 2>&1 || true
        sleep 180
    done
) &
HEAVY_PID=$!

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Fast dispatcher (PID $FAST_PID) and Heavy generator (PID $HEAVY_PID) spawned successfully" >> /var/log/check_deadlines.log

# Keep daemon process running and wait for background subshells
wait
