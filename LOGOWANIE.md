# Logowanie Google — przygotowane, jeszcze nieaktywne

## Stan

`/auth/` obsługuje logowanie Google; `/app/` sprawdza sesję po stronie PHP.
To odrębna bramka, nie pełna ochrona dotychczasowych statycznych stron DEMO.
Nie dodawaj danych firmowych do index.html, pracownik.html ani localStorage.
Brak jeszcze produkcyjnego API danych oraz powiązania uprawnień z rekordami MySQL.

## Uruchomienie na OVH

1. Utwórz klienta OAuth typu aplikacja internetowa w Google Cloud dla właściciela
   JURKO. Skonfiguruj ekran zgody i konta testowe, jeżeli projekt pozostaje testowy.
   Zakresy: openid oraz email. Nie są potrzebne Gmail ani Dysk.
2. Zarejestruj dokładny adres powrotu:
   https://koszty.jurkoseo.pl/auth/index.php?action=callback
   Adres musi wskazywać na OVH obsługujące PHP; GitHub Pages nie wystarczy.
3. Na serwerze uruchom `composer install --no-dev --prefer-dist`.
   Zapisz rozwiązaną wersję composer.lock w repozytorium przed produkcją.
4. Poza katalogiem www zapisz `jurko-private/auth.php` według przykładu
   server/auth-config.example.php. Ustaw prawa katalogu 700 i pliku 600.
   Wpisz identyfikator i sekret klienta bezpośrednio na serwerze.
5. Zatwierdzaj konta według stałego identyfikatora Google sub, uzyskanego z
   poprawnie zweryfikowanej tożsamości. Nie kopiuj danych z nieweryfikowanych JWT.
   Pusta lista oznacza odmowę dostępu wszystkim, również właścicielowi.
6. Włącz weryfikację dwuetapową na koncie Google. Google decyduje o rodzaju
   wyzwania. Aplikacja nie gwarantuje potwierdzenia liczbą przy każdym wejściu.
7. Test na HTTPS: brak konfiguracji, niezatwierdzone konto, zatwierdzone konto,
   wylogowanie, odwołanie dostępu, wygasła sesja, anulowanie zgody Google.

## Zabezpieczenia

Biblioteka Google weryfikuje podpis tokenu. Polityka dodatkowo sprawdza issuer,
audience, nonce, czas oraz sub na liście kont. State jest jednorazowy i ważny 5 min.
Sesja ma ciasteczko Secure/HttpOnly/SameSite=Lax, limit bezczynności 30 min oraz
maksymalnie 8 godzin. Przy każdym wejściu do panelu ponownie sprawdzana jest lista
kont. Wylogowanie wymaga POST z CSRF. Tokeny Google nie są zapisywane w sesji.

Przed danymi produkcyjnymi wymagane są: limit prób także po IP na serwerze,
pełne testy HTTPS rzeczywistego przepływu, ochrona każdego endpointu danych,
uprawnienia między spółkami i użytkownikami oraz kopie zapasowe.
