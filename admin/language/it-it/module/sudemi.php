<?php
// Heading
$_['heading_title'] = 'sudemi Connector';

// Text
$_['text_extension'] = 'Estensioni';
$_['text_success'] = 'Le impostazioni sono state salvate.';
$_['text_edit'] = 'Collega questo negozio a sudemi';
$_['text_intro'] = 'Collegando questo negozio, un assistente IA può trovare i tuoi prodotti e inviarti acquirenti. Confermi il collegamento su sudemi, dopo aver effettuato l\'accesso al tuo account - questa estensione non ti chiede mai la password di sudemi.';
$_['text_connected'] = 'Collegato a sudemi.';
$_['text_not_confirmed'] = 'Non ancora confermato. Apri il link, conferma il negozio su sudemi, poi verifica di nuovo.';
$_['text_disconnected'] = 'Scollegato.';
$_['text_sync_started'] = 'La sincronizzazione del catalogo è stata avviata e viene eseguita in background; il catalogo viene inviato entro pochi minuti.';
$_['text_synced'] = 'Il catalogo è stato inviato.';
$_['text_sync_failed'] = 'Non è stato possibile inviare il catalogo. Nessuna inserzione è stata rimossa.';
$_['text_sync_held'] = 'La sincronizzazione del catalogo è in sospeso: rimuoverebbe da sudemi molti prodotti in vendita, quindi non è stato modificato nulla. Se quei prodotti sono stati davvero rimossi da questo negozio, contatta l’assistenza sudemi (support@sudemi.com).';
$_['text_locked'] = 'Ragione sociale, paese, qualifica di professionista e partita IVA determinano i diritti dell\'acquirente, per questo vengono confermati una sola volta su sudemi e si modificano lì. Questa estensione non può modificarli.';
$_['text_shop_url_fixed'] = 'Fisso. sudemi ha verificato che questo negozio rispondesse da questo indirizzo e ogni inserzione indica all\'acquirente da dove proviene - per cambiarlo, quindi, devi ricollegare il negozio dal nuovo indirizzo.';
$_['text_key_held'] = 'La parte privata della chiave di firma non lascia mai questo server e non viene mai inviata a sudemi. Non può nemmeno essere mostrata qui.';

// Entry
$_['entry_shop_url'] = 'Indirizzo del negozio';
$_['entry_install'] = 'Installazione';
$_['entry_key'] = 'Chiave di firma';

// Button
$_['button_connect'] = 'Collega questo negozio';
$_['button_confirm'] = 'Conferma questo negozio su sudemi';
$_['button_check'] = 'Verifica di nuovo';
$_['button_sync'] = 'Invia ora il catalogo';
$_['button_disconnect'] = 'Scollega';

// Error
$_['error_permission'] = 'Non hai i permessi per modificare questa impostazione.';
$_['error_token'] = 'Non è stato possibile verificare la richiesta. Riprova da questa pagina.';
$_['error_sodium'] = 'Su questo server manca l\'estensione sodium. La connessione ne ha bisogno per firmare le richieste e senza di essa non funziona. Chiedi al tuo provider di hosting di attivarla.';

// The listing-status panel. These describe a failure the shop owner cannot see from their own
// admin - products stop being shown when the heartbeat stops arriving, and from this side
// everything looks fine - so they say what happened and what to do about it.
$_['text_listing_status'] = 'Stato delle inserzioni';
$_['text_never_reported'] = 'Questo negozio non ha ancora inviato segnalazioni a sudemi. Aggiungi la voce cron qui sotto; finché non viene eseguita, le segnalazioni partono solo mentre questa pagina è aperta.';
$_['text_unreachable'] = 'Questo negozio non è riuscito a raggiungere sudemi. I suoi prodotti restano pubblicati per un breve periodo e poi vengono sospesi, perché sudemi non propone un prezzo che non può confermare.';
$_['text_listed'] = 'I prodotti vengono mostrati su sudemi.';
$_['entry_pending'] = 'Variazioni di prezzo in attesa';
$_['text_pending'] = 'Variazioni di prezzo o di disponibilità che non è stato ancora possibile inviare. Verranno ritentate alla prossima segnalazione e non vanno perse.';
$_['entry_trusted_until'] = 'Prezzi confermati fino al';
$_['text_trusted_until'] = 'Dopo questo momento, sudemi smette di mostrare questo negozio finché non invia una nuova segnalazione. Non viene eliminato nulla.';
$_['entry_cron'] = 'Voce cron';
$_['text_cron'] = 'Aggiungi questa riga al crontab dell\'account che esegue questo negozio. Ogni cinque minuti comunica a sudemi che il negozio è ancora attivo e che i suoi prezzi sono ancora validi, e invia le variazioni di prezzo che non sono arrivate al primo tentativo. Il link contiene un segreto: trattalo come una password.';

