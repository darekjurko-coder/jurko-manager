<?php
declare(strict_types=1);
function jurkoAuthorizeGoogle(array $claims, array $accounts, string $nonce, string $clientId, int $now): array
{
    if (!in_array($claims['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true)
        || ($claims['aud'] ?? null) !== $clientId
        || !is_int($claims['exp'] ?? null) || $claims['exp'] <= $now
        || !is_int($claims['iat'] ?? null) || $claims['iat'] > $now + 60
        || !is_string($claims['nonce'] ?? null) || !hash_equals($nonce, $claims['nonce'])
        || ($claims['email_verified'] ?? false) !== true
        || !is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
        throw new RuntimeException('Niepoprawna tożsamość.');
    }
    $account = $accounts[$claims['sub']] ?? null;
    if (!is_array($account) || !in_array($account['role'] ?? '', ['owner', 'manager', 'employee'], true)) {
        throw new DomainException('Konto nie zostało zatwierdzone.');
    }
    return ['sub' => $claims['sub'], 'role' => $account['role']];
}
