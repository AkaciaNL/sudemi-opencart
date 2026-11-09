# Changelog

## 1.3.0

Orders and shipping managed from the OpenCart admin (owner, 2026-11-09). The sudemi portal stays the
source of truth: every page reads from sudemi, every action is sent to sudemi, and the local order
follows what sudemi answers. Needs sudemi core 2026-11-09 (PROTOCOL.md section 4d); with an older
core the extension works as 1.2 did.

- **sudemi orders page** (menu sudemi > sudemi orders): the portal's order table with its filters
  (status, dates, order number or product, sort), paging, the CSV export (the portal's columns, in
  the admin's language, decimal point or comma), and bulk actions: accept, decline with a reason,
  shipped (a carrier, a tracking code per order) and delivered, at most 100 orders at a time.
- **Order page**: lines (linked to the product), money (goods, shipping, commission, what you
  receive, refunded), tracking, the history (placed, accepted, paid, shipped, delivered, refunds,
  buyer requests), the payout (state, what you receive, paid out, due date), an open return or
  cancellation request with accept and reject, and the actions sudemi allows now: accept, decline,
  ship with carrier and tracking code, delivered, **part refunds**, and cancel with a full refund.
  Refunds and cancellations carry an idempotency key, so a retry never pays the buyer back twice.
  A link goes to the order in this shop.
- **Accepting by hand**: "Confirm new orders automatically" can be switched off. New orders then
  wait under "Waiting for your answer" until you accept (the order is booked here and the buyer
  charged) or decline them with a reason. On (the default) works as before.
- **Orders changed anywhere reach this shop** (the heartbeat's change feed): shipped, delivered,
  refunded, cancelled, tracking codes and buyer requests set on the portal, by the seller API, by
  sudemi support or by a deadline are applied to the local order (status and a note in its
  history), once, by revision. An order accepted on the portal is booked here. A daily check of the
  orders since yesterday catches anything missed.
- **Tracking code on the order**: OpenCart's order page has a sudemi panel with a carrier and
  tracking code field. Setting the order to Shipped sends them (before, only the shipping method's
  name was sent as the carrier).
- **sudemi shipping page**: ship to the shop's own prices, or choose countries and a price per
  country on sudemi; "Fill in from my shop" takes the countries and prices from your geo zones and
  shipping methods; the page shows what buyers pay now per country.
- **Payouts panel** on the orders page: the payout account's state and, per currency, paid out,
  still to come, waiting for shipment, scheduled (with the next date), on its way and held.
- A Refunded or Canceled set by hand on an order already refunded or cancelled on sudemi no longer
  asks sudemi for the money again.
- All new texts in the 12 admin languages. The extension still sends no e-mail.

## 1.2.13

- **Shipping VAT is booked separately.** The shipping on a sudemi order is now booked net, with its
  VAT in the order's tax row at the shipping method's tax class, as OpenCart's own checkout does.
  Before, the shipping kept its VAT inside and the order had no tax row for it.

## 1.2.12

- **Orders are booked in the currency the buyer paid in.** A sudemi order carries the shop's
  currency (the buyer's card is charged in it). The order is now written with that currency
  (its OpenCart currency id and rate) instead of currency id 0. An order in a currency that is
  not this shop's default currency, or that is switched off in the shop, is declined with the
  reason ("this shop sells in PLN, not in EUR") and an error line in the extension log, instead of
  being booked in the wrong currency. Nothing is charged for a declined order.
- **Amounts use each currency's own decimals.** Prices, shipping costs and order amounts were
  converted with a fixed x 100; they now follow ISO 4217 (two decimals for EUR, PLN, HUF, TRY;
  none for JPY; three for KWD).
- **Refunds name their currency.** A refund sent to sudemi carries the order's currency. A refund
  in another currency than the one the buyer paid in is refused here, logged, and not sent.
- **The settings page shows the shop currency sudemi sees**, that buyers pay in it, and for a
  currency other than EUR that Stripe's conversion fee (about 2%) is taken from the payout (which
  is in EUR). A warning shows when sudemi cannot take payments in the shop's default currency
  (supported: EUR and every ECB currency except ISK) or still has an older one.
- The order list on the settings page shows what the buyer paid next to what you receive, both in
  the currency charged and with that currency's decimals. The delivery cost preview too.

## 1.2.11

- **A confirmed connection is collected by cron.** While a connection is started and not yet
  collected, every run of the extension's cron URL (and, for shops without cron, opening the
  extension page, after the page was sent) asks sudemi once whether the shop owner has confirmed
  it: at most once a minute for the first half hour, then every twenty minutes (within the 100
  polls a pairing allows), and never sooner than a 429 Retry-After says. When it has, the extension
  stores the connection exactly as the "Check again" button does, and the same cron run sends the
  first heartbeat and the catalogue. Before, the shop only connected when someone pressed "Check
  again".
- A connection attempt older than 24 hours (the pairing lifetime) is given up, with one line in
  the extension log; start it again.
- **The cron URL stays the same after Disconnect and a new Connect.** Before, Disconnect deleted the
  cron token and the extension page made a new one, so a crontab line set up earlier got "no"
  (403) and the shop went quiet. The token is only the secret that lets the crontab trigger a run
  (which does nothing while the shop is not connected), so it is kept. "Connect" also makes sure it
  exists, so the cron URL works for a freshly started connection.
- "Check again" says the shop is connected when cron collected the connection a moment earlier.
- **Names and descriptions are sent as text.** OpenCart stores what is typed in its admin
  HTML-escaped, and the extension sent it like that: the seller portal and buyers saw "Laptops
  &amp;amp; Notebooks", and a description kept its tags because they were stored as "&amp;lt;p&amp;gt;".
  Product titles, descriptions, brands, attributes, category names and descriptions, the shop's
  name and description (sync and the draft offered when connecting) are now decoded first, then
  tags removed from descriptions. What is already on sudemi is corrected by the next full sync.

## 1.2.10

- **A sudemi order is booked at what the buyer paid.** Each order line gets the price the buyer
  paid on sudemi (not the shop's current price, which may have changed since the last sync), and
  the order gets OpenCart's totals rows: sub-total, the shipping the buyer paid ("sudemi shipping"),
  the VAT per tax rate and the total. Before, the lines had no price and the order had no totals
  rows, so the order page and the shop's books showed no shipping. The total is exactly what the
  buyer paid; the payment method is sudemi.
- The VAT is taken out of the paid prices at the shop's own percentage rates for the destination
  country (the same rule the sync uses to put it into the price), never added on top. The shipping
  is booked as paid.
- The totals rows are written in the store's language (12 languages).
- With a sudemi core that does not send the paid amounts yet, orders are written as before.
- PROTOCOL.md: current copy (shipping_minor and unit_price_minor on pending orders).

## 1.2.9

- **No full catalogue sync inside any page request.** In 1.2.8 the extension page's "Send the
  catalogue now" button still ran the whole sync inside that request. It now only asks for it
  ("The catalogue sync has started and runs in the background", in all 12 languages); the cron URL
  sends it, or without cron the small steps after the extension page is opened. Saving the
  delivery times now also asks for a sync (every product carries the times). Storefront pages
  never ran or start a full sync.
- PROTOCOL.md: current copy (a full sync never runs inside a page request).

## 1.2.8

- **A full catalogue sync never runs inside an admin page load any more.** Before, the heartbeat
  sent when the extension page was opened (the fallback for shops without a cron entry) ran the
  whole sync inline when sudemi asked for one, which on a large catalogue held the admin's screen
  for minutes. Now the heartbeat only asks for the sync. The extension's cron URL runs it to the
  end; opening the extension page sends at most a small step (25 products, about two seconds) after
  the page was produced (after it was sent, on PHP-FPM), and later page loads continue where it
  stopped. A database lock keeps two requests from sending the sync at once; a sync stopped
  halfway resumes where it stopped; one older than 5.5 hours starts again. "Send the catalogue
  now" still sends it at once.
- Editing a master product with variants, or one of its variants, sends the whole family (the
  master and every variant, each its own listing) in one push, with the shop's catalogue revision
  computed after the save (after OpenCart updated the variants), so such an edit no longer costs a
  full sync.
- The revision is sent only when no price change is queued, the category tree sudemi holds is
  current, every request of the push was accepted whole, and no listing removed in the shop (a
  product or variant deleted, disabled or given a required option) is still waiting for a full sync
  to take it off sudemi. Before, editing another product after such a removal could make sudemi
  agree with the shop while the removed listing stayed listed.
- Product changes are pushed once per product family at the end of the request, instead of once
  per event.

## 1.2.7 - 2026-11-08
- The full sync's commit carries the catalogue revision the shop had when the sync started. sudemi adopts it, so a product changed or disabled while the sync ran is caught by the next heartbeat and synced. The heartbeat right after the commit is still sent.
- Saving a product (and a stock change from an order) sends the product's whole listing (title, description, price, stock, categories, images, shipping) instead of price and stock only, with the shop's catalogue revision computed after the save when nothing else is waiting to be sent. sudemi adopts it when every item applied, so a product edit no longer costs a full catalogue sync. A master product with variants and a push while queued price changes wait go without the revision; a disabled product or one with required options is still sent as a price update without it (a full sync follows, as before). When the category tree changed since it was last sent, it is sent first; if that fails, the push goes without the revision.
- A newer push for a product drops its older queued price, which could otherwise be sent after it and put the old price back.

## 1.2.6 - 2026-11-07
- After a full catalogue sync the extension tells sudemi at once which catalogue state the sync described (a heartbeat with the revision taken when the sync started). Before, sudemi adopted whatever the next heartbeat reported, so a product disabled in the minutes after a sync could stay listed on sudemi.
- Order safety: an order is only voided when sudemi gives a definitive answer. Before, any answer to the confirmation other than "ok" (sudemi briefly unavailable, 429, 409 site_mismatch, or "already answered" after a lost reply) put the stock back and left the order unconfirmed, although the buyer could already have been charged, so a paid order was never shipped. Now: "already answered, PAID" confirms the order and fetches the address; "already answered, DECLINED" (or CANCELLED) releases it and puts the stock back once; anything else leaves the order and its stock as they are and asks again on the next heartbeat.
- Every heartbeat re-asks sudemi about local orders still unconfirmed that the beat did not mention (up to 25, orders up to 14 days old), so an order whose answer was lost is confirmed or released instead of waiting forever with its stock reserved. After three days it asks with a rejection, which returns the final state (also for a suspended shop).
- A voided order, or one the merchant set to Canceled or Refunded, is answered with a rejection when sudemi asks about it again. Before, it was accepted again: the buyer was charged while the stock had already been put back (overselling).
- Setting Canceled on a sudemi order that is not paid yet (accepted and waiting for the other shops in the same payment) now rejects it on sudemi, so the buyer is not charged later; a paid order is still cancelled with a full refund. sudemi decides which applies.
- Shipped and delivered reports that do not get through (sudemi unreachable, 5xx, 429, 409, or the order not paid yet) are no longer logged as reported: they are queued and sent again on every heartbeat for up to 14 days.
- The carrier sent with Shipped is the shipping method's name, not OpenCart 4's JSON (`{"name": ..., "code": ...}`). The sudemi order id is read correctly from a released order's comment.
- Queued price updates: a newer price saved while an older one was being sent stays queued (it was dropped), and a batch sudemi refuses outright (400, 403, 413, 422) is dropped instead of being resent forever; a full sync then sends the prices again.
- A full sync that sudemi holds (it would remove many listed products) is reported as on hold, with what to do, instead of as synced (12 languages).
- Product texts with invalid UTF-8 no longer make a whole call fail; the bad bytes are replaced.

## 1.2.5 - 2026-11-07
- Return requests: after delivery a buyer can ask on sudemi to return an order, and the shop answers within 7 days. The open requests arrive with every heartbeat (the full list each time, so the stored copy is replaced and cleared when there are none) and are listed on the extension page: the local order (linked to its OpenCart order page), the buyer's reason, the answer-by date (marked when overdue), and **Accept return** / **Reject return** with a required reason the buyer is shown. Answers are signed calls to sudemi, need the modify permission and the admin token, and add a note to the order history. After accepting, refund the order once the item is back by setting its status to Refunded (the existing refund path); that refund closes the request. The order history on the page marks orders with an open return request. All 12 languages, escaped like the rest of the page.
- A buyer who cancels a paid order on sudemi before it ships (sudemi refunds the buyer in full) now cancels the local order too: on the next heartbeat it moves to Canceled, its stock is restored, and the history says "Cancelled by the buyer on sudemi before shipping; the buyer has been refunded in full". Before, only an order still on hold was touched, so a paid order stayed Pending/Processing and could still be shipped. The extension does not send sudemi a cancel or refund for this change, also not when Canceled or Refunded is set on such an order later. Orders already shipped, complete, cancelled or refunded are left alone.

## 1.2.4 - 2026-11-07
- Every signed call now says which site it comes from: the `Sudemi-Site` header, the storefront's base URL (the shop address pairing announced in the admin, `HTTP_CATALOG`/`HTTP_SERVER` elsewhere). It is not part of the signature. A staging copy or a restored backup of the shop holds the same install id and key, and sudemi now refuses its calls (409 `site_mismatch`), so a copy can no longer push its catalogue or answer orders as the live shop.
- When sudemi refuses this site because the shop is connected under another address, the extension page shows it as not connected and says what to do: if this site is a staging copy or a restored backup, disconnect sudemi there; if the shop moved, connect it again. The support section says the same instead of a generic error. All 12 languages, escaped like the rest of the page.

## 1.2.3 - 2026-11-07
- Security: the extension page is HTML-escaped explicitly (OpenCart 4 turns Twig's autoescaping off). Before, a support message or ticket subject containing HTML was rendered as HTML in the shop's back office.

## 1.2.2

- New **Support** section on the extension page: the shop owner opens a ticket with sudemi support
  (topic, subject, message, optional sudemi order ID), sees this shop's tickets with their status,
  reads each conversation and replies. Calls are signed `/integration/v1/support/tickets` requests
  with the shop's key, like every other call; opening and replying need the modify permission and
  the admin token. The admin's language is sent so sudemi answers in it (12 languages).
- sudemi e-mails the shop owner's sudemi account when support answers or closes a ticket; this
  extension sends no e-mail itself. Too many tickets, a wrong order ID or an unknown ticket show a
  short message in the admin's language. Only available while the shop is connected.

## 1.2.1

- New per-product setting on the product form (Data tab): **Right of withdrawal**. For products
  that legally fall under an exception of the EU right of withdrawal (Directive 2011/83/EU,
  art. 16): sealed for hygiene reasons, made to the buyer's specifications or personalised,
  perishable, sealed audio/video/software, newspapers and magazines, inseparably mixed after
  delivery, or alcohol with a market-dependent price delivered after 30+ days. sudemi shows the
  exception to the buyer before payment. Default: none (the normal right of withdrawal applies).
  Variants follow their master product. A change re-sends the catalogue on the next heartbeat.
- Stored in a new table `oc_sudemi_product`. Shops that replace the extension files without
  reinstalling are upgraded automatically (table and product-form event) on the next settings
  page visit, product save or cron beat.
- The version sent to sudemi (`pluginVersion`) is now the extension's real version.

## 1.2.0

- Refunded / Canceled set in the admin reach sudemi; specials and single-item discounts are sent
  as the price; products with a required option are not offered; order history on the extension
  page.
