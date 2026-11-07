<?php
declare(strict_types=1);
try {
    $config = json_decode(getenv('AUTH_JSON') ?: '', true, 512, JSON_THROW_ON_ERROR);
    foreach (['client_id','client_secret','redirect_uri'] as $key) {
        if (!is_string($config[$key] ?? null) || $config[$key] === '') { throw new RuntimeException(); }
    }
    if ($config['redirect_uri'] !== 'https://koszty.jurkoseo.pl/auth/index.php?action=callback'
        || !is_array($config['accounts'] ?? null) || !$config['accounts']) { throw new RuntimeException(); }
    foreach ($config['accounts'] as $sub => $account) {
        if (!preg_match('/^[0-9]{1,255}$/D', (string)$sub)
            || !in_array($account['role'] ?? '', ['owner','manager','employee'], true)) { throw new RuntimeException(); }
    }
    // var_export koduje dane jako literały; żadnych interpolowanych poleceń lub sekretów w logach.
    umask(0077);
    if (file_put_contents('/tmp/jurko-auth.php', '<?php return ' . var_export($config, true) . ';') === false) { throw new RuntimeException(); }
} catch (Throwable $e) {
    fwrite(STDERR, "Niekompletna konfiguracja logowania w chronionych ustawieniach GitHuba.\n");
    exit(1);
}
