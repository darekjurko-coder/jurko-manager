# JURKO MANAGER

Panel właściciela: https://koszty.jurkoseo.pl/index.html

## Działająca aplikacja

OVH uruchamia PHP 8.4 i MySQL. Dostęp wymaga zatwierdzonego konta Google właściciela. Finanse obu spółek, zadania oraz kartoteki, plany i ustawienia premii pracowników zapisują się we wspólnej bazie. Panel nie korzysta z localStorage. Dawny adres `/app/` kieruje do tego samego panelu.

WAPRO, banki, KSeF, magazyny i pozostałe oznaczone moduły czekają na podłączenie. Symulacja premii nie nalicza listy płac. Saldo ewidencji nie jest wynikiem księgowym ani saldem bankowym.

## Kod i wdrożenie

- `portal.php`: chronione wejście pod `/index.html` przez regułę Apache.
- `server/owner-panel.html`, `owner-panel.js`, `staff.js`: wygląd i obsługa panelu.
- `portal-api.php`, `server/portal-data.php`: kontrola sesji, CSRF, walidacja i zapis MySQL.
- `server/migrations/`: uporządkowane migracje z kontrolą integralności.
- `auth/`: logowanie Google. Konfiguracja i klucze pozostają poza katalogiem strony i repozytorium.

Testy wykonuje workflow „Kontrola bazy MySQL”. Wdrożenie na OVH uruchamia się osobno przez „Wdrożenie portalu OVH” z gałęzi `main`. Przed migracją powstaje prywatna kopia bazy i plików. Samo zatwierdzenie kodu na GitHubie nie wdraża PHP na OVH.

GitHub Pages nie obsługuje PHP ani produkcyjnego logowania. `CNAME` zachowujemy; produkcyjna domena wskazuje OVH. Lokalny prosty serwer statyczny nie uruchomi chronionego panelu.

Szczegóły: `JEDEN_PANEL.md`, `server/WDROZENIE.md`, `AGENTS.md`. Nie zapisuj haseł ani rzeczywistych danych firmowych w repozytorium.
