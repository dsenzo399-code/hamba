#!/data/data/com.termux/files/usr/bin/bash
# Hamba Termux server — run each time. Starts MariaDB + serves the app on port 8080.
# Keep this session open (and run termux-wake-lock once so Android naps don't kill it).
# Open http://127.0.0.1:8080/install.php on first run, then http://127.0.0.1:8080/
mysqld_safe >/dev/null 2>&1 &
sleep 3
exec php -S 0.0.0.0:8080 -t "$HOME/hamba"
