#!/data/data/com.termux/files/usr/bin/bash
# Hamba doctor — prints PASS/FAIL for each piece. Send all lines to support.
echo "== 1 files =="
ls "$HOME/hamba/termux-run.sh" >/dev/null 2>&1 && echo PASS || echo FAIL
echo "== 2 php =="
command -v php >/dev/null 2>&1 && php -v 2>/dev/null | head -1 || echo FAIL
echo "== 3 database files =="
ls -d "$PREFIX/var/lib/mysql/mysql" >/dev/null 2>&1 && echo PASS || echo FAIL
echo "== 4 socket folder =="
ls -ld "$PREFIX/var/run/mysqld" 2>&1
echo "== 5 database awake? =="
mysqladmin -u root ping 2>&1 || true
echo "== 6 engine answering? =="
curl -s -m 5 http://127.0.0.1:8080/api/health.php 2>&1 || echo FAIL
echo "== done =="
