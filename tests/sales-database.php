<?php
declare(strict_types=1);
require __DIR__.'/../server/database.php';require __DIR__.'/../server/sales-import.php';
$db=jurkoDatabase();$public='synthetic-sales-ci';
$db->exec("INSERT INTO jm_accounts(email,role,active) VALUES ('sales-ci@example.invalid','owner',1)");$actor=(int)$db->lastInsertId();
$db->prepare("INSERT INTO jm_employees(public_id,display_name,department,plan_metric) VALUES (?,'SYNTHETIC SALES','sales','turnover')")->execute([$public]);$employee=(int)$db->lastInsertId();
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$write=static function(string $action,array $data,?string $key=null)use($db,$actor,$public):array{return jurkoSalesWrite($db,$actor,['employee'=>$public,'month'=>'2026-10','action'=>$action,'data'=>$data,'request_id'=>$key??sprintf('11111111-1111-4111-8111-%012d',random_int(1,999999999999))]);};
try{
    $grid=[['Raport do prowizji handlowca','dla okresu: 01.10.2026 - 31.10.2026'],['SYNTHETIC SALES',0],['SYNTHETIC CUSTOMER',46300,'F/TEST/26',1000,100,900,300,0,0,'',999,'Uwaga: już odjęto w zysku'],['SYNTHETIC CUSTOMER',46301,'FK/TEST/26',-100,0,-100,-30],['SYNTHETIC CUSTOMER',46302,'Z/TEST/26',500,0,500,100],['Dopisek do sprawdzenia','','','Koszt przeniesiony',10]];
    $parsed=jurkoSalesParseGrid($grid,'2026-10');$assert(count($parsed['documents'])===3,'Parser pomija dokumenty');$assert(count($parsed['notes'])===1,'Utracone uwagi');$assert($parsed['documents'][0]['source_profit']==='300.00','Zmieniono źródłowy zysk');
    try{jurkoSalesParseGrid($grid,'2026-09');throw new LogicException('Przyjęto inny miesiąc');}catch(InvalidArgumentException $e){}
    require_once __DIR__.'/../vendor/autoload.php';
    foreach(['Xls','Xlsx'] as $type){$book=new \PhpOffice\PhpSpreadsheet\Spreadsheet();$book->getActiveSheet()->fromArray($grid,null,'A1');$file=tempnam(sys_get_temp_dir(),'jm-sales-');try{\PhpOffice\PhpSpreadsheet\IOFactory::createWriter($book,$type)->save($file);$read=jurkoSalesReadExcel($file,strtolower($type),'2026-10');$assert(count($read['documents'])===3,'Błąd odczytu '.$type);}finally{unlink($file);$book->disconnectWorksheets();}}
    $preview=['employee'=>$public,'month'=>'2026-10','company'=>'spk','filename'=>'synthetic.xls','hash'=>hash('sha256','synthetic-sales'),'parsed'=>$parsed];
    $first=jurkoSalesImport($db,$actor,$preview);$again=jurkoSalesImport($db,$actor,$preview);$assert($first['imported']===3&&$again['duplicate'],'Duplikat importu');
    $state=jurkoSalesState($db,$public,'2026-10');$assert($state['summary']['commission']===null&&!$state['summary']['ready'],'Nieustalona prowizja została naliczona');$assert($state['summary']['turnover']==='800.00','Zamówienie doliczone do obrotu');
    $state=$write('rule',['rate'=>'50','revision'=>$state['revision']]);$invoice=$state['documents'][0];$invoice=array_values(array_filter($state['documents'],fn($d)=>$d['document_type']==='invoice'))[0];
    $cost=['document_id'=>(string)$invoice['id'],'category'=>'cutting','description'=>'SYNTHETIC CUTTING','quantity'=>'2.5','unit_price'=>'20','percent'=>''];$key='11111111-1111-4111-8111-000000000001';$write('cost_add',$cost,$key);$state=$write('cost_add',$cost,$key);$assert(count($state['costs'])===1,'Podwójny koszt po ponowieniu');
    $state=$write('cost_add',['document_id'=>(string)$invoice['id'],'category'=>'architect','description'=>'SYNTHETIC ARCHITECT','quantity'=>'1','unit_price'=>'1000','percent'=>'10']);
    $assert($state['summary']['costs']==='150.00'&&$state['summary']['commission']==='60.00','Błędna ilość, procent, korekta lub 50/50');
    foreach($state['documents'] as $d)$state=$write('document',['id'=>(string)$d['id'],'version'=>(int)$d['version'],'settlement_month'=>'2026-10','share'=>'100','profit_override'=>'','note'=>$d['note'],'reviewed'=>true]);
    $assert(!$state['summary']['ready'],'Pominięto sprawdzenie kosztów');$state=$write('review_costs',['revision'=>$state['revision']]);$state=$write('calculate',[]);$assert($state['summary']['ready']&&$state['snapshot_current'],'Błąd zatwierdzenia podsumowania');
    $d=array_values(array_filter($state['documents'],fn($d)=>$d['document_type']==='invoice'))[0];$update=['id'=>(string)$d['id'],'version'=>(int)$d['version'],'settlement_month'=>'2026-10','share'=>'50','profit_override'=>'','note'=>'SYNTHETIC SHARE','reviewed'=>true];$state=$write('document',$update);$assert($state['summary']['commission']==='22.50'&&!$state['snapshot_current'],'Udział dokumentu lub unieważnienie podsumowania');
    try{$write('document',$update);throw new LogicException('Nadpisano nowszy dokument');}catch(DomainException $e){}
    $d=array_values(array_filter($state['documents'],fn($d)=>$d['document_type']==='invoice'))[0];$update['version']=(int)$d['version'];$update['settlement_month']='2026-11';$state=$write('document',$update);$next=jurkoSalesState($db,$public,'2026-11');$assert(count($next['costs'])===2&&$state['summary']['costs']==='0.00','Koszty nie podążyły za fakturą');
    $conflict=$preview;$conflict['hash']=hash('sha256','synthetic-conflict');$conflict['parsed']['documents'][0]['source_profit']='999.00';try{jurkoSalesImport($db,$actor,$conflict);throw new LogicException('Nadpisano inny import');}catch(DomainException $e){}
    echo "Import XLS/XLSX, duplikaty, 50/50, koszty ilościowe i procentowe, korekty, udziały, wersje oraz przeniesienie miesiąca OK\n";
}finally{
    if($db->inTransaction())$db->rollBack();
    foreach(['jm_sales_costs','jm_sales_documents','jm_sales_imports','jm_sales_months','jm_sales_rules'] as $table)$db->prepare('DELETE FROM '.$table.' WHERE employee_id=?')->execute([$employee]);
    $db->prepare('DELETE FROM jm_employees WHERE id=?')->execute([$employee]);$db->prepare('DELETE FROM jm_audit_log WHERE actor_id=?')->execute([$actor]);$db->prepare('DELETE FROM jm_write_requests WHERE actor_id=?')->execute([$actor]);$db->prepare('DELETE FROM jm_accounts WHERE id=?')->execute([$actor]);
}
