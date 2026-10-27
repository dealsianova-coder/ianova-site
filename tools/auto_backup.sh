#!/bin/sh
# Nightly backup: the database every time, the uploads folder on Sundays. Keeps the newest 14 (see backup.php).
# Set IANOVA_COPY_DOWNLOADS=1 to also copy the newest database backup to Downloads/ianova-backups (keeps 7).
DIR="$(cd "$(dirname "$0")" && pwd)"
B="$HOME/ianova-backups"; mkdir -p "$B"
LOG="$B/backup.log"
ARG=""; [ "$(date +%u)" = "7" ] && ARG="uploads"
{ echo "== $(date)"; sh "$DIR/run.sh" backup $ARG; } >> "$LOG" 2>&1
if [ "$IANOVA_COPY_DOWNLOADS" = "1" ] && [ -d "$HOME/storage/downloads" ]; then
  NEW=$(ls -1t "$B"/ianova-*.sql.gz 2>/dev/null | head -1)
  OUT="$HOME/storage/downloads/ianova-backups"
  if [ -n "$NEW" ]; then mkdir -p "$OUT" && cp "$NEW" "$OUT/" && ls -1t "$OUT"/ianova-*.sql.gz | tail -n +8 | xargs -r rm -f; fi
fi
tail -n 300 "$LOG" > "$LOG.tmp" && mv "$LOG.tmp" "$LOG"
tail -n 3 "$LOG"
