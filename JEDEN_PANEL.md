# Jeden panel JURKO MANAGER

Adres produkcyjny: /index.html. Apache kieruje go wewnętrznie do portal.php,
który sprawdza sesję Google oraz aktywne konto właściciela przed pobraniem danych.
Bez sesji następuje przekierowanie do /auth/. /app/ przekierowuje do tego samego
panelu; zachowuje filtry spółki i miesiąca. Stary pracownik.html prowadzi do
kartotek właściciela. Indywidualne konta pracowników nie są jeszcze uruchomione.

Finanse, zadania i plany pracowników nie korzystają z localStorage. Dotychczasowe
dane lokalne nie są usuwane ani automatycznie importowane. Zapisy z poprzedniego
modułu /app/ są widoczne w nowym panelu, bo korzystają z tej samej tabeli.

Wszystkie operacje API wymagają sesji właściciela. Zapis wymaga dodatkowo tokenu
CSRF i unikalnego klucza żądania. Powtórzenie zapisu nie tworzy duplikatu.
Archiwizacja finansów i zadań ustawia deleted_at i zapisuje audyt.
Konflikt wersji zadania lub planów daje 409 zamiast nadpisania nowszych danych.

Kartoteki trafiają do jm_employees, miesiące do jm_monthly_results, a premie do
jm_bonus_settings. Identyfikator public_id wiąże kartotekę z interfejsem.
jm_module_revisions kontroluje wersję planów; jm_write_requests chroni przed
duplikatami. Nowe migracje nie zmieniają wcześniejszej historii migracji.

Przed migracją wdrożenie zapisuje prywatną kopię SQL i kopię www. W razie błędu
kopii migracja nie rusza. Prywatne pliki pozostają poza www z prawami 600.
Odtworzenie SQL wykonuje administrator po sprawdzeniu kopii, w osobnej bazie
lub kontrolowanym oknie serwisowym. Zwykłe cofnięcie plików nie cofa migracji.

Sprzedaż z WAPRO, magazyny, nieruchomości, logistyka, AI i integracje są nadal
oznaczone jako planowane. Kalkulator premii nie jest listą płac.

Testy: integralność MySQL, idempotencja, archiwizacja bez utraty rekordu,
konflikty edycji, anonimowe wejście, CSRF i dane rozruchowe strony.
Po wdrożeniu sprawdź prawdziwe logowanie, zapis, odczyt na odświeżeniu i wylogowanie
w przeglądarce, używając wyłącznie rekordów syntetycznych.
