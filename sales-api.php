<?php
declare(strict_types=1);
require __DIR__.'/server/auth-bootstrap.php';require_once __DIR__.'/server/database.php';require_once __DIR__.'/server/sales-import.php';
header('Content-Type: application/json; charset=utf-8');
try{
    $config=jurkoAuthConfig();$user=jurkoCurrentUser($config);if(!$user){http_response_code(401);echo '{"error":"Zaloguj się ponownie."}';exit;}
    $db=jurkoDatabase();$actor=jurkoFinanceAccount($db,$user,$config);
    if($_SERVER['REQUEST_METHOD']==='GET'){$result=jurkoSalesState($db,jurkoText($_GET['employee']??null,64),jurkoMonth($_GET['month']??null));}
    elseif($_SERVER['REQUEST_METHOD']==='POST'){
        if(!hash_equals($_SESSION['csrf']??'',(string)($_SERVER['HTTP_X_CSRF_TOKEN']??''))||empty($_SERVER['HTTP_X_CSRF_TOKEN'])){http_response_code(403);echo '{"error":"Formularz wygasł. Odśwież stronę."}';exit;}
        if((int)($_SERVER['CONTENT_LENGTH']??0)>4000000)throw new InvalidArgumentException('Plik lub formularz jest za duży.');
        if(isset($_FILES['report'])){
            $f=$_FILES['report'];if($f['error']!==UPLOAD_ERR_OK||$f['size']>3000000||!is_uploaded_file($f['tmp_name']))throw new InvalidArgumentException('Nie udało się odczytać pliku do 3 MB.');
            $employee=jurkoText($_POST['employee']??null,64);jurkoSalesEmployee($db,$employee);$month=jurkoMonth($_POST['month']??null);$company=jurkoEnum($_POST['company']??null,['zoo','spk']);
            $parsed=jurkoSalesReadExcel($f['tmp_name'],strtolower(pathinfo($f['name'],PATHINFO_EXTENSION)),$month);$token=bin2hex(random_bytes(16));
            $_SESSION['sales_preview']=['token'=>$token,'expires'=>time()+1200,'employee'=>$employee,'month'=>$month,'company'=>$company,'filename'=>mb_substr(basename($f['name']),0,180),'hash'=>hash_file('sha256',$f['tmp_name']),'parsed'=>$parsed];
            $result=['preview_token'=>$token,'person'=>$parsed['person'],'period'=>$parsed['period'],'documents'=>$parsed['documents'],'notes'=>$parsed['notes'],'mapping'=>$parsed['mapping']];
        }else{
            $raw=file_get_contents('php://input',false,null,0,1048577);if(strlen($raw)>1048576)throw new InvalidArgumentException('Formularz jest za duży.');$input=json_decode($raw,true,64,JSON_THROW_ON_ERROR);if(!is_array($input))throw new InvalidArgumentException();
            if(($input['action']??'')==='import_confirm'){
                $p=$_SESSION['sales_preview']??null;if(!$p||$p['expires']<time()||!hash_equals($p['token'],(string)($input['preview_token']??'')))throw new DomainException('Podgląd importu wygasł. Wybierz plik ponownie.');
                $result=jurkoSalesImport($db,$actor,$p);unset($_SESSION['sales_preview']);
            }else{$result=jurkoSalesWrite($db,$actor,$input);}
        }
    }else{http_response_code(405);header('Allow: GET, POST');echo '{"error":"Niedozwolona metoda."}';exit;}
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(DomainException $e){http_response_code(409);echo json_encode(['error'=>$e->getMessage()]);}
catch(InvalidArgumentException|JsonException $e){http_response_code(422);echo json_encode(['error'=>$e instanceof JsonException?'Niepoprawny formularz.':$e->getMessage()]);}
catch(Throwable $e){http_response_code(503);echo '{"error":"Nie można potwierdzić operacji. Odśwież raport. Niczego nie wgrywaj ponownie przed sprawdzeniem."}';}
