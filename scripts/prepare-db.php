<?php
declare(strict_types=1);
$password = getenv('JURKO_DB_PASSWORD');
if (!is_string($password) || $password === '' || strlen($password) > 1024) {
    fwrite(STDERR, "Brak hasła MySQL w chronionych ustawieniach.\n");
    exit(1);
}
umask(0077);
$config = ['host'=>'geuveaxmanager.mysql.db','database'=>'geuveaxmanager','username'=>'geuveaxmanager','password'=>$password,'port'=>3306];
if (file_put_contents('/tmp/jurko-db-config.php', '<?php return ' . var_export($config, true) . ';') === false) { exit(1); }
