<?php
declare(strict_types=1);
// Wyłącznie po weryfikacji podpisu tokenu przez bibliotekę Google.
function jurkoOwnerCandidate(array $claims, array $config, string $nonce, int $now): array
{
    $email = $config['owner_email'] ?? '';
    if (!is_string($email) || !preg_match('/^[a-z0-9._%+-]+@gmail\.com$/D', $email)
        || ($claims['email'] ?? null) !== $email) { throw new DomainException('Brak dostępu.'); }
    return jurkoAuthorizeGoogle($claims, [(string)($claims['sub'] ?? '')=>['role'=>'owner']], $nonce, $config['client_id'], $now);
}
function jurkoBindOwner(array $user, array $config): void
{
    $path = dirname(__DIR__, 2) . '/jurko-private/owner.json';
    $old = umask(0077); $f = fopen($path, 'c+'); umask($old);
    if (!$f) { throw new RuntimeException('Brak dostępu do konfiguracji.'); }
    try {
        if (!flock($f, LOCK_EX)) { throw new RuntimeException(); }
        $raw = stream_get_contents($f);
        if ($raw !== '') {
            $saved = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (($saved['sub'] ?? null) !== $user['sub'] || ($saved['email'] ?? null) !== $config['owner_email']) {
                throw new DomainException('Konto właściciela zostało już powiązane.');
            }
        } else {
            $json = json_encode(['sub'=>$user['sub'], 'email'=>$config['owner_email']], JSON_THROW_ON_ERROR);
            if (fwrite($f, $json) !== strlen($json) || !fflush($f)) { throw new RuntimeException(); }
        }
    } finally { flock($f, LOCK_UN); fclose($f); }
}
