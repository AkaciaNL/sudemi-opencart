<?php
// Heading
$_['heading_title'] = 'sudemi Connector';

// Text
$_['text_extension'] = 'Extensies';
$_['text_success'] = 'De instellingen zijn opgeslagen.';
$_['text_edit'] = 'Deze winkel koppelen aan sudemi';
$_['text_intro'] = 'Als je deze winkel koppelt, kan een AI-assistent je producten vinden en kopers naar je doorsturen. Je bevestigt de koppeling op sudemi, ingelogd op je eigen account - deze extensie vraagt nooit om je sudemi-wachtwoord.';
$_['text_connected'] = 'Gekoppeld aan sudemi.';
$_['text_not_confirmed'] = 'Nog niet bevestigd. Open de link, bevestig de winkel op sudemi en controleer daarna opnieuw.';
$_['text_disconnected'] = 'Ontkoppeld.';
$_['text_synced'] = 'De catalogus is verstuurd.';
$_['text_sync_failed'] = 'De catalogus kon niet worden verstuurd. Er is niets offline gehaald.';
$_['text_sync_held'] = 'De catalogussynchronisatie staat in de wacht: die zou veel vermelde producten van sudemi verwijderen, dus er is niets gewijzigd. Zijn die producten echt uit deze winkel verwijderd, neem dan contact op met sudemi-support (support@sudemi.com).';
$_['text_locked'] = 'Je juridische naam, land, status als handelaar en btw-nummer bepalen waar een koper recht op heeft. Daarom worden ze één keer bevestigd op sudemi en daar gewijzigd. Deze extensie kan ze niet bewerken.';
$_['text_shop_url_fixed'] = 'Vast. sudemi heeft gecontroleerd dat deze winkel vanaf dit adres antwoordde, en een vermelding laat een koper zien waar die vandaan komt - wijzigen betekent dus opnieuw koppelen vanaf het nieuwe adres.';
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
// admin - products stop being shown when the heartbeat stops arriving, and from this side
// everything looks fine - so they say what happened and what to do about it.
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
$_['text_status_awaiting_seller'] = 'Wacht op jou - koper nog niet belast';
$_['text_status_accepted'] = 'Geaccepteerd - wacht op de andere winkels in de betaling van de koper; koper nog niet belast, nog niet verzenden';
$_['text_status_paid'] = 'Bevestigd en afgeschreven';
$_['text_status_declined'] = 'Afgewezen - niets afgeschreven';
$_['text_status_shipped'] = 'Verzonden';
$_['text_status_delivered'] = 'Afgeleverd';
$_['text_status_cancelled'] = 'Geannuleerd';
$_['text_status_refunded'] = 'Terugbetaald';
$_['text_history_awaiting'] = 'Bestellingen die op bevestiging wachten: %d. Die kopers zijn nog niet belast.';
$_['text_options_skipped'] = '%d product(en) met een verplichte optie (bijvoorbeeld een maat om te kiezen) worden niet aangeboden op sudemi: een bestelling via de AI-assistent van een koper bevat geen optie, dus je zou niet weten welk artikel je moet versturen. Bied ze in plaats daarvan aan als OpenCart-varianten (elke variant is een eigen product).';

// Product form: right of withdrawal (Directive 2011/83/EU, art. 16)
$_['entry_withdrawal'] = 'Herroepingsrecht';
$_['text_withdrawal_none'] = 'Geen - het normale herroepingsrecht geldt';
$_['help_withdrawal'] = 'Alleen voor producten die wettelijk onder een uitzondering vallen. Kopers zien dit vóór het betalen; misbruik is niet toegestaan.';
$_['text_withdrawal_hygiene'] = 'Verzegeld om gezondheids- of hygiëneredenen (niet retour na verbreken van de verzegeling)';
$_['text_withdrawal_custom'] = 'Volgens specificaties van de koper gemaakt of gepersonaliseerd';
$_['text_withdrawal_perishable'] = 'Bederfelijk of snel verlopend';
$_['text_withdrawal_media'] = 'Verzegelde audio, video of software (niet retour na verbreken van de verzegeling)';
$_['text_withdrawal_press'] = 'Krant, tijdschrift of magazine';
$_['text_withdrawal_mixed'] = 'Na levering onlosmakelijk vermengd met andere zaken';
$_['text_withdrawal_alcohol'] = 'Alcohol met een marktafhankelijke prijs, geleverd na meer dan 30 dagen';

