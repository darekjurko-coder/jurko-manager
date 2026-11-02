<?php
declare(strict_types=1);
// Uruchamiaj wyłącznie z SSH; nigdy przez przeglądarkę.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/database.php';
try {
    $pdo = jurkoDatabase();
    $sql = file_get_contents(__DIR__ . '/schema.sql');
    if ($sql === false) {
        throw new RuntimeException('Brak schematu.');
    }
    $sql = preg_replace('/^--.*$/m', '', $sql);
    foreach (explode(';', $sql) as $statement) {
        if (trim($statement) !== '') {
            $pdo->exec(trim($statement));
        }
    }
    $stmt = $pdo->prepare('INSERT IGNORE INTO jm_migrations (version) VALUES (?)');
    $stmt->execute(['001_initial']);
    fwrite(STDOUT, "Schemat utworzony. Nie utworzono kont ani nie zaimportowano danych.\n");
} catch (Throwable $error) {
    // Nie ujawniaj DSN, danych konta ani treści wyjątków sterownika.
    fwrite(STDERR, "Nie udało się przygotować schematu. Sprawdź konfigurację, uprawnienia i stan bazy.\n");
    exit(1);
}
