#!/data/data/com.termux/files/usr/bin/bash
# Hamba engine — the ONLY command you ever run in Termux.
# Installs everything missing, fixes database login, starts the server.
# Keep this session open, then open the Hamba app.
set -e
cd "$HOME/hamba" 2>/dev/null || { echo "Cloning Hamba first..."; echo "Run: git clone <your-github-link> ~/hamba"; exit 1; }
command -v php >/dev/null || pkg install -y php mariadb
php -m | grep -qi pdo_mysql || pkg install -y php-mysql 2>/dev/null || true
if [ ! -d "$PREFIX/var/lib/mysql/mysql" ]; then
  mariadb-install-db 2>/dev/null || mysql_install_db 2>/dev/null || true
fi
mysqld_safe >/dev/null 2>&1 &
sleep 3
if ! mysql -h 127.0.0.1 -u root -e "SELECT 1" >/dev/null 2>&1; then
  mysql -u root -e "ALTER USER 'root'@'localhost' IDENTIFIED VIA mysql_native_password USING PASSWORD(''); FLUSH PRIVILEGES;" 2>/dev/null || \
  mysql -u root -e "SET PASSWORD FOR 'root'@'localhost' = PASSWORD('');" 2>/dev/null || true
fi
termux-wake-lock 2>/dev/null || true
echo "Hamba engine running. Open the Hamba app now."
exec php -S 0.0.0.0:8080 -t "$HOME/hamba"
