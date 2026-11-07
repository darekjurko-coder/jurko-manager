# Ręczne wdrażanie OVH z GitHub Actions

Workflow ovh-deploy.yml nie uruchamia się po zmianie kodu. Wymaga ręcznego
uruchomienia z main, środowiska ovh-production i skonfigurowanych sekretów.
Nie został jeszcze uruchomiony ani sprawdzony na rzeczywistym OVH.

## Chronione ustawienia środowiska ovh-production

- OVH_SSH_PASSWORD: istniejące hasło FTP–SSH użytkownika geuveax.
- OVH_KNOWN_HOSTS: pełna, niezależnie zweryfikowana linia known_hosts dla
  ssh.cluster131.hosting.ovh.net. Nie stosuj ślepego ssh-keyscan ani wyłączania
  kontroli hosta. Potwierdzenie klucza jest wymagane przed pierwszym połączeniem.
- JURKO_AUTH_JSON: JSON z polami client_id, client_secret, redirect_uri oraz
  accounts. Format odpowiada server/auth-config.example.php. Accounts mapuje
  zatwierdzone identyfikatory sub na role. Nie wpisuj sekretów do repozytorium.

Wymagane jest odrębne zatwierdzenie udostępnienia poświadczeń GitHub Actions
oraz pierwszego wdrożenia. Ogranicz środowisko do main i ustaw zatwierdzanie
wdrożeń, jeśli plan konta GitHub to umożliwia.

## Działanie i ograniczenia

Paczka zawiera bibliotekę Google, więc Composer nie jest potrzebny na OVH.
Przed zmianami powstaje prywatna kopia www i wcześniejszej konfiguracji logowania.
Workflow nie zmienia DNS, nie uruchamia migracji i nie zapisuje hasła MySQL.
Nie wdrażaj go, dopóki www nie jest potwierdzonym katalogiem JURKO MANAGER.
Po przejściu na tę metodę nie używaj równocześnie wdrażania Git z panelu OVH;
zmiany plików roboczych i katalog vendor mogą powodować konflikty kolejnych wdrożeń.

Niepowodzenie po rozpoczęciu rozpakowywania może pozostawić częściowo wdrożone pliki.
Nie jest to wdrożenie atomowe. W takim wypadku sprawdź logi i odtwórz prywatną kopię;
nie usuwaj blokady jurko-deploy.lock bez sprawdzenia stanu.
Przed produkcją zapisz composer.lock w repozytorium, przetestuj odtwarzanie,
logowanie HTTPS i odwołanie dostępu. Sam workflow nie oznacza gotowości aplikacji.
