# Przygotowanie OVH — etap techniczny

Ten katalog jest przygotowaniem do migracji. Obecna aplikacja nadal działa jako DEMO
z localStorage. Nie ma jeszcze produkcyjnego API ani systemu kont. Nie wpisuj pensji
ani rzeczywistych dokumentów finansowych do DEMO.

## Zabezpieczenia i stan

- Schemat nie zawiera haseł, kont, nazwisk ani wyników. Nie usuwa tabel ani danych.
- Kwoty używają DECIMAL, a brak wyniku jest NULL (nie zero).
- Konta są domyślnie nieaktywne; schemat nie uruchamia publicznej rejestracji.
- Hasło bazy nie jest dostępne w GitHubie ani w przeglądarce.
- `server/.htaccess` blokuje dostęp HTTP. Skrypty PHP dodatkowo wymagają CLI.
- Przed udostępnieniem danych potrzebne są: uwierzytelnianie, autoryzacja po stronie
  serwera, CSRF, limit prób logowania, bezpieczne sesje, MFA administratora i kopie
  poza kontem OVH. Sam ten schemat ich nie implementuje.

## Prywatna konfiguracja

Na hostingu utwórz katalog `jurko-private` obok `www`, nie wewnątrz niego.
Skopiuj tam `config.example.php` jako `config.php` i wypełnij hasło bezpośrednio na
serwerze. Uprawnienia: katalog 700, plik 600. Wersji z hasłem nie commituj.

Docelowo: `/home/geuveax/jurko-private/config.php`.
Nie wysyłaj tego pliku e-mailem ani przez rozmowę z czatem.

## Migracja

Po uzyskaniu dostępu SSH i skonfigurowaniu prywatnego pliku:

```sh
php ~/www/server/migrate.php
```

Nie udostępniaj internetowego instalatora przyjmującego hasło bazy. Skrypt czyta uporządkowany manifest modułów. Rejestruje sumy SHA-256 wykonanych
plików w jm_migration_files, odrzuca zmienioną historię i blokuje równoległe migracje.
Istniejąca tabela jm_migrations z wersji v1 pozostaje zgodna.
CREATE TABLE IF NOT EXISTS nie sprawdza struktury zastanych tabel; przed migracją
istniejącej bazy porównaj SHOW CREATE TABLE z oczekiwanym schematem.
Nowe zmiany dodawaj jako kolejne pliki; nie edytuj wykonanych migracji.
MySQL wykonuje DDL poza transakcją; niepowodzenie może pozostawić część tabel.
Nie twórz kont pracowników, dopóki nie jest gotowy i przetestowany system kont.

## Wdrożenie

Repozytorium jest powiązane z katalogiem `www` w OVH. Ręczne `Wdróż Git` pobiera
zmiany. Automatyczny webhook nie został jeszcze skonfigurowany.
Nie zmieniaj DNS `koszty.jurkoseo.pl` przed testem HTTPS i połączenia z bazą.
Pozostałe domeny i poczta pozostają niezależne od tego wdrożenia.

## Import przez phpMyAdmin

`schema.sql` jest generowany z plików modułów przez `python3 scripts/build-schema.py`.
Można go importować do pustej bazy, bez SSH. Import tworzy wyłącznie tabele,
nie łączy aplikacji z bazą i nie zapisuje historii jm_migration_files.
Późniejsze uruchomienie migrate.php na takim samym schemacie uzupełnia historię.
Po zmianie hasła MySQL zaktualizuj również prywatny config.php na OVH.

## Walidacja

Workflow `.github/workflows/database.yml` sprawdza składnię PHP, spójność eksportu,
migrację na MySQL 8.4, ponowne uruchomienie, klucze obce, ograniczenia wartości,
nieaktywne konta oraz odrzucenie zmienionej historii. Dane testowe są syntetyczne.
Testy nie potwierdzają połączenia z produkcyjnym OVH.
