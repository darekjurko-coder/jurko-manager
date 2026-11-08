<?php
declare(strict_types=1);
require_once __DIR__.'/finance.php';
function jurkoMonth(mixed $value): string {
    if (!is_string($value) || !preg_match('/^(20[0-9]{2}|2100)-(0[1-9]|1[0-2])$/D',$value)) { throw new InvalidArgumentException('Wybierz poprawny miesiąc.'); }
    return $value;
}
function jurkoText(mixed $v, int $max, bool $empty=false): string {
    if (!is_string($v) || (!$empty && trim($v)==='') || mb_strlen($v)>$max) { throw new InvalidArgumentException('Sprawdź pola formularza.'); }
    return trim($v);
}
function jurkoEnum(mixed $v, array $allowed): string {
    if (!is_string($v) || !in_array($v,$allowed,true)) { throw new InvalidArgumentException('Niepoprawna wartość.'); }
    return $v;
}
function jurkoAudit(PDO $db, int $actor, string $action, string $entity, string $id): void {
    $db->prepare('INSERT INTO jm_audit_log(actor_id,action,entity_type,entity_id) VALUES (?,?,?,?)')->execute([$actor,$action,$entity,$id]);
}
function jurkoStaffLoad(PDO $db): array {
    $people=$db->query('SELECT id,public_id,display_name,department FROM jm_employees WHERE public_id IS NOT NULL AND active=TRUE ORDER BY id')->fetchAll();
    $model=['employees'=>[]]; $indices=[];
    foreach($people as $p) {
        $indices[(string)$p['id']]=count($model['employees']);
        $model['employees'][]=['id'=>$p['public_id'],'name'=>$p['display_name'],'group'=>$p['department'],'metric'=>'turnover','years'=>[]];
    }
    $yearDefault=static fn()=>['months'=>array_fill(0,12,['plan'=>null,'actual'=>null]),'base'=>null,'monthlyBonus'=>null,'quarterlyBonus'=>null,'teamBonus'=>null,'threshold'=>100];
    foreach($db->query('SELECT * FROM jm_monthly_results ORDER BY year,month')->fetchAll() as $r) {
        if(!isset($indices[(string)$r['employee_id']]))continue;
        $e=&$model['employees'][$indices[(string)$r['employee_id']]]; $y=(string)$r['year'];
        $e['metric']=$r['metric'];
        if(!isset($e['years'][$y]))$e['years'][$y]=$yearDefault();
        $e['years'][$y]['months'][(int)$r['month']-1]=['plan'=>$r['target']===null?null:(float)$r['target'],'actual'=>$r['actual']===null?null:(float)$r['actual']];
        unset($e);
    }
    foreach($db->query('SELECT * FROM jm_bonus_settings')->fetchAll() as $r) {
        if(!isset($indices[(string)$r['employee_id']]))continue;
        $e=&$model['employees'][$indices[(string)$r['employee_id']]]; $y=(string)$r['year'];
        if(!isset($e['years'][$y]))$e['years'][$y]=$yearDefault();
        foreach(['base'=>'base_gross','monthlyBonus'=>'monthly_bonus','quarterlyBonus'=>'quarterly_bonus','teamBonus'=>'team_bonus','threshold'=>'threshold_percent'] as $to=>$from)$e['years'][$y][$to]=$r[$from]===null?null:(float)$r[$from];
        unset($e);
    }
    // Miernik bez wprowadzonych wyników jest przechowywany w osobnej kolumnie.
    foreach($db->query('SELECT id,plan_metric FROM jm_employees WHERE public_id IS NOT NULL')->fetchAll() as $r)if(isset($indices[(string)$r['id']]))$model['employees'][$indices[(string)$r['id']]]['metric']=$r['plan_metric'];
    $v=$db->query("SELECT version FROM jm_module_revisions WHERE module_key='staff'")->fetchColumn();
    foreach($model['employees'] as &$person)$person['years']=(object)$person['years'];
    unset($person);
    return ['model'=>$model['employees']?$model:null,'version'=>(int)($v?:0)];
}
function jurkoPortalState(PDO $db, string $month): array {
    jurkoMonth($month); $start=$month.'-01';$end=(new DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d');
    $q=$db->prepare('SELECT id,company,entry_type,entry_date,description,cost_code,amount_net FROM jm_financial_entries WHERE deleted_at IS NULL AND entry_date>=? AND entry_date<? ORDER BY id LIMIT 10001');
    $q->execute([$start,$end]);$rows=$q->fetchAll();
    if(count($rows)>10000)throw new InvalidArgumentException('Miesiąc przekracza limit widoku. Skontaktuj się z administratorem.');
    $entries=array_map(static fn($r)=>['id'=>(string)$r['id'],'company'=>$r['company'],'type'=>$r['entry_type'],'date'=>$r['entry_date'],'description'=>$r['description'],'costCode'=>$r['cost_code'],'category'=>$r['entry_type']==='revenue'?'Sprzedaż':'Inne','amount'=>$r['amount_net']],$rows);
    $rows=$db->query('SELECT * FROM jm_tasks WHERE deleted_at IS NULL ORDER BY id LIMIT 5001')->fetchAll();
    if(count($rows)>5000)throw new InvalidArgumentException('Lista zadań przekracza limit widoku.');
    $statuses=['todo'=>'Do zrobienia','in_progress'=>'W toku','done'=>'Zrobione'];$priorities=['low'=>'Niski','medium'=>'Średni','high'=>'Wysoki'];
    $tasks=array_map(static fn($r)=>['id'=>(string)$r['id'],'company'=>$r['company'],'title'=>$r['title'],'owner'=>$r['owner_label'],'date'=>$r['due_date'],'status'=>$statuses[$r['status']],'priority'=>$priorities[$r['priority']],'version'=>(int)$r['version']],$rows);
    return ['entries'=>$entries,'tasks'=>$tasks,'staff'=>jurkoStaffLoad($db),'month'=>$month];
}
function jurkoStaffValidate(mixed $model): array {
    if(!is_array($model)||!is_array($model['employees']??null)||count($model['employees'])>500)throw new InvalidArgumentException('Niepoprawna kartoteka.');
    $seen=[];
    foreach($model['employees'] as &$p) {
        if(!is_array($p))throw new InvalidArgumentException();
        $p['id']=jurkoText($p['id']??null,64);
        if(!preg_match('/^[A-Za-z0-9_-]+$/D',$p['id'])||isset($seen[$p['id']]))throw new InvalidArgumentException('Powielony identyfikator pracownika.');
        $seen[$p['id']]=true; $p['name']=jurkoText($p['name']??null,120);
        $p['group']=jurkoEnum($p['group']??null,['sales','warehouse','drivers']);
        $p['metric']=jurkoEnum($p['metric']??null,['turnover','tasks','deliveries']);
        if(!is_array($p['years']??null)||count($p['years'])>101)throw new InvalidArgumentException('Niepoprawne lata.');
        foreach($p['years'] as $year=>&$d) {
            if(!preg_match('/^(20[0-9]{2}|2100)$/D',(string)$year)||!is_array($d)||!is_array($d['months']??null)||count($d['months'])!==12||!array_is_list($d['months']))throw new InvalidArgumentException('Niepoprawny rok lub miesiące.');
            foreach($d['months'] as &$m) {
                if(!is_array($m))throw new InvalidArgumentException();
                foreach(['plan','actual'] as $key)$m[$key]=jurkoNullableAmount($m[$key]??null,9999999999999.99);
            }unset($m);
            foreach(['base','monthlyBonus','quarterlyBonus','teamBonus'] as $key)$d[$key]=jurkoNullableAmount($d[$key]??null,9999999999.99);
            $d['threshold']=jurkoNullableAmount($d['threshold']??null,99999.99);
            if($d['threshold']===null||(float)$d['threshold']<=0)throw new InvalidArgumentException('Próg musi być większy od zera.');
        }unset($d);
    }unset($p);
    return $model;
}
function jurkoNullableAmount(mixed $v,float $max): ?string {
    if($v===null)return null;
    if((!is_int($v)&&!is_float($v))||!is_finite((float)$v)||$v<0||$v>$max||(float)$v!==round((float)$v,2))throw new InvalidArgumentException('Kwoty muszą być nieujemne, z dwoma miejscami po przecinku.');
    return number_format((float)$v,2,'.','');
}
function jurkoStaffSave(PDO $db,array $model,int $version,int $actor): void {
    $db->exec("INSERT IGNORE INTO jm_module_revisions(module_key,version) VALUES ('staff',0)");
    $v=(int)$db->query("SELECT version FROM jm_module_revisions WHERE module_key='staff' FOR UPDATE")->fetchColumn();
    if($v!==$version)throw new DomainException('Dane pracowników zmieniono w innym oknie. Odśwież stronę przed zapisaniem.');
    foreach($model['employees'] as $p) {
        $q=$db->prepare('SELECT id,plan_metric FROM jm_employees WHERE public_id=? FOR UPDATE');$q->execute([$p['id']]);$row=$q->fetch();
        if(!$row) {
            $db->prepare('INSERT INTO jm_employees(public_id,display_name,department,plan_metric) VALUES (?,?,?,?)')->execute([$p['id'],$p['name'],$p['group'],$p['metric']]);$id=$db->lastInsertId();
        }else{
            $id=$row['id'];
            if($row['plan_metric']!==$p['metric'])throw new InvalidArgumentException('Zmiana miernika istniejącej kartoteki wymaga migracji wyników.');
            $db->prepare('UPDATE jm_employees SET display_name=?,department=? WHERE id=?')->execute([$p['name'],$p['group'],$id]);
        }
        foreach($p['years'] as $y=>$d) {
            foreach($d['months'] as $i=>$m)$db->prepare('INSERT INTO jm_monthly_results(employee_id,year,month,metric,target,actual) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE target=VALUES(target),actual=VALUES(actual)')->execute([$id,$y,$i+1,$p['metric'],$m['plan'],$m['actual']]);
            $db->prepare('INSERT INTO jm_bonus_settings(employee_id,year,base_gross,monthly_bonus,quarterly_bonus,team_bonus,threshold_percent) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE base_gross=VALUES(base_gross),monthly_bonus=VALUES(monthly_bonus),quarterly_bonus=VALUES(quarterly_bonus),team_bonus=VALUES(team_bonus),threshold_percent=VALUES(threshold_percent)')->execute([$id,$y,$d['base'],$d['monthlyBonus'],$d['quarterlyBonus'],$d['teamBonus'],$d['threshold']]);
        }
    }
    $db->exec("UPDATE jm_module_revisions SET version=version+1 WHERE module_key='staff'");
    jurkoAudit($db,$actor,'update','staff_plan',(string)($v+1));
}
function jurkoPortalWrite(PDO $db,int $actor,array $input): array {
    $action=jurkoEnum($input['action']??null,['entry_add','entry_archive','task_add','task_status','task_archive','staff_save']);
    $request=jurkoText($input['request_id']??null,36);
    if(!preg_match('/^[a-f0-9-]{36}$/D',$request))throw new InvalidArgumentException('Niepoprawny klucz zapisu.');
    $payload=$input['data']??[];if(!is_array($payload))throw new InvalidArgumentException();
    $hash=hash('sha256',json_encode([$action,$payload],JSON_THROW_ON_ERROR));
    $db->beginTransaction();
    try {
        $q=$db->prepare('INSERT IGNORE INTO jm_write_requests(actor_id,request_id,action,payload_hash) VALUES (?,?,?,?)');$q->execute([$actor,$request,$action,$hash]);
        if(!$q->rowCount()) {
            $q=$db->prepare('SELECT payload_hash FROM jm_write_requests WHERE actor_id=? AND request_id=? FOR UPDATE');$q->execute([$actor,$request]);
            if(!hash_equals((string)$q->fetchColumn(),$hash))throw new DomainException('Klucz zapisu został już użyty.');
            $db->commit();return ['ok'=>true];
        }
        if($action==='entry_add'){
            $entry=jurkoValidateEntry(['company'=>$payload['company']??null,'entry_type'=>$payload['type']??null,'entry_date'=>$payload['date']??null,'description'=>$payload['description']??null,'cost_code'=>$payload['costCode']??'','amount_net'=>$payload['amount']??null]);
            $db->prepare('INSERT INTO jm_financial_entries(company,entry_type,entry_date,description,cost_code,amount_net,created_by) VALUES (?,?,?,?,?,?,?)')->execute([...$entry,$actor]);
            jurkoAudit($db,$actor,'create','financial_entry',$db->lastInsertId());
        }elseif($action==='entry_archive'){
            $id=jurkoText($payload['id']??null,20);if(!ctype_digit($id))throw new InvalidArgumentException();
            $q=$db->prepare('UPDATE jm_financial_entries SET deleted_at=UTC_TIMESTAMP() WHERE id=? AND deleted_at IS NULL');$q->execute([$id]);
            if($q->rowCount())jurkoAudit($db,$actor,'archive','financial_entry',$id);
        }elseif($action==='task_add'){
            $company=jurkoEnum($payload['company']??null,['zoo','spk']);$title=jurkoText($payload['title']??null,200);
            $owner=jurkoText($payload['owner']??null,80);$date=jurkoText($payload['date']??null,10);
            jurkoValidateEntry(['company'=>$company,'entry_type'=>'cost','entry_date'=>$date,'description'=>$title,'amount_net'=>'0.01']);
            $priority=['Niski'=>'low','Średni'=>'medium','Wysoki'=>'high'][jurkoEnum($payload['priority']??null,['Niski','Średni','Wysoki'])];
            $db->prepare('INSERT INTO jm_tasks(company,title,owner_label,due_date,priority,created_by) VALUES (?,?,?,?,?,?)')->execute([$company,$title,$owner,$date,$priority,$actor]);
            jurkoAudit($db,$actor,'create','task',$db->lastInsertId());
        }elseif(in_array($action,['task_status','task_archive'],true)){
            $id=jurkoText($payload['id']??null,20);if(!ctype_digit($id)||!is_int($payload['version']??null))throw new InvalidArgumentException();
            if($action==='task_status'){
                $status=['Do zrobienia'=>'todo','W toku'=>'in_progress','Zrobione'=>'done'][jurkoEnum($payload['status']??null,['Do zrobienia','W toku','Zrobione'])];
                $q=$db->prepare('UPDATE jm_tasks SET status=?,version=version+1 WHERE id=? AND version=? AND deleted_at IS NULL');$q->execute([$status,$id,$payload['version']]);
            }else{$q=$db->prepare('UPDATE jm_tasks SET deleted_at=UTC_TIMESTAMP(),version=version+1 WHERE id=? AND version=? AND deleted_at IS NULL');$q->execute([$id,$payload['version']]);}
            if(!$q->rowCount())throw new DomainException('Zadanie zostało zmienione w innym oknie. Odśwież stronę.');
            jurkoAudit($db,$actor,$action==='task_status'?'update':'archive','task',$id);
        }else{
            $model=jurkoStaffValidate($payload['model']??null);
            if(!is_int($payload['version']??null))throw new InvalidArgumentException();
            jurkoStaffSave($db,$model,$payload['version'],$actor);
        }
        $db->commit();return ['ok'=>true];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
