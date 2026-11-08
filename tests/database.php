<?php
declare(strict_types=1);
require __DIR__ . '/../server/database.php';
$pdo = jurkoDatabase();
if ((int)$pdo->query('SELECT COUNT(*) FROM jm_migration_files')->fetchColumn() !== count(json_decode(file_get_contents(__DIR__.'/../server/migrations/manifest.json'),true))) {
    throw new RuntimeException('Niepełna historia.');
}
// Dane syntetyczne wyłącznie w transakcji, zawsze wycofywane.
$pdo->beginTransaction();
try {
    $pdo->exec("INSERT INTO jm_employees(display_name, department) VALUES ('TEST', 'sales')");
    $id = (int)$pdo->lastInsertId();
    $rejected = 0;
    foreach ([
        "INSERT INTO jm_monthly_results(employee_id,year,month,metric) VALUES ($id,2026,13,'turnover')",
        "INSERT INTO jm_monthly_results(employee_id,year,month,metric,actual) VALUES ($id,2026,1,'turnover',-1)",
        "INSERT INTO jm_accounts(employee_id,email) VALUES (18446744073709551614,'test@example.invalid')"
    ] as $sql) {
        try { $pdo->exec($sql); } catch (PDOException $e) {
            if (in_array((int)($e->errorInfo[1] ?? 0), [3819, 1452], true)) { $rejected++; }
            else { throw $e; }
        }
    }
    if ($rejected !== 3) { throw new RuntimeException('Brak ochrony integralności.'); }
    $pdo->exec("INSERT INTO jm_accounts(employee_id,email) VALUES ($id,'test@example.invalid')");
    if ((int)$pdo->query("SELECT active FROM jm_accounts WHERE employee_id=$id")->fetchColumn() !== 0) {
        throw new RuntimeException('Konto nie powinno być aktywne.');
    }
} finally { $pdo->rollBack(); }
echo "Integralność danych OK\n";
