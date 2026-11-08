<?php
declare(strict_types=1);
function jurkoValidateEntry(array $input): array
{
    $company = $input['company'] ?? ''; $type = $input['entry_type'] ?? '';
    $date = $input['entry_date'] ?? ''; $description = $input['description'] ?? '';
    $amount = $input['amount_net'] ?? ''; $code = $input['cost_code'] ?? '';
    foreach ([$company,$type,$date,$description,$amount,$code] as $value) {
        if (!is_string($value)) { throw new InvalidArgumentException('Niepoprawne dane formularza.'); }
    }
    $description = trim($description); $amount = str_replace(',', '.', trim($amount)); $code = trim($code);
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!in_array($company, ['zoo','spk'], true) || !in_array($type, ['cost','revenue'], true)
        || !$d || $d->format('Y-m-d') !== $date || $date < '2000-01-01' || $date > '2100-12-31'
        || $description === '' || mb_strlen($description) > 200
        || !preg_match('/^(?:0|[1-9][0-9]{0,12})(?:\.[0-9]{1,2})?$/D', $amount)
        || !preg_match('/[1-9]/', $amount)
        || ($code !== '' && !preg_match('/^[A-Za-z0-9._-]{1,8}$/D', $code))) {
        throw new InvalidArgumentException('Sprawdź datę, opis i kwotę większą od zera (maksymalnie dwa miejsca po przecinku). Kod kosztu: do 8 liter lub cyfr.');
    }
    $parts = explode('.', $amount);
    $amount = $parts[0] . '.' . str_pad($parts[1] ?? '', 2, '0');
    return [$company,$type,$date,$description,$code === '' ? null : $code,$amount];
}
function jurkoFinanceAccount(PDO $pdo, array $user, array $config): int
{
    if ($user['role'] !== 'owner' || !is_string($config['owner_email'] ?? null)
        || ($config['accounts'][$user['sub']]['email'] ?? null) !== $config['owner_email']) {
        throw new DomainException('Brak dostępu do finansów.');
    }
    $q = $pdo->prepare('SELECT id, role, active FROM jm_accounts WHERE email = ?');
    $q->execute([$config['owner_email']]); $account = $q->fetch();
    if (!$account) {
        $q = $pdo->prepare("INSERT INTO jm_accounts (email,role,active) VALUES (?, 'owner', TRUE)");
        try { $q->execute([$config['owner_email']]); }
        catch (PDOException $e) { if (($e->errorInfo[1] ?? 0) !== 1062) { throw $e; } }
        return jurkoFinanceAccount($pdo, $user, $config);
    }
    if (!$account['active'] || $account['role'] !== 'owner') { throw new DomainException('Dostęp do finansów jest wyłączony.'); }
    return (int)$account['id'];
}
function jurkoSaveEntry(PDO $pdo, int $actor, array $entry): void
{
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('INSERT INTO jm_financial_entries (company,entry_type,entry_date,description,cost_code,amount_net,created_by) VALUES (?,?,?,?,?,?,?)');
        $q->execute([...$entry, $actor]); $id = $pdo->lastInsertId();
        $q = $pdo->prepare("INSERT INTO jm_audit_log (actor_id,action,entity_type,entity_id) VALUES (?, 'create', 'financial_entry', ?)");
        $q->execute([$actor,$id]); $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $e; }
}
