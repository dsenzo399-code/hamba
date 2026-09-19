#!/data/data/com.termux/files/usr/bin/bash
# Hamba engine — the ONLY command you ever run in Termux.
# Installs everything missing, fixes database login, starts the server.
# Keep this session open, then open the Hamba app.
set -e
cd "$HOME/hamba" 2>/dev/null || { echo "Cloning Hamba first..."; echo "Run: git clone https://github.com/dsenzo399-code/hamba.git ~/hamba"; exit 1; }
command -v php >/dev/null || pkg install -y php mariadb
php -m | grep -qi pdo_mysql || pkg install -y php-mysql 2>/dev/null || true
if [ ! -d "$PREFIX/var/lib/mysql/mysql" ]; then
  mariadb-install-db 2>/dev/null || mysql_install_db 2>/dev/null || true
fi
mkdir -p "$PREFIX/var/run/mysqld"
LOG="$HOME/hamba-mysqld.log"
SOCK="$PREFIX/var/run/mysqld/mysqld.sock"
# NOTE: mysqld_safe is not used on purpose — it tries to lock folders
# Android never allows. mariadbd started directly with explicit paths works.
mysqladmin -h 127.0.0.1 -u root shutdown 2>/dev/null || true
sleep 2
nohup mariadbd --datadir="$PREFIX/var/lib/mysql" --bind-address=127.0.0.1 --port=3306 --socket="$SOCK" --pid-file="$PREFIX/var/run/mysqld/mysqld.pid" >"$LOG" 2>&1 &
sleep 5
if ! mysqladmin -h 127.0.0.1 -u root ping >/dev/null 2>&1; then
  echo "--- database did not start, here is why: ---"
  tail -30 "$LOG" 2>/dev/null || echo "(no log file was written)"
  exit 1
fi
if ! mysql -h 127.0.0.1 -u root -e "SELECT 1" >/dev/null 2>&1; then
  mysql --socket="$SOCK" -u root -e "ALTER USER 'root'@'localhost' IDENTIFIED VIA mysql_native_password USING PASSWORD(''); FLUSH PRIVILEGES;" 2>/dev/null || \
  mysql --socket="$SOCK" -u root -e "SET PASSWORD FOR 'root'@'localhost' = PASSWORD('');" 2>/dev/null || true
fi
termux-wake-lock 2>/dev/null || true
echo "Hamba engine running. Open the Hamba app now."
exec php -S 0.0.0.0:8080 -t "$HOME/hamba"
