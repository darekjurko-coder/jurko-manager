<?php
declare(strict_types=1);

function jurkoStaffAccount(PDO $db, array $session): ?array
{
    if (($session['role'] ?? '') !== 'employee' || !isset($session['staff_account'], $session['session_version'])
        || time() - ($_SESSION['last_seen'] ?? 0) > 1800 || time() - ($_SESSION['signed_at'] ?? 0) > 28800) {
        return null;
    }
    $q = $db->prepare("SELECT a.id,a.employee_id,e.display_name,e.department FROM jm_accounts a
        JOIN jm_employees e ON e.id=a.employee_id AND e.active=TRUE
        WHERE a.id=? AND a.session_version=? AND a.role='employee' AND a.active=TRUE AND a.staff_login IS NOT NULL");
    $q->execute([$session['staff_account'], $session['session_version']]);
    $account = $q->fetch() ?: null;
    if ($account) {
        $_SESSION['last_seen'] = time();
    }
    return $account;
}

function jurkoStaffSignIn(PDO $db, string $login, string $password): bool
{
    $buckets = [
        hash('sha256', 'ip:'.($_SERVER['REMOTE_ADDR'] ?? '')),
        hash('sha256', 'login:'.$login),
    ];
    $db->beginTransaction();
    try {
        $db->exec('DELETE FROM jm_staff_login_attempts WHERE expires_at<=UTC_TIMESTAMP()');
        $q = $db->prepare('INSERT INTO jm_staff_login_attempts VALUES (?,1,UTC_TIMESTAMP()+INTERVAL 15 MINUTE)
            ON DUPLICATE KEY UPDATE hits=LEAST(hits+1,21)');
        $hits = $db->prepare('SELECT hits FROM jm_staff_login_attempts WHERE bucket=?');
        $allowed = true;
        foreach ($buckets as $bucket) {
            $q->execute([$bucket]);
            $hits->execute([$bucket]);
            $allowed = (int)$hits->fetchColumn() <= 20 && $allowed;
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    if (!$allowed) {
        return false;
    }
    $q = $db->prepare("SELECT a.id,a.password_hash,a.session_version FROM jm_accounts a
        JOIN jm_employees e ON e.id=a.employee_id AND e.active=TRUE
        WHERE a.staff_login=? AND a.role='employee' AND a.active=TRUE");
    $q->execute([$login]);
    $account = $q->fetch();
    $hash = $account['password_hash'] ?? '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
    if (!password_verify($password, $hash) || empty($account['password_hash'])) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION = [
        'user'=>['role'=>'employee','staff_account'=>(int)$account['id'],'session_version'=>(int)$account['session_version']],
        'signed_at'=>time(),'last_seen'=>time(),'csrf'=>bin2hex(random_bytes(32)),
    ];
    return true;
}