$_['text_delivery_title'] = 'Consegna agli acquirenti sudemi';
$_['text_delivery_preview'] = 'Costi di spedizione inviati a sudemi (dai tuoi metodi di spedizione; un articolo, il tuo primo prodotto):';
$_['text_delivery_skipped'] = 'I paesi senza un prezzo fisso (altre estensioni, spedizione gratuita con importo minimo, zone geografiche che coprono solo parte di un paese) vengono esclusi anziché stimati.';
$_['text_delivery_none'] = 'Non è stato possibile leggere alcun costo di spedizione dai tuoi metodi di spedizione, quindi gli acquirenti sudemi non possono ordinare. Attiva Tariffa fissa, Per articolo, In base al peso o Spedizione gratuita senza condizioni per le zone geografiche in cui spedisci.';
$_['entry_handling_days'] = 'Giorni prima della spedizione di un ordine (al massimo)';
$_['entry_transit_days'] = 'Giorni di trasporto del corriere (al massimo)';
$_['button_delivery'] = 'Salva i tempi di consegna';
$_['text_delivery_help'] = 'Gli acquirenti vedono la somma come data di consegna più tardiva prevista. Inserisci i tuoi tempi reali; i valori predefiniti sono volutamente prudenti. Il catalogo viene inviato di nuovo con la prossima sincronizzazione.';
$_['text_delivery_saved'] = 'Tempi di consegna salvati.';

// Order history on this page
$_['text_history_title'] = 'Ordini tramite sudemi';
$_['text_history_error'] = 'Al momento non è possibile raggiungere sudemi per caricare lo storico degli ordini. Gli ordini in sé non hanno alcun problema; riprova tra poco.';
$_['text_history_empty'] = 'Ancora nessun ordine tramite sudemi.';
$_['column_order'] = 'Ordine';
$_['column_items'] = 'Articoli venduti';
$_['column_status'] = 'Stato';
$_['column_net'] = 'Ricevi';
$_['column_customer'] = 'Cliente';
$_['text_status_awaiting_seller'] = 'In attesa di tua risposta - nessun addebito all\'acquirente';
$_['text_status_accepted'] = 'Accettato - in attesa degli altri negozi compresi nel pagamento dell\'acquirente; nessun addebito ancora, non spedire';
$_['text_status_paid'] = 'Confermato e addebitato';
$_['text_status_declined'] = 'Rifiutato - nessun addebito';
$_['text_status_shipped'] = 'Spedito';
$_['text_status_delivered'] = 'Consegnato';
$_['text_status_cancelled'] = 'Annullato';
$_['text_status_refunded'] = 'Rimborsato';
$_['text_history_awaiting'] = 'Ordini in attesa di conferma: %d. A questi acquirenti non è ancora stato addebitato nulla.';
$_['text_options_skipped'] = '%d prodotto/i con un\'opzione obbligatoria (ad esempio una taglia da scegliere) non sono offerti su sudemi: un ordine dall\'assistente IA di un acquirente non contiene alcuna opzione, quindi non sapresti quale articolo spedire. Offrili invece come varianti OpenCart (ogni variante è un prodotto a sé).';

// Product form: right of withdrawal (Directive 2011/83/EU, art. 16)
$_['entry_withdrawal'] = 'Diritto di recesso';
$_['text_withdrawal_none'] = 'Nessuna - si applica il normale diritto di recesso';
$_['help_withdrawal'] = 'Solo per prodotti che rientrano per legge in un’eccezione. Gli acquirenti lo vedono prima di pagare; l’uso improprio non è consentito.';
$_['text_withdrawal_hygiene'] = 'Sigillato per motivi igienici o connessi alla protezione della salute (non restituibile se aperto)';
$_['text_withdrawal_custom'] = 'Confezionato su misura per l’acquirente o personalizzato';
$_['text_withdrawal_perishable'] = 'Deperibile o a rapida scadenza';
$_['text_withdrawal_media'] = 'Registrazioni audio, video o software sigillati (non restituibili se aperti)';
$_['text_withdrawal_press'] = 'Giornale, periodico o rivista';
$_['text_withdrawal_mixed'] = 'Mescolato inscindibilmente con altri beni dopo la consegna';
$_['text_withdrawal_alcohol'] = 'Bevande alcoliche con prezzo legato al mercato, consegnate dopo oltre 30 giorni';

