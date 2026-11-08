<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require getenv('HOME').'/www/server/database.php';
$pdo = jurkoDatabase();
$config = require getenv('HOME').'/jurko-private/auth.php';
$marker = 'TEST TECHNICZNY JM-20261108-1655-CLEANUP';
$pdo->beginTransaction();
try {
    $q = $pdo->prepare("SELECT e.id FROM jm_financial_entries e JOIN jm_accounts a ON a.id=e.created_by WHERE e.description=? AND e.amount_net=0.01 AND e.entry_date='2026-11-08' AND e.company='zoo' AND e.entry_type='cost' AND e.cost_code='TEST' AND a.email=? AND a.role='owner' FOR UPDATE");
    $q->execute([$marker,$config['owner_email']]);
    $ids = $q->fetchAll(PDO::FETCH_COLUMN);
    if (count($ids)>1) { throw new RuntimeException('Niejednoznaczny wpis testowy'); }
    if ($ids) {
        $id=(string)$ids[0];
        $q=$pdo->prepare("DELETE FROM jm_audit_log WHERE entity_type='financial_entry' AND entity_id=? AND action='create'");
        $q->execute([$id]);
        $q=$pdo->prepare('DELETE FROM jm_financial_entries WHERE id=? AND description=?');
        $q->execute([$id,$marker]);
        if ($q->rowCount()!==1) { throw new RuntimeException(); }
    }
    $pdo->commit();
    echo "SYNTHETIC_TEST_CLEANUP_OK\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    fwrite(STDERR,"Nie usunięto wpisu testowego. Brak zmian w danych firmowych.\n");
    exit(1);
}
