#!/bin/bash

CRON_USER="www"
APP_DIR="/www/wwwroot/x2bsky.desuwa.org"
LOG_FILE="/var/log/x2bsky/cron.log"

exec >> $LOG_FILE 2>&1
echo "=== Cron run at $(date) ==="

cd $APP_DIR || exit 1

/usr/bin/php81 cron.php 10 >> $LOG_FILE 2>&1

echo "=== Cron completed at $(date) ==="