// Support: tickets between this shop and sudemi support (sudemi sends the e-mails, not this extension)
$_['text_support_title'] = 'Assistenza';
$_['text_support_not_connected'] = 'Collega prima questo negozio a sudemi; poi potrai contattare l’assistenza sudemi da qui.';
$_['text_support_intro'] = 'Domande su ordini, pagamenti, accrediti o questa estensione vanno all’assistenza sudemi. Le risposte compaiono qui e vengono inviate anche via e-mail all’indirizzo del tuo account sudemi.';
$_['text_support_tickets'] = 'I tuoi ticket';
$_['text_support_empty'] = 'Non hai ancora aperto nessun ticket di assistenza.';
$_['text_support_new'] = 'Apri un ticket';
$_['text_support_email'] = 'sudemi ti avvisa via e-mail quando l’assistenza risponde. Questa estensione non invia e-mail.';
$_['text_support_opened'] = 'Ticket %s aperto. L’assistenza sudemi ti risponderà.';
$_['text_support_replied'] = 'La tua risposta è stata inviata.';
$_['text_support_reply_reopens'] = 'Rispondendo, questo ticket chiuso viene riaperto.';
$_['text_support_from_support'] = 'Assistenza sudemi';
$_['text_support_from_shop'] = 'Tu (questo negozio)';
$_['text_support_status_open'] = 'Aperto';
$_['text_support_status_waiting_for_customer'] = 'In attesa della tua risposta';
$_['text_support_status_answered'] = 'Risposto';
$_['text_support_status_closed'] = 'Chiuso';
$_['text_support_category_order'] = 'Un ordine';
$_['text_support_category_payment'] = 'Un pagamento';
$_['text_support_category_refund_return'] = 'Un rimborso o un reso';
$_['text_support_category_account'] = 'Il tuo account sudemi';
$_['text_support_category_shop_plugin'] = 'Questo negozio o l’estensione';
$_['text_support_category_payouts'] = 'Accrediti';
$_['text_support_category_other'] = 'Altro';
$_['column_support_number'] = 'Ticket';
$_['column_support_subject'] = 'Oggetto';
$_['column_support_status'] = 'Stato';
$_['column_support_updated'] = 'Ultimo aggiornamento';
$_['entry_support_category'] = 'Argomento';
$_['entry_support_subject'] = 'Oggetto';
$_['entry_support_message'] = 'Messaggio';
$_['entry_support_order'] = 'ID ordine sudemi (facoltativo)';
$_['help_support_order'] = 'Ad esempio 3f2a9c1e-…; lascia vuoto se non riguarda un singolo ordine';
$_['entry_support_reply'] = 'La tua risposta';
$_['button_support_open'] = 'Apri ticket';
$_['button_support_reply'] = 'Invia risposta';
$_['button_support_back'] = 'Torna a tutti i ticket';
$_['error_support_invalid'] = 'Controlla il modulo: l’oggetto deve avere da 3 a 200 caratteri, il messaggio al massimo 10.000, e l’ID ordine deve essere un ordine sudemi di questo negozio.';
$_['error_support_not_found'] = 'Ticket non trovato per questo negozio.';
$_['error_support_too_many'] = 'Troppi ticket insieme: al massimo 20 ticket aperti e al massimo 10 nuovi all’ora. Rispondi a un ticket esistente o riprova più tardi.';
$_['error_support_unavailable'] = 'Al momento l’assistenza sudemi non è raggiungibile. Non è stato inviato nulla; riprova tra poco.';

// sudemi refused this site as not being the connected shop (409 site_mismatch): a staging copy,
// a restored backup, or the shop moved. %s is the address the shop is connected under.
$_['text_site_mismatch_at'] = 'sudemi ha rifiutato le richieste da questo sito: questo negozio è collegato a sudemi con un altro indirizzo (%s). Se questo sito è una copia di staging o un backup ripristinato, scollega sudemi qui. Se il negozio si è trasferito a questo indirizzo, collegalo di nuovo.';
$_['text_site_mismatch'] = 'sudemi ha rifiutato le richieste da questo sito: questo negozio è collegato a sudemi con un altro indirizzo. Se questo sito è una copia di staging o un backup ripristinato, scollega sudemi qui. Se il negozio si è trasferito a questo indirizzo, collegalo di nuovo.';

