<?php
declare(strict_types=1);
require __DIR__ . '/../server/auth-bootstrap.php';
try { $user = jurkoCurrentUser(jurkoAuthConfig()); }
catch (Throwable $e) { http_response_code(503); exit('Panel wymaga konfiguracji logowania.'); }
if (!$user) { header('Location: /auth/', true, 303); exit; }
?><!doctype html><html lang="pl"><meta charset="utf-8"><title>Panel — JURKO MANAGER</title><h1>JURKO MANAGER</h1><p>Zalogowano przez Google.</p><p>Rola: <?= jurkoEscape($user['role']) ?></p><p>Zapisywanie danych firmowych i moduły panelu nie są jeszcze uruchomione. Dotychczasowa wersja demonstracyjna nie jest chroniona tym logowaniem.</p><form method="post" action="/auth/index.php?action=logout"><input type="hidden" name="csrf" value="<?= jurkoEscape($_SESSION['csrf']) ?>"><button>Wyloguj</button></form></html>
