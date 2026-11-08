<?php
declare(strict_types=1);
require __DIR__.'/../server/auth-policy.php';
require __DIR__.'/../server/owner.php';
require __DIR__.'/../server/finance.php';
$c = ['client_id'=>'synthetic','owner_email'=>'synthetic.owner@gmail.com'];
$claims = ['iss'=>'https://accounts.google.com','aud'=>'synthetic','exp'=>2000,'iat'=>900,'nonce'=>'n','email_verified'=>true,'sub'=>'123','email'=>'synthetic.owner@gmail.com'];
if (jurkoOwnerCandidate($claims,$c,'n',1000)['role'] !== 'owner') { throw new RuntimeException(); }
foreach ([['email'=>'other@gmail.com'],['email_verified'=>false],['aud'=>'other'],['sub'=>''],['nonce'=>'wrong']] as $change) {
    try { jurkoOwnerCandidate(array_replace($claims,$change),$c,'n',1000); throw new LogicException('Naruszona polityka właściciela'); }
    catch (RuntimeException|DomainException $e) {}
}
$entry = ['company'=>'zoo','entry_type'=>'cost','entry_date'=>'2026-11-08','description'=>'Syntetyczny test','amount_net'=>'1234,56','cost_code'=>'100'];
if (jurkoValidateEntry($entry)[5] !== '1234.56') { throw new RuntimeException('Niepoprawna kwota'); }
foreach ([['amount_net'=>'0'],['amount_net'=>'-1'],['amount_net'=>'1.001'],['amount_net'=>'1e5'],['company'=>'other'],['description'=>''],['entry_date'=>'2026-02-30'],['cost_code'=>'123456789'],['amount_net'=>[]]] as $change) {
    try { jurkoValidateEntry(array_replace($entry,$change)); throw new LogicException('Przyjęto niepoprawny wpis'); }
    catch (InvalidArgumentException $e) {}
}
echo "Walidacja finansów i polityka właściciela OK\n";
