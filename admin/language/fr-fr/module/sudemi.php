<?php
// Heading
$_['heading_title'] = 'Connecteur sudemi';

// Text
$_['text_extension'] = 'Extensions';
$_['text_success'] = 'Les paramètres ont été enregistrés.';
$_['text_edit'] = 'Connecter cette boutique à sudemi';
$_['text_intro'] = 'Connecter cette boutique permet à un assistant IA de trouver vos produits et de vous envoyer des acheteurs. Vous confirmez la connexion sur sudemi, connecté à votre propre compte - cette extension ne vous demande jamais votre mot de passe sudemi.';
$_['text_connected'] = 'Connecté à sudemi.';
$_['text_not_confirmed'] = 'Pas encore confirmé. Ouvrez le lien, confirmez la boutique sur sudemi, puis vérifiez à nouveau.';
$_['text_disconnected'] = 'Déconnecté.';
$_['text_synced'] = 'Le catalogue a été envoyé.';
$_['text_sync_failed'] = 'Le catalogue n’a pas pu être envoyé. Aucun produit n’a été retiré.';
$_['text_locked'] = 'Votre raison sociale, votre pays, votre statut de professionnel et votre numéro de TVA déterminent ce qui est dû à l’acheteur ; ils sont donc confirmés une seule fois sur sudemi et modifiés là-bas. Cette extension ne peut pas les modifier.';
$_['text_shop_url_fixed'] = 'Fixe. sudemi a vérifié que cette boutique répondait à cette adresse, et chaque annonce indique à l’acheteur d’où elle provient - la modifier implique donc de vous reconnecter depuis la nouvelle adresse.';
$_['text_key_held'] = 'La partie privée de la clé de signature ne quitte jamais ce serveur et n’est jamais envoyée à sudemi. Elle ne peut pas non plus être affichée ici.';

// Entry
$_['entry_shop_url'] = 'Adresse de la boutique';
$_['entry_install'] = 'Installation';
$_['entry_key'] = 'Clé de signature';

// Button
$_['button_connect'] = 'Connecter cette boutique';
$_['button_confirm'] = 'Confirmer cette boutique sur sudemi';
$_['button_check'] = 'Vérifier à nouveau';
$_['button_sync'] = 'Envoyer le catalogue maintenant';
$_['button_disconnect'] = 'Déconnecter';

// Error
$_['error_permission'] = 'Vous n’avez pas l’autorisation de modifier ceci.';
$_['error_token'] = 'Cette requête n’a pas pu être vérifiée. Réessayez depuis cette page.';
$_['error_sodium'] = 'L’extension PHP sodium est absente de ce serveur. La connexion en a besoin pour signer les requêtes et ne fonctionnera pas sans elle. Demandez à votre hébergeur de l’activer.';

// The listing-status panel. These describe a failure the shop owner cannot see from their own
// admin - products stop being shown when the heartbeat stops arriving, and from this side
// everything looks fine - so they say what happened and what to do about it.
$_['text_listing_status'] = 'État du référencement';
$_['text_never_reported'] = 'Cette boutique ne s’est pas encore signalée auprès de sudemi. Ajoutez la tâche cron ci-dessous ; tant qu’elle ne tourne pas, les signalements ne sont envoyés que lorsque cette page est ouverte.';
$_['text_unreachable'] = 'Cette boutique n’a pas pu joindre sudemi. Ses produits restent référencés pendant un court moment, puis sont suspendus, car sudemi n’annonce pas un prix qu’il ne peut pas confirmer.';
$_['text_listed'] = 'Les produits sont affichés sur sudemi.';
$_['entry_pending'] = 'Modifications de prix en attente';
$_['text_pending'] = 'Modifications de prix ou de stock qui n’ont pas encore pu être envoyées. Elles seront renvoyées lors du prochain signalement et ne sont pas perdues.';
$_['entry_trusted_until'] = 'Prix confirmés jusqu’au';
$_['text_trusted_until'] = 'Passé ce délai, sudemi cesse d’afficher cette boutique jusqu’à son prochain signalement. Rien n’est supprimé.';
$_['entry_cron'] = 'Tâche cron';
$_['text_cron'] = 'Ajoutez ceci à la crontab du compte qui exécute cette boutique. Elle indique à sudemi toutes les cinq minutes que cette boutique est toujours là et que ses prix sont toujours valables, et elle renvoie toute modification de prix qui n’est pas passée du premier coup. Le lien contient un secret : traitez-le comme un mot de passe.';