// Support: tickets between this shop and sudemi support (sudemi sends the e-mails, not this extension)
$_['text_support_title'] = 'Support';
$_['text_support_not_connected'] = 'Koppel deze winkel eerst aan sudemi; daarna kunt u hier contact opnemen met sudemi-support.';
$_['text_support_intro'] = 'Vragen over bestellingen, betalingen, uitbetalingen of deze extensie stelt u aan sudemi-support. Antwoorden verschijnen hier en worden ook gemaild naar het adres van uw sudemi-account.';
$_['text_support_tickets'] = 'Uw tickets';
$_['text_support_empty'] = 'U heeft nog geen supporttickets aangemaakt.';
$_['text_support_new'] = 'Nieuw ticket';
$_['text_support_email'] = 'sudemi mailt u zodra support antwoordt. Deze extensie verstuurt zelf geen e-mail.';
$_['text_support_opened'] = 'Ticket %s is aangemaakt. sudemi-support neemt contact met u op.';
$_['text_support_replied'] = 'Uw antwoord is verstuurd.';
$_['text_support_reply_reopens'] = 'Met een antwoord wordt dit gesloten ticket heropend.';
$_['text_support_from_support'] = 'sudemi-support';
$_['text_support_from_shop'] = 'U (deze winkel)';
$_['text_support_status_open'] = 'Open';
$_['text_support_status_waiting_for_customer'] = 'Wacht op uw reactie';
$_['text_support_status_answered'] = 'Beantwoord';
$_['text_support_status_closed'] = 'Gesloten';
$_['text_support_category_order'] = 'Een bestelling';
$_['text_support_category_payment'] = 'Een betaling';
$_['text_support_category_refund_return'] = 'Een terugbetaling of retour';
$_['text_support_category_account'] = 'Uw sudemi-account';
$_['text_support_category_shop_plugin'] = 'Deze winkel of extensie';
$_['text_support_category_payouts'] = 'Uitbetalingen';
$_['text_support_category_other'] = 'Iets anders';
$_['column_support_number'] = 'Ticket';
$_['column_support_subject'] = 'Onderwerp';
$_['column_support_status'] = 'Status';
$_['column_support_updated'] = 'Laatst bijgewerkt';
$_['entry_support_category'] = 'Onderwerpcategorie';
$_['entry_support_subject'] = 'Onderwerp';
$_['entry_support_message'] = 'Bericht';
$_['entry_support_order'] = 'sudemi-bestelnummer (optioneel)';
$_['help_support_order'] = 'Bijvoorbeeld 3f2a9c1e-…; laat leeg als het niet over één bestelling gaat';
$_['entry_support_reply'] = 'Uw antwoord';
$_['button_support_open'] = 'Ticket aanmaken';
$_['button_support_reply'] = 'Antwoord versturen';
$_['button_support_back'] = 'Terug naar alle tickets';
$_['error_support_invalid'] = 'Controleer het formulier: het onderwerp moet 3 tot 200 tekens hebben, het bericht maximaal 10.000, en een bestelnummer moet een sudemi-bestelling van deze winkel zijn.';
$_['error_support_not_found'] = 'Dat ticket is niet gevonden voor deze winkel.';
$_['error_support_too_many'] = 'Te veel tickets tegelijk: maximaal 20 open tickets en maximaal 10 nieuwe per uur. Reageer op een bestaand ticket of probeer het later opnieuw.';
$_['error_support_unavailable'] = 'sudemi-support is op dit moment niet bereikbaar. Er is niets verstuurd; probeer het zo nog eens.';

// sudemi refused this site as not being the connected shop (409 site_mismatch): a staging copy,
// a restored backup, or the shop moved. %s is the address the shop is connected under.
$_['text_site_mismatch_at'] = 'sudemi weigert verzoeken van deze site: deze winkel is onder een ander adres aan sudemi gekoppeld (%s). Is deze site een testkopie (staging) of een teruggezette back-up, ontkoppel sudemi dan hier. Is de winkel naar dit adres verhuisd, koppel hem dan opnieuw.';
$_['text_site_mismatch'] = 'sudemi weigert verzoeken van deze site: deze winkel is onder een ander adres aan sudemi gekoppeld. Is deze site een testkopie (staging) of een teruggezette back-up, ontkoppel sudemi dan hier. Is de winkel naar dit adres verhuisd, koppel hem dan opnieuw.';

// Return requests from buyers (answered within 7 days: accept, or reject with a reason the buyer
// sees). The notes go into the order history; %s is the reason given.
$_['text_requests_title'] = 'Retourverzoeken';
$_['text_requests_intro'] = 'Kopers willen deze geleverde bestellingen retourneren. Beantwoord elk verzoek vóór de getoonde datum: accepteer het, of wijs het af met een reden (de koper ziet die). Een onbeantwoord verzoek gaat na 7 dagen naar sudemi. De lijst wordt bijgewerkt bij elke melding aan sudemi.';
$_['text_requests_empty'] = 'Geen openstaande retourverzoeken.';
$_['column_request_order'] = 'Bestelling';
$_['column_request_reason'] = 'Reden van de koper';
$_['column_request_requested'] = 'Aangevraagd';
$_['column_request_respond_by'] = 'Antwoorden vóór';
$_['column_request_answer'] = 'Uw antwoord';
$_['text_request_no_local'] = 'Niet gevonden in deze winkel';
$_['text_request_no_reason'] = 'Geen reden opgegeven';
$_['text_request_overdue'] = 'Te laat';
$_['entry_request_reject_reason'] = 'Reden van afwijzing (verplicht, de koper ziet deze)';
$_['button_request_accept'] = 'Retour accepteren';
$_['button_request_reject'] = 'Retour afwijzen';
$_['text_request_accepted'] = 'Retour geaccepteerd. De koper stuurt het artikel nu terug. Is het binnen, betaal de bestelling dan terug door de status op Terugbetaald te zetten: de terugbetaling gaat via sudemi naar de koper en sluit het verzoek af.';
$_['text_request_rejected'] = 'Retour afgewezen. De koper ziet uw reden.';
$_['text_request_already'] = 'Dit verzoek was al beantwoord. De lijst is bijgewerkt.';
$_['error_request_not_found'] = 'Er staat geen retourverzoek meer open op deze bestelling. De lijst is bijgewerkt.';
$_['error_request_reason'] = 'Voor het afwijzen van een retour is een reden nodig; de koper ziet die.';
$_['error_request_unavailable'] = 'sudemi is op dit moment niet bereikbaar. Er is niets beantwoord; probeer het zo opnieuw.';
$_['text_request_note_accepted'] = 'sudemi: retourverzoek geaccepteerd. Betaal de bestelling terug (status Terugbetaald) zodra het artikel terug is.';
$_['text_request_note_rejected'] = 'sudemi: retourverzoek afgewezen. Reden getoond aan de koper: %s';
$_['text_history_return_requested'] = 'Retour aangevraagd';
