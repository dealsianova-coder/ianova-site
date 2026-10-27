#!/bin/sh
# One-time setup for nightly backups at 03:15 (Termux cron).
# Usage: sh ~/ianova-php/tools/schedule.sh          (backups stay in ~/ianova-backups)
#        sh ~/ianova-php/tools/schedule.sh copy     (also copy each night's database backup to Downloads/ianova-backups)
DIR="$(cd "$(dirname "$0")" && pwd)"
COPY=0; [ "$1" = "copy" ] && COPY=1
command -v crond >/dev/null 2>&1 || pkg install -y cronie termux-services
LINE="15 3 * * * IANOVA_COPY_DOWNLOADS=$COPY sh $DIR/auto_backup.sh >/dev/null 2>&1"
( crontab -l 2>/dev/null | grep -v auto_backup.sh; echo "$LINE" ) | crontab -
command -v termux-wake-lock >/dev/null 2>&1 && termux-wake-lock
pgrep -x crond >/dev/null 2>&1 || crond
echo "Scheduled: every night at 03:15. Running one backup now..."
IANOVA_COPY_DOWNLOADS=$COPY sh "$DIR/auto_backup.sh"
echo
echo "Check it later with:  crontab -l   and   tail ~/ianova-backups/backup.log"
echo "Android may stop Termux in the background. Set Termux's battery use to Unrestricted so the night job runs."
