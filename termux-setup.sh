#!/data/data/com.termux/files/usr/bin/bash
# Hamba Termux setup — run once. Installs PHP + MariaDB and prepares the database.
set -e
pkg update -y
pkg install -y php mariadb
if [ ! -d "$PREFIX/var/lib/mysql/mysql" ]; then
  mariadb-install-db
fi
echo "Setup done. Start everything with: ./termux-serve.sh"
