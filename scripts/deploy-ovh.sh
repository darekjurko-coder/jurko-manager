#!/bin/sh
set -eu
umask 077
cd "$HOME"
test -d www
test -f www/server/database.php
test -f jurko-release.tar.gz
test -f jurko-private/auth.pending.php
php -r 'foreach (["zip","gd","xml","xmlreader","xmlwriter"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR,"Brak rozszerzenia PHP: ".$extension."\n"); exit(1); } }'
php -l jurko-private/auth.pending.php >/dev/null
mkdir jurko-deploy.lock
mkdir -p jurko-private/backups
chmod 700 jurko-private jurko-private/backups
stamp=$(date -u +%Y%m%dT%H%M%SZ)
tar -czf "jurko-private/backups/www-$stamp.tar.gz" www
if test -f jurko-private/auth.php; then cp jurko-private/auth.php "jurko-private/backups/auth-$stamp.php"; fi
mkdir jurko-stage
tar -xzf jurko-release.tar.gz -C jurko-stage
php jurko-stage/scripts/backup-db.php
php jurko-stage/server/migrate.php
find jurko-stage -type d -exec chmod 755 {} +
find jurko-stage -type f -exec chmod 644 {} +
if test -f www/.htaccess; then cp www/.htaccess "jurko-private/backups/htaccess-$stamp"; fi
printf '%s\n' 'Require all denied' > www/.htaccess
cp -R jurko-stage/server jurko-stage/auth jurko-stage/app jurko-stage/vendor www/
cp jurko-stage/index.php jurko-stage/index.html jurko-stage/pracownik.html jurko-stage/portal.php jurko-stage/portal-api.php jurko-stage/sales-api.php jurko-stage/sales.js jurko-stage/portal-client.js jurko-stage/owner-panel.js jurko-stage/staff.js jurko-stage/composer.json jurko-stage/composer.lock jurko-stage/.ovhconfig www/
chmod 644 www/index.html www/pracownik.html www/portal.php www/portal-api.php www/sales-api.php www/sales.js www/portal-client.js www/owner-panel.js www/staff.js
find www/server www/auth www/app www/vendor -type d -exec chmod 755 {} +
find www/server www/auth www/app www/vendor -type f -exec chmod 644 {} +
printf '%s\n' 'Require all denied' > www/vendor/.htaccess
chmod 644 www/index.php www/composer.json www/composer.lock www/.ovhconfig www/vendor/.htaccess
mv jurko-private/auth.pending.php jurko-private/auth.php
chmod 600 jurko-private/auth.php
cp jurko-stage/.htaccess www/.htaccess
chmod 644 www/.htaccess
rm -rf jurko-stage
rm jurko-release.tar.gz
rmdir jurko-deploy.lock
printf '%s\n' 'PORTAL_DEPLOYED — wymagane sprawdzenie HTTPS i logowania.'
