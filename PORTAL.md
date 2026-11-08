# Portal finansowy na OVH

Pierwszy moduł pod /app/ zapisuje koszty i przychody obu spółek w MySQL.
Wersja index.html pozostaje demonstracją; nie przenosi danych automatycznie.

Logowanie: oficjalna biblioteka Google sprawdza podpis ID tokenu, a polityka
sprawdza issuer, audience, czas, nonce i zweryfikowany adres. Pierwsze powiązanie
jest dostępne wyłącznie dla dokładnego adresu Gmail z GOOGLE_OWNER_EMAIL.
Stały identyfikator sub zostaje zapisany z blokadą pliku w prywatnym owner.json.
Zmiana sub po powiązaniu jest odrzucana. Usunięcie owner_email odwołuje dostęp.
Wyłączone konto jm_accounts nie jest automatycznie reaktywowane.

Formularze wymagają sesji, CSRF i jednorazowego klucza zapisu; zapis finansowy
i audyt stanowią jedną transakcję. Kwoty są walidowane jako tekst dziesiętny.
Brak edycji/usuwania i importu na tym etapie. Nie jest to system księgowy.

Ręczny workflow ovh-deploy wymaga sekretów GOOGLE_CLIENT_ID,
GOOGLE_CLIENT_SECRET, OVH_SSH_PASSWORD i zmiennej GOOGLE_OWNER_EMAIL w
ovh-production. Hasło bazy pozostaje poza www. Serwer SSH jest przypięty
do wcześniej potwierdzonego klucza ED25519. Przed podmianą powstaje kopia
www i konfiguracji w jurko-private/backups, a migracje wykonują się z blokadą.
W razie błędu blokada jurko-deploy.lock pozostaje do sprawdzenia stanu.
Nie uruchamiaj równocześnie wdrażania Git z panelu OVH.

Po wdrożeniu wymagane są: powiązanie koszty.jurkoseo.pl z www na OVH,
certyfikat HTTPS, skierowanie DNS, przejście Google i test formularza na
syntetycznych danych. Sam udany workflow nie oznacza gotowego portalu.
Odtworzenie: rozpakuj prywatną kopię www i przywróć auth z tej samej daty;
zachowaj aktualny config.php i owner.json. Kopia www nie zastępuje kopii MySQL.
