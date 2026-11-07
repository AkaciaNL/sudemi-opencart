<?php
// Heading
$_['heading_title'] = 'sudemi Connector';

// Text
$_['text_extension'] = 'Erweiterungen';
$_['text_success'] = 'Die Einstellungen wurden gespeichert.';
$_['text_edit'] = 'Diesen Shop mit sudemi verbinden';
$_['text_intro'] = 'Wenn Sie diesen Shop verbinden, kann ein KI-Assistent Ihre Produkte finden und Käufer an Sie weiterleiten. Sie bestätigen die Verbindung auf sudemi, angemeldet mit Ihrem eigenen Konto - diese Erweiterung fragt nie nach Ihrem sudemi-Passwort.';
$_['text_connected'] = 'Mit sudemi verbunden.';
$_['text_not_confirmed'] = 'Noch nicht bestätigt. Öffnen Sie den Link, bestätigen Sie den Shop auf sudemi und prüfen Sie dann erneut.';
$_['text_disconnected'] = 'Getrennt.';
$_['text_synced'] = 'Der Katalog wurde gesendet.';
$_['text_sync_failed'] = 'Der Katalog konnte nicht gesendet werden. Es wurde nichts aus der Listung genommen.';
$_['text_locked'] = 'Ihr rechtlicher Name, Ihr Land, Ihr Unternehmerstatus und Ihre USt-IdNr. bestimmen, welche Rechte ein Käufer hat. Deshalb werden sie einmalig auf sudemi bestätigt und dort geändert. Diese Erweiterung kann sie nicht bearbeiten.';
$_['text_shop_url_fixed'] = 'Unveränderlich. sudemi hat geprüft, dass dieser Shop unter dieser Adresse geantwortet hat, und ein Eintrag zeigt dem Käufer, woher er stammt - eine Änderung bedeutet daher, sich von der neuen Adresse aus erneut zu verbinden.';
$_['text_key_held'] = 'Der private Teil des Signaturschlüssels verlässt diesen Server nie und wird nie an sudemi gesendet. Er kann auch hier nicht angezeigt werden.';

// Entry
$_['entry_shop_url'] = 'Shop-Adresse';
$_['entry_install'] = 'Installation';
$_['entry_key'] = 'Signaturschlüssel';

// Button
$_['button_connect'] = 'Diesen Shop verbinden';
$_['button_confirm'] = 'Diesen Shop auf sudemi bestätigen';
$_['button_check'] = 'Erneut prüfen';
$_['button_sync'] = 'Katalog jetzt senden';
$_['button_disconnect'] = 'Trennen';

// Error
$_['error_permission'] = 'Sie sind nicht berechtigt, dies zu ändern.';
$_['error_token'] = 'Diese Anfrage konnte nicht verifiziert werden. Versuchen Sie es erneut von dieser Seite aus.';
$_['error_sodium'] = 'Auf diesem Server fehlt die Sodium-Erweiterung. Die Verbindung benötigt sie zum Signieren von Anfragen und funktioniert ohne sie nicht. Bitten Sie Ihren Hoster, sie zu aktivieren.';

// The listing-status panel. These describe a failure the shop owner cannot see from their own
// admin - products stop being shown when the heartbeat stops arriving, and from this side
// everything looks fine - so they say what happened and what to do about it.
$_['text_listing_status'] = 'Listungsstatus';
$_['text_never_reported'] = 'Dieser Shop hat sich noch nicht bei sudemi gemeldet. Fügen Sie den Cron-Eintrag unten hinzu; bis er läuft, werden Meldungen nur gesendet, solange diese Seite geöffnet ist.';
$_['text_unreachable'] = 'Dieser Shop konnte sudemi nicht erreichen. Seine Produkte bleiben noch kurz gelistet und werden dann pausiert, weil sudemi keinen Preis nennt, den es nicht bestätigen kann.';
$_['text_listed'] = 'Die Produkte werden auf sudemi angezeigt.';
$_['entry_pending'] = 'Ausstehende Preisänderungen';
$_['text_pending'] = 'Preis- oder Bestandsänderungen, die noch nicht gesendet werden konnten. Sie werden bei der nächsten Meldung erneut versucht und gehen nicht verloren.';
$_['entry_trusted_until'] = 'Preise bestätigt bis';
$_['text_trusted_until'] = 'Danach zeigt sudemi diesen Shop nicht mehr an, bis er sich wieder meldet. Es wird nichts gelöscht.';
$_['entry_cron'] = 'Cron-Eintrag';
$_['text_cron'] = 'Fügen Sie dies zur Crontab des Kontos hinzu, unter dem dieser Shop läuft. Damit teilt der Shop sudemi alle fünf Minuten mit, dass er noch da ist und seine Preise weiterhin gelten, und sendet jede Preisänderung, die beim ersten Mal nicht angekommen ist. Der Link enthält ein Geheimnis; behandeln Sie ihn wie ein Passwort.';

