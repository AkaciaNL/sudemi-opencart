<?php
// Heading
$_['heading_title'] = 'sudemi Connector';

// Text
$_['text_extension'] = 'Extensions';
$_['text_success'] = 'The settings were saved.';
$_['text_edit'] = 'Connect this shop to sudemi';
$_['text_intro'] = 'Connecting this shop lets an AI assistant find your products and send buyers to you. You confirm the connection on sudemi, signed in to your own account - this extension never asks for your sudemi password.';
$_['text_connected'] = 'Connected to sudemi.';
$_['text_not_confirmed'] = 'Not confirmed yet. Open the link, confirm the shop on sudemi, then check again.';
$_['text_disconnected'] = 'Disconnected.';
$_['text_sync_started'] = 'The catalogue sync has started and runs in the background; the catalogue is sent within a few minutes.';
$_['text_synced'] = 'The catalogue was sent.';
$_['text_sync_failed'] = 'The catalogue could not be sent. Nothing was delisted.';
$_['text_sync_held'] = 'The catalogue sync is on hold: it would remove many listed products from sudemi, so nothing was changed. If those products really were removed from this shop, contact sudemi support (support@sudemi.com).';
$_['text_locked'] = 'Your legal name, country, trader status and VAT number decide what a buyer is owed, so they are confirmed once on sudemi and changed there. This extension cannot edit them.';
$_['text_shop_url_fixed'] = 'Fixed. sudemi checked that this shop answered from this address, and a listing tells a buyer where it came from - so changing it means connecting again from the new address.';
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
// admin - products stop being shown when the heartbeat stops arriving, and from this side
// everything looks fine - so they say what happened and what to do about it.
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

$_['text_delivery_title'] = 'Delivery to sudemi buyers';
$_['text_delivery_preview'] = 'Shipping prices sent to sudemi (from your shipping methods; one item, your first product):';
$_['text_delivery_skipped'] = 'Countries without a fixed price (other extensions, free shipping with a minimum, geo zones covering only part of a country) are left out rather than guessed.';
$_['text_delivery_none'] = 'No shipping price could be read from your shipping methods, so sudemi buyers cannot order. Enable Flat rate, Per item, Weight based or unconditional Free shipping for the geo zones you ship to.';
$_['entry_handling_days'] = 'Days until you dispatch an order (at most)';
$_['entry_transit_days'] = 'Days the carrier takes (at most)';
$_['button_delivery'] = 'Save delivery times';
$_['text_delivery_help'] = 'Buyers see the sum as the latest delivery estimate. Set your real times; the defaults are deliberately cautious. The catalogue is sent again with the next sync.';
$_['text_delivery_saved'] = 'Delivery times saved.';

// Order history on this page
$_['text_history_title'] = 'Orders through sudemi';
$_['text_history_error'] = 'Could not reach sudemi to load the order history just now. Nothing is wrong with the orders themselves; try again shortly.';
$_['text_history_empty'] = 'No orders through sudemi yet.';
$_['column_order'] = 'Order';
$_['column_items'] = 'What sold';
$_['column_status'] = 'Status';
$_['column_net'] = 'You receive';
$_['column_customer'] = 'Customer';
$_['text_status_awaiting_seller'] = 'Waiting for you - buyer not charged';
$_['text_status_accepted'] = 'Accepted - waiting for the other shops in the buyer\'s payment; buyer not charged yet, do not ship';
$_['text_status_paid'] = 'Confirmed and charged';
$_['text_status_declined'] = 'Declined - nothing charged';
$_['text_status_shipped'] = 'Shipped';
$_['text_status_delivered'] = 'Delivered';
$_['text_status_cancelled'] = 'Cancelled';
$_['text_status_refunded'] = 'Refunded';
$_['text_history_awaiting'] = 'Orders waiting to be confirmed: %d. Those buyers have not been charged yet.';
$_['text_options_skipped'] = '%d product(s) with a required option (for example a size to choose) are not offered on sudemi: an order from a buyer\'s AI assistant carries no option, so you could not tell which item to send. Offer them as OpenCart variants (each variant is its own product) instead.';

// Product form: right of withdrawal (Directive 2011/83/EU, art. 16)
$_['entry_withdrawal'] = 'Right of withdrawal';
$_['text_withdrawal_none'] = 'None - normal right of withdrawal applies';
$_['help_withdrawal'] = 'Only for products that legally fall under an exception. Buyers see it before paying; misuse is not allowed.';
$_['text_withdrawal_hygiene'] = 'Sealed for health or hygiene reasons (not returnable once unsealed)';
$_['text_withdrawal_custom'] = 'Made to the buyer’s specifications or personalised';
$_['text_withdrawal_perishable'] = 'Perishable or quickly expiring';
$_['text_withdrawal_media'] = 'Sealed audio, video or software (not returnable once unsealed)';
$_['text_withdrawal_press'] = 'Newspaper, periodical or magazine';
$_['text_withdrawal_mixed'] = 'Inseparably mixed with other items after delivery';
$_['text_withdrawal_alcohol'] = 'Alcohol with a market-dependent price, delivered after 30+ days';

