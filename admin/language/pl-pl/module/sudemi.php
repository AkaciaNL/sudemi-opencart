<?php
// Heading
$_['heading_title'] = 'sudemi Connector';

// Text
$_['text_extension'] = 'Rozszerzenia';
$_['text_success'] = 'Ustawienia zostały zapisane.';
$_['text_edit'] = 'Połącz ten sklep z sudemi';
$_['text_intro'] = 'Połączenie tego sklepu pozwala asystentowi AI znajdować Twoje produkty i kierować do Ciebie kupujących. Połączenie potwierdzasz w sudemi, zalogowany na własne konto - to rozszerzenie nigdy nie prosi o Twoje hasło do sudemi.';
$_['text_connected'] = 'Połączono z sudemi.';
$_['text_not_confirmed'] = 'Jeszcze niepotwierdzone. Otwórz link, potwierdź sklep w sudemi, a następnie sprawdź ponownie.';
$_['text_disconnected'] = 'Rozłączono.';
$_['text_synced'] = 'Katalog został wysłany.';
$_['text_sync_failed'] = 'Nie udało się wysłać katalogu. Żadna oferta nie została wycofana.';
$_['text_locked'] = 'Twoja nazwa prawna, kraj, status przedsiębiorcy i numer VAT decydują o tym, co przysługuje kupującemu, dlatego są potwierdzane jednorazowo w sudemi i tam się je zmienia. To rozszerzenie nie może ich edytować.';
$_['text_shop_url_fixed'] = 'Stały. sudemi sprawdziło, że ten sklep odpowiadał spod tego adresu, a oferta informuje kupującego, skąd pochodzi - dlatego zmiana adresu oznacza ponowne połączenie z nowego adresu.';
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
// admin - products stop being shown when the heartbeat stops arriving, and from this side
// everything looks fine - so they say what happened and what to do about it.
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

// Order history on this page
$_['text_history_title'] = 'Zamówienia przez sudemi';
$_['text_history_error'] = 'Nie udało się teraz połączyć z sudemi, aby wczytać historię zamówień. Z samymi zamówieniami wszystko jest w porządku; spróbuj ponownie za chwilę.';
$_['text_history_empty'] = 'Brak jeszcze zamówień przez sudemi.';
$_['column_order'] = 'Zamówienie';
$_['column_items'] = 'Co sprzedano';
$_['column_status'] = 'Status';
$_['column_net'] = 'Otrzymujesz';
$_['column_customer'] = 'Klient';
$_['text_status_awaiting_seller'] = 'Czeka na Ciebie - kupujący nie został obciążony';
$_['text_status_accepted'] = 'Przyjęte - oczekiwanie na pozostałe sklepy objęte płatnością kupującego; kupujący nie został jeszcze obciążony, nie wysyłaj';
$_['text_status_paid'] = 'Potwierdzone i opłacone';
$_['text_status_declined'] = 'Odrzucone - nic nie pobrano';
$_['text_status_shipped'] = 'Wysłane';
$_['text_status_delivered'] = 'Dostarczone';
$_['text_status_cancelled'] = 'Anulowane';
$_['text_status_refunded'] = 'Zwrócone';
$_['text_history_awaiting'] = 'Zamówienia czekające na potwierdzenie: %d. Tych kupujących jeszcze nie obciążono.';
$_['text_options_skipped'] = 'Produkty z wymaganą opcją (np. rozmiarem do wyboru): %d. Nie są oferowane w sudemi: zamówienie od asystenta AI kupującego nie zawiera opcji, więc nie wiedziałbyś, który artykuł wysłać. Zamiast tego oferuj je jako warianty OpenCart (każdy wariant to osobny produkt).';

