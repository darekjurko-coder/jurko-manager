<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/database.php';
$pdo = null;
$locked = false;
$current = 'konfiguracja';
$exitCode = 0;
try {
    $files = json_decode(file_get_contents(__DIR__ . '/migrations/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($files) || !$files || count($files) !== count(array_unique($files))) {
        throw new RuntimeException('Niepoprawny manifest.');
    }
    // Sprawdź cały zestaw przed pierwszą zmianą bazy.
    $migrations = [];
    foreach ($files as $file) {
        if (!is_string($file) || !preg_match('~^[a-z]+/[0-9]{3}_[a-z_]+\.sql$~D', $file)) {
            throw new RuntimeException('Niepoprawna ścieżka.');
        }
        $sql = file_get_contents(__DIR__ . '/migrations/' . $file);
        if ($sql === false || trim($sql) === '') { throw new RuntimeException('Brak migracji.'); }
        $migrations[$file] = [$sql, hash('sha256', $sql)];
    }
    $pdo = jurkoDatabase();
    $locked = (int)$pdo->query("SELECT GET_LOCK(CONCAT('jm:', DATABASE()), 0)")->fetchColumn() === 1;
    if (!$locked) { throw new RuntimeException('Inna migracja jest uruchomiona.'); }
    $pdo->exec('CREATE TABLE IF NOT EXISTS jm_migration_files (
        version VARCHAR(128) PRIMARY KEY,
        checksum CHAR(64) NOT NULL,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $applied = $pdo->query('SELECT version, checksum FROM jm_migration_files')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($applied as $version => $checksum) {
        if (!isset($migrations[$version]) || !hash_equals($checksum, $migrations[$version][1])) {
            throw new RuntimeException('Zmieniono historię migracji.');
        }
    }
    $save = $pdo->prepare('INSERT INTO jm_migration_files (version, checksum) VALUES (?, ?)');
    foreach ($migrations as $current => [$sql, $checksum]) {
        if (isset($applied[$current])) { continue; }
        // Jeden plik = jedno polecenie SQL; brak zawodnego dzielenia po średniku.
        $pdo->exec($sql);
        $save->execute([$current, $checksum]);
        fwrite(STDOUT, "Zastosowano: $current\n");
    }
    fwrite(STDOUT, "Migracje zakończone. Nie utworzono kont ani danych firmowych.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Migracja zatrzymana na etapie: $current. Sprawdź konfigurację, historię i stan bazy.\n");
    $exitCode = 1;
} finally {
    if ($locked && $pdo instanceof PDO) {
        $pdo->query("SELECT RELEASE_LOCK(CONCAT('jm:', DATABASE()))");
    }
}

exit($exitCode);
