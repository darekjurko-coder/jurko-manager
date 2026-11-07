<?php
declare(strict_types=1);
require __DIR__ . '/../server/auth-policy.php';
$claims = ['iss'=>'https://accounts.google.com','aud'=>'test-client','exp'=>2000,'iat'=>900,'nonce'=>'test-nonce','email_verified'=>true,'sub'=>'synthetic-sub'];
$accounts = ['synthetic-sub'=>['role'=>'owner']];
if (jurkoAuthorizeGoogle($claims,$accounts,'test-nonce','test-client',1000)['role'] !== 'owner') { throw new RuntimeException('Brak dostępu właściciela.'); }
$cases = [['aud'=>'another-client'], ['iss'=>'https://attacker.invalid'], ['exp'=>999], ['iat'=>2000], ['nonce'=>'another-nonce'], ['email_verified'=>false], ['sub'=>'unapproved-sub']];
foreach ($cases as $change) {
    $failed = false;
    try { jurkoAuthorizeGoogle(array_replace($claims,$change),$accounts,'test-nonce','test-client',1000); }
    catch (RuntimeException $e) { $failed = true; }
    catch (DomainException $e) { $failed = true; }
    if (!$failed) { throw new RuntimeException('Przepuszczono niepoprawną tożsamość.'); }
}
$failed = false;
try { jurkoAuthorizeGoogle($claims,[],'test-nonce','test-client',1000); }
catch (DomainException $e) { $failed = true; }
if (!$failed) { throw new RuntimeException('Nie odwołano dostępu.'); }
echo "Polityka logowania OK\n";
