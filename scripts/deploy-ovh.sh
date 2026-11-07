#!/bin/sh
set -eu
umask 077
cd "$HOME"
test -d www
test -f jurko-release.tar.gz
test -f jurko-private/auth.pending.php
php -l jurko-private/auth.pending.php >/dev/null
# Blokada nie jest automatycznie usuwana po niepowodzeniu: najpierw należy sprawdzić stan.
mkdir jurko-deploy.lock
mkdir -p jurko-private/backups
chmod 700 jurko-private jurko-private/backups
stamp=$(date -u +%Y%m%dT%H%M%SZ)
tar -czf "jurko-private/backups/www-$stamp.tar.gz" www
if test -f jurko-private/auth.php; then
    cp jurko-private/auth.php "jurko-private/backups/auth-$stamp.php"
fi
tar -xzf jurko-release.tar.gz -C www
# Pliki publiczne muszą być czytelne przez serwer WWW.
find www/auth www/app www/server www/vendor -type d -exec chmod 755 {} \;
find www/auth www/app www/server www/vendor -type f -exec chmod 644 {} \;
chmod 644 www/index.html www/pracownik.html www/staff.js www/composer.json www/composer.lock
mv jurko-private/auth.pending.php jurko-private/auth.php
chmod 600 jurko-private/auth.php
rm jurko-release.tar.gz
rmdir jurko-deploy.lock
printf '%s\n' 'Pliki i konfiguracja zapisane. Wymagany test HTTPS logowania Google.'
