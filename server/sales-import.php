<?php
declare(strict_types=1);
require_once __DIR__.'/sales.php';
function jurkoSalesReadExcel(string $file,string $extension,string $month): array {
    if(!in_array($extension,['xls','xlsx'],true))throw new InvalidArgumentException('Wybierz plik XLS lub XLSX.');
    require_once __DIR__.'/../vendor/autoload.php';
    if($extension==='xlsx'){
        $zip=new ZipArchive();if($zip->open($file)!==true)throw new InvalidArgumentException('Niepoprawny plik XLSX.');$bytes=0;
        if($zip->numFiles>2000)throw new InvalidArgumentException('Plik ma zbyt wiele części.');
        for($i=0;$i<$zip->numFiles;$i++){$st=$zip->statIndex($i);$bytes+=$st['size'];if($bytes>20000000)throw new InvalidArgumentException('Rozpakowany plik jest za duży.');}$zip->close();
    }
    $type=$extension==='xls'?'Xls':'Xlsx';$reader=\PhpOffice\PhpSpreadsheet\IOFactory::createReader($type);
    if(!$reader->canRead($file))throw new InvalidArgumentException('Format pliku nie zgadza się z rozszerzeniem.');
    $info=$reader->listWorksheetInfo($file);if(!$info||count($info)>10||$info[0]['totalRows']>2000||$info[0]['totalColumns']>30)throw new InvalidArgumentException('Raport może mieć do 2000 wierszy i 30 kolumn.');
    $reader->setLoadSheetsOnly($info[0]['worksheetName']);$reader->setReadDataOnly(true);$reader->setReadEmptyCells(false);$book=$reader->load($file);$sheet=$book->getSheet(0);$grid=[];
    for($r=1;$r<=$info[0]['totalRows'];$r++){$line=[];for($c=1;$c<=$info[0]['totalColumns'];$c++){$cell=$sheet->getCell([$c,$r]);$v=$cell->isFormula()?$cell->getOldCalculatedValue():$cell->getValue();if(is_object($v))$v=(string)$v;if($cell->isFormula()&&$v===null)throw new InvalidArgumentException('Raport zawiera formułę bez zapisanego wyniku. Otwórz go w Excelu i zapisz.');$line[]=$v??'';}$grid[]=$line;}
    $book->disconnectWorksheets();return jurkoSalesParseGrid($grid,$month);
}
function jurkoSalesParseGrid(array $grid,string $month): array {
    jurkoMonth($month);$header=(string)($grid[0][0]??'');$period=(string)($grid[0][1]??'');
    if(mb_stripos($header,'Raport do prowizji handlowca')===false||!preg_match('/\d{2}\.(\d{2})\.(20\d{2})\s*-\s*\d{2}\.(\d{2})\.(20\d{2})/u',$period,$matches)||$matches[2].'-'.$matches[1]!==$month||$matches[4].'-'.$matches[3]!==$month)throw new InvalidArgumentException('Ten import obsługuje raport „Raport do prowizji handlowca”. Sprawdź wybrany miesiąc i nagłówek.');
    $documents=[];$notes=[];$section=(string)($grid[1][0]??'');$seen=[];
    foreach($grid as $i=>$r){
        $number=trim((string)($r[2]??''));
        if(preg_match('/^(F|FK|FV|KOR|Z|ZO|ZAM|FS)[\/-]/iu',$number)){
            foreach([3,4,5,6] as $c)if(!is_numeric($r[$c]??null))throw new InvalidArgumentException('Brak kwoty w wierszu '.($i+1).'.');
            $date=$r[1]??null;if(!is_numeric($date)||$date<36526||$date>73415)throw new InvalidArgumentException('Niepoprawna data w wierszu '.($i+1).'.');
            $date=(new DateTimeImmutable('1899-12-30'))->modify('+'.(int)$date.' days')->format('Y-m-d');
            $type=preg_match('/^(Z|ZO|ZAM)[\/-]/iu',$number)?'order':(preg_match('/^(FK|KOR)[\/-]/iu',$number)?'correction':'invoice');
            $key=$number.':'.$date;if(isset($seen[$key]))throw new InvalidArgumentException('Powtórzony dokument w tym pliku: '.$number);$seen[$key]=true;
            $extra=[];for($j=9;$j<count($r);$j++)if((string)$r[$j]!=='')$extra[]=chr(65+$j).': '.(string)$r[$j];
            $note='Sekcja: '.$section.'; wiersz '.($i+1).'. '.implode(' | ',$extra);if(substr($date,0,7)!==$month)$note.=' Uwaga: data dokumentu poza miesiącem raportu.';
            $documents[]=['source_row'=>$i+1,'document_no'=>jurkoText($number,100),'document_type'=>$type,'document_date'=>$date,'customer'=>jurkoText((string)($r[0]??''),250),'invoice_net'=>jurkoDecimal(jurkoCents((float)$r[3])),'source_deduction'=>jurkoDecimal(jurkoCents((float)$r[4])),'turnover_net'=>jurkoDecimal(jurkoCents((float)$r[5])),'source_profit'=>jurkoDecimal(jurkoCents((float)$r[6])),'note'=>mb_substr($note,0,4000)];
        }else{
            $nonempty=array_filter($r,static fn($x)=>(string)$x!=='');
            if($i>1&&is_numeric($r[1]??null)&&(float)$r[1]===0.0&&is_string($r[0]??null)&&$r[0]!=='')$section=$r[0];
            if($i>1&&$nonempty)$notes[]=['row'=>$i+1,'text'=>implode(' | ',array_map(static fn($x)=>(string)$x,$nonempty))];
        }
    }
    if(!$documents)throw new InvalidArgumentException('Nie znaleziono dokumentów w raporcie.');
    return ['person'=>(string)($grid[1][0]??''),'period'=>$period,'documents'=>$documents,'notes'=>$notes,'raw_rows'=>$grid,'mapping'=>'D: wartość faktury; E: odliczenia z raportu; F: obrót po odliczeniach; G: zapisany zysk. Odliczeń E i dopisków J–Q nie odejmujemy ponownie automatycznie. Zysk G może już zawierać ręczne poprawki.'];
}
function jurkoSalesImport(PDO $db,int $actor,array $preview): array {
    $db->beginTransaction();
    try{
        $e=jurkoSalesEmployee($db,$preview['employee'],true);$emp=(int)$e['id'];$month=jurkoMonth($preview['month']);$company=jurkoEnum($preview['company'],['zoo','spk']);
        $q=$db->prepare('SELECT id FROM jm_sales_imports WHERE employee_id=? AND company=? AND file_hash=?');$q->execute([$emp,$company,$preview['hash']]);if($q->fetchColumn()){$db->commit();return ['imported'=>0,'duplicate'=>true];}
        $source=$preview['parsed'];$db->prepare('INSERT INTO jm_sales_imports(employee_id,company,report_month,filename,file_hash,source_json,created_by) VALUES (?,?,?,?,?,?,?)')->execute([$emp,$company,$month,$preview['filename'],$preview['hash'],json_encode($source,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$actor]);$import=$db->lastInsertId();$count=0;
        foreach($source['documents'] as $d){
            $q=$db->prepare('SELECT source_profit,invoice_net,turnover_net FROM jm_sales_documents WHERE employee_id=? AND company=? AND document_no=? AND document_date=?');$q->execute([$emp,$company,$d['document_no'],$d['document_date']]);$existing=$q->fetch();
            if($existing){foreach(['source_profit','invoice_net','turnover_net'] as $field)if(jurkoCents($existing[$field])!==jurkoCents($d[$field]))throw new DomainException('Dokument '.$d['document_no'].' jest już w bazie z innymi kwotami. Import zatrzymany bez nadpisywania.');continue;}
            $db->prepare('INSERT INTO jm_sales_documents(employee_id,import_id,source_row,company,document_no,document_type,document_date,settlement_month,customer,invoice_net,turnover_net,source_deduction,source_profit,note) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$emp,$import,$d['source_row'],$company,$d['document_no'],$d['document_type'],$d['document_date'],$month,$d['customer'],$d['invoice_net'],$d['turnover_net'],$d['source_deduction'],$d['source_profit'],$d['note']]);$count++;
        }
        jurkoSalesTouch($db,$emp,$month);jurkoAudit($db,$actor,'import','sales_import',(string)$import);$db->commit();return ['imported'=>$count,'duplicate'=>false];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
