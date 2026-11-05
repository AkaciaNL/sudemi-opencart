<?php
// Heading
$_['heading_title'] = 'Connecteur sudemi';

// Text
$_['text_extension'] = 'Extensions';
$_['text_success'] = 'Les paramètres ont été enregistrés.';
$_['text_edit'] = 'Connecter cette boutique à sudemi';
$_['text_intro'] = 'Connecter cette boutique permet à un assistant IA de trouver vos produits et de vous envoyer des acheteurs. Vous confirmez la connexion sur sudemi, connecté à votre propre compte — cette extension ne vous demande jamais votre mot de passe sudemi.';
$_['text_connected'] = 'Connecté à sudemi.';
$_['text_not_confirmed'] = 'Pas encore confirmé. Ouvrez le lien, confirmez la boutique sur sudemi, puis vérifiez à nouveau.';
$_['text_disconnected'] = 'Déconnecté.';
$_['text_synced'] = 'Le catalogue a été envoyé.';
$_['text_sync_failed'] = 'Le catalogue n’a pas pu être envoyé. Aucun produit n’a été retiré.';
$_['text_locked'] = 'Votre raison sociale, votre pays, votre statut de professionnel et votre numéro de TVA déterminent ce qui est dû à l’acheteur ; ils sont donc confirmés une seule fois sur sudemi et modifiés là-bas. Cette extension ne peut pas les modifier.';
$_['text_shop_url_fixed'] = 'Fixe. sudemi a vérifié que cette boutique répondait à cette adresse, et chaque annonce indique à l’acheteur d’où elle provient — la modifier implique donc de vous reconnecter depuis la nouvelle adresse.';
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
// admin — products stop being shown when the heartbeat stops arriving, and from this side
// everything looks fine — so they say what happened and what to do about it.
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
$_['text_status_awaiting_seller'] = 'En attente de votre réponse — acheteur non débité';
$_['text_status_accepted'] = 'Acceptée — en attente des autres boutiques du paiement de l’acheteur ; acheteur pas encore débité, n’expédiez pas';
$_['text_status_paid'] = 'Confirmée et débitée';
$_['text_status_declined'] = 'Refusée — rien n’a été débité';
$_['text_status_shipped'] = 'Expédiée';
$_['text_status_delivered'] = 'Livrée';
$_['text_status_cancelled'] = 'Annulée';
$_['text_status_refunded'] = 'Remboursée';
$_['text_history_awaiting'] = 'Commandes en attente de confirmation : %d. Ces acheteurs n’ont encore rien payé.';
$_['text_options_skipped'] = '%d produit(s) avec une option obligatoire (par exemple une taille à choisir) ne sont pas proposés sur sudemi : une commande passée par l’assistant IA d’un acheteur ne contient aucune option, vous ne sauriez donc pas quel article envoyer. Proposez-les plutôt comme variantes OpenCart (chaque variante est un produit à part entière).';
