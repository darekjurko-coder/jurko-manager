<?php
declare(strict_types=1);
try {
    $c = ['client_id'=>trim(getenv('GOOGLE_CLIENT_ID') ?: ''),
        'client_secret'=>trim(getenv('GOOGLE_CLIENT_SECRET') ?: ''),
        'owner_email'=>trim(getenv('GOOGLE_OWNER_EMAIL') ?: ''),
        'redirect_uri'=>'https://koszty.jurkoseo.pl/auth/index.php?action=callback','accounts'=>[]];
    if (!preg_match('/^[0-9]+-[a-zA-Z0-9_-]+\.apps\.googleusercontent\.com$/D',$c['client_id'])
        || !str_starts_with($c['client_secret'],'GOCSPX-')
        || !preg_match('/^[a-z0-9._%+-]+@gmail\.com$/D',$c['owner_email'])) { throw new RuntimeException(); }
    umask(0077);
    if (file_put_contents('/tmp/jurko-auth.php','<?php return '.var_export($c,true).';') === false) { throw new RuntimeException(); }
} catch (Throwable $e) { fwrite(STDERR,"Brak poprawnej prywatnej konfiguracji Google.\n"); exit(1); }
