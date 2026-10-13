#!/data/data/com.termux/files/usr/bin/bash
SITE="$HOME/ianova-php"
pick() { for c in "$@"; do command -v "$c" >/dev/null 2>&1 && { echo "$c"; return; }; done; }
SAFE=$(pick mysqld_safe mariadbd-safe)
ADMIN=$(pick mariadb-admin mysqladmin)
CLIENT=$(pick mariadb mysql)
if ! $ADMIN -u root ping >/dev/null 2>&1; then
  echo "Starting MariaDB..."
  nohup $SAFE >/dev/null 2>&1 &
  for i in $(seq 1 40); do
    $ADMIN -u root ping >/dev/null 2>&1 && break
    sleep 1
  done
fi
$ADMIN -u root ping >/dev/null 2>&1 || { echo "MariaDB did not start."; exit 1; }
[ "$1" = "--db-only" ] && exit 0
SOCK=$($CLIENT -u root -N -e "SELECT @@socket" 2>/dev/null)
tmux kill-session -t ianova 2>/dev/null
tmux new-session -d -s ianova "cd '$SITE' && php -d upload_max_filesize=8M -d post_max_size=10M -d pdo_mysql.default_socket='$SOCK' -d mysqli.default_socket='$SOCK' -S 127.0.0.1:8080"
sleep 2
R=$(curl -s http://127.0.0.1:8080/api/categories.php)
case "$R" in
  *'"success":true'*) echo "IANOVA is running at http://127.0.0.1:8080" ;;
  *) echo "The site is up, but the API answered:"; echo "$R" | head -c 300; echo; echo "Details: tmux attach -t ianova" ;;
esac
