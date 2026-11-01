# JURKO MANAGER

Demonstracyjny panel kosztów i finansów JURKO.

Aplikacja: https://koszty.jurkoseo.pl/

## Funkcje
- Przychody i koszty netto dla JURKO sp. z o.o. oraz JURKO sp. z o.o. sp.k.
- Filtrowanie według spółki, rejestr zapisów i eksport CSV.
- Zapis lokalny w przeglądarce (localStorage).

## Uruchomienie podglądu

```sh
python3 -m http.server 8000
```

Otwórz http://localhost:8000. Aplikacja nie wymaga instalacji zależności ani kompilacji.

## Publikacja
GitHub Pages publikuje gałąź `main`, katalog główny. Plik `CNAME` wskazuje `koszty.jurkoseo.pl`; zachowaj go przy zmianach. Aktualizacje na `main` uruchamiają publikację.

## Ograniczenia wersji demo
Brak logowania, serwera, wspólnej bazy danych oraz integracji bankowych i WAPRO. Dane są przechowywane tylko w danej przeglądarce. Nie wprowadzaj poufnych danych finansowych. Saldo ewidencji nie jest wynikiem księgowym ani saldem bankowym.

## Praca z Codexem
Instrukcje projektu znajdują się w `AGENTS.md`. Wybieraj repozytorium `darekjurko-coder/jurko-manager`. Zmiany w tej aplikacji nie powinny dotyczyć pozostałych stron JURKO.
