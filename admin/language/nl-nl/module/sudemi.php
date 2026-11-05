<?php
// Heading
$_['heading_title'] = 'sudemi Connector';

// Text
$_['text_extension'] = 'Extensies';
$_['text_success'] = 'De instellingen zijn opgeslagen.';
$_['text_edit'] = 'Deze winkel koppelen aan sudemi';
$_['text_intro'] = 'Als je deze winkel koppelt, kan een AI-assistent je producten vinden en kopers naar je doorsturen. Je bevestigt de koppeling op sudemi, ingelogd op je eigen account — deze extensie vraagt nooit om je sudemi-wachtwoord.';
$_['text_connected'] = 'Gekoppeld aan sudemi.';
$_['text_not_confirmed'] = 'Nog niet bevestigd. Open de link, bevestig de winkel op sudemi en controleer daarna opnieuw.';
$_['text_disconnected'] = 'Ontkoppeld.';
$_['text_synced'] = 'De catalogus is verstuurd.';
$_['text_sync_failed'] = 'De catalogus kon niet worden verstuurd. Er is niets offline gehaald.';
$_['text_locked'] = 'Je juridische naam, land, status als handelaar en btw-nummer bepalen waar een koper recht op heeft. Daarom worden ze één keer bevestigd op sudemi en daar gewijzigd. Deze extensie kan ze niet bewerken.';
$_['text_shop_url_fixed'] = 'Vast. sudemi heeft gecontroleerd dat deze winkel vanaf dit adres antwoordde, en een vermelding laat een koper zien waar die vandaan komt — wijzigen betekent dus opnieuw koppelen vanaf het nieuwe adres.';
$_['text_key_held'] = 'Het privédeel van de ondertekeningssleutel verlaat deze server nooit en wordt nooit naar sudemi gestuurd. Het kan hier ook niet worden getoond.';

// Entry
$_['entry_shop_url'] = 'Winkeladres';
$_['entry_install'] = 'Installatie';
$_['entry_key'] = 'Ondertekeningssleutel';

// Button
$_['button_connect'] = 'Deze winkel koppelen';
$_['button_confirm'] = 'Deze winkel bevestigen op sudemi';
$_['button_check'] = 'Opnieuw controleren';
$_['button_sync'] = 'Catalogus nu versturen';
$_['button_disconnect'] = 'Ontkoppelen';

// Error
$_['error_permission'] = 'Je hebt geen toestemming om dit te wijzigen.';
$_['error_token'] = 'Dat verzoek kon niet worden geverifieerd. Probeer het opnieuw vanaf deze pagina.';
$_['error_sodium'] = 'Op deze server ontbreekt de sodium-extensie. De koppeling heeft die nodig om verzoeken te ondertekenen en werkt niet zonder. Vraag je hostingprovider om hem in te schakelen.';

// The listing-status panel. These describe a failure the shop owner cannot see from their own
// admin — products stop being shown when the heartbeat stops arriving, and from this side
// everything looks fine — so they say what happened and what to do about it.
$_['text_listing_status'] = 'Vermeldingsstatus';
$_['text_never_reported'] = 'Deze winkel heeft zich nog niet bij sudemi gemeld. Voeg de cron-regel hieronder toe; tot die draait, worden meldingen alleen verstuurd terwijl deze pagina openstaat.';
$_['text_unreachable'] = 'Deze winkel kon sudemi niet bereiken. De producten blijven nog even zichtbaar en worden daarna gepauzeerd, omdat sudemi geen prijs noemt die het niet kan bevestigen.';
$_['text_listed'] = 'Producten worden getoond op sudemi.';
$_['entry_pending'] = 'Wachtende prijswijzigingen';
$_['text_pending'] = 'Prijs- of voorraadwijzigingen die nog niet verstuurd konden worden. Ze worden bij de volgende melding opnieuw geprobeerd en gaan niet verloren.';
$_['entry_trusted_until'] = 'Prijzen bevestigd tot';
$_['text_trusted_until'] = 'Daarna toont sudemi deze winkel niet meer tot hij zich opnieuw meldt. Er wordt niets verwijderd.';
$_['entry_cron'] = 'Cron-regel';
$_['text_cron'] = 'Voeg dit toe aan de crontab van het account waaronder deze winkel draait. Het laat sudemi elke vijf minuten weten dat deze winkel er nog is en dat de prijzen nog kloppen, en het verstuurt elke prijswijziging die de eerste keer niet aankwam. De link bevat een geheime sleutel; behandel hem als een wachtwoord.';

$_['text_delivery_title'] = 'Levering aan sudemi-kopers';
$_['text_delivery_preview'] = 'Verzendprijzen die naar sudemi zijn gestuurd (uit je verzendmethoden; één artikel, je eerste product):';
$_['text_delivery_skipped'] = 'Landen zonder vaste prijs (andere extensies, gratis verzending vanaf een minimum, geozones die maar een deel van een land dekken) worden weggelaten in plaats van geschat.';
$_['text_delivery_none'] = 'Uit je verzendmethoden kon geen verzendprijs worden gelezen, dus sudemi-kopers kunnen niet bestellen. Schakel Flat Rate, Per Item, Weight Based of onvoorwaardelijke Free Shipping in voor de geozones waar je naartoe verzendt.';
$_['entry_handling_days'] = 'Dagen tot je een bestelling verzendt (maximaal)';
$_['entry_transit_days'] = 'Dagen die de vervoerder nodig heeft (maximaal)';
$_['button_delivery'] = 'Levertijden opslaan';
$_['text_delivery_help'] = 'Kopers zien de som als de uiterste geschatte levertijd. Vul je echte tijden in; de standaardwaarden zijn bewust voorzichtig. De catalogus wordt bij de volgende synchronisatie opnieuw verstuurd.';
$_['text_delivery_saved'] = 'Levertijden opgeslagen.';

// Order history on this page
$_['text_history_title'] = 'Bestellingen via sudemi';
$_['text_history_error'] = 'De bestelgeschiedenis kon zojuist niet bij sudemi worden opgehaald. Met de bestellingen zelf is niets mis; probeer het zo opnieuw.';
$_['text_history_empty'] = 'Nog geen bestellingen via sudemi.';
$_['column_order'] = 'Bestelling';
$_['column_items'] = 'Verkocht';
$_['column_status'] = 'Status';
$_['column_net'] = 'Jij ontvangt';
$_['column_customer'] = 'Klant';
$_['text_status_awaiting_seller'] = 'Wacht op jou — koper nog niet belast';
$_['text_status_accepted'] = 'Geaccepteerd — wacht op de andere winkels in de betaling van de koper; koper nog niet belast, nog niet verzenden';
$_['text_status_paid'] = 'Bevestigd en afgeschreven';
$_['text_status_declined'] = 'Afgewezen — niets afgeschreven';
$_['text_status_shipped'] = 'Verzonden';
$_['text_status_delivered'] = 'Afgeleverd';
$_['text_status_cancelled'] = 'Geannuleerd';
$_['text_status_refunded'] = 'Terugbetaald';
$_['text_history_awaiting'] = 'Bestellingen die op bevestiging wachten: %d. Die kopers zijn nog niet belast.';
