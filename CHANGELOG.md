# Changelog

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
