<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require getenv('HOME').'/www/server/database.php';
$db=jurkoDatabase();$config=require getenv('HOME').'/jurko-private/auth.php';
$db->beginTransaction();
try{
    $q=$db->prepare("SELECT id FROM jm_accounts WHERE email=? AND role='owner' AND active=1");$q->execute([$config['owner_email']]);$actor=$q->fetchColumn();if(!$actor)throw new RuntimeException();
    foreach(['TEST TECHNICZNY JM-UNIFIED-20261108-1738','TEST TECHNICZNY JM-UNIFIED-20261108-1738-V2'] as $marker){
        $q=$db->prepare("SELECT id FROM jm_financial_entries WHERE description=? AND amount_net=0.01 AND entry_date='2026-11-08' AND company='zoo' AND entry_type='cost' AND cost_code='100' AND created_by=? AND deleted_at IS NOT NULL FOR UPDATE");$q->execute([$marker,$actor]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);if(count($ids)>1)throw new RuntimeException();
        foreach($ids as $id){$db->prepare("DELETE FROM jm_audit_log WHERE actor_id=? AND entity_type='financial_entry' AND entity_id=? AND action IN ('create','archive')")->execute([$actor,(string)$id]);$db->prepare('DELETE FROM jm_financial_entries WHERE id=? AND description=?')->execute([$id,$marker]);}
    }
    $marker='TEST ZADANIE JM-UNIFIED-20261108-1740';
    $q=$db->prepare("SELECT id FROM jm_tasks WHERE title=? AND company='zoo' AND owner_label='TEST TECHNICZNY' AND due_date='2026-11-08' AND status='done' AND created_by=? AND deleted_at IS NOT NULL FOR UPDATE");$q->execute([$marker,$actor]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);if(count($ids)>1)throw new RuntimeException();
    foreach($ids as $id){$db->prepare("DELETE FROM jm_audit_log WHERE actor_id=? AND entity_type='task' AND entity_id=? AND action IN ('create','update','archive')")->execute([$actor,(string)$id]);$db->prepare('DELETE FROM jm_tasks WHERE id=? AND title=?')->execute([$id,$marker]);}
    $q=$db->prepare("SELECT id FROM jm_employees WHERE public_id='3e2a6672-699a-4310-99f9-86c41070bc53' AND display_name='TEST KARTOTEKA JM-UNIFIED-20261108-1740' AND department='warehouse' AND plan_metric='tasks' FOR UPDATE");$q->execute();$id=$q->fetchColumn();
    if($id){
        $q=$db->prepare('SELECT COUNT(*) FROM jm_accounts WHERE employee_id=?');$q->execute([$id]);if($q->fetchColumn()!=0)throw new RuntimeException();
        $q=$db->prepare("SELECT COUNT(*) FROM jm_monthly_results WHERE employee_id=? AND NOT (year=2026 AND metric='tasks' AND ((month=1 AND target=12 AND actual=10) OR (month BETWEEN 2 AND 12 AND target IS NULL AND actual IS NULL)))");$q->execute([$id]);if($q->fetchColumn()!=0)throw new RuntimeException();
        $q=$db->prepare('SELECT COUNT(*) FROM jm_bonus_settings WHERE employee_id=? AND NOT (year=2026 AND base_gross IS NULL AND monthly_bonus IS NULL AND quarterly_bonus IS NULL AND team_bonus IS NULL AND threshold_percent=100)');$q->execute([$id]);if($q->fetchColumn()!=0)throw new RuntimeException();
        $db->prepare('DELETE FROM jm_monthly_results WHERE employee_id=?')->execute([$id]);$db->prepare('DELETE FROM jm_bonus_settings WHERE employee_id=?')->execute([$id]);$db->prepare('DELETE FROM jm_employees WHERE id=?')->execute([$id]);
        $db->exec("UPDATE jm_module_revisions SET version=version+1 WHERE module_key='staff'");
    }
    $db->commit();echo "SYNTHETIC_TEST_CLEANUP_OK\n";
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();fwrite(STDERR,"Porządkowanie testu zatrzymane. Brak zmian w danych firmowych.\n");exit(1);}
