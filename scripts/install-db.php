<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
try {
    $dir = getenv('HOME') . '/jurko-private';
    $pending = $dir . '/config.pending.php';
    $config = require $pending;
    foreach (['host'=>'geuveaxmanager.mysql.db','database'=>'geuveaxmanager','username'=>'geuveaxmanager'] as $key=>$value) {
        if (($config[$key] ?? null) !== $value) { throw new RuntimeException(); }
    }
    $pdo = new PDO('mysql:host=geuveaxmanager.mysql.db;port=3306;dbname=geuveaxmanager;charset=utf8mb4', $config['username'], $config['password'],
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false, PDO::ATTR_TIMEOUT=>10]);
    if ((int)$pdo->query('SELECT 1')->fetchColumn() !== 1) { throw new RuntimeException(); }
    chmod($pending, 0600);
    if (is_file($dir . '/config.php')) {
        $backup = $dir . '/config.backup.' . gmdate('YmdHis') . '.' . bin2hex(random_bytes(4)) . '.php';
        if (!copy($dir . '/config.php', $backup) || !chmod($backup, 0600)) { throw new RuntimeException(); }
    }
    if (!rename($pending, $dir . '/config.php')) { throw new RuntimeException(); }
    echo "DATABASE_CONNECTION_OK — konfiguracja zapisana.\n";
} catch (Throwable $e) {
    if (isset($pending) && is_file($pending)) { unlink($pending); }
    fwrite(STDERR, "Nie potwierdzono połączenia MySQL. Dotychczasowa konfiguracja nie została zastąpiona.\n");
    exit(1);
}