$_['text_delivery_title'] = 'Livraison aux acheteurs sudemi';
$_['text_delivery_preview'] = 'Tarifs de livraison envoyés à sudemi (d’après vos modes de livraison ; un article, votre premier produit) :';
$_['text_delivery_skipped'] = 'Les pays sans tarif fixe (autres extensions, livraison gratuite avec minimum d’achat, zones géographiques ne couvrant qu’une partie d’un pays) sont exclus plutôt qu’estimés.';
$_['text_delivery_none'] = 'Aucun tarif de livraison n’a pu être lu dans vos modes de livraison : les acheteurs sudemi ne peuvent donc pas commander. Activez Tarif forfaitaire, Par article, Selon le poids ou la livraison gratuite sans condition pour les zones géographiques vers lesquelles vous expédiez.';
$_['entry_handling_days'] = 'Jours avant l’expédition d’une commande (au maximum)';
$_['entry_transit_days'] = 'Jours de transport par le transporteur (au maximum)';
$_['button_delivery'] = 'Enregistrer les délais de livraison';
$_['text_delivery_help'] = 'Les acheteurs voient la somme comme date de livraison estimée au plus tard. Indiquez vos délais réels ; les valeurs par défaut sont volontairement prudentes. Le catalogue est renvoyé lors de la prochaine synchronisation.';
$_['text_delivery_saved'] = 'Délais de livraison enregistrés.';

// Order history on this page
$_['text_history_title'] = 'Commandes via sudemi';
$_['text_history_error'] = 'Impossible de joindre sudemi pour charger l’historique des commandes pour le moment. Les commandes elles-mêmes ne sont pas en cause ; réessayez dans quelques instants.';
$_['text_history_empty'] = 'Aucune commande via sudemi pour l’instant.';
$_['column_order'] = 'Commande';
$_['column_items'] = 'Articles vendus';
$_['column_status'] = 'État';
$_['column_net'] = 'Vous recevez';
$_['column_customer'] = 'Client';
$_['text_status_awaiting_seller'] = 'En attente de votre réponse - acheteur non débité';
$_['text_status_accepted'] = 'Acceptée - en attente des autres boutiques du paiement de l’acheteur ; acheteur pas encore débité, n’expédiez pas';
$_['text_status_paid'] = 'Confirmée et débitée';
$_['text_status_declined'] = 'Refusée - rien n’a été débité';
$_['text_status_shipped'] = 'Expédiée';
$_['text_status_delivered'] = 'Livrée';
$_['text_status_cancelled'] = 'Annulée';
$_['text_status_refunded'] = 'Remboursée';
$_['text_history_awaiting'] = 'Commandes en attente de confirmation : %d. Ces acheteurs n’ont encore rien payé.';
$_['text_options_skipped'] = '%d produit(s) avec une option obligatoire (par exemple une taille à choisir) ne sont pas proposés sur sudemi : une commande passée par l’assistant IA d’un acheteur ne contient aucune option, vous ne sauriez donc pas quel article envoyer. Proposez-les plutôt comme variantes OpenCart (chaque variante est un produit à part entière).';

