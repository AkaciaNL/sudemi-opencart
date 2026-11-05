<?php
// Heading
$_['heading_title'] = 'sudemi Connector';

// Text
$_['text_extension'] = 'Estensioni';
$_['text_success'] = 'Le impostazioni sono state salvate.';
$_['text_edit'] = 'Collega questo negozio a sudemi';
$_['text_intro'] = 'Collegando questo negozio, un assistente IA può trovare i tuoi prodotti e inviarti acquirenti. Confermi il collegamento su sudemi, dopo aver effettuato l\'accesso al tuo account — questa estensione non ti chiede mai la password di sudemi.';
$_['text_connected'] = 'Collegato a sudemi.';
$_['text_not_confirmed'] = 'Non ancora confermato. Apri il link, conferma il negozio su sudemi, poi verifica di nuovo.';
$_['text_disconnected'] = 'Scollegato.';
$_['text_synced'] = 'Il catalogo è stato inviato.';
$_['text_sync_failed'] = 'Non è stato possibile inviare il catalogo. Nessuna inserzione è stata rimossa.';
$_['text_locked'] = 'Ragione sociale, paese, qualifica di professionista e partita IVA determinano i diritti dell\'acquirente, per questo vengono confermati una sola volta su sudemi e si modificano lì. Questa estensione non può modificarli.';
$_['text_shop_url_fixed'] = 'Fisso. sudemi ha verificato che questo negozio rispondesse da questo indirizzo e ogni inserzione indica all\'acquirente da dove proviene — per cambiarlo, quindi, devi ricollegare il negozio dal nuovo indirizzo.';
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
// admin — products stop being shown when the heartbeat stops arriving, and from this side
// everything looks fine — so they say what happened and what to do about it.
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
$_['text_status_awaiting_seller'] = 'In attesa di tua risposta — nessun addebito all\'acquirente';
$_['text_status_accepted'] = 'Accettato — in attesa degli altri negozi compresi nel pagamento dell\'acquirente; nessun addebito ancora, non spedire';
$_['text_status_paid'] = 'Confermato e addebitato';
$_['text_status_declined'] = 'Rifiutato — nessun addebito';
$_['text_status_shipped'] = 'Spedito';
$_['text_status_delivered'] = 'Consegnato';
$_['text_status_cancelled'] = 'Annullato';
$_['text_status_refunded'] = 'Rimborsato';
$_['text_history_awaiting'] = 'Ordini in attesa di conferma: %d. A questi acquirenti non è ancora stato addebitato nulla.';
