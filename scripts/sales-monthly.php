<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require getenv('HOME').'/www/server/database.php';require_once getenv('HOME').'/www/server/sales.php';
try{
    $db=jurkoDatabase();$config=require getenv('HOME').'/jurko-private/auth.php';
    $q=$db->prepare("SELECT id FROM jm_accounts WHERE email=? AND role='owner' AND active=1");$q->execute([$config['owner_email']]);$actor=$q->fetchColumn();if(!$actor)throw new RuntimeException();
    $last=(new DateTimeImmutable('first day of this month',new DateTimeZone('Europe/Warsaw')))->modify('-1 month')->format('Y-m');
    $q=$db->prepare('SELECT DISTINCT e.public_id,d.settlement_month FROM jm_sales_documents d JOIN jm_employees e ON e.id=d.employee_id WHERE e.active=1 AND d.settlement_month<=?');$q->execute([$last]);$months=$q->fetchAll();$ready=0;$draft=0;
    foreach($months as $m){$db->beginTransaction();jurkoSalesEmployee($db,$m['public_id'],true);$s=jurkoSalesSnapshot($db,(int)$actor,$m['public_id'],$m['settlement_month']);$db->commit();if($s['ready'])$ready++;else $draft++;}
    echo 'MONTHLY_COMMISSION_CALCULATION_OK reports='.count($months).' reviewed='.$ready.' draft='.$draft."\n";
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();fwrite(STDERR,"Nie zakończono przeliczenia raportów. Brak automatycznych wypłat.\n");exit(1);}