// Product form: right of withdrawal (Directive 2011/83/EU, art. 16)
$_['entry_withdrawal'] = 'Droit de rétractation';
$_['text_withdrawal_none'] = 'Aucune - le droit de rétractation normal s’applique';
$_['help_withdrawal'] = 'Uniquement pour les produits qui relèvent légalement d’une exception. Les acheteurs le voient avant de payer ; tout abus est interdit.';
$_['text_withdrawal_hygiene'] = 'Scellé pour des raisons de santé ou d’hygiène (non retournable une fois descellé)';
$_['text_withdrawal_custom'] = 'Confectionné selon les spécifications de l’acheteur ou personnalisé';
$_['text_withdrawal_perishable'] = 'Périssable ou à péremption rapide';
$_['text_withdrawal_media'] = 'Enregistrement audio, vidéo ou logiciel scellé (non retournable une fois descellé)';
$_['text_withdrawal_press'] = 'Journal, périodique ou magazine';
$_['text_withdrawal_mixed'] = 'Mélangé de manière indissociable à d’autres articles après la livraison';
$_['text_withdrawal_alcohol'] = 'Boissons alcoolisées à prix dépendant du marché, livrées après plus de 30 jours';

// Support: tickets between this shop and sudemi support (sudemi sends the e-mails, not this extension)
$_['text_support_title'] = 'Assistance';
$_['text_support_not_connected'] = 'Connectez d’abord cette boutique à sudemi ; vous pourrez ensuite contacter l’assistance sudemi depuis cette page.';
$_['text_support_intro'] = 'Pour toute question sur les commandes, les paiements, les versements ou cette extension, contactez l’assistance sudemi. Les réponses s’affichent ici et sont aussi envoyées par e-mail à l’adresse de votre compte sudemi.';
$_['text_support_tickets'] = 'Vos tickets';
$_['text_support_empty'] = 'Vous n’avez encore ouvert aucun ticket d’assistance.';
$_['text_support_new'] = 'Ouvrir un ticket';
$_['text_support_email'] = 'sudemi vous prévient par e-mail lorsque l’assistance répond. Cette extension n’envoie elle-même aucun e-mail.';
$_['text_support_opened'] = 'Le ticket %s a été ouvert. L’assistance sudemi vous répondra.';
$_['text_support_replied'] = 'Votre réponse a été envoyée.';
$_['text_support_reply_reopens'] = 'Répondre rouvre ce ticket fermé.';
$_['text_support_from_support'] = 'Assistance sudemi';
$_['text_support_from_shop'] = 'Vous (cette boutique)';
$_['text_support_status_open'] = 'Ouvert';
$_['text_support_status_waiting_for_customer'] = 'En attente de votre réponse';
$_['text_support_status_answered'] = 'Répondu';
$_['text_support_status_closed'] = 'Fermé';
$_['text_support_category_order'] = 'Une commande';
$_['text_support_category_payment'] = 'Un paiement';
$_['text_support_category_refund_return'] = 'Un remboursement ou un retour';
$_['text_support_category_account'] = 'Votre compte sudemi';
$_['text_support_category_shop_plugin'] = 'Cette boutique ou l’extension';
$_['text_support_category_payouts'] = 'Versements';
$_['text_support_category_other'] = 'Autre chose';
$_['column_support_number'] = 'Ticket';
$_['column_support_subject'] = 'Objet';
$_['column_support_status'] = 'Statut';
$_['column_support_updated'] = 'Dernière mise à jour';
$_['entry_support_category'] = 'Sujet';
$_['entry_support_subject'] = 'Objet';
$_['entry_support_message'] = 'Message';
$_['entry_support_order'] = 'Identifiant de commande sudemi (facultatif)';
$_['help_support_order'] = 'Par exemple 3f2a9c1e-… ; laissez vide si cela ne concerne pas une commande précise';
$_['entry_support_reply'] = 'Votre réponse';
$_['button_support_open'] = 'Ouvrir le ticket';
$_['button_support_reply'] = 'Envoyer la réponse';
$_['button_support_back'] = 'Retour à tous les tickets';
$_['error_support_invalid'] = 'Veuillez vérifier le formulaire : l’objet doit compter entre 3 et 200 caractères, le message 10 000 au maximum, et l’identifiant de commande doit correspondre à une commande sudemi de cette boutique.';
$_['error_support_not_found'] = 'Ce ticket est introuvable pour cette boutique.';
$_['error_support_too_many'] = 'Trop de tickets à la fois : 20 tickets ouverts au maximum et 10 nouveaux par heure au maximum. Répondez à un ticket existant ou réessayez plus tard.';
$_['error_support_unavailable'] = 'L’assistance sudemi est injoignable pour le moment. Rien n’a été envoyé ; veuillez réessayer dans un instant.';

