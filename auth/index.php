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
?><!doctype html><html lang="pl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Logowanie — JURKO MANAGER</title><h1>JURKO MANAGER</h1><p>Dostęp wyłącznie dla zatwierdzonych kont.</p><a href="?action=login">Zaloguj przez Google</a><p>Google może poprosić o potwierdzenie na telefonie. Hasło podajesz wyłącznie Google.</p></html>

