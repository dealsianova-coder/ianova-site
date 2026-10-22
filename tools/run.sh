#!/bin/sh
# Runs a tool with the database socket set (Termux's command-line PHP does not know it by default).
# Usage: sh ~/ianova-php/tools/run.sh selftest   |   backup   |   reset_security you@email.com
DIR="$(cd "$(dirname "$0")" && pwd)"
NAME="$1"; shift
SOCK=""
for c in "${PREFIX:-/data/data/com.termux/files/usr}/tmp/mysqld.sock" /run/mysqld/mysqld.sock /var/run/mysqld/mysqld.sock /tmp/mysql.sock; do
  [ -S "$c" ] && SOCK="$c" && break
done
if [ -n "$SOCK" ]; then exec php -d "pdo_mysql.default_socket=$SOCK" "$DIR/$NAME.php" "$@"; fi
exec php "$DIR/$NAME.php" "$@"
