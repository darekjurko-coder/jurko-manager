<?php
declare(strict_types=1);
require_once __DIR__.'/portal-data.php';
function jurkoCents(mixed $value): int {
    if(is_int($value)||is_float($value))$value=number_format((float)$value,2,'.','');
    if(!is_string($value)||!preg_match('/^-?\d{1,11}(?:\.\d{1,2})?$/D',$value))throw new InvalidArgumentException('Wpisz kwotę netto z maksymalnie dwoma miejscami po przecinku.');
    return (int)round((float)$value*100);
}
function jurkoDecimal(int $value): string {return ($value<0?'-':'').intdiv(abs($value),100).'.'.str_pad((string)(abs($value)%100),2,'0',STR_PAD_LEFT);}
function jurkoSalesEmployee(PDO $db,string $publicId,bool $lock=false): array {
    $q=$db->prepare('SELECT id,public_id,display_name,department FROM jm_employees WHERE public_id=? AND active=1'.($lock?' FOR UPDATE':''));$q->execute([$publicId]);$e=$q->fetch();
    if(!$e)throw new InvalidArgumentException('Nie znaleziono kartoteki pracownika.');return $e;
}
function jurkoSalesMonth(PDO $db,int $employee,string $month): array {
    jurkoMonth($month);
    $db->prepare('INSERT IGNORE INTO jm_sales_months(employee_id,month,rate_bp) SELECT ?,?,rate_bp FROM jm_sales_rules WHERE employee_id=?')->execute([$employee,$month,$employee]);
    $db->prepare('INSERT IGNORE INTO jm_sales_months(employee_id,month) VALUES (?,?)')->execute([$employee,$month]);
    $q=$db->prepare('SELECT * FROM jm_sales_months WHERE employee_id=? AND month=?');$q->execute([$employee,$month]);return $q->fetch();
}
function jurkoSalesTouch(PDO $db,int $employee,string $month): void {
    jurkoSalesMonth($db,$employee,$month);$db->prepare('UPDATE jm_sales_months SET revision=revision+1,costs_reviewed=FALSE WHERE employee_id=? AND month=?')->execute([$employee,$month]);
}
function jurkoSalesCalculate(array $docs,array $costs,?int $rate): array {
    $byDoc=[];$unassigned=0;
    foreach($costs as $c){$n=jurkoCents($c['amount_net']);if($c['document_id']===null)$unassigned+=$n;else $byDoc[(string)$c['document_id']]=($byDoc[(string)$c['document_id']]??0)+$n;}
    $turnover=0;$invoices=0;$profit=0;$allCost=$unassigned;$eligible=-$unassigned;$unreviewed=0;$counts=['invoice'=>0,'correction'=>0,'order'=>0,'other'=>0];
    foreach($docs as $d){
        $counts[$d['document_type']]++;
        if($d['document_type']==='order'){if(!$d['reviewed'])$unreviewed++;continue;}
        $turnover+=jurkoCents($d['turnover_net']);$invoices+=jurkoCents($d['invoice_net']??$d['turnover_net']);
        $p=jurkoCents($d['profit_override']??$d['source_profit']);$profit+=$p;$c=$byDoc[(string)$d['id']]??0;$allCost+=$c;
        $eligible+=(int)round(($p-$c)*(int)$d['share_bp']/10000,0,PHP_ROUND_HALF_UP);
        if(!$d['reviewed'])$unreviewed++;
    }
    $commission=$rate===null?null:(int)round($eligible*$rate/10000,0,PHP_ROUND_HALF_UP);
    return ['invoice_turnover'=>jurkoDecimal($invoices),'turnover'=>jurkoDecimal($turnover),'profit'=>jurkoDecimal($profit),'costs'=>jurkoDecimal($allCost),'adjusted_profit'=>jurkoDecimal($profit-$allCost),'eligible_profit'=>jurkoDecimal($eligible),'margin'=>$turnover===0?null:round(($profit-$allCost)/$turnover*100,2),'commission'=>$commission===null?null:jurkoDecimal($commission),'rate_bp'=>$rate,'unreviewed'=>$unreviewed,'counts'=>$counts,'documents'=>count($docs),'ready'=>$unreviewed===0&&$rate!==null&&count($docs)>0];
}
function jurkoSalesState(PDO $db,string $publicId,string $month): array {
    $e=jurkoSalesEmployee($db,$publicId);$m=jurkoSalesMonth($db,(int)$e['id'],$month);
    $q=$db->prepare('SELECT * FROM jm_sales_documents WHERE employee_id=? AND settlement_month=? ORDER BY document_date,document_no LIMIT 2001');$q->execute([$e['id'],$month]);$docs=$q->fetchAll();if(count($docs)>2000)throw new InvalidArgumentException('Zbyt dużo dokumentów w jednym miesiącu.');
    $q=$db->prepare('SELECT c.*,d.document_no FROM jm_sales_costs c LEFT JOIN jm_sales_documents d ON d.id=c.document_id WHERE c.employee_id=? AND c.deleted_at IS NULL AND ((c.document_id IS NULL AND c.month=?) OR d.settlement_month=?) ORDER BY c.id');$q->execute([$e['id'],$month,$month]);$costs=$q->fetchAll();
    $q=$db->prepare('SELECT id,filename,report_month,source_json FROM jm_sales_imports WHERE employee_id=? AND report_month=? ORDER BY id');$q->execute([$e['id'],$month]);$imports=$q->fetchAll();
    foreach($imports as &$i){$i['source']=json_decode($i['source_json'],true,64,JSON_THROW_ON_ERROR);unset($i['source_json']);}unset($i);
    $summary=jurkoSalesCalculate($docs,$costs,$m['rate_bp']===null?null:(int)$m['rate_bp']);
    $summary['costs_reviewed']=(bool)$m['costs_reviewed'];$summary['ready']=$summary['ready']&&(bool)$m['costs_reviewed'];
    $q=$db->prepare('SELECT base_gross FROM jm_bonus_settings WHERE employee_id=? AND year=?');$q->execute([$e['id'],(int)substr($month,0,4)]);$base=$q->fetchColumn();
    return ['employee'=>['id'=>$publicId,'name'=>$e['display_name']],'month'=>$month,'documents'=>$docs,'costs'=>$costs,'imports'=>$imports,'summary'=>$summary,'base_gross'=>$base===false?null:$base,'revision'=>(int)$m['revision'],'snapshot_current'=>$m['snapshot_revision']!==null&&(int)$m['snapshot_revision']===(int)$m['revision'],'calculated_at'=>$m['calculated_at']];
}
function jurkoSalesSnapshot(PDO $db,int $actor,string $publicId,string $month): array {
    $state=jurkoSalesState($db,$publicId,$month);$e=jurkoSalesEmployee($db,$publicId);
    $db->prepare('UPDATE jm_sales_months SET snapshot_json=?,snapshot_revision=revision,calculated_at=UTC_TIMESTAMP() WHERE employee_id=? AND month=?')->execute([json_encode($state['summary'],JSON_THROW_ON_ERROR),$e['id'],$month]);
    jurkoAudit($db,$actor,'calculate','sales_month',$e['id'].':'.$month);return $state['summary'];
}
function jurkoSalesWrite(PDO $db,int $actor,array $input): array {
    $publicId=jurkoText($input['employee']??null,64);$month=jurkoMonth($input['month']??null);$action=jurkoEnum($input['action']??null,['document','cost_add','cost_archive','rule','calculate','review_costs']);$p=$input['data']??[];
    if(!is_array($p))throw new InvalidArgumentException();$request=jurkoText($input['request_id']??null,36);if(!preg_match('/^[a-f0-9-]{36}$/D',$request))throw new InvalidArgumentException();
    $db->beginTransaction();
    try{
        $e=jurkoSalesEmployee($db,$publicId,true);$employee=(int)$e['id'];$m=jurkoSalesMonth($db,$employee,$month);
        $hash=hash('sha256',json_encode([$publicId,$month,$action,$p],JSON_THROW_ON_ERROR));
        $q=$db->prepare('INSERT IGNORE INTO jm_write_requests(actor_id,request_id,action,payload_hash) VALUES (?,?,?,?)');$q->execute([$actor,$request,'sales_'.$action,$hash]);
        if(!$q->rowCount()){$q=$db->prepare('SELECT payload_hash FROM jm_write_requests WHERE actor_id=? AND request_id=?');$q->execute([$actor,$request]);if(!hash_equals((string)$q->fetchColumn(),$hash))throw new DomainException('Klucz zapisu został użyty.');$db->commit();return jurkoSalesState($db,$publicId,$month);}
        if($action==='rule'){
            $rate=jurkoCents($p['rate']??'');if($rate<0||$rate>10000)throw new InvalidArgumentException('Udział musi wynosić od 0 do 100%.');
            if((int)($p['revision']??-1)!==(int)$m['revision'])throw new DomainException('Raport zmienił się w innym oknie. Odśwież stronę.');
            $db->prepare('UPDATE jm_sales_months SET rate_bp=? WHERE employee_id=? AND month=?')->execute([$rate,$employee,$month]);
            $db->prepare('INSERT INTO jm_sales_rules(employee_id,rate_bp) VALUES (?,?) ON DUPLICATE KEY UPDATE rate_bp=VALUES(rate_bp)')->execute([$employee,$rate]);
        }elseif($action==='document'){
            $q=$db->prepare('SELECT * FROM jm_sales_documents WHERE id=? AND employee_id=? FOR UPDATE');$q->execute([$p['id']??0,$employee]);$d=$q->fetch();if(!$d)throw new InvalidArgumentException('Brak dokumentu.');
            if((int)($p['version']??0)!==(int)$d['version'])throw new DomainException('Dokument zmieniono w innym oknie. Odśwież raport.');
            $newMonth=jurkoMonth($p['settlement_month']??null);$share=jurkoCents($p['share']??'');if($share<0||$share>10000)throw new InvalidArgumentException('Udział dokumentu musi wynosić od 0 do 100%.');
            $profit=($p['profit_override']??'')===''?null:jurkoDecimal(jurkoCents($p['profit_override']));$note=jurkoText($p['note']??'',4000,true);
            $reviewed=($p['reviewed']??false)===true?1:0;
            $db->prepare('UPDATE jm_sales_documents SET settlement_month=?,share_bp=?,profit_override=?,note=?,reviewed=?,version=version+1 WHERE id=?')->execute([$newMonth,$share,$profit,$note,$reviewed,$d['id']]);
            jurkoSalesTouch($db,$employee,$d['settlement_month']);if($newMonth!==$d['settlement_month'])jurkoSalesTouch($db,$employee,$newMonth);
        }elseif($action==='cost_add'){
            $category=jurkoEnum($p['category']??null,['transport','cutting','architect','other']);$description=jurkoText($p['description']??null,250);
            $qty=$p['quantity']??'1';if(!is_string($qty)||!preg_match('/^\d{1,7}(?:\.\d{1,4})?$/D',$qty)||(float)$qty<=0)throw new InvalidArgumentException('Wpisz dodatnią ilość.');
            $unit=jurkoCents($p['unit_price']??'');if($unit<0)throw new InvalidArgumentException('Koszt nie może być ujemny.');
            $bp=($p['percent']??'')===''?null:jurkoCents($p['percent']);if($bp!==null&&($bp<0||$bp>10000))throw new InvalidArgumentException('Procent od 0 do 100.');
            $amount=(int)round((float)$qty*$unit*($bp===null?1:$bp/10000),0,PHP_ROUND_HALF_UP);if($amount>9999999999999)throw new InvalidArgumentException('Zbyt duży koszt.');
            $doc=null;$costMonth=$month;if(($p['document_id']??'')!==''){$q=$db->prepare('SELECT id,settlement_month FROM jm_sales_documents WHERE id=? AND employee_id=?');$q->execute([$p['document_id'],$employee]);$d=$q->fetch();if(!$d)throw new InvalidArgumentException('Wybierz fakturę tego pracownika.');$doc=$d['id'];$costMonth=$d['settlement_month'];}
            $db->prepare('INSERT INTO jm_sales_costs(employee_id,document_id,month,category,description,quantity,unit_price,percent_bp,amount_net,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$employee,$doc,$costMonth,$category,$description,$qty,jurkoDecimal($unit),$bp,jurkoDecimal($amount),$actor]);
            jurkoSalesTouch($db,$employee,$costMonth);
        }elseif($action==='cost_archive'){
            $q=$db->prepare('SELECT c.*,d.settlement_month FROM jm_sales_costs c LEFT JOIN jm_sales_documents d ON d.id=c.document_id WHERE c.id=? AND c.employee_id=? AND c.deleted_at IS NULL FOR UPDATE');$q->execute([$p['id']??0,$employee]);$c=$q->fetch();if(!$c)throw new InvalidArgumentException('Nie znaleziono kosztu.');
            $db->prepare('UPDATE jm_sales_costs SET deleted_at=UTC_TIMESTAMP() WHERE id=?')->execute([$c['id']]);jurkoSalesTouch($db,$employee,$c['settlement_month']??$c['month']);
        }elseif($action==='review_costs'){
            if((int)($p['revision']??-1)!==(int)$m['revision'])throw new DomainException('Raport zmienił się w innym oknie. Odśwież stronę.');
            $db->prepare('UPDATE jm_sales_months SET costs_reviewed=TRUE,revision=revision+1 WHERE employee_id=? AND month=?')->execute([$employee,$month]);
        }else{jurkoSalesSnapshot($db,$actor,$publicId,$month);}
        if($action==='rule')jurkoSalesTouch($db,$employee,$month);
        jurkoAudit($db,$actor,$action,'sales',$employee.':'.$month);$db->commit();return jurkoSalesState($db,$publicId,$month);
    }catch(Throwable $ex){if($db->inTransaction())$db->rollBack();throw $ex;}
}
