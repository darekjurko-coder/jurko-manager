<?php
declare(strict_types=1);
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; style-src \'self\'; form-action \'self\'; frame-ancestors \'none\'; base-uri \'none\'');
if (($_SERVER['HTTPS'] ?? '') !== 'on') {
    http_response_code(503); exit('Logowanie wymaga HTTPS.');
}
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('__Host-jurko');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
session_start();
require_once __DIR__ . '/auth-policy.php';
require_once __DIR__ . '/owner.php';
function jurkoAuthConfig(): array
{
    $path = dirname(__DIR__, 2) . '/jurko-private/auth.php';
    if (!is_file($path)) { throw new RuntimeException('Brak konfiguracji.'); }
    $c = require $path;
    foreach (['client_id','client_secret','redirect_uri'] as $key) {
        if (!is_string($c[$key] ?? null) || $c[$key] === '') { throw new RuntimeException('Brak konfiguracji.'); }
    }
    $u = parse_url($c['redirect_uri']);
    if (($u['scheme'] ?? '') !== 'https' || isset($u['user']) || isset($u['pass'])
        || ($u['path'] ?? '') !== '/auth/index.php' || !is_array($c['accounts'] ?? null)) {
        throw new RuntimeException('Niepoprawna konfiguracja.');
    }
    $ownerPath = dirname(__DIR__, 2) . '/jurko-private/owner.json';
    if (is_file($ownerPath)) {
        $owner = json_decode(file_get_contents($ownerPath), true, 512, JSON_THROW_ON_ERROR);
        if (is_string($owner['sub'] ?? null) && ($owner['email'] ?? null) === ($c['owner_email'] ?? null)) {
            $c['accounts'][$owner['sub']] = ['role'=>'owner', 'email'=>$owner['email']];
        }
    }
    return $c;
}
function jurkoCurrentUser(array $config): ?array
{
    $u = $_SESSION['user'] ?? null;
    if (!is_array($u) || time() - ($_SESSION['last_seen'] ?? 0) > 1800
        || time() - ($_SESSION['signed_at'] ?? 0) > 28800) { unset($_SESSION['user']); return null; }
    $role = $config['accounts'][$u['sub']]['role'] ?? null;
    if ($role === null && ($u['role'] ?? null) === 'employee' && is_string($u['transport_email'] ?? null)) {
        $_SESSION['last_seen'] = time();
        return ['sub'=>$u['sub'], 'role'=>'employee', 'transport_email'=>$u['transport_email']];
    }
    if (!in_array($role, ['owner','manager','employee'], true)) { unset($_SESSION['user']); return null; }
    $_SESSION['last_seen'] = time();
    return ['sub'=>$u['sub'], 'role'=>$role];
}
function jurkoEscape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