// Product form: right of withdrawal (Directive 2011/83/EU, art. 16)
$_['entry_withdrawal'] = 'Prawo odstąpienia od umowy';
$_['text_withdrawal_none'] = 'Brak - obowiązuje zwykłe prawo odstąpienia od umowy';
$_['help_withdrawal'] = 'Tylko dla produktów, które zgodnie z prawem są objęte wyjątkiem. Kupujący widzą to przed zapłatą; nadużycia są niedozwolone.';
$_['text_withdrawal_hygiene'] = 'Zapieczętowany ze względu na ochronę zdrowia lub higienę (bez zwrotu po otwarciu)';
$_['text_withdrawal_custom'] = 'Wyprodukowany według specyfikacji kupującego lub spersonalizowany';
$_['text_withdrawal_perishable'] = 'Szybko psujący się lub o krótkim terminie przydatności';
$_['text_withdrawal_media'] = 'Zapieczętowane nagrania audio, wideo lub oprogramowanie (bez zwrotu po otwarciu)';
$_['text_withdrawal_press'] = 'Dziennik, periodyk lub czasopismo';
$_['text_withdrawal_mixed'] = 'Po dostarczeniu nierozłącznie połączony z innymi rzeczami';
$_['text_withdrawal_alcohol'] = 'Napoje alkoholowe o cenie zależnej od rynku, dostarczane po ponad 30 dniach';

// Support: tickets between this shop and sudemi support (sudemi sends the e-mails, not this extension)
$_['text_support_title'] = 'Pomoc';
$_['text_support_not_connected'] = 'Najpierw połącz ten sklep z sudemi; potem możesz kontaktować się stąd z pomocą techniczną sudemi.';
$_['text_support_intro'] = 'Pytania dotyczące zamówień, płatności, wypłat lub tego rozszerzenia kieruj do pomocy technicznej sudemi. Odpowiedzi pojawiają się tutaj i są też wysyłane e-mailem na adres Twojego konta sudemi.';
$_['text_support_tickets'] = 'Twoje zgłoszenia';
$_['text_support_empty'] = 'Nie masz jeszcze żadnych zgłoszeń.';
$_['text_support_new'] = 'Nowe zgłoszenie';
$_['text_support_email'] = 'sudemi powiadomi Cię e-mailem, gdy pomoc techniczna odpowie. To rozszerzenie samo nie wysyła e-maili.';
$_['text_support_opened'] = 'Zgłoszenie %s zostało utworzone. Pomoc techniczna sudemi odezwie się do Ciebie.';
$_['text_support_replied'] = 'Twoja odpowiedź została wysłana.';
$_['text_support_reply_reopens'] = 'Odpowiedź ponownie otworzy to zamknięte zgłoszenie.';
$_['text_support_from_support'] = 'Pomoc techniczna sudemi';
$_['text_support_from_shop'] = 'Ty (ten sklep)';
$_['text_support_status_open'] = 'Otwarte';
$_['text_support_status_waiting_for_customer'] = 'Czeka na Twoją odpowiedź';
$_['text_support_status_answered'] = 'Odpowiedziano';
$_['text_support_status_closed'] = 'Zamknięte';
$_['text_support_category_order'] = 'Zamówienie';
$_['text_support_category_payment'] = 'Płatność';
$_['text_support_category_refund_return'] = 'Zwrot pieniędzy lub towaru';
$_['text_support_category_account'] = 'Twoje konto sudemi';
$_['text_support_category_shop_plugin'] = 'Ten sklep lub rozszerzenie';
$_['text_support_category_payouts'] = 'Wypłaty';
$_['text_support_category_other'] = 'Inna sprawa';
$_['column_support_number'] = 'Zgłoszenie';
$_['column_support_subject'] = 'Temat';
$_['column_support_status'] = 'Status';
$_['column_support_updated'] = 'Ostatnia zmiana';
$_['entry_support_category'] = 'Kategoria';
$_['entry_support_subject'] = 'Temat';
$_['entry_support_message'] = 'Wiadomość';
$_['entry_support_order'] = 'ID zamówienia sudemi (opcjonalnie)';
$_['help_support_order'] = 'Na przykład 3f2a9c1e-…; zostaw puste, jeśli sprawa nie dotyczy jednego zamówienia';
$_['entry_support_reply'] = 'Twoja odpowiedź';
$_['button_support_open'] = 'Wyślij zgłoszenie';
$_['button_support_reply'] = 'Wyślij odpowiedź';
$_['button_support_back'] = 'Wróć do wszystkich zgłoszeń';
$_['error_support_invalid'] = 'Sprawdź formularz: temat musi mieć od 3 do 200 znaków, wiadomość najwyżej 10 000, a ID zamówienia musi należeć do zamówienia sudemi tego sklepu.';
$_['error_support_not_found'] = 'Nie znaleziono tego zgłoszenia dla tego sklepu.';
$_['error_support_too_many'] = 'Za dużo zgłoszeń naraz: najwyżej 20 otwartych zgłoszeń i najwyżej 10 nowych na godzinę. Odpowiedz w istniejącym zgłoszeniu lub spróbuj później.';
$_['error_support_unavailable'] = 'Pomoc techniczna sudemi jest w tej chwili nieosiągalna. Nic nie zostało wysłane; spróbuj ponownie za chwilę.';

