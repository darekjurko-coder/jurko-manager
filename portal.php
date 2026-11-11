<?php
declare(strict_types=1);
require __DIR__.'/server/auth-bootstrap.php';
require_once __DIR__.'/server/database.php';
require_once __DIR__.'/server/portal-data.php';
try{
    $config=jurkoAuthConfig();$user=jurkoCurrentUser($config);
    if(!$user){header('Location: /auth/',true,303);exit;}
    $db=jurkoDatabase();jurkoFinanceAccount($db,$user,$config);
    if (isset($_GET['original_report']) || isset($_GET['report_api']) || isset($_GET['report_asset'])) {
        require_once __DIR__.'/server/original-report/route.php';
        jurkoOriginalReport($db);
        exit;
    }

    $month=jurkoMonth($_GET['month']??date('Y-m'));
    $state=jurkoPortalState($db,$month);
    $state['csrf']=$_SESSION['csrf'];
    $nonce=base64_encode(random_bytes(24));
    header("Content-Security-Policy: default-src 'none'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'unsafe-inline'; connect-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
    header('Content-Type: text/html; charset=utf-8');
    $boot='<script nonce="'.htmlspecialchars($nonce,ENT_QUOTES).'">window.JurkoInitial='.json_encode($state,JSON_THROW_ON_ERROR|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';</script>';
    $html=file_get_contents(__DIR__.'/server/owner-panel.html');
    foreach(['portal-client.js','staff.js','owner-panel.js','sales.js'] as $asset){
        $version=substr(hash_file('sha256',__DIR__.'/'.$asset),0,16);
        $html=str_replace('src="/'.$asset.'"','src="/'.$asset.'?v='.$version.'"',$html);
    }
    echo str_replace(['<!--JURKO_BOOT-->','<!--JURKO_CSRF-->'],[$boot,jurkoEscape($_SESSION['csrf'])],$html);
}catch(DomainException $e){http_response_code(403);echo 'To konto nie ma dostępu do panelu właściciela.';}
catch(Throwable $e){http_response_code(503);echo 'Nie można wczytać danych. Odśwież stronę za chwilę. Nic nie zostało zmienione.';}