// sudemi refused this site as not being the connected shop (409 site_mismatch): a staging copy,
// a restored backup, or the shop moved. %s is the address the shop is connected under.
$_['text_site_mismatch_at'] = 'sudemi a refusé les requêtes de ce site : cette boutique est connectée à sudemi sous une autre adresse (%s). Si ce site est une copie de préproduction (staging) ou une sauvegarde restaurée, déconnectez sudemi ici. Si la boutique a été déplacée vers cette adresse, connectez-la à nouveau.';
$_['text_site_mismatch'] = 'sudemi a refusé les requêtes de ce site : cette boutique est connectée à sudemi sous une autre adresse. Si ce site est une copie de préproduction (staging) ou une sauvegarde restaurée, déconnectez sudemi ici. Si la boutique a été déplacée vers cette adresse, connectez-la à nouveau.';

// Return requests from buyers (answered within 7 days: accept, or reject with a reason the buyer
// sees). The notes go into the order history; %s is the reason given.
$_['text_requests_title'] = 'Demandes de retour';
$_['text_requests_intro'] = 'Des acheteurs souhaitent retourner ces commandes livrées. Répondez à chaque demande avant la date indiquée : acceptez-la, ou refusez-la en donnant un motif (l’acheteur le verra). Une demande sans réponse est transmise à sudemi après 7 jours. La liste est mise à jour à chaque rapport envoyé à sudemi.';
$_['text_requests_empty'] = 'Aucune demande de retour en cours.';
$_['column_request_order'] = 'Commande';
$_['column_request_reason'] = 'Motif de l’acheteur';
$_['column_request_requested'] = 'Demandée le';
$_['column_request_respond_by'] = 'Répondre avant le';
$_['column_request_answer'] = 'Votre réponse';
$_['text_request_no_local'] = 'Introuvable dans cette boutique';
$_['text_request_no_reason'] = 'Aucun motif indiqué';
$_['text_request_overdue'] = 'En retard';
$_['entry_request_reject_reason'] = 'Motif du refus (obligatoire, affiché à l’acheteur)';
$_['button_request_accept'] = 'Accepter le retour';
$_['button_request_reject'] = 'Refuser le retour';
$_['text_request_accepted'] = 'Retour accepté. L’acheteur renvoie maintenant l’article. Une fois celui-ci reçu, remboursez la commande en passant son statut à Remboursée : le remboursement est versé à l’acheteur via sudemi et clôt la demande.';
$_['text_request_rejected'] = 'Retour refusé. L’acheteur voit votre motif.';
$_['text_request_already'] = 'Cette demande a déjà reçu une réponse. La liste a été mise à jour.';
$_['error_request_not_found'] = 'Il n’y a plus de demande de retour en cours pour cette commande. La liste a été mise à jour.';
$_['error_request_reason'] = 'Refuser un retour nécessite un motif ; il est affiché à l’acheteur.';
$_['error_request_unavailable'] = 'sudemi est injoignable pour le moment. Aucune réponse n’a été envoyée ; réessayez dans un instant.';
$_['text_request_note_accepted'] = 'sudemi : demande de retour acceptée. Remboursez la commande (statut Remboursée) dès que l’article est revenu.';
$_['text_request_note_rejected'] = 'sudemi : demande de retour refusée. Motif affiché à l’acheteur : %s';
$_['text_history_return_requested'] = 'Retour demandé';
