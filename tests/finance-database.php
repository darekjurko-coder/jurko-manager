<?php
declare(strict_types=1);
require __DIR__.'/../server/database.php';
require __DIR__.'/../server/finance.php';
$pdo = jurkoDatabase();
$user = ['role'=>'owner','sub'=>'synthetic-sub'];
$c = ['owner_email'=>'synthetic-finance@gmail.com','accounts'=>['synthetic-sub'=>['email'=>'synthetic-finance@gmail.com']]];
$id = jurkoFinanceAccount($pdo,$user,$c);
try {
    jurkoSaveEntry($pdo,$id,jurkoValidateEntry(['company'=>'spk','entry_type'=>'cost','entry_date'=>'2026-11-08','description'=>'synthetic-test','amount_net'=>'123,45']));
    $q=$pdo->prepare('SELECT amount_net FROM jm_financial_entries WHERE created_by=?'); $q->execute([$id]);
    if ($q->fetchColumn() !== '123.45') { throw new RuntimeException('Nie zachowano kwoty'); }
    $q=$pdo->prepare('SELECT COUNT(*) FROM jm_audit_log WHERE actor_id=?'); $q->execute([$id]);
    if ((int)$q->fetchColumn() !== 1) { throw new RuntimeException('Brak audytu'); }
    $pdo->exec('UPDATE jm_accounts SET active=FALSE WHERE id='.(int)$id);
    try { jurkoFinanceAccount($pdo,$user,$c); throw new LogicException('Brak odwołania dostępu'); }
    catch (DomainException $e) {}
} finally {
    $pdo->exec('DELETE FROM jm_audit_log WHERE actor_id='.(int)$id);
    $pdo->exec('DELETE FROM jm_financial_entries WHERE created_by='.(int)$id);
    $pdo->exec('DELETE FROM jm_accounts WHERE id='.(int)$id);
}
echo "Zapis finansów, dokładność kwoty, audyt i odwołanie dostępu OK\n";
