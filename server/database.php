<?php
declare(strict_types=1);
// Plik nie ma interfejsu HTTP. Konfiguracja znajduje się poza katalogiem strony.
if (PHP_SAPI !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
function jurkoDatabase(): PDO
{
    $path = dirname(__DIR__, 2) . '/jurko-private/config.php';
    if (!is_file($path)) {
        throw new RuntimeException('Brak prywatnej konfiguracji bazy.');
    }
    $config = require $path;
    foreach (['host', 'database', 'username', 'password'] as $key) {
        if (!is_array($config) || !isset($config[$key]) || !is_string($config[$key]) || $config[$key] === '') {
            throw new RuntimeException('Niekompletna prywatna konfiguracja.');
        }
    }
    foreach (['host', 'database'] as $key) {
        if (!preg_match('/^[a-zA-Z0-9._-]+$/D', $config[$key])) {
            throw new RuntimeException('Niepoprawna konfiguracja bazy.');
        }
    }
    $port = (int)($config['port'] ?? 3306);
    if ($port < 1 || $port > 65535) {
        throw new RuntimeException('Niepoprawny port bazy.');
    }
    $pdo = new PDO(
        'mysql:host=' . $config['host'] . ';port=' . $port . ';dbname=' . $config['database'] . ';charset=utf8mb4',
        $config['username'], $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 10]
    );
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

