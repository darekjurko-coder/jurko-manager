<?php
declare(strict_types=1);
require __DIR__.'/../server/database.php';
require __DIR__.'/../server/portal-data.php';
$db=jurkoDatabase();
$db->exec("INSERT INTO jm_accounts(email,role,active) VALUES ('unified-ci@example.invalid','owner',TRUE)");
$actor=(int)$db->lastInsertId();
$call=static function(string $action,array $data,?string $id=null)use($db,$actor):array{
    return jurkoPortalWrite($db,$actor,['action'=>$action,'data'=>$data,'request_id'=>$id??sprintf('00000000-0000-4000-8000-%012d',random_int(1,999999999999))]);
};
try{
    $entry=['company'=>'spk','type'=>'cost','date'=>'2026-11-08','description'=>'SYNTHETIC UNIFIED TEST','costCode'=>'100','amount'=>'123.45'];
    $key='00000000-0000-4000-8000-000000000001';
    $call('entry_add',$entry,$key);$call('entry_add',$entry,$key);
    $state=jurkoPortalState($db,'2026-11');$mine=array_values(array_filter($state['entries'],fn($x)=>$x['description']===$entry['description']));
    if(count($mine)!==1||$mine[0]['amount']!=='123.45')throw new RuntimeException('Błąd zapisu lub idempotencji');
    $call('entry_archive',['id'=>$mine[0]['id']]);
    if(array_filter(jurkoPortalState($db,'2026-11')['entries'],fn($x)=>$x['id']===$mine[0]['id']))throw new RuntimeException('Błąd archiwum');
    $q=$db->prepare('SELECT deleted_at FROM jm_financial_entries WHERE id=?');$q->execute([$mine[0]['id']]);if(!$q->fetchColumn())throw new RuntimeException('Utracono archiwizowany wpis');
    $call('task_add',['company'=>'zoo','title'=>'SYNTHETIC UNIFIED TASK','owner'=>'Test','date'=>'2026-11-08','priority'=>'Średni']);
    $task=array_values(array_filter(jurkoPortalState($db,'2026-11')['tasks'],fn($x)=>$x['title']==='SYNTHETIC UNIFIED TASK'))[0];
    $call('task_status',['id'=>$task['id'],'version'=>1,'status'=>'W toku']);
    try{$call('task_status',['id'=>$task['id'],'version'=>1,'status'=>'Zrobione']);throw new LogicException('Nie odrzucono nadpisania zadania');}catch(DomainException $e){}
    $data=['employees'=>[['id'=>'synthetic-ci-employee','name'=>'SYNTHETIC EMPLOYEE','group'=>'warehouse','metric'=>'tasks','years'=>['2026'=>['months'=>array_fill(0,12,['plan'=>100,'actual'=>null]),'base'=>1000,'monthlyBonus'=>null,'quarterlyBonus'=>0,'teamBonus'=>null,'threshold'=>100]]]]];
    jurkoStaffValidate($data);
    $call('staff_save',['model'=>$data,'version'=>0]);
    $staff=jurkoStaffLoad($db);
    if($staff['version']!==1||$staff['model']['employees'][0]['years']->{'2026'}['months'][0]['actual']!==null)throw new RuntimeException('Utracono brak danych');
    try{$call('staff_save',['model'=>$data,'version'=>0]);throw new LogicException('Nie odrzucono nadpisania planu');}catch(DomainException $e){}
    try{jurkoNullableAmount(1.001,100);throw new LogicException('Przyjęto trzecią cyfrę');}catch(InvalidArgumentException $e){}
    echo "Zapis, idempotencja, archiwum, statusy i ochrona planów przed nadpisaniem OK\n";
}finally{
    $db->exec('DELETE FROM jm_audit_log WHERE actor_id='.$actor);
    $db->exec('DELETE FROM jm_write_requests WHERE actor_id='.$actor);
    $db->exec('DELETE FROM jm_financial_entries WHERE created_by='.$actor);
    $db->exec('DELETE FROM jm_tasks WHERE created_by='.$actor);
    $db->exec('DELETE FROM jm_accounts WHERE id='.$actor);
    $db->exec("DELETE r FROM jm_monthly_results r JOIN jm_employees e ON e.id=r.employee_id WHERE e.public_id='synthetic-ci-employee'");
    $db->exec("DELETE r FROM jm_bonus_settings r JOIN jm_employees e ON e.id=r.employee_id WHERE e.public_id='synthetic-ci-employee'");
    $db->exec("DELETE FROM jm_employees WHERE public_id='synthetic-ci-employee'");
    $db->exec("DELETE FROM jm_module_revisions WHERE module_key='staff'");
}