// sudemi refused this site as not being the connected shop (409 site_mismatch): a staging copy,
// a restored backup, or the shop moved. %s is the address the shop is connected under.
$_['text_site_mismatch_at'] = 'sudemi odrzuciło żądania z tej witryny: ten sklep jest połączony z sudemi pod innym adresem (%s). Jeśli ta witryna jest kopią testową (staging) lub przywróconą kopią zapasową, rozłącz tutaj sudemi. Jeśli sklep przeniósł się pod ten adres, połącz go ponownie.';
$_['text_site_mismatch'] = 'sudemi odrzuciło żądania z tej witryny: ten sklep jest połączony z sudemi pod innym adresem. Jeśli ta witryna jest kopią testową (staging) lub przywróconą kopią zapasową, rozłącz tutaj sudemi. Jeśli sklep przeniósł się pod ten adres, połącz go ponownie.';

// Return requests from buyers (answered within 7 days: accept, or reject with a reason the buyer
// sees). The notes go into the order history; %s is the reason given.
$_['text_requests_title'] = 'Prośby o zwrot';
$_['text_requests_intro'] = 'Kupujący chcą zwrócić te dostarczone zamówienia. Odpowiedz na każdą prośbę przed podaną datą: zaakceptuj ją albo odrzuć, podając powód (kupujący go zobaczy). Prośba bez odpowiedzi trafia po 7 dniach do sudemi. Lista aktualizuje się przy każdym raporcie wysyłanym do sudemi.';
$_['text_requests_empty'] = 'Brak otwartych próśb o zwrot.';
$_['column_request_order'] = 'Zamówienie';
$_['column_request_reason'] = 'Powód kupującego';
$_['column_request_requested'] = 'Zgłoszono';
$_['column_request_respond_by'] = 'Odpowiedz do';
$_['column_request_answer'] = 'Twoja odpowiedź';
$_['text_request_no_local'] = 'Nie znaleziono w tym sklepie';
$_['text_request_no_reason'] = 'Nie podano powodu';
$_['text_request_overdue'] = 'Po terminie';
$_['entry_request_reject_reason'] = 'Powód odrzucenia (wymagany, widoczny dla kupującego)';
$_['button_request_accept'] = 'Zaakceptuj zwrot';
$_['button_request_reject'] = 'Odrzuć zwrot';
$_['text_request_accepted'] = 'Zwrot zaakceptowany. Kupujący odsyła teraz towar. Gdy dotrze, zwróć pieniądze za zamówienie, ustawiając jego status na Zwrócono: zwrot trafia do kupującego przez sudemi i zamyka prośbę.';
$_['text_request_rejected'] = 'Zwrot odrzucony. Kupujący widzi Twój powód.';
$_['text_request_already'] = 'Na tę prośbę już odpowiedziano. Lista została zaktualizowana.';
$_['error_request_not_found'] = 'To zamówienie nie ma już otwartej prośby o zwrot. Lista została zaktualizowana.';
$_['error_request_reason'] = 'Odrzucenie zwrotu wymaga podania powodu; kupujący go zobaczy.';
$_['error_request_unavailable'] = 'Nie można teraz połączyć się z sudemi. Nic nie zostało wysłane; spróbuj ponownie za chwilę.';
$_['text_request_note_accepted'] = 'sudemi: prośba o zwrot zaakceptowana. Zwróć pieniądze za zamówienie (status Zwrócono), gdy towar wróci.';
$_['text_request_note_rejected'] = 'sudemi: prośba o zwrot odrzucona. Powód pokazany kupującemu: %s';
$_['text_history_return_requested'] = 'Zgłoszono zwrot';
