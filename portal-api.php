<?php
declare(strict_types=1);
require __DIR__.'/server/auth-bootstrap.php';
require_once __DIR__.'/server/database.php';
require_once __DIR__.'/server/portal-data.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $config=jurkoAuthConfig();$user=jurkoCurrentUser($config);
    if(!$user){http_response_code(401);echo json_encode(['error'=>'Zaloguj się ponownie.']);exit;}
    $db=jurkoDatabase();$actor=jurkoFinanceAccount($db,$user,$config);
    if($_SERVER['REQUEST_METHOD']==='GET'){
        echo json_encode(jurkoPortalState($db,jurkoMonth($_GET['month']??date('Y-m'))),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    }elseif($_SERVER['REQUEST_METHOD']==='POST'){
        if(!is_string($_SERVER['HTTP_X_CSRF_TOKEN']??null)||!hash_equals($_SESSION['csrf']??'',$_SERVER['HTTP_X_CSRF_TOKEN'])){http_response_code(403);echo '{"error":"Formularz wygasł. Odśwież stronę."}';exit;}
        if((int)($_SERVER['CONTENT_LENGTH']??0)>1048576)throw new InvalidArgumentException('Zbyt dużo danych w jednym zapisie.');
        $raw=file_get_contents('php://input',false,null,0,1048577);
        if(strlen($raw)>1048576)throw new InvalidArgumentException('Zbyt dużo danych.');
        $input=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
        if(!is_array($input))throw new InvalidArgumentException('Niepoprawny formularz.');
        echo json_encode(jurkoPortalWrite($db,$actor,$input),JSON_THROW_ON_ERROR);
    }else{http_response_code(405);header('Allow: GET, POST');echo '{"error":"Niedozwolona metoda."}';}
}catch(DomainException $e){http_response_code(409);echo json_encode(['error'=>$e->getMessage()]);}
catch(InvalidArgumentException|JsonException $e){http_response_code(422);echo json_encode(['error'=>$e instanceof JsonException?'Niepoprawny formularz.':$e->getMessage()]);}
catch(Throwable $e){http_response_code(503);echo '{"error":"Nie można potwierdzić operacji. Odśwież stronę przed kolejną próbą."}';}
