<?php
// Heading
$_['heading_title'] = 'sudemi Connector';

// Text
$_['text_extension'] = 'Erweiterungen';
$_['text_success'] = 'Die Einstellungen wurden gespeichert.';
$_['text_edit'] = 'Diesen Shop mit sudemi verbinden';
$_['text_intro'] = 'Wenn Sie diesen Shop verbinden, kann ein KI-Assistent Ihre Produkte finden und Käufer an Sie weiterleiten. Sie bestätigen die Verbindung auf sudemi, angemeldet mit Ihrem eigenen Konto — diese Erweiterung fragt nie nach Ihrem sudemi-Passwort.';
$_['text_connected'] = 'Mit sudemi verbunden.';
$_['text_not_confirmed'] = 'Noch nicht bestätigt. Öffnen Sie den Link, bestätigen Sie den Shop auf sudemi und prüfen Sie dann erneut.';
$_['text_disconnected'] = 'Getrennt.';
$_['text_synced'] = 'Der Katalog wurde gesendet.';
$_['text_sync_failed'] = 'Der Katalog konnte nicht gesendet werden. Es wurde nichts aus der Listung genommen.';
$_['text_locked'] = 'Ihr rechtlicher Name, Ihr Land, Ihr Unternehmerstatus und Ihre USt-IdNr. bestimmen, welche Rechte ein Käufer hat. Deshalb werden sie einmalig auf sudemi bestätigt und dort geändert. Diese Erweiterung kann sie nicht bearbeiten.';
$_['text_shop_url_fixed'] = 'Unveränderlich. sudemi hat geprüft, dass dieser Shop unter dieser Adresse geantwortet hat, und ein Eintrag zeigt dem Käufer, woher er stammt — eine Änderung bedeutet daher, sich von der neuen Adresse aus erneut zu verbinden.';
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
// admin — products stop being shown when the heartbeat stops arriving, and from this side
// everything looks fine — so they say what happened and what to do about it.
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
