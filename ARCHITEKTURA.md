# Organizacja JURKO MANAGER

## Stan faktyczny

Frontend (`index.html`, `pracownik.html`, `staff.js`) nadal jest DEMO z localStorage.
Nie ma API ani gotowego logowania. Połączenie GitHub w czacie umożliwia odczyt
oraz zapis kodu; nie daje dostępu do MySQL ani automatycznie do SSH OVH.
GitHub Pages nie wykonuje PHP. Docelowy backend musi działać na OVH.

## Moduły bazy

| Katalog migracji | Odpowiedzialność |
|---|---|
| core | Metadane schematu |
| employees | Pracownicy, wyniki, plany i premie |
| auth | Konta i role |
| finance | Przychody i koszty obu spółek |
| tasks | Zadania, terminy i przydziały |
| audit | Historia operacji |

`server/migrations/manifest.json` ustala kolejność zależności. Jeden plik zawiera
jedno polecenie SQL. `server/schema.sql` to wygenerowany eksport do phpMyAdmin.
Zmiana wykonanej migracji zatrzymuje wdrożenie; dodaj kolejny plik zamiast poprawiać
historię. Nie usuwaj wpisów historii. Po błędzie DDL sprawdź stan bazy przed ponowieniem;
MySQL nie wycofuje zmian struktury jak zwykłej transakcji danych.

## Rozbudowa

Nowe funkcje powinny mieć osobny katalog modułu, logikę serwerową i uprawnienia.
Przyszłe magazyny, nieruchomości i logistyka nie mają jeszcze implementacji.
Przed ich dodaniem ustal model danych oraz powiązania ze spółką i użytkownikiem.
Nie twórz pustych atrap API. Docelowo wydziel CSS i logikę ekranów z HTML osobno,
zachowując istniejące klucze localStorage do czasu jawnego importu danych.

## Kontrole przed produkcją

1. Zielony workflow bazy MySQL 8.4.
2. Kopia bazy i plików przed wdrożeniem; sprawdzone odtwarzanie.
3. W OVH porównanie repozytorium, gałęzi i wdrożonego commitu.
4. Prywatna konfiguracja z aktualnym hasłem, test połączenia i migracji.
5. API, logowanie i uprawnienia testowane między użytkownikami i spółkami.
6. Test HTTPS na OVH; dopiero potem przełączenie domeny z GitHub Pages.

DNS, poczta i hosting nie zostały zmienione w ramach tego porządkowania.
