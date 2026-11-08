<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require getenv('HOME').'/www/server/database.php';$db=jurkoDatabase();$db->beginTransaction();
try{
    $q=$db->prepare("SELECT id FROM jm_employees WHERE public_id='652ed037-ae5b-468d-915e-f138786c3d5c' AND display_name='TEST PROWIZJI JM-20261108' AND department='sales' FOR UPDATE");$q->execute();$id=$q->fetchColumn();
    if($id){
        foreach(['jm_accounts','jm_monthly_results','jm_bonus_settings'] as $table){$q=$db->prepare('SELECT COUNT(*) FROM '.$table.' WHERE employee_id=?');$q->execute([$id]);if($q->fetchColumn()!=0)throw new RuntimeException();}
        $q=$db->prepare("SELECT COUNT(*) FROM jm_sales_documents WHERE employee_id=? AND NOT (document_no='F/TEST-JM-20261108/26' AND company='spk' AND document_date='2026-10-05' AND invoice_net=1000 AND turnover_net=900 AND source_profit=300)");$q->execute([$id]);if($q->fetchColumn()!=0)throw new RuntimeException();
        $q=$db->prepare("SELECT COUNT(*) FROM jm_sales_imports WHERE employee_id=? AND filename<>'synthetic-sales.xlsx'");$q->execute([$id]);if($q->fetchColumn()!=0)throw new RuntimeException();
        $q=$db->prepare("SELECT COUNT(*) FROM jm_sales_costs WHERE employee_id=? AND NOT (description='TEST KOSZT JM-20261108' AND category='transport' AND quantity=2.5 AND unit_price=20 AND amount_net=50 AND percent_bp IS NULL)");$q->execute([$id]);if($q->fetchColumn()!=0)throw new RuntimeException();
        foreach(['jm_sales_costs','jm_sales_documents','jm_sales_imports','jm_sales_months','jm_sales_rules'] as $table)$db->prepare('DELETE FROM '.$table.' WHERE employee_id=?')->execute([$id]);
        $db->prepare("DELETE FROM jm_audit_log WHERE entity_type IN ('sales','sales_month') AND entity_id IN (?,?)")->execute([$id.':2026-10',$id.':2026-11']);
        $db->prepare('DELETE FROM jm_employees WHERE id=?')->execute([$id]);$db->exec("UPDATE jm_module_revisions SET version=version+1 WHERE module_key='staff'");
    }
    $db->commit();echo "SYNTHETIC_SALES_CLEANUP_OK\n";
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();fwrite(STDERR,"Porządkowanie testu zatrzymane. Brak zmian w danych firmowych.\n");exit(1);}
