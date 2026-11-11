<?php
declare(strict_types=1);
require __DIR__ . '/../server/auth-bootstrap.php';
try {
    $config = jurkoAuthConfig();
    require __DIR__ . '/../vendor/autoload.php';
    $action = $_GET['action'] ?? '';
    $client = new Google\Client();
    $client->setClientId($config['client_id']);
    $client->setClientSecret($config['client_secret']);
    $client->setRedirectUri($config['redirect_uri']);
    $client->setScopes(['openid','email']);
    if ($action === 'login') {
        // Stan i nonce są jednorazowe; sesja ogranicza też częstotliwość prób.
        if (time() - ($_SESSION['login_started'] ?? 0) < 5) { http_response_code(429); exit('Spróbuj ponownie za chwilę.'); }
        $_SESSION['login_started'] = time();
        $_SESSION['oauth'] = ['state'=>bin2hex(random_bytes(32)), 'nonce'=>bin2hex(random_bytes(32)), 'time'=>time()];
        $client->setState($_SESSION['oauth']['state']);
        $client->setPrompt('select_account');
        $url = $client->createAuthUrl();
        header('Location: ' . $url . '&nonce=' . rawurlencode($_SESSION['oauth']['nonce']), true, 303); exit;
    }
    if ($action === 'callback') {
        $pending = $_SESSION['oauth'] ?? [];
        unset($_SESSION['oauth']);
        if (!is_string($_GET['state'] ?? null) || !is_string($pending['state'] ?? null)
            || !hash_equals($pending['state'], $_GET['state']) || time() - ($pending['time'] ?? 0) > 300
            || !is_string($_GET['code'] ?? null)) { throw new RuntimeException('Niepoprawny powrót.'); }
        $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
        if (!is_string($token['id_token'] ?? null)) { throw new RuntimeException('Brak tokenu.'); }
        // Biblioteka Google weryfikuje podpis i ważność, polityka sprawdza odbiorcę, nonce i listę kont.
        $claims = $client->verifyIdToken($token['id_token']);
        if (!is_array($claims)) { throw new RuntimeException('Niepoprawny token.'); }
        if (!isset($config['accounts'][$claims['sub'] ?? ''])) {
            $user = jurkoOwnerCandidate($claims, $config, $pending['nonce'], time());
            jurkoBindOwner($user, $config);
        } else {
            $user = jurkoAuthorizeGoogle($claims, $config['accounts'], $pending['nonce'], $config['client_id'], time());
        }
        session_regenerate_id(true);
        $_SESSION['user'] = $user;
        $_SESSION['signed_at'] = $_SESSION['last_seen'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        header('Location: /app/', true, 303); exit;
    }
    if ($action === 'logout') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_string($_POST['csrf'] ?? null)
            || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) { http_response_code(403); exit('Brak zgody na operację.'); }
        $_SESSION = [];
        session_destroy();
        setcookie(session_name(), '', ['expires'=>1,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
        header('Location: /auth/', true, 303); exit;
    }
} catch (DomainException $e) {
    http_response_code(403); exit('To konto nie ma dostępu do JURKO MANAGER. Skontaktuj się z właścicielem.');
} catch (Throwable $e) {
    http_response_code(503); exit('Logowanie Google nie jest jeszcze dostępne. Wymagana konfiguracja na serwerze.');
}
?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#101513">
<title>Logowanie — JURKO MANAGER</title>
<style>
*{box-sizing:border-box}
body{margin:0;min-height:100vh;min-height:100svh;display:grid;place-items:center;padding:24px;color:#f3f5f1;font:16px/1.5 Inter,system-ui,sans-serif;background:#101513;background-image:radial-gradient(ellipse at 50% 10%,#214236 0%,transparent 58%),repeating-linear-gradient(155deg,transparent 0 22px,#c8d3ca09 23px,transparent 25px 69px),linear-gradient(135deg,#202623,#0d100f)}
main{width:min(100%,440px);padding:clamp(24px,6vw,48px);background:#171d1a;border:1px solid #41534a;border-radius:22px;box-shadow:0 30px 90px #0008;text-align:center;animation:enter .45s ease-out}
.brand{font-weight:850;letter-spacing:.26em;color:#a2e0bd;text-shadow:0 0 24px #60c19070}
h1{margin:8px 0 12px;font-size:clamp(28px,6vw,42px);line-height:1.05;letter-spacing:-.04em}
p{margin:0;color:#bac7bf}
a{display:flex;align-items:center;justify-content:center;gap:12px;min-height:50px;margin:26px 0 16px;padding:10px 16px;border-radius:10px;background:#f1f7f2;color:#14251c;text-decoration:none;font-weight:700}
a:hover{background:#d5f0df}
a:focus-visible{outline:3px solid #9be0b7;outline-offset:5px}
.google{color:#1967d2;font-size:22px;font-weight:800}
.note{font-size:13px}
@keyframes enter{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
@media(prefers-reduced-motion:reduce){main{animation:none}}
</style>
</head>
<body>
<main>
<div class="brand">JURKO</div>
<h1>Logowanie do Managera</h1>
<p>Dostęp wyłącznie dla zatwierdzonych kont.</p>
<a href="?action=login"><span class="google" aria-hidden="true">G</span>Zaloguj przez Google</a>
<p class="note">Google może poprosić o potwierdzenie na telefonie. Hasło podajesz wyłącznie Google.</p>
</main>
</body>
</html>