$_['text_delivery_title'] = 'Lieferung an sudemi-Käufer';
$_['text_delivery_preview'] = 'An sudemi gesendete Versandpreise (aus Ihren Versandarten; ein Artikel, Ihr erstes Produkt):';
$_['text_delivery_skipped'] = 'Länder ohne festen Preis (andere Erweiterungen, kostenloser Versand ab Mindestbestellwert, Geozonen, die nur einen Teil eines Landes abdecken) werden ausgelassen statt geschätzt.';
$_['text_delivery_none'] = 'Aus Ihren Versandarten konnte kein Versandpreis gelesen werden, daher können sudemi-Käufer nicht bestellen. Aktivieren Sie Pauschal (Flat Rate), Pro Artikel (Per Item), Gewichtsbasiert (Weight Based) oder bedingungslos kostenlosen Versand (Free Shipping) für die Geozonen, in die Sie liefern.';
$_['entry_handling_days'] = 'Tage bis zum Versand einer Bestellung (höchstens)';
$_['entry_transit_days'] = 'Laufzeit beim Versanddienstleister in Tagen (höchstens)';
$_['button_delivery'] = 'Lieferzeiten speichern';
$_['text_delivery_help'] = 'Käufer sehen die Summe als spätesten voraussichtlichen Liefertermin. Tragen Sie Ihre tatsächlichen Zeiten ein; die Standardwerte sind bewusst vorsichtig. Der Katalog wird mit der nächsten Synchronisierung erneut gesendet.';
$_['text_delivery_saved'] = 'Lieferzeiten gespeichert.';

// Order history on this page
$_['text_history_title'] = 'Bestellungen über sudemi';
$_['text_history_error'] = 'Der Bestellverlauf konnte gerade nicht von sudemi geladen werden. Mit den Bestellungen selbst ist alles in Ordnung; versuchen Sie es gleich noch einmal.';
$_['text_history_empty'] = 'Noch keine Bestellungen über sudemi.';
$_['column_order'] = 'Bestellung';
$_['column_items'] = 'Verkauft';
$_['column_status'] = 'Status';
$_['column_net'] = 'Sie erhalten';
$_['column_customer'] = 'Kunde';
$_['text_status_awaiting_seller'] = 'Wartet auf Sie - Käufer nicht belastet';
$_['text_status_accepted'] = 'Angenommen - wartet auf die anderen Shops in der Zahlung des Käufers; Käufer noch nicht belastet, nicht versenden';
$_['text_status_paid'] = 'Bestätigt und belastet';
$_['text_status_declined'] = 'Abgelehnt - nichts belastet';
$_['text_status_shipped'] = 'Versandt';
$_['text_status_delivered'] = 'Zugestellt';
$_['text_status_cancelled'] = 'Storniert';
$_['text_status_refunded'] = 'Erstattet';
$_['text_history_awaiting'] = 'Bestellungen, die auf Bestätigung warten: %d. Diesen Käufern wurde noch nichts berechnet.';
$_['text_options_skipped'] = '%d Produkt(e) mit einer Pflichtoption (zum Beispiel einer zu wählenden Größe) werden auf sudemi nicht angeboten: Eine Bestellung über den KI-Assistenten eines Käufers enthält keine Option, Sie wüssten also nicht, welchen Artikel Sie versenden sollen. Bieten Sie sie stattdessen als OpenCart-Varianten an (jede Variante ist ein eigenes Produkt).';

// Product form: right of withdrawal (Directive 2011/83/EU, art. 16)
$_['entry_withdrawal'] = 'Widerrufsrecht';
$_['text_withdrawal_none'] = 'Keine - es gilt das normale Widerrufsrecht';
$_['help_withdrawal'] = 'Nur für Produkte, die gesetzlich unter eine Ausnahme fallen. Käufer sehen dies vor dem Bezahlen; Missbrauch ist nicht erlaubt.';
$_['text_withdrawal_hygiene'] = 'Aus Gesundheits- oder Hygienegründen versiegelt (nach Entsiegelung keine Rückgabe)';
$_['text_withdrawal_custom'] = 'Nach Kundenspezifikation angefertigt oder personalisiert';
$_['text_withdrawal_perishable'] = 'Schnell verderblich oder mit kurzem Verfallsdatum';
$_['text_withdrawal_media'] = 'Versiegelte Audio-, Video- oder Software-Datenträger (nach Entsiegelung keine Rückgabe)';
$_['text_withdrawal_press'] = 'Zeitung, Zeitschrift oder Illustrierte';
$_['text_withdrawal_mixed'] = 'Nach der Lieferung untrennbar mit anderen Gütern vermischt';
$_['text_withdrawal_alcohol'] = 'Alkoholische Getränke mit marktabhängigem Preis, Lieferung nach mehr als 30 Tagen';

