<?php
// Kopia: /home/geuveax/jurko-private/auth.php. Nigdy w katalogu www.
return [
    'client_id' => '',
    'client_secret' => '',
    'redirect_uri' => 'https://koszty.jurkoseo.pl/auth/index.php?action=callback',
    // Kluczem jest stały identyfikator Google „sub”, nie domena ani samo imię.
    // Dostęp tylko po ręcznym zatwierdzeniu konta przez właściciela.
    'accounts' => [
        // 'IDENTYFIKATOR_GOOGLE_SUB' => ['role' => 'owner', 'email' => ''],
    ],
];