// Support: tickets between this shop and sudemi support (sudemi sends the e-mails, not this extension)
$_['text_support_title'] = 'Support';
$_['text_support_not_connected'] = 'Connect this shop to sudemi first; then you can contact sudemi support from here.';
$_['text_support_intro'] = 'Questions about orders, payments, payouts or this extension go to sudemi support. Answers appear here and are also e-mailed to the address of your sudemi account.';
$_['text_support_tickets'] = 'Your tickets';
$_['text_support_empty'] = 'You have not opened any support tickets yet.';
$_['text_support_new'] = 'Open a ticket';
$_['text_support_email'] = 'sudemi e-mails you when support answers. This extension sends no e-mail itself.';
$_['text_support_opened'] = 'Ticket %s opened. sudemi support will get back to you.';
$_['text_support_replied'] = 'Your reply was sent.';
$_['text_support_reply_reopens'] = 'Replying reopens this closed ticket.';
$_['text_support_from_support'] = 'sudemi support';
$_['text_support_from_shop'] = 'You (this shop)';
$_['text_support_status_open'] = 'Open';
$_['text_support_status_waiting_for_customer'] = 'Waiting for your reply';
$_['text_support_status_answered'] = 'Answered';
$_['text_support_status_closed'] = 'Closed';
$_['text_support_category_order'] = 'An order';
$_['text_support_category_payment'] = 'A payment';
$_['text_support_category_refund_return'] = 'A refund or return';
$_['text_support_category_account'] = 'Your sudemi account';
$_['text_support_category_shop_plugin'] = 'This shop or extension';
$_['text_support_category_payouts'] = 'Payouts';
$_['text_support_category_other'] = 'Something else';
$_['column_support_number'] = 'Ticket';
$_['column_support_subject'] = 'Subject';
$_['column_support_status'] = 'Status';
$_['column_support_updated'] = 'Last updated';
$_['entry_support_category'] = 'Topic';
$_['entry_support_subject'] = 'Subject';
$_['entry_support_message'] = 'Message';
$_['entry_support_order'] = 'sudemi order ID (optional)';
$_['help_support_order'] = 'For example 3f2a9c1e-…; leave empty if it is not about one order';
$_['entry_support_reply'] = 'Your reply';
$_['button_support_open'] = 'Open ticket';
$_['button_support_reply'] = 'Send reply';
$_['button_support_back'] = 'Back to all tickets';
$_['error_support_invalid'] = 'Please check the form: the subject needs 3 to 200 characters, the message up to 10,000, and an order ID must be one of this shop\'s sudemi orders.';
$_['error_support_not_found'] = 'That ticket was not found for this shop.';
$_['error_support_too_many'] = 'Too many tickets at once: at most 20 open tickets, and at most 10 new ones per hour. Reply to an existing ticket or try again later.';
$_['error_support_unavailable'] = 'sudemi support could not be reached just now. Nothing was sent; please try again shortly.';

// sudemi refused this site as not being the connected shop (409 site_mismatch): a staging copy,
// a restored backup, or the shop moved. %s is the address the shop is connected under.
$_['text_site_mismatch_at'] = 'sudemi refused requests from this site: this shop is connected to sudemi under a different address (%s). If this site is a staging copy or a restored backup, disconnect sudemi here. If the shop has moved to this address, connect it again.';
$_['text_site_mismatch'] = 'sudemi refused requests from this site: this shop is connected to sudemi under a different address. If this site is a staging copy or a restored backup, disconnect sudemi here. If the shop has moved to this address, connect it again.';

// Return requests from buyers (answered within 7 days: accept, or reject with a reason the buyer
// sees). The notes go into the order history; %s is the reason given.
$_['text_requests_title'] = 'Return requests';
$_['text_requests_intro'] = 'Buyers asked to return these delivered orders. Answer each one by the date shown: accept it, or reject it with a reason (the buyer sees it). A request left unanswered goes to sudemi after 7 days. The list is updated with every report to sudemi.';
$_['text_requests_empty'] = 'No open return requests.';
$_['column_request_order'] = 'Order';
$_['column_request_reason'] = 'Buyer\'s reason';
$_['column_request_requested'] = 'Requested';
$_['column_request_respond_by'] = 'Answer by';
$_['column_request_answer'] = 'Your answer';
$_['text_request_no_local'] = 'Not found in this shop';
$_['text_request_no_reason'] = 'No reason given';
$_['text_request_overdue'] = 'Overdue';
$_['entry_request_reject_reason'] = 'Reason for rejecting (required, shown to the buyer)';
$_['button_request_accept'] = 'Accept return';
$_['button_request_reject'] = 'Reject return';
$_['text_request_accepted'] = 'Return accepted. The buyer now sends the item back. Once it has arrived, refund the order by setting its status to Refunded: the refund goes to the buyer through sudemi and closes the request.';
$_['text_request_rejected'] = 'Return rejected. The buyer is shown your reason.';
$_['text_request_already'] = 'This request was already answered. The list has been updated.';
$_['error_request_not_found'] = 'There is no open return request on this order any more. The list has been updated.';
$_['error_request_reason'] = 'Rejecting a return needs a reason; the buyer is shown it.';
$_['error_request_unavailable'] = 'sudemi could not be reached just now. Nothing was answered; please try again shortly.';
$_['text_request_note_accepted'] = 'sudemi: return request accepted. Refund the order (status Refunded) once the item is back.';
$_['text_request_note_rejected'] = 'sudemi: return request rejected. Reason shown to the buyer: %s';
$_['text_history_return_requested'] = 'Return requested';
// The totals of a sudemi order (order page, invoice), written in the store's language.
$_['text_order_sub_total'] = 'Sub-Total';
$_['text_order_shipping'] = 'sudemi shipping';
$_['text_order_total'] = 'Total';
