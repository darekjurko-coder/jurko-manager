#!/bin/sh
set -eu
root=$(pwd)
export JURKO_HTTP_ROOT="$root"
mkdir -m 700 -p /tmp/jurko-ci-sessions
php <<'PHP'
<?php
file_put_contents('../jurko-private/auth.php','<?php return '.var_export(['client_id'=>'synthetic','client_secret'=>'synthetic','owner_email'=>'http-ci@example.invalid','redirect_uri'=>'https://koszty.jurkoseo.pl/auth/index.php?action=callback','accounts'=>['synthetic-http'=>['role'=>'owner','email'=>'http-ci@example.invalid']]],true).';');
session_save_path('/tmp/jurko-ci-sessions');
session_name('__Host-jurko');session_id('synthetichttpowner1234567890');session_start();
$_SESSION=['user'=>['sub'=>'synthetic-http','role'=>'owner'],'signed_at'=>time(),'last_seen'=>time(),'csrf'=>'synthetic-csrf'];session_write_close();
PHP
cat > /tmp/jurko-ci-router.php <<'PHP'
<?php
$_SERVER['HTTPS']='on';
session_save_path('/tmp/jurko-ci-sessions');
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/index.html'){require getenv('JURKO_HTTP_ROOT').'/portal.php';return true;}
return false;
PHP
php -S 127.0.0.1:8091 -t "$root" /tmp/jurko-ci-router.php >/tmp/jurko-ci-http.log 2>&1 &
server_pid=$!
trap 'kill "$server_pid" 2>/dev/null || true' EXIT
count=0
until curl -s -o /dev/null http://127.0.0.1:8091/portal-api.php; do count=$((count+1)); test "$count" -lt 20; sleep 1; done
test "$(curl -s -o /tmp/jurko-anon.json -w '%{http_code}' http://127.0.0.1:8091/portal-api.php)" = 401
test "$(curl -s -o /tmp/jurko-anon.html -w '%{http_code}' http://127.0.0.1:8091/index.html)" = 303
test "$(curl -s -H 'Cookie: __Host-jurko=synthetichttpowner1234567890' -o /tmp/jurko-owner.html -w '%{http_code}' http://127.0.0.1:8091/index.html)" = 200
test "$(curl -s -H 'Cookie: __Host-jurko=synthetichttpowner1234567890' -H 'Content-Type: application/json' -d '{}' -o /tmp/jurko-csrf.json -w '%{http_code}' http://127.0.0.1:8091/portal-api.php)" = 403
test "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8091/sales-api.php)" = 401
test "$(curl -s -H 'Cookie: __Host-jurko=synthetichttpowner1234567890' -H 'Content-Type: application/json' -d '{}' -o /dev/null -w '%{http_code}' http://127.0.0.1:8091/sales-api.php)" = 403
python3 - <<'PY'
from pathlib import Path
import hashlib
import os
html=Path('/tmp/jurko-owner.html').read_text()
for asset in ['portal-client.js','staff.js','owner-panel.js','sales.js']:
    digest=hashlib.sha256((Path(os.environ['JURKO_HTTP_ROOT'])/asset).read_bytes()).hexdigest()[:16]
    assert f'src="/{asset}?v={digest}"' in html
assert 'CENTRUM ZARZĄDZANIA' in html and 'window.JurkoInitial' in html
assert 'http-ci@example.invalid' not in html and 'client_secret' not in html
assert 'synthetic-csrf' not in Path('/tmp/jurko-anon.html').read_text()
print('Odmowa bez sesji, widok właściciela i CSRF OK')
PY
