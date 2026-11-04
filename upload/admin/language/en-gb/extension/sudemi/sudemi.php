<?php
// Heading
$_['heading_title'] = 'sudemi Connector';

// Text
$_['text_extension'] = 'Extensions';
$_['text_success'] = 'The settings were saved.';
$_['text_edit'] = 'Connect this shop to sudemi';
$_['text_intro'] = 'Connecting this shop lets an AI assistant find your products and send buyers to you. You confirm the connection on sudemi, signed in to your own account — this extension never asks for your sudemi password.';
$_['text_connected'] = 'Connected to sudemi.';
$_['text_not_confirmed'] = 'Not confirmed yet. Open the link, confirm the shop on sudemi, then check again.';
$_['text_disconnected'] = 'Disconnected.';
$_['text_synced'] = 'The catalogue was sent.';
$_['text_sync_failed'] = 'The catalogue could not be sent. Nothing was delisted.';
$_['text_locked'] = 'Your legal name, country, trader status and VAT number decide what a buyer is owed, so they are confirmed once on sudemi and changed there. This extension cannot edit them.';
$_['text_shop_url_fixed'] = 'Fixed. sudemi checked that this shop answered from this address, and a listing tells a buyer where it came from — so changing it means connecting again from the new address.';
$_['text_key_held'] = 'The private half of the signing key never leaves this server and is never sent to sudemi. It cannot be shown here either.';

// Entry
$_['entry_shop_url'] = 'Shop address';
$_['entry_install'] = 'Install';
$_['entry_key'] = 'Signing key';

// Button
$_['button_connect'] = 'Connect this shop';
$_['button_confirm'] = 'Confirm this shop on sudemi';
$_['button_check'] = 'Check again';
$_['button_sync'] = 'Send the catalogue now';
$_['button_disconnect'] = 'Disconnect';

// Error
$_['error_permission'] = 'You do not have permission to change this.';
$_['error_token'] = 'That request could not be verified. Try again from this page.';
$_['error_sodium'] = 'This server is missing the sodium extension. The connection needs it to sign requests and will not work without it. Ask your host to enable it.';

// The listing-status panel. These describe a failure the shop owner cannot see from their own
// admin — products stop being shown when the heartbeat stops arriving, and from this side
// everything looks fine — so they say what happened and what to do about it.
$_['text_listing_status'] = 'Listing status';
$_['text_never_reported'] = 'This shop has not reported in to sudemi yet. Add the cron entry below; until it runs, reports are only sent while this page is open.';
$_['text_unreachable'] = 'This shop could not reach sudemi. Its products stay listed for a short while and are then paused, because sudemi will not quote a price it cannot confirm.';
$_['text_listed'] = 'Products are being shown on sudemi.';
$_['entry_pending'] = 'Price changes waiting';
$_['text_pending'] = 'Price or stock changes that could not be sent yet. They are retried on the next report and are not lost.';
$_['entry_trusted_until'] = 'Prices confirmed until';
$_['text_trusted_until'] = 'After this, sudemi stops showing this shop until it reports in again. Nothing is deleted.';
$_['entry_cron'] = 'Cron entry';
$_['text_cron'] = 'Add this to the crontab of the account that runs this shop. It tells sudemi every five minutes that this shop is still here and that its prices still stand, and it sends any price change that did not get through the first time. The link contains a secret; treat it like a password.';