// Return requests from buyers (answered within 7 days: accept, or reject with a reason the buyer
// sees). The notes go into the order history; %s is the reason given.
$_['text_requests_title'] = 'Richieste di reso';
$_['text_requests_intro'] = 'Gli acquirenti chiedono di restituire questi ordini consegnati. Rispondi a ogni richiesta entro la data indicata: accettala oppure rifiutala indicando un motivo (l’acquirente lo vedrà). Una richiesta senza risposta passa a sudemi dopo 7 giorni. L’elenco si aggiorna a ogni segnalazione inviata a sudemi.';
$_['text_requests_empty'] = 'Nessuna richiesta di reso aperta.';
$_['column_request_order'] = 'Ordine';
$_['column_request_reason'] = 'Motivo dell’acquirente';
$_['column_request_requested'] = 'Richiesta il';
$_['column_request_respond_by'] = 'Rispondere entro';
$_['column_request_answer'] = 'La tua risposta';
$_['text_request_no_local'] = 'Non trovato in questo negozio';
$_['text_request_no_reason'] = 'Nessun motivo indicato';
$_['text_request_overdue'] = 'Scaduta';
$_['entry_request_reject_reason'] = 'Motivo del rifiuto (obbligatorio, mostrato all’acquirente)';
$_['button_request_accept'] = 'Accetta il reso';
$_['button_request_reject'] = 'Rifiuta il reso';
$_['text_request_accepted'] = 'Reso accettato. Ora l’acquirente rispedisce l’articolo. Quando è arrivato, rimborsa l’ordine impostandone lo stato su Rimborsato: il rimborso arriva all’acquirente tramite sudemi e chiude la richiesta.';
$_['text_request_rejected'] = 'Reso rifiutato. L’acquirente vede il tuo motivo.';
$_['text_request_already'] = 'Questa richiesta aveva già una risposta. L’elenco è stato aggiornato.';
$_['error_request_not_found'] = 'Su questo ordine non c’è più nessuna richiesta di reso aperta. L’elenco è stato aggiornato.';
$_['error_request_reason'] = 'Per rifiutare un reso serve un motivo; l’acquirente lo vedrà.';
$_['error_request_unavailable'] = 'Al momento sudemi non è raggiungibile. Non è stata inviata alcuna risposta; riprova tra poco.';
$_['text_request_note_accepted'] = 'sudemi: richiesta di reso accettata. Rimborsa l’ordine (stato Rimborsato) quando l’articolo è tornato.';
$_['text_request_note_rejected'] = 'sudemi: richiesta di reso rifiutata. Motivo mostrato all’acquirente: %s';
$_['text_history_return_requested'] = 'Reso richiesto';
// The totals of a sudemi order (order page, invoice), written in the store's language.
$_['text_order_sub_total'] = 'Subtotale';
$_['text_order_shipping'] = 'Spedizione sudemi';
$_['text_order_total'] = 'Totale';
// The shop currency sudemi charges buyers in (1.2.12).
$_['entry_currency'] = 'Valuta';
$_['text_currency_seen'] = 'Gli acquirenti su sudemi pagano in %s, la valuta predefinita di questo negozio. I tuoi prezzi vengono inviati in questa valuta così come sono, senza conversione.';
$_['text_currency_fee'] = 'sudemi ti paga in EUR: la società di pagamenti con carta Stripe converte il pagamento e la sua commissione di conversione (circa il 2%) viene trattenuta dal tuo pagamento.';
$_['text_currency_unsupported'] = 'sudemi non può accettare pagamenti in %s, quindi gli acquirenti non possono ordinare da questo negozio. Imposta come valuta predefinita (System > Settings, scheda Local) una di queste: %s. Poi invia di nuovo il catalogo.';
$_['text_currency_changed'] = 'Questo negozio ora vende in %1$s, ma sudemi ha ancora %2$s. Premi "Invia ora il catalogo" perché gli acquirenti paghino in %1$s.';
$_['column_paid'] = 'Pagato dall\'acquirente';
