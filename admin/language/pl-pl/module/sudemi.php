<?php
// Heading
$_['heading_title'] = 'sudemi Connector';

// Text
$_['text_extension'] = 'Rozszerzenia';
$_['text_success'] = 'Ustawienia zostały zapisane.';
$_['text_edit'] = 'Połącz ten sklep z sudemi';
$_['text_intro'] = 'Połączenie tego sklepu pozwala asystentowi AI znajdować Twoje produkty i kierować do Ciebie kupujących. Połączenie potwierdzasz w sudemi, zalogowany na własne konto — to rozszerzenie nigdy nie prosi o Twoje hasło do sudemi.';
$_['text_connected'] = 'Połączono z sudemi.';
$_['text_not_confirmed'] = 'Jeszcze niepotwierdzone. Otwórz link, potwierdź sklep w sudemi, a następnie sprawdź ponownie.';
$_['text_disconnected'] = 'Rozłączono.';
$_['text_synced'] = 'Katalog został wysłany.';
$_['text_sync_failed'] = 'Nie udało się wysłać katalogu. Żadna oferta nie została wycofana.';
$_['text_locked'] = 'Twoja nazwa prawna, kraj, status przedsiębiorcy i numer VAT decydują o tym, co przysługuje kupującemu, dlatego są potwierdzane jednorazowo w sudemi i tam się je zmienia. To rozszerzenie nie może ich edytować.';
$_['text_shop_url_fixed'] = 'Stały. sudemi sprawdziło, że ten sklep odpowiadał spod tego adresu, a oferta informuje kupującego, skąd pochodzi — dlatego zmiana adresu oznacza ponowne połączenie z nowego adresu.';
$_['text_key_held'] = 'Prywatna część klucza podpisu nigdy nie opuszcza tego serwera i nigdy nie jest wysyłana do sudemi. Nie można jej też wyświetlić w tym miejscu.';

// Entry
$_['entry_shop_url'] = 'Adres sklepu';
$_['entry_install'] = 'Instalacja';
$_['entry_key'] = 'Klucz podpisu';

// Button
$_['button_connect'] = 'Połącz ten sklep';
$_['button_confirm'] = 'Potwierdź ten sklep w sudemi';
$_['button_check'] = 'Sprawdź ponownie';
$_['button_sync'] = 'Wyślij katalog teraz';
$_['button_disconnect'] = 'Rozłącz';

// Error
$_['error_permission'] = 'Nie masz uprawnień, aby to zmienić.';
$_['error_token'] = 'Nie udało się zweryfikować tego żądania. Spróbuj ponownie z tej strony.';
$_['error_sodium'] = 'Na tym serwerze brakuje rozszerzenia sodium. Połączenie potrzebuje go do podpisywania żądań i bez niego nie zadziała. Poproś dostawcę hostingu o jego włączenie.';

// The listing-status panel. These describe a failure the shop owner cannot see from their own
// admin — products stop being shown when the heartbeat stops arriving, and from this side
// everything looks fine — so they say what happened and what to do about it.
$_['text_listing_status'] = 'Status ofert';
$_['text_never_reported'] = 'Ten sklep nie zgłosił się jeszcze do sudemi. Dodaj poniższy wpis cron; dopóki nie zacznie działać, zgłoszenia są wysyłane tylko wtedy, gdy ta strona jest otwarta.';
$_['text_unreachable'] = 'Ten sklep nie mógł połączyć się z sudemi. Jego produkty pozostają widoczne jeszcze przez krótki czas, a potem zostają wstrzymane, ponieważ sudemi nie podaje ceny, której nie może potwierdzić.';
$_['text_listed'] = 'Produkty są wyświetlane w sudemi.';
$_['entry_pending'] = 'Oczekujące zmiany cen';
$_['text_pending'] = 'Zmiany cen lub stanów magazynowych, których nie udało się jeszcze wysłać. Zostaną ponowione przy następnym zgłoszeniu i nie przepadną.';
$_['entry_trusted_until'] = 'Ceny potwierdzone do';
$_['text_trusted_until'] = 'Po tym czasie sudemi przestaje wyświetlać ten sklep, dopóki ponownie się nie zgłosi. Nic nie zostaje usunięte.';
$_['entry_cron'] = 'Wpis cron';
$_['text_cron'] = 'Dodaj to do crontaba konta, na którym działa ten sklep. Co pięć minut informuje to sudemi, że sklep nadal działa i że jego ceny są aktualne, a także wysyła zmiany cen, które nie dotarły za pierwszym razem. Link zawiera sekret; traktuj go jak hasło.';

$_['text_delivery_title'] = 'Dostawa do kupujących z sudemi';
$_['text_delivery_preview'] = 'Ceny wysyłki przesłane do sudemi (z Twoich metod wysyłki; jedna sztuka, Twój pierwszy produkt):';
$_['text_delivery_skipped'] = 'Kraje bez stałej ceny (inne rozszerzenia, darmowa wysyłka od minimalnej kwoty, strefy geograficzne obejmujące tylko część kraju) są pomijane, a nie szacowane.';
$_['text_delivery_none'] = 'Nie udało się odczytać żadnej ceny wysyłki z Twoich metod wysyłki, więc kupujący z sudemi nie mogą składać zamówień. Włącz Stałą stawkę, Za sztukę, Według wagi lub bezwarunkową Darmową wysyłkę dla stref geograficznych, do których wysyłasz.';
$_['entry_handling_days'] = 'Liczba dni do nadania zamówienia (maksymalnie)';
$_['entry_transit_days'] = 'Liczba dni transportu przez przewoźnika (maksymalnie)';
$_['button_delivery'] = 'Zapisz czasy dostawy';
$_['text_delivery_help'] = 'Kupujący widzą sumę jako najpóźniejszy przewidywany termin dostawy. Ustaw swoje rzeczywiste czasy; wartości domyślne są celowo ostrożne. Katalog zostanie ponownie wysłany przy następnej synchronizacji.';
$_['text_delivery_saved'] = 'Zapisano czasy dostawy.';
