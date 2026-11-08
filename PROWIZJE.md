# Obrót i prowizje pracownika

W kartotece handlowca wybierz „Obrót i prowizja”, następnie miesiąc. Formularz przyjmuje XLS/XLSX z układem „Raport do prowizji handlowca”. Najpierw pokazuje podgląd i nazwę osoby z pliku; zapis następuje po zatwierdzeniu. Nie pobiera samodzielnie nowych plików z Google Drive. Dostęp nadal wymaga konta właściciela.

Kolumny źródła: D — wartość dokumentu, E — odliczenia zapisane w raporcie, F — obrót po tych odliczeniach, G — zapisany wynik. Zysk G może już zawierać ręczne poprawki. Nie odejmujemy ponownie E ani kwot z dopisków. Oryginalne uwagi i wiersze pozostają w prywatnej bazie do weryfikacji, poza repozytorium.

Każdy dokument pozwala poprawić zysk przed nowymi kosztami, ustawić udział pracownika w transakcji wspólnej i przenieść miesiąc rozliczenia. Koszty przypisane do dokumentu podążają za nim. Zamówienia liczymy osobno, bez ponownego doliczania ich do obrotu z faktur.

Nowy koszt = ilość × cena jednostkowa netto, opcjonalnie × procent. Koszty nieprzypisane do dokumentu pomniejszają wspólny wynik miesiąca. Kosztów odjętych już w G lub poprawce nie dopisujemy drugi raz. Archiwizacja zachowuje koszt w bazie.

Podstawa prowizji = suma (zysk dokumentu − jego dodatkowe koszty) × udział dokumentu, pomniejszona o wspólne koszty miesiąca. Prowizja = ta podstawa × uzgodniony procent. Zasada 50/50 wymaga wpisania 50 w wybranym miesiącu; domyślna zasada jest kopiowana do nowych miesięcy. Nie zmienia historycznych zasad. Kwoty liczymy w groszach z zaokrągleniem do grosza. Ujemny wynik pozostaje saldem do rozliczenia, bez automatycznego potrącania pensji.

Wszystkie importowane dokumenty zaczynają jako niesprawdzone. Udział jest roboczy, dopóki nie sprawdzono dokumentów oraz kompletności i braku podwójnych kosztów. Każda zmiana unieważnia zapisane podsumowanie. Podstawa brutto z kartoteki jest pokazana osobno, bez mieszania jej z netto z raportu.

Workflow „Miesięczne prowizje handlowców” uruchamia przeliczenie pierwszego dnia miesiąca o 06:15 UTC, czyli rano w Polsce. Przelicza dostępne raporty do poprzedniego miesiąca włącznie. Brak raportu, zasad lub sprawdzonych kosztów nie powoduje zatwierdzenia wypłaty. GitHub może opóźnić zadanie. Przycisk pozwala przeliczyć po późniejszym uzupełnieniu raportu. Nie wykonujemy przelewów ani listy płac.

Warstwa danych: `server/migrations/sales/014_commissions.sql`; obliczenia: `server/sales.php`; import: `server/sales-import.php`; chronione API: `sales-api.php`; widok: `sales.js`. Testy używają wyłącznie syntetycznych XLS/XLSX i osobnej bazy CI. Ponowienie importu jest bezpieczne, zmienione kwoty istniejącego dokumentu wymagają wyjaśnienia zamiast nadpisania. Zapisy mają klucze ponowień i kontrolę wersji.
