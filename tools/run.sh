#!/bin/sh
# Runs a tool with the database reachable (Termux's command-line PHP does not know the socket by default).
# Usage: sh ~/ianova-php/tools/run.sh selftest   |   backup   |   reset_security you@email.com
DIR="$(cd "$(dirname "$0")" && pwd)"
NAME="$1"; shift
P="${PREFIX:-/data/data/com.termux/files/usr}"
SOCK=""
# 1. ask the database itself
for q in mariadb mysql; do
  command -v $q >/dev/null 2>&1 && S=$($q -N -e 'select @@socket' 2>/dev/null) && [ -S "$S" ] && SOCK="$S" && break
done
# 2. common places, then anything that looks like a socket
if [ -z "$SOCK" ]; then
  for c in "$P/tmp/mysqld.sock" "$P/var/run/mysqld/mysqld.sock" /run/mysqld/mysqld.sock /var/run/mysqld/mysqld.sock /tmp/mysql.sock /tmp/mysqld.sock; do
    [ -S "$c" ] && SOCK="$c" && break
  done
fi
if [ -z "$SOCK" ]; then
  SOCK=$(find "$P/tmp" "$P/var/run" /tmp /run -maxdepth 3 -name '*.sock' 2>/dev/null | grep -i -E 'mysql|maria' | head -1)
fi
if [ -n "$SOCK" ]; then exec php -d "pdo_mysql.default_socket=$SOCK" "$DIR/$NAME.php" "$@"; fi
# 3. no socket found: connect over the network instead
PRE="${TMPDIR:-/tmp}/ianova_tcp.php"
printf "<?php if (!defined('DB_HOST')) define('DB_HOST', '127.0.0.1');\n" > "$PRE"
exec php -d "auto_prepend_file=$PRE" "$DIR/$NAME.php" "$@"