// Support: tickets between this shop and sudemi support (sudemi sends the e-mails, not this extension)
$_['text_support_title'] = 'Support';
$_['text_support_not_connected'] = 'Verbinden Sie diesen Shop zuerst mit sudemi; danach können Sie sich hier an den sudemi-Support wenden.';
$_['text_support_intro'] = 'Fragen zu Bestellungen, Zahlungen, Auszahlungen oder dieser Erweiterung richten Sie an den sudemi-Support. Antworten erscheinen hier und werden zusätzlich an die E-Mail-Adresse Ihres sudemi-Kontos gesendet.';
$_['text_support_tickets'] = 'Ihre Tickets';
$_['text_support_empty'] = 'Sie haben noch keine Support-Tickets eröffnet.';
$_['text_support_new'] = 'Ticket eröffnen';
$_['text_support_email'] = 'sudemi benachrichtigt Sie per E-Mail, sobald der Support antwortet. Diese Erweiterung versendet selbst keine E-Mails.';
$_['text_support_opened'] = 'Ticket %s wurde eröffnet. Der sudemi-Support meldet sich bei Ihnen.';
$_['text_support_replied'] = 'Ihre Antwort wurde gesendet.';
$_['text_support_reply_reopens'] = 'Mit einer Antwort wird dieses geschlossene Ticket wieder geöffnet.';
$_['text_support_from_support'] = 'sudemi-Support';
$_['text_support_from_shop'] = 'Sie (dieser Shop)';
$_['text_support_status_open'] = 'Offen';
$_['text_support_status_waiting_for_customer'] = 'Wartet auf Ihre Antwort';
$_['text_support_status_answered'] = 'Beantwortet';
$_['text_support_status_closed'] = 'Geschlossen';
$_['text_support_category_order'] = 'Eine Bestellung';
$_['text_support_category_payment'] = 'Eine Zahlung';
$_['text_support_category_refund_return'] = 'Eine Erstattung oder Rücksendung';
$_['text_support_category_account'] = 'Ihr sudemi-Konto';
$_['text_support_category_shop_plugin'] = 'Dieser Shop oder die Erweiterung';
$_['text_support_category_payouts'] = 'Auszahlungen';
$_['text_support_category_other'] = 'Etwas anderes';
$_['column_support_number'] = 'Ticket';
$_['column_support_subject'] = 'Betreff';
$_['column_support_status'] = 'Status';
$_['column_support_updated'] = 'Zuletzt aktualisiert';
$_['entry_support_category'] = 'Thema';
$_['entry_support_subject'] = 'Betreff';
$_['entry_support_message'] = 'Nachricht';
$_['entry_support_order'] = 'sudemi-Bestell-ID (optional)';
$_['help_support_order'] = 'Zum Beispiel 3f2a9c1e-…; leer lassen, wenn es nicht um eine bestimmte Bestellung geht';
$_['entry_support_reply'] = 'Ihre Antwort';
$_['button_support_open'] = 'Ticket eröffnen';
$_['button_support_reply'] = 'Antwort senden';
$_['button_support_back'] = 'Zurück zu allen Tickets';
$_['error_support_invalid'] = 'Bitte prüfen Sie das Formular: Der Betreff braucht 3 bis 200 Zeichen, die Nachricht höchstens 10.000, und eine Bestell-ID muss zu einer sudemi-Bestellung dieses Shops gehören.';
$_['error_support_not_found'] = 'Dieses Ticket wurde für diesen Shop nicht gefunden.';
$_['error_support_too_many'] = 'Zu viele Tickets auf einmal: höchstens 20 offene Tickets und höchstens 10 neue pro Stunde. Antworten Sie auf ein bestehendes Ticket oder versuchen Sie es später erneut.';
$_['error_support_unavailable'] = 'Der sudemi-Support ist gerade nicht erreichbar. Es wurde nichts gesendet; bitte versuchen Sie es gleich noch einmal.';
