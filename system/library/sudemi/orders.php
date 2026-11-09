<?php
namespace Sudemi;

require_once __DIR__ . '/currency.php';
require_once __DIR__ . '/shipping.php';

/**
 * Answering sudemi's question: can this shop actually fulfil the order?
 *
 * Nobody but this shop can answer it. sudemi holds no stock - it carries a figure this extension
 * pushed, which was true when it was pushed and may not be now, because the same units are on
 * sale in this storefront at the same time. So sudemi does not decide. It authorises the buyer's
 * card, holds the money without taking it, and asks.
 *
 * Until this extension answers, nothing has been charged. Yes captures the money and splits it;
 * no releases the hold; saying nothing releases it too once the window closes. Silence is never
 * taken as agreement - a shop that has stopped answering is usually a shop that is gone, and
 * charging a buyer because nobody objected is the failure this mechanism exists to prevent.
 */
class Orders
{
    /** Written on the order so one sudemi order can never produce two local ones. */
    const COMMENT_PREFIX = 'sudemi:';

    /** OpenCart order_status_id 0 means "missing" - an order not yet confirmed to anyone. */
    const STATE_INCOMPLETE = 0;

    /** OpenCart's built-in Shipped, Canceled and Refunded statuses. */
    const STATUS_SHIPPED = 3;
    const STATUS_CANCELED = 7;
    const STATUS_REFUNDED = 11;

    /** The history note on a local order cancelled because the buyer cancelled it on sudemi. */
    const NOTE_CANCELLED_BY_BUYER = 'Cancelled by the buyer on sudemi before shipping; the buyer has been refunded in full';

    /** Shipped/delivered (and shop cancellations) that did not get through, retried each beat. */
    const SETTING_CALLS = 'sudemi_order_calls';
    const CALLS_MAX = 500;
    const CALLS_PER_BEAT = 25;
    const CALLS_MAX_AGE = 1209600; // 14 days

    /** Unconfirmed local orders re-asked per beat, and when to stop accepting and ask with reject. */
    const RECONCILE_PER_BEAT = 25;
    const RECONCILE_REJECT_AFTER = 259200; // 3 days: sudemi's answer window is about two
    const RECONCILE_MAX_AGE_DAYS = 14;

    const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    private $db;
    private $config;
    private $client;
    private $logger;
    private $prefix;
    private $store;

    public function __construct($db, $config, Client $client, Logger $logger, $store = null)
    {
        $this->db = $db;
        $this->config = $config;
        $this->client = $client;
        $this->logger = $logger;
        $this->prefix = DB_PREFIX;
        $this->store = $store;
    }

    /**
     * What an answer to /accept means for the local order.
     *
     * Only a definitive answer decides. 'paid' (captured now, or an earlier accept landed),
     * 'waiting' (accepted, the money is taken once the other shops in the payment answer),
     * 'declined' (released, nothing charged), 'cancelled' (cancelled on sudemi, any payment
     * refunded), or 'retry': unreachable, 5xx, 429, 401, 403, any 409 (site_mismatch,
     * authorisation_lapsed) or an answer without a known order_status. A retry never cancels or
     * restocks anything: the buyer may already have been charged.
     *
     * @param array|null $response as Client returns it
     *
     * @return string paid|waiting|declined|cancelled|retry
     */
    public static function acceptOutcome($response)
    {
        $body = self::bodyOf($response);
        if ($body === null) {
            return 'retry';
        }
        if ((int) $response['status'] === 200 && isset($body['status']) && $body['status'] === 'ok') {
            return isset($body['payment']) && $body['payment'] === 'waiting_for_other_shops' ? 'waiting' : 'paid';
        }

        return self::resolvedOutcome($response, $body);
    }

    /** The same for an answer to /reject: ok means released, nothing charged. */
    public static function rejectOutcome($response)
    {
        $body = self::bodyOf($response);
        if ($body === null) {
            return 'retry';
        }
        if ((int) $response['status'] === 200 && isset($body['status']) && $body['status'] === 'ok') {
            return 'declined';
        }

        return self::resolvedOutcome($response, $body);
    }

    private static function bodyOf($response)
    {
        if (!is_array($response) || !isset($response['status'])) {
            return null;
        }

        return isset($response['body']) && is_array($response['body']) ? $response['body'] : array();
    }

    private static function resolvedOutcome($response, array $body)
    {
        $status = (int) $response['status'];
        if ($status === 404 && isset($body['error']) && $body['error'] === 'no_such_order') {
            // No such order for this shop at all: nothing was ever charged for it here.
            return 'declined';
        }
        if ($status !== 200 || !isset($body['status']) || $body['status'] !== 'already_resolved') {
            return 'retry';
        }
        $orderStatus = isset($body['order_status']) ? (string) $body['order_status'] : '';
        if (in_array($orderStatus, array('PAID', 'SHIPPED', 'DELIVERED'), true)) {
            return 'paid';
        }
        if ($orderStatus === 'ACCEPTED') {
            return 'waiting';
        }
        if ($orderStatus === 'DECLINED') {
            return 'declined';
        }
        if ($orderStatus === 'CANCELLED' || $orderStatus === 'REFUNDED') {
            return 'cancelled';
        }

        return 'retry';
    }

    /** "HTTP 503 (message)" or "unreachable", for log lines. */
    private static function describe($response)
    {
        if (!is_array($response) || !isset($response['status'])) {
            return 'sudemi could not be reached';
        }
        $body = isset($response['body']) && is_array($response['body']) ? $response['body'] : array();
        $what = isset($body['error']) ? (string) $body['error'] : (isset($body['status']) ? (string) $body['status'] : '');
        if (isset($body['order_status'])) {
            $what .= ' ' . (string) $body['order_status'];
        }

        return 'HTTP ' . (int) $response['status'] . ($what !== '' ? ' ' . trim($what) : '');
    }

    /**
     * Works through what arrived on the heartbeat.
     *
     * One order at a time, and a failure on one does not stop the rest: each is a buyer whose
     * money is on hold against a deadline.
     *
     * @param array $pending The pending_orders block of a heartbeat response
     */
    public function handlePending($pending)
    {
        if (empty($pending) || !is_array($pending)) {
            return;
        }
        foreach ($pending as $order) {
            if (empty($order['order_id'])) {
                continue;
            }
            try {
                $this->answer($order);
            } catch (\Exception $e) {
                $this->logger->error(sprintf(
                    'Could not answer sudemi order %s: %s',
                    $order['order_id'],
                    $e->getMessage()
                ));
            }
        }
    }

    /**
     * Decides one order and tells sudemi.
     *
     * The local order is recorded *before* the acceptance is sent. The other way round would
     * leave a buyer charged for something this shop has no record of; this way a refused
     * acceptance leaves a local order with no money behind it, which is recoverable here.
     */
    private function answer(array $pending)
    {
        $sudemiId = (string) $pending['order_id'];

        $local = $this->localRow($sudemiId);
        if ($local !== null) {
            if ($local['released'] || in_array($local['status'], array(self::STATUS_CANCELED, self::STATUS_REFUNDED), true)) {
                // Voided here (released, or cancelled by the merchant). Accepting it now would
                // charge the buyer for goods whose stock was already put back: answer no instead.
                $this->rejectLocal($sudemiId, $local, 'cancelled in the shop');

                return;
            }
            // A previous run got this far and the answer was lost in transit. Re-sending is safe:
            // sudemi answers already_resolved with how the order ended if it landed the first time.
            $this->accept($sudemiId, $local['id']);

            return;
        }

        // The currency first: an order this shop cannot book in the currency the buyer paid must
        // not become a local order in another one (a PLN order written as EUR misstates every
        // amount on it). Declined with the reason, which is not a failure of stock.
        $currencyProblem = $this->currencyProblem($pending);
        if ($currencyProblem !== null) {
            $this->client->postSigned(
                '/integration/v1/orders/' . rawurlencode($sudemiId) . '/reject',
                array('reason' => $currencyProblem)
            );
            $this->logger->error(sprintf(
                'Declined sudemi order %s: %s. Nothing was charged. Check the default currency under System > Settings > Store > Local and that it is enabled under System > Localisation > Currencies.',
                $sudemiId,
                $currencyProblem
            ));

            return;
        }

        $shortfall = $this->firstShortfall($pending);
        if ($shortfall !== null) {
            // The ordinary case, and not a failure. The buyer's hold is released within a minute.
            $this->client->postSigned(
                '/integration/v1/orders/' . rawurlencode($sudemiId) . '/reject',
                array('reason' => $shortfall)
            );
            $this->logger->info(sprintf(
                'Declined sudemi order %s: %s. Nothing was charged.',
                $sudemiId,
                $shortfall
            ));
            return;
        }

        $orderId = $this->createLocalOrder($pending, $sudemiId);
        if (!$orderId) {
            $this->client->postSigned(
                '/integration/v1/orders/' . rawurlencode($sudemiId) . '/reject',
                array('reason' => 'this shop could not record the order')
            );
            return;
        }
        $this->accept($sudemiId, $orderId);
    }

    /** Sends the acceptance and applies only a definitive answer; anything else is retried. */
    private function accept($sudemiId, $orderId)
    {
        $response = $this->client->postSigned(
            '/integration/v1/orders/' . rawurlencode($sudemiId) . '/accept',
            array()
        );
        $this->applyOutcome(self::acceptOutcome($response), $sudemiId, $orderId, $response);
    }

    /**
     * Answers no for a local order this shop voided or that never got an answer in time, and
     * applies how sudemi says the order ended.
     */
    private function rejectLocal($sudemiId, array $local, $reason)
    {
        $response = $this->client->postSigned(
            '/integration/v1/orders/' . rawurlencode($sudemiId) . '/reject',
            array('reason' => $reason)
        );
        $outcome = self::rejectOutcome($response);
        if ($outcome === 'paid' && ($local['status'] !== self::STATE_INCOMPLETE || $local['released'])) {
            // The buyer was charged (an earlier accept landed) but the order is voided here.
            $this->logger->error(sprintf(
                'sudemi order %s is paid, but local order %d is cancelled here. Ship it, or set it to Canceled to refund the buyer through sudemi.',
                $sudemiId,
                $local['id']
            ));

            return $outcome;
        }
        $this->applyOutcome($outcome, $sudemiId, $local['id'], $response);

        return $outcome;
    }

    /**
     * Applies a definitive outcome to the local order; 'retry' leaves order and stock untouched.
     */
    private function applyOutcome($outcome, $sudemiId, $orderId, $response)
    {
        $local = $this->rowById($orderId);
        if ($local === null) {
            return;
        }
        $open = $local['status'] === self::STATE_INCOMPLETE && !$local['released'];

        if ($outcome === 'paid') {
            if (!$open) {
                return;
            }
            // The address arrives only now. sudemi withholds it while the order is still being
            // decided, because answering "do I have stock" needs nobody's address.
            $this->fillCustomer($orderId, $sudemiId);
            $this->setStatus($orderId, (int) $this->config->get('config_order_status_id'));
            $this->logger->info(sprintf('Confirmed sudemi order %s as order %d.', $sudemiId, $orderId));

            return;
        }
        if ($outcome === 'waiting') {
            // Accepted, but not paid: the buyer paid several shops in one payment, and sudemi takes
            // the money once every shop has answered (settled_orders says so). Until then the order
            // stays unconfirmed and has no address.
            $this->logger->info(sprintf(
                'Accepted sudemi order %s (order %d). Not paid yet: waiting for the other shops in the '
                . 'same payment. Do NOT ship until it is confirmed.',
                $sudemiId,
                $orderId
            ));

            return;
        }
        if ($outcome === 'declined' || ($outcome === 'cancelled' && $local['status'] === self::STATE_INCOMPLETE)) {
            if ($local['released']) {
                return;
            }
            // Never charged (or refunded in full): the order must not survive, and the stock it
            // reserved goes back.
            $this->release($orderId);
            $this->logger->warn(sprintf(
                'sudemi order %s ended as %s (%s). Nothing is owed for it: stock restored, do not ship it.',
                $sudemiId,
                $outcome,
                self::describe($response)
            ));

            return;
        }
        if ($outcome === 'cancelled') {
            $this->cancelledOnSudemi($orderId, $sudemiId, $local['status']);

            return;
        }

        // Not answered (unreachable, 5xx, 429, 401, 403, 409...). The buyer may already be
        // charged, so the order and its stock stay exactly as they are; the next heartbeat asks again.
        $this->logger->warn(sprintf(
            'sudemi order %s (order %d): no definitive answer (%s). The order stays as it is; will try again on the next heartbeat.',
            $sudemiId,
            $orderId,
            self::describe($response)
        ));
    }

    /** Stock back and the order marked released, once (a released order is never restocked again). */
    private function release($orderId)
    {
        $this->restockOrder($orderId);
        $this->db->query(
            "UPDATE " . $this->prefix . "order
                SET comment = CONCAT(comment, ' (released)'), date_modified = NOW()
              WHERE order_id = '" . (int) $orderId . "'"
        );
    }

    /**
     * Re-asks sudemi about local orders still unconfirmed here that this beat did not mention.
     *
     * An accept whose answer was lost, or that got a 5xx/429, is otherwise only retried while
     * the order is still in pending_orders; once sudemi's window closes (or the money was
     * captured) it never comes back, and the order would sit here unconfirmed with its stock
     * reserved forever. Re-sending accept is safe: sudemi answers how the order ended. After
     * three days (past the answer window) it asks with reject, which a suspended shop may
     * still send and which also returns the final order_status.
     *
     * @param string[] $skip sudemi order ids this beat already handled
     */
    public function reconcile(array $skip = array())
    {
        $rows = $this->db->query(
            "SELECT order_id, comment, TIMESTAMPDIFF(SECOND, date_added, NOW()) AS age
               FROM " . $this->prefix . "order
              WHERE order_status_id = '0' AND comment LIKE 'sudemi:%'
                AND date_added > DATE_SUB(NOW(), INTERVAL " . (int) self::RECONCILE_MAX_AGE_DAYS . " DAY)
              ORDER BY order_id LIMIT 200"
        );
        $done = 0;
        foreach ($rows->rows as $row) {
            if ($done >= self::RECONCILE_PER_BEAT) {
                break;
            }
            if (!preg_match('/^' . preg_quote(self::COMMENT_PREFIX, '/') . '(' . self::UUID . ')$/', (string) $row['comment'], $m)
                || in_array($m[1], $skip, true)) {
                continue;
            }
            ++$done;
            $sudemiId = $m[1];
            $orderId = (int) $row['order_id'];
            try {
                if ((int) $row['age'] > self::RECONCILE_REJECT_AFTER) {
                    $this->rejectLocal($sudemiId, array('id' => $orderId, 'status' => self::STATE_INCOMPLETE, 'released' => false),
                        'no confirmation from sudemi in time');
                } else {
                    $this->accept($sudemiId, $orderId);
                }
            } catch (\Exception $e) {
                $this->logger->error('Could not re-check sudemi order ' . $sudemiId . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * A sudemi order cancelled in the shop: /reject while it is not paid yet (accepted and
     * waiting for other shops, or never answered), /cancel with a full refund once it is.
     * sudemi decides which applies: a reject on a paid order answers already_resolved PAID and
     * changes nothing, and only then is /cancel sent.
     *
     * @return bool whether sudemi gave a definitive answer (false: queued for the next beat)
     */
    public function cancelFromShop($sudemiId, $orderId, $reason, $queue = true)
    {
        $response = $this->client->postSigned(
            '/integration/v1/orders/' . rawurlencode($sudemiId) . '/reject',
            array('reason' => $reason)
        );
        $outcome = self::rejectOutcome($response);
        if ($outcome === 'paid') {
            $this->cancel($sudemiId, $reason);

            return true;
        }
        if ($outcome === 'retry') {
            $this->logger->warn('sudemi order ' . $sudemiId . ': the cancellation could not be sent ('
                . self::describe($response) . '). Will try again on the next heartbeat.');
            if ($queue) {
                $this->enqueueCall($sudemiId, 'cancel_shop', array('reason' => $reason, 'order' => (int) $orderId));
            }

            return false;
        }
        if ($outcome === 'declined') {
            // Never charged. OpenCart does not restock an order that never reached a processing
            // status, so the reservation this extension took is put back here, once.
            $local = $this->rowById($orderId);
            if ($local !== null && !$local['released']) {
                $this->release($orderId);
            }
            $this->logger->info('sudemi order ' . $sudemiId . ' was released: the buyer is not charged.');
        }

        return true;
    }

    /**
     * Why this shop cannot book the order in its currency, or null when it can.
     *
     * An order from a core that sends no currency_code (older than currency step 2, EUR only)
     * is booked as before.
     *
     * @return string|null
     */
    private function currencyProblem(array $pending)
    {
        if (!isset($pending['currency_code'])) {
            return null;
        }

        return self::currencyRefusal(
            $pending['currency_code'],
            $this->config->get('config_currency'),
            $this->currencyRow(Currency::normalise($pending['currency_code']))
        );
    }

    /**
     * The reason an order in $orderCode cannot be booked by a shop whose default currency is
     * $shopCode, or null. $row is the shop's currency row for $orderCode (null when the shop
     * has none). Plain words: sudemi passes the reason on.
     *
     * @return string|null
     */
    public static function currencyRefusal($orderCode, $shopCode, $row)
    {
        $order = Currency::normalise($orderCode);
        $shop = Currency::normalise($shopCode);
        if ($order === null) {
            return 'the order did not say which currency it was paid in';
        }
        if ($shop !== null && $order !== $shop) {
            return sprintf('this shop sells in %s, not in %s', $shop, $order);
        }
        if ($row === null || empty($row['status'])) {
            return sprintf('this shop cannot take payments in %s right now', $order);
        }

        return null;
    }

    /** The shop's currency row for a code (currency_id, code, value, status), or null. */
    private function currencyRow($code)
    {
        if ($code === null) {
            return null;
        }
        $query = $this->db->query(
            "SELECT currency_id, code, value, status FROM " . $this->prefix . "currency
              WHERE code = '" . $this->db->escape($code) . "' LIMIT 1"
        );

        return $query->num_rows ? $query->row : null;
    }

    /**
     * The first line this shop cannot supply, or null when it can supply all of them.
     *
     * Checked against live stock rather than against anything this extension last pushed: what
     * was true at the last sync is precisely what cannot be relied on here.
     *
     * @return string|null
     */
    private function firstShortfall(array $pending)
    {
        $lines = isset($pending['lines']) && is_array($pending['lines']) ? $pending['lines'] : array();
        if (empty($lines)) {
            return 'the order had no recognisable items';
        }

        foreach ($lines as $line) {
            $productId = isset($line['external_id']) ? (int) $line['external_id'] : 0;
            if ($productId <= 0) {
                return 'an item on the order is no longer sold here';
            }
            $query = $this->db->query(
                "SELECT p.quantity, p.status, p.subtract, pd.name
                   FROM " . $this->prefix . "product p
                   LEFT JOIN " . $this->prefix . "product_description pd
                          ON pd.product_id = p.product_id
                         AND pd.language_id = '" . (int) $this->config->get('config_language_id') . "'
                  WHERE p.product_id = '" . (int) $productId . "'"
            );
            if (!$query->num_rows || !(int) $query->row['status']) {
                return sprintf(
                    '"%s" is no longer sold here',
                    isset($line['title']) ? (string) $line['title'] : 'an item'
                );
            }
            $quantity = isset($line['quantity']) ? (int) $line['quantity'] : 1;
            // subtract = 0 means this product does not track stock at all, so there is no
            // shortfall to find: the merchant has said it is always available.
            if ((int) $query->row['subtract'] && (int) $query->row['quantity'] < $quantity) {
                return sprintf('"%s" is out of stock', $query->row['name']);
            }
        }
        return null;
    }

    /**
     * Records the order so the merchant fulfils it through their normal workflow.
     *
     * A real order rather than a note of our own: it appears where the merchant already looks,
     * stock comes down, and picking and shipping work as for any other sale.
     *
     * @return int|null The order_id
     */
    private function createLocalOrder(array $pending, $sudemiId)
    {
        // Checked by currencyProblem() already: the order's currency is the shop's own and is
        // enabled. EUR from a core that does not send one (it charged in EUR only).
        $currency = isset($pending['currency_code']) ? (string) Currency::normalise($pending['currency_code']) : 'EUR';
        $currencyRow = $this->currencyRow($currency);
        $currencyId = $currencyRow !== null ? (int) $currencyRow['currency_id'] : 0;
        // OpenCart keeps an order's amounts in the default currency and shows them times
        // currency_value. The order is in the default currency, so the value is 1 in a sound
        // shop; dividing by it keeps what the order page shows equal to what the buyer paid.
        $value = $currencyRow !== null && (float) $currencyRow['value'] > 0 ? (float) $currencyRow['value'] : 1.0;
        $amount = function ($minor) use ($currency, $value) {
            return Currency::fromMinor($minor, $currency) / $value;
        };
        $total = isset($pending['total_minor']) ? $amount($pending['total_minor']) : 0.0;
        $country = isset($pending['destination_country']) ? (string) $pending['destination_country'] : '';

        // The comment is how this order is found again, which is what makes a retry safe. No
        // buyer identity is stored: sudemi does not send one with the question, because a shop
        // deciding whether it has stock does not need to know who is asking. It arrives on
        // confirmation.
        // OpenCart 4 order columns: payment/shipping method are JSON, countries are ids. (The
        // payment_code / *_iso_code_2 columns written here before do not exist in OpenCart 4, so
        // every insert failed and no sudemi order could be created.)
        list($countryId, $countryName) = $this->country($country);
        $method = json_encode(['name' => 'sudemi', 'code' => 'sudemi.sudemi']);

        $this->db->query(
            "INSERT INTO " . $this->prefix . "order SET
                store_id = '" . (int) $this->config->get('config_store_id') . "',
                store_name = '" . $this->db->escape((string) $this->config->get('config_name')) . "',
                store_url = '" . $this->db->escape($this->storeBase() . '/') . "',
                customer_id = '0',
                customer_group_id = '" . (int) $this->config->get('config_customer_group_id') . "',
                language_id = '" . (int) $this->config->get('config_language_id') . "',
                currency_id = '" . $currencyId . "',
                payment_method = '" . $this->db->escape($method) . "',
                shipping_method = '" . $this->db->escape($method) . "',
                payment_country = '" . $this->db->escape($countryName) . "',
                payment_country_id = '" . $countryId . "',
                shipping_country = '" . $this->db->escape($countryName) . "',
                shipping_country_id = '" . $countryId . "',
                comment = '" . $this->db->escape(self::COMMENT_PREFIX . $sudemiId) . "',
                total = '" . (float) $total . "',
                order_status_id = '" . (int) self::STATE_INCOMPLETE . "',
                currency_code = '" . $this->db->escape($currency) . "',
                currency_value = '" . (float) $value . "',
                date_added = NOW(),
                date_modified = NOW()"
        );
        $orderId = (int) $this->db->getLastId();
        if (!$orderId) {
            return null;
        }

        // What the buyer paid, from sudemi. Null from a core that does not send it yet, and then
        // the lines are written without prices, as before.
        $paid = self::paidAmounts($pending);
        $goodsNet = 0;
        $taxes = [];
        foreach ($pending['lines'] as $index => $line) {
            $productId = isset($line['external_id']) ? (int) $line['external_id'] : 0;
            $quantity = isset($line['quantity']) ? (int) $line['quantity'] : 1;
            $name = isset($line['title']) ? (string) $line['title'] : '';
            $price = 0.0;
            $lineTotal = 0.0;
            $unitTax = 0.0;
            if ($paid !== null) {
                // The buyer's price wins over today's catalogue price: it may have changed since
                // the last sync, and the shop's order has to say what was actually paid. OpenCart
                // keeps prices without tax, so the VAT inside the paid price is split out at the
                // shop's own rates for the destination, never added on top.
                $gross = $paid['lines'][$index] * max(1, $quantity);
                $split = self::splitTax($gross, $this->percentRates($productId, $countryId));
                $goodsNet += $split['net'];
                foreach ($split['taxes'] as $rateId => $tax) {
                    $taxes[$rateId] = (isset($taxes[$rateId]) ? $taxes[$rateId] : 0) + $tax;
                }
                $qty = max(1, $quantity);
                $price = round($amount($split['net']) / $qty, 4);
                $lineTotal = $amount($split['net']);
                $unitTax = round($amount(array_sum($split['taxes'])) / $qty, 4);
            }
            $this->db->query(
                "INSERT INTO " . $this->prefix . "order_product SET
                    order_id = '" . $orderId . "',
                    product_id = '" . $productId . "',
                    name = '" . $this->db->escape($name) . "',
                    model = '',
                    quantity = '" . $quantity . "',
                    price = '" . (float) $price . "',
                    total = '" . (float) $lineTotal . "',
                    tax = '" . (float) $unitTax . "',
                    reward = '0'"
            );
            // Taken now, with the order, so this storefront cannot sell the same unit while the
            // confirmation is in flight. Put back only when sudemi says the order ended unpaid. Guarded by
            // subtract so a product the merchant does not track is left alone.
            $this->db->query(
                "UPDATE " . $this->prefix . "product
                    SET quantity = quantity - '" . $quantity . "'
                  WHERE product_id = '" . $productId . "' AND subtract = '1'"
            );
        }

        if ($paid !== null) {
            // The shipping as OpenCart's own checkout books it: without tax, its VAT in the tax
            // rows at the rates of the shipping method's tax class for the destination (the
            // shipping price sudemi charged was synced with that tax in it).
            $shippingNet = $paid['shipping'];
            if ($paid['shipping'] > 0 && $countryId) {
                $shipping = new Shipping($this->db, $this->config);
                $taxClassId = $shipping->taxClassFor($countryId, Currency::fromMinor($paid['shipping'], $currency));
                $split = self::splitTax($paid['shipping'], $this->classRates($taxClassId, $countryId));
                $shippingNet = $split['net'];
                foreach ($split['taxes'] as $rateId => $tax) {
                    $taxes[$rateId] = (isset($taxes[$rateId]) ? $taxes[$rateId] : 0) + $tax;
                }
            }
            // The order's totals, as OpenCart's own checkout writes them: without them the order
            // page shows no shipping and the shop's books miss what the buyer paid for it. The
            // total row is total_minor whatever the parts add up to.
            $text = $this->text();
            $rows = [['sub_total', $text['text_order_sub_total'], $goodsNet, 1]];
            $rows[] = ['shipping', $text['text_order_shipping'], $shippingNet, 3];
            foreach ($taxes as $rateId => $tax) {
                $rows[] = ['tax', $this->rateName($rateId), $tax, 5];
            }
            $rows[] = ['total', $text['text_order_total'], $paid['total'], 9];
            $hasExtension = $this->db->query(
                "SHOW COLUMNS FROM " . $this->prefix . "order_total LIKE 'extension'"
            )->num_rows > 0;
            foreach ($rows as $row) {
                $this->db->query(
                    "INSERT INTO " . $this->prefix . "order_total SET
                        order_id = '" . $orderId . "',"
                        . ($hasExtension ? " extension = 'opencart'," : '') . "
                        code = '" . $this->db->escape($row[0]) . "',
                        title = '" . $this->db->escape($row[1]) . "',
                        value = '" . (float) $amount($row[2]) . "',
                        sort_order = '" . (int) $row[3] . "'"
                );
            }
        }

        return $orderId;
    }

    /**
     * What the buyer paid for a pending order, in minor units: the shipping, each line's unit
     * price (keyed like the lines) and the total.
     *
     * Null when sudemi did not send them (a core older than this extension) or sent something
     * that is not money; the order is then written as before.
     *
     * @return array|null
     */
    public static function paidAmounts(array $pending)
    {
        if (!isset($pending['shipping_minor'], $pending['total_minor']) || empty($pending['lines'])
            || !is_array($pending['lines']) || !is_numeric($pending['shipping_minor'])
            || !is_numeric($pending['total_minor']) || (int) $pending['shipping_minor'] < 0) {
            return null;
        }
        $lines = [];
        foreach ($pending['lines'] as $index => $line) {
            if (!is_array($line) || !isset($line['unit_price_minor']) || !is_numeric($line['unit_price_minor'])
                || (int) $line['unit_price_minor'] < 0) {
                return null;
            }
            $lines[$index] = (int) $line['unit_price_minor'];
        }

        return [
            'shipping' => (int) $pending['shipping_minor'],
            'lines' => $lines,
            'total' => (int) $pending['total_minor'],
        ];
    }

    /**
     * Splits the VAT out of a gross amount: net plus the taxes is always exactly the gross.
     *
     * @param int                $grossMinor
     * @param array<int, float>  $rates tax_rate_id => percentage
     *
     * @return array{net: int, taxes: array<int, int>}
     */
    public static function splitTax($grossMinor, array $rates)
    {
        $sum = array_sum($rates);
        if ($grossMinor <= 0 || $sum <= 0) {
            return ['net' => (int) $grossMinor, 'taxes' => []];
        }
        $net = (int) round($grossMinor * 100 / (100 + $sum));
        $left = $grossMinor - $net;
        $taxes = [];
        $ids = array_keys($rates);
        foreach ($ids as $i => $rateId) {
            // The last rate takes the rounding, so the parts add up to the gross exactly.
            $tax = $i === count($ids) - 1 ? $left : (int) round(($grossMinor - $net) * $rates[$rateId] / $sum);
            $taxes[$rateId] = $tax;
            $left -= $tax;
        }

        return ['net' => $net, 'taxes' => $taxes];
    }

    /**
     * The shop's percentage tax rates for a product shipped to a country (the same rule the sync
     * uses to put the tax into the price it sends). Fixed-amount rates are left in the price.
     *
     * @return array<int, float> tax_rate_id => percentage
     */
    private function percentRates($productId, $countryId)
    {
        if (!$countryId) {
            return [];
        }
        $product = $this->db->query(
            "SELECT tax_class_id FROM " . $this->prefix . "product WHERE product_id = '" . (int) $productId . "' LIMIT 1"
        );

        return $this->classRates($product->num_rows ? (int) $product->row['tax_class_id'] : 0, $countryId);
    }

    /**
     * A tax class's percentage rates for a country. Fixed-amount rates are left in the price.
     *
     * @return array<int, float> tax_rate_id => percentage
     */
    private function classRates($taxClassId, $countryId)
    {
        if (!$taxClassId || !$countryId) {
            return [];
        }
        $query = $this->db->query(
            'SELECT tr2.tax_rate_id, tr2.rate FROM ' . $this->prefix . 'tax_rule tr1
               JOIN ' . $this->prefix . 'tax_rate tr2 ON tr1.tax_rate_id = tr2.tax_rate_id
               JOIN ' . $this->prefix . 'zone_to_geo_zone z2gz ON tr2.geo_zone_id = z2gz.geo_zone_id
              WHERE tr1.tax_class_id = ' . $taxClassId . ' AND z2gz.country_id = ' . (int) $countryId . "
                AND z2gz.zone_id = 0 AND tr2.type = 'P'
              GROUP BY tr2.tax_rate_id, tr2.rate"
        );
        $rates = [];
        foreach ($query->rows as $row) {
            $rates[(int) $row['tax_rate_id']] = (float) $row['rate'];
        }

        return $rates;
    }

    /** The shop's own name for a tax rate, as OpenCart's checkout titles its tax rows. */
    private function rateName($rateId)
    {
        $row = $this->db->query(
            "SELECT name FROM " . $this->prefix . "tax_rate WHERE tax_rate_id = '" . (int) $rateId . "' LIMIT 1"
        );

        return $row->num_rows ? (string) $row->row['name'] : 'VAT';
    }

    /**
     * The order texts in the store's default language, from this extension's own language files
     * (the 12 languages of the admin page), English when the store's language is not one of them.
     *
     * @return array<string, string>
     */
    private function text()
    {
        $code = 'en-gb';
        $row = $this->db->query(
            "SELECT code FROM " . $this->prefix . "language WHERE language_id = '" . (int) $this->config->get('config_language_id') . "' LIMIT 1"
        );
        if ($row->num_rows) {
            $code = strtolower((string) $row->row['code']);
        }

        return self::orderTexts($code);
    }

    /** The order texts for one OpenCart language code, falling back to English per text. */
    public static function orderTexts($code)
    {
        $keys = ['text_order_sub_total', 'text_order_shipping', 'text_order_total'];
        $out = [];
        foreach ([self::languageFile('en-gb'), self::languageFile($code)] as $strings) {
            foreach ($keys as $key) {
                if (isset($strings[$key]) && $strings[$key] !== '') {
                    $out[$key] = (string) $strings[$key];
                }
            }
        }
        foreach ($keys as $key) {
            if (!isset($out[$key])) {
                $out[$key] = 'sudemi';
            }
        }

        return $out;
    }

    private static function languageFile($code)
    {
        if (!preg_match('/^[a-z]{2}(-[a-z]{2})?$/', (string) $code)) {
            return [];
        }
        $file = __DIR__ . '/../../../admin/language/' . $code . '/module/sudemi.php';
        if (!is_file($file)) {
            return [];
        }
        $_ = [];
        include $file;

        return $_;
    }

    /**
     * Writes the buyer's delivery address onto the order, now that sudemi will release it.
     *
     * Straight onto the order's own shipping columns, which is where OpenCart keeps them and
     * where invoices, packing slips and shipping extensions read from. Without this a merchant
     * has a paid order and nowhere to send it.
     *
     * @param int    $orderId
     * @param string $sudemiId
     */
    private function fillCustomer($orderId, $sudemiId)
    {
        $response = $this->client->getSigned(
            '/integration/v1/orders/' . rawurlencode($sudemiId) . '/fulfilment'
        );
        if ($response === null || (int) $response['status'] !== 200) {
            // Not fatal: the order is paid and real and the address can be fetched again. Said
            // loudly because a merchant about to pack this needs to know it has no destination.
            $this->logger->warn('Could not fetch the delivery address for sudemi order '
                . $sudemiId . '. The order is paid; retry before shipping.');

            return;
        }
        $body = isset($response['body']) ? $response['body'] : array();
        if (empty($body['deliverable']) || empty($body['ship_to'])) {
            $this->logger->warn('sudemi has no delivery address for order ' . $sudemiId . ': '
                . (isset($body['message']) ? (string) $body['message'] : 'reason not given'));

            return;
        }

        $to = $body['ship_to'];
        $name = isset($to['recipient_name']) ? trim((string) $to['recipient_name']) : '';
        // OpenCart stores the name split, and a recipient line is not reliably "first last" in
        // every locale. Everything before the last space is the first name: wrong for some
        // names, and never loses part of one.
        $space = strrpos($name, ' ');
        $first = $space === false ? $name : substr($name, 0, $space);
        $last = $space === false ? '' : substr($name, $space + 1);

        $country = isset($to['country']) ? (string) $to['country'] : '';
        list($countryId, $countryName) = $this->country($country);

        $this->db->query(
            "UPDATE " . $this->prefix . "order SET
                firstname = '" . $this->db->escape($first) . "',
                lastname = '" . $this->db->escape($last) . "',
                telephone = '" . $this->db->escape(isset($to['phone']) ? (string) $to['phone'] : '') . "',
                shipping_firstname = '" . $this->db->escape($first) . "',
                shipping_lastname = '" . $this->db->escape($last) . "',
                shipping_address_1 = '" . $this->db->escape(isset($to['line1']) ? (string) $to['line1'] : '') . "',
                shipping_address_2 = '" . $this->db->escape(isset($to['line2']) ? (string) $to['line2'] : '') . "',
                shipping_postcode = '" . $this->db->escape(isset($to['postal_code']) ? (string) $to['postal_code'] : '') . "',
                shipping_city = '" . $this->db->escape(isset($to['city']) ? (string) $to['city'] : '') . "',
                shipping_zone = '" . $this->db->escape(isset($to['region']) ? (string) $to['region'] : '') . "',
                shipping_country = '" . $this->db->escape($countryName) . "',
                shipping_country_id = '" . $countryId . "',
                date_modified = NOW()
              WHERE order_id = '" . (int) $orderId . "'"
        );
    }

    /**
     * Works through the settled_orders block of a heartbeat.
     *
     * These are orders this shop accepted while the buyer's payment still had other shops to
     * hear from. sudemi repeats each one for a week, so applying one must change nothing the
     * second time: only an order still unconfirmed here is touched, and every outcome moves it.
     *
     * @param array $settled The settled_orders block of a heartbeat response
     */
    public function handleSettled($settled)
    {
        if (empty($settled) || !is_array($settled)) {
            return;
        }
        foreach ($settled as $entry) {
            if (!is_array($entry) || empty($entry['order_id']) || empty($entry['status'])) {
                continue;
            }
            try {
                $this->settle($entry);
            } catch (\Exception $e) {
                $this->logger->error(sprintf(
                    'Could not apply the outcome of sudemi order %s: %s',
                    $entry['order_id'],
                    $e->getMessage()
                ));
            }
        }
    }

    /** Paid means ship it; declined means it never happened; cancelled means do not ship it. */
    private function settle(array $settled)
    {
        $sudemiId = (string) $settled['order_id'];
        $state = (string) $settled['status'];
        if ($state === 'ACCEPTED' || $state === 'AWAITING_SELLER') {
            return;
        }
        $orderId = $this->findLocalOrder($sudemiId);
        if (!$orderId) {
            return;
        }
        $current = $this->statusOf($orderId);

        // The buyer cancelled before shipping (or the order was cancelled on sudemi another way):
        // sudemi already refunded the buyer in full. An order confirmed here would otherwise stay
        // Pending/Processing and could still be shipped.
        if ($state === 'CANCELLED' && $current !== self::STATE_INCOMPLETE) {
            $this->cancelledOnSudemi($orderId, $sudemiId, $current);

            return;
        }

        // Only an order still unconfirmed here. One already confirmed or voided was settled
        // before -- by an earlier beat, or by an accept that captured straight away.
        if ($current !== self::STATE_INCOMPLETE) {
            return;
        }

        if ($state === 'DECLINED' || $state === 'CANCELLED' || $state === 'REFUNDED') {
            // Released without being charged: the capture failed, the authorisation lapsed before
            // the last shop answered, or the buyer cancelled while it waited. (CANCELLED or
            // REFUNDED on an order never confirmed here: paid and refunded between two beats.) The
            // stock the order reserved goes back, the order stays voided, and it is never shipped.
            $reason = !empty($settled['declined_reason']) ? (string) $settled['declined_reason']
                : ($state === 'DECLINED' ? 'reason not given' : 'cancelled on sudemi; any payment was refunded');
            // Marked released, so a second beat does not restock again.
            $this->release($orderId);
            $this->logger->warn(sprintf(
                'sudemi order %s was released after all: %s. Stock restored; do not ship it.',
                $sudemiId,
                $reason
            ));
            return;
        }

        // PAID, or already further along: the money was taken, and only now is the address released.
        $this->fillCustomer($orderId, $sudemiId);
        $this->setStatus($orderId, (int) $this->config->get('config_order_status_id'));
        $this->logger->info(sprintf('sudemi order %s is paid; order %d can be shipped.', $sudemiId, $orderId));
    }

    /**
     * A confirmed local order whose sudemi order was cancelled (by the buyer, before shipping):
     * moved to Canceled with stock restored the way OpenCart's own cancel restores it, and a note
     * in the order history. Written straight to the tables, so OpenCart's addHistory event does
     * not fire and this extension does not send sudemi a cancel (or refund) for a cancellation
     * that came from sudemi; isCancelledOnSudemi() guards the event for later manual changes.
     *
     * Left alone: an order already Canceled or Refunded here, one already shipped or complete,
     * and anything not in an open (processing) status. sudemi repeats the entry for 7 days.
     */
    private function cancelledOnSudemi($orderId, $sudemiId, $current)
    {
        $processing = $this->statusList('config_processing_status');
        $complete = $this->statusList('config_complete_status');
        $confirmed = (int) $this->config->get('config_order_status_id');

        if (in_array($current, array(self::STATUS_CANCELED, self::STATUS_REFUNDED, self::STATUS_SHIPPED), true)
            || in_array($current, $complete, true)
            || $this->isCancelledOnSudemi($orderId)) {
            return;
        }
        if (!in_array($current, $processing, true) && $current !== $confirmed) {
            $this->logger->warn(sprintf(
                'sudemi order %s was cancelled on sudemi, but local order %d has a status this extension does not change. Check it before shipping.',
                $sudemiId,
                $orderId
            ));

            return;
        }

        // OpenCart restocks when an order leaves a processing/complete status for one that is
        // neither; the extension's own confirmation status counts too, because it took that stock.
        if (in_array($current, array_merge($processing, $complete), true) || $current === $confirmed) {
            $this->restockOrder($orderId);
        }
        $this->setStatus($orderId, self::STATUS_CANCELED);
        $this->note($orderId, self::NOTE_CANCELLED_BY_BUYER, self::STATUS_CANCELED);
        $this->logger->info(sprintf(
            'sudemi order %s was cancelled by the buyer before shipping; order %d is now Canceled and its stock restored. The buyer was refunded in full by sudemi. Do not ship it.',
            $sudemiId,
            $orderId
        ));
    }

    /**
     * Whether this local order was cancelled from sudemi's side (the note above is in its
     * history). The order-status event uses it so a later Canceled/Refunded set by hand on such an
     * order does not ask sudemi to cancel or refund a second time.
     */
    public function isCancelledOnSudemi($orderId)
    {
        $query = $this->db->query(
            "SELECT COUNT(*) AS n FROM " . $this->prefix . "order_history
              WHERE order_id = '" . (int) $orderId . "'
                AND comment = '" . $this->db->escape(self::NOTE_CANCELLED_BY_BUYER) . "'"
        );

        return $query->num_rows && (int) $query->row['n'] > 0;
    }

    /**
     * Adds a note to the order's history (not sent to the customer), keeping its status unless
     * one is given.
     */
    public function note($orderId, $comment, $statusId = null)
    {
        $statusId = $statusId === null ? $this->statusOf($orderId) : (int) $statusId;
        if ($statusId < 0) {
            return;
        }
        $this->db->query(
            "INSERT INTO " . $this->prefix . "order_history SET
                order_id = '" . (int) $orderId . "',
                order_status_id = '" . (int) $statusId . "',
                notify = '0',
                comment = '" . $this->db->escape((string) $comment) . "',
                date_added = NOW()"
        );
    }

    /** @return int[] a configured status list (config_processing_status, config_complete_status) */
    private function statusList($key)
    {
        $value = $this->config->get($key);

        return is_array($value) ? array_map('intval', $value) : array();
    }

    /** @return int|null the local order for a sudemi order, if this shop has one */
    public function localOrderOf($sudemiId)
    {
        return $this->findLocalOrder((string) $sudemiId);
    }

    private function statusOf($orderId)
    {
        $query = $this->db->query(
            "SELECT order_status_id FROM " . $this->prefix . "order WHERE order_id = '" . (int) $orderId . "'"
        );
        return $query->num_rows ? (int) $query->row['order_status_id'] : -1;
    }

    /** Puts back what a local order reserved, from its own lines. */
    private function restockOrder($orderId)
    {
        $lines = $this->db->query(
            "SELECT product_id, quantity FROM " . $this->prefix . "order_product
              WHERE order_id = '" . (int) $orderId . "'"
        );
        foreach ($lines->rows as $line) {
            $this->db->query(
                "UPDATE " . $this->prefix . "product
                    SET quantity = quantity + '" . (int) $line['quantity'] . "'
                  WHERE product_id = '" . (int) $line['product_id'] . "' AND subtract = '1'"
            );
        }
    }

    private function setStatus($orderId, $statusId)
    {
        $this->db->query(
            "UPDATE " . $this->prefix . "order
                SET order_status_id = '" . (int) $statusId . "', date_modified = NOW()
              WHERE order_id = '" . (int) $orderId . "'"
        );
    }

    /**
     * The sudemi order id behind an OpenCart order, or null when it was not placed through us.
     *
     * The link lives in the order comment, which is where createLocalOrder wrote it. OpenCart
     * has no per-order meta table, and adding one for a single string would be a schema change
     * for every shop that installs this.
     */
    public function sudemiIdOf($orderId)
    {
        $query = $this->db->query(
            "SELECT comment FROM " . $this->prefix . "order
              WHERE order_id = '" . (int) $orderId . "' LIMIT 1"
        );
        if (!$query->num_rows) {
            return null;
        }
        return self::idFromComment((string) $query->row['comment']);
    }

    /**
     * The sudemi order id in an order comment ('sudemi:<uuid>', possibly followed by
     * ' (released)'), or null. Only the uuid: the suffix once ended up in API paths.
     */
    public static function idFromComment($comment)
    {
        if (!preg_match('/^' . preg_quote(self::COMMENT_PREFIX, '/') . '\s*(' . self::UUID . ')/', (string) $comment, $m)) {
            return null;
        }

        return $m[1];
    }

    /**
     * Tells sudemi the order has shipped or arrived.
     *
     * @param string $sudemiId the sudemi order id
     * @param string $event    'shipped' or 'delivered'
     * @param int    $orderId  the local order, for reading tracking details
     */
    public function report($sudemiId, $event, $orderId)
    {
        $body = array();
        if ('shipped' === $event) {
            // OpenCart core keeps no tracking field. Shipping extensions add their own, and the
            // shipping_method is the only thing core records -- so its name is sent as the carrier
            // and the code is left out rather than invented. sudemi says "not given" rather than
            // withholding the dispatch notice over a missing field.
            $query = $this->db->query(
                "SELECT shipping_method FROM " . $this->prefix . "order
                  WHERE order_id = '" . (int) $orderId . "' LIMIT 1"
            );
            $carrier = $query->num_rows ? self::carrierName((string) $query->row['shipping_method']) : null;
            $body = array('carrier' => $carrier, 'trackingCode' => null);
        }

        $this->sendReport($sudemiId, $event, $body, true);
    }

    /**
     * OpenCart 4 stores shipping_method as JSON ({"name": ..., "code": ...}); the buyer should see
     * the name, not the JSON. A plain string (OpenCart 3 style) is sent as it is.
     *
     * @return string|null
     */
    public static function carrierName($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $name = isset($decoded['name']) && is_string($decoded['name']) ? trim($decoded['name']) : '';

            return $name === '' ? null : $name;
        }

        return $raw;
    }

    /**
     * Whether a fulfilment call should be tried again: not answered (unreachable, 5xx, 429), not
     * accepted for now (401, 403, 409 site_mismatch) or 409 not_paid (an accepted order that is
     * still waiting for the other shops in its payment).
     */
    public static function shouldRetry($response)
    {
        if (!is_array($response) || !isset($response['status'])) {
            return true;
        }
        $status = (int) $response['status'];
        if ($status >= 500 || in_array($status, array(401, 403, 429), true)) {
            return true;
        }
        $error = isset($response['body']['error']) ? (string) $response['body']['error'] : '';

        return $status === 409 && in_array($error, array('site_mismatch', 'not_paid'), true);
    }

    /** @return string 'ok', 'retry' or 'failed' */
    private function sendReport($sudemiId, $event, array $body, $queue)
    {
        $response = $this->client->postSigned(
            '/integration/v1/orders/' . rawurlencode($sudemiId) . '/' . $event,
            $body
        );
        if (is_array($response) && (int) $response['status'] === 200) {
            $this->logger->info('Reported ' . $event . ' for sudemi order ' . $sudemiId . '.');

            return 'ok';
        }
        if (self::shouldRetry($response)) {
            $this->logger->warn('Could not report ' . $event . ' for sudemi order ' . $sudemiId . ' ('
                . self::describe($response) . '). Will try again on the next heartbeat.');
            if ($queue) {
                $this->enqueueCall($sudemiId, $event, $body);
            }

            return 'retry';
        }
        $this->logger->error('sudemi refused ' . $event . ' for order ' . $sudemiId . ' ('
            . self::describe($response) . ').');

        return 'failed';
    }

    private function calls()
    {
        if ($this->store === null) {
            return array();
        }
        $raw = $this->store->read(self::SETTING_CALLS);
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($decoded) ? $decoded : array();
    }

    private function saveCalls(array $calls)
    {
        if ($this->store === null) {
            return;
        }
        if (empty($calls)) {
            $this->store->delete(self::SETTING_CALLS);
        } else {
            $this->store->write(self::SETTING_CALLS, json_encode($calls));
        }
    }

    /** Writes down an order call that did not get through, newest per order and call. */
    private function enqueueCall($sudemiId, $call, array $body)
    {
        if ($this->store === null) {
            return;
        }
        $calls = $this->calls();
        $key = $sudemiId . '|' . $call;
        $since = isset($calls[$key]['since']) ? (int) $calls[$key]['since'] : time();
        unset($calls[$key]);
        $calls[$key] = array('order_id' => (string) $sudemiId, 'call' => (string) $call, 'body' => $body, 'since' => $since);
        if (count($calls) > self::CALLS_MAX) {
            $calls = array_slice($calls, -self::CALLS_MAX, null, true);
        }
        $this->saveCalls($calls);
    }

    /** @return array the order calls waiting for a retry (for the admin page and tests) */
    public function pendingCalls()
    {
        return $this->calls();
    }

    /** Sends the order calls that did not get through before; run on every heartbeat. */
    public function drainCalls()
    {
        $calls = $this->calls();
        if (empty($calls)) {
            return;
        }
        $done = 0;
        foreach ($calls as $key => $entry) {
            if ($done >= self::CALLS_PER_BEAT) {
                break;
            }
            $sudemiId = isset($entry['order_id']) ? (string) $entry['order_id'] : '';
            $call = isset($entry['call']) ? (string) $entry['call'] : '';
            $body = isset($entry['body']) && is_array($entry['body']) ? $entry['body'] : array();
            if (!preg_match('/^' . self::UUID . '$/', $sudemiId)) {
                unset($calls[$key]);
                continue;
            }
            if (time() - (isset($entry['since']) ? (int) $entry['since'] : 0) > self::CALLS_MAX_AGE) {
                $this->logger->error('Gave up reporting ' . $call . ' for sudemi order ' . $sudemiId
                    . ' after 14 days. Report it in the sudemi portal.');
                unset($calls[$key]);
                continue;
            }
            ++$done;
            if ($call === 'cancel_shop') {
                $orderId = isset($body['order']) ? (int) $body['order'] : 0;
                $reason = isset($body['reason']) ? (string) $body['reason'] : 'Cancelled by the shop';
                if ($this->cancelFromShop($sudemiId, $orderId, $reason, false)) {
                    unset($calls[$key]);
                }
                continue;
            }
            if ($call !== 'shipped' && $call !== 'delivered') {
                unset($calls[$key]);
                continue;
            }
            if ($this->sendReport($sudemiId, $call, $body, false) !== 'retry') {
                unset($calls[$key]);
            }
        }
        $this->saveCalls($calls);
    }

    /**
     * The local order for a sudemi order including a released (voided) one:
     * ['id' => int, 'status' => int, 'released' => bool], or null.
     */
    private function localRow($sudemiId)
    {
        $query = $this->db->query(
            "SELECT order_id, order_status_id, comment FROM " . $this->prefix . "order
              WHERE comment IN ('" . $this->db->escape(self::COMMENT_PREFIX . $sudemiId) . "', '"
                . $this->db->escape(self::COMMENT_PREFIX . $sudemiId . ' (released)') . "')
              ORDER BY order_id LIMIT 1"
        );

        return $query->num_rows ? self::row($query->row) : null;
    }

    private function rowById($orderId)
    {
        $query = $this->db->query(
            "SELECT order_id, order_status_id, comment FROM " . $this->prefix . "order
              WHERE order_id = '" . (int) $orderId . "' LIMIT 1"
        );

        return $query->num_rows ? self::row($query->row) : null;
    }

    private static function row(array $row)
    {
        return array(
            'id' => (int) $row['order_id'],
            'status' => (int) $row['order_status_id'],
            'released' => substr((string) $row['comment'], -strlen(' (released)')) === ' (released)',
        );
    }

    /** @return int|null The order_id already built for this sudemi order, if there is one (not released). */
    private function findLocalOrder($sudemiId)
    {
        $query = $this->db->query(
            "SELECT order_id FROM " . $this->prefix . "order
              WHERE comment = '" . $this->db->escape(self::COMMENT_PREFIX . $sudemiId) . "'
              LIMIT 1"
        );
        return $query->num_rows ? (int) $query->row['order_id'] : null;
    }

    /**
     * The storefront's base URL. OpenCart 4 has no config_url setting for the default store; its
     * address is the HTTP_CATALOG constant in the admin and HTTP_SERVER in the storefront.
     */
    private function storeBase()
    {
        $url = (string) $this->config->get('config_url');
        if ($url === '' && defined('HTTP_CATALOG')) {
            $url = HTTP_CATALOG;
        } elseif ($url === '' && defined('HTTP_SERVER')) {
            $url = HTTP_SERVER;
        }

        return rtrim($url, '/');
    }

    /**
     * [country_id, name] for an ISO code. OpenCart 4.1 keeps names in country_description
     * (per language); 4.0 has a name column on country.
     */
    private function country($iso)
    {
        $iso = $this->db->escape((string) $iso);
        $hasDescriptions = $this->db->query("SHOW TABLES LIKE '" . $this->db->escape($this->prefix . 'country_description') . "'")->num_rows > 0;
        if ($hasDescriptions) {
            $row = $this->db->query(
                "SELECT c.country_id, cd.name FROM " . $this->prefix . "country c
                   LEFT JOIN " . $this->prefix . "country_description cd
                     ON (cd.country_id = c.country_id AND cd.language_id = '" . (int) $this->config->get('config_language_id') . "')
                  WHERE c.iso_code_2 = '" . $iso . "' LIMIT 1"
            );
        } else {
            $row = $this->db->query(
                "SELECT country_id, name FROM " . $this->prefix . "country WHERE iso_code_2 = '" . $iso . "' LIMIT 1"
            );
        }
        if (!$row->num_rows) {
            return [0, (string) $iso];
        }

        return [(int) $row->row['country_id'], (string) ($row->row['name'] !== null ? $row->row['name'] : $iso)];
    }

    /**
     * A refund made in the shop (status Refunded), passed to sudemi, which holds the buyer's
     * payment and pays it back; null amount = in full. The outcome is logged either way.
     */
    public function refund($sudemiId, $amountMinor, $reason, $currency = null)
    {
        // A refund is in the order's currency (the one the buyer paid in). One in another
        // currency would pay back a different amount than meant: refused here, never sent.
        $booked = $this->bookedCurrency($sudemiId);
        $currency = $currency !== null ? Currency::normalise($currency) : $booked;
        if ($booked !== null && $currency !== $booked) {
            $this->logger->error(sprintf(
                'sudemi order %s: not refunded. The refund is in %s but the buyer paid in %s; a refund has to be in the currency the buyer paid in. Nothing was sent to sudemi.',
                $sudemiId,
                $currency === null ? '?' : $currency,
                $booked
            ));

            return false;
        }
        $body = ['reason' => $reason];
        if ($amountMinor !== null) {
            $body['amountMinor'] = (int) $amountMinor;
        }
        if ($currency !== null) {
            $body['currency'] = $currency;
        }

        return $this->moneyResult($sudemiId, $this->client->postSigned('/integration/v1/orders/' . rawurlencode($sudemiId) . '/refund', $body));
    }

    /** The currency the local order for a sudemi order was booked in, or null when there is none. */
    private function bookedCurrency($sudemiId)
    {
        $query = $this->db->query(
            "SELECT currency_code FROM " . $this->prefix . "order
              WHERE comment IN ('" . $this->db->escape(self::COMMENT_PREFIX . $sudemiId) . "', '"
                . $this->db->escape(self::COMMENT_PREFIX . $sudemiId . ' (released)') . "')
              ORDER BY order_id LIMIT 1"
        );

        return $query->num_rows ? Currency::normalise($query->row['currency_code']) : null;
    }

    /** A paid order cancelled in the shop: refunded in full and cancelled on sudemi. */
    public function cancel($sudemiId, $reason)
    {
        return $this->moneyResult($sudemiId, $this->client->postSigned('/integration/v1/orders/' . rawurlencode($sudemiId) . '/cancel', ['reason' => $reason]));
    }

    private function moneyResult($sudemiId, $response)
    {
        if (is_array($response) && (int) $response['status'] === 200) {
            $this->logger->info('sudemi order ' . $sudemiId . ': refunded to the buyer through sudemi.');

            return true;
        }
        $message = is_array($response) && isset($response['body']['message']) ? (string) $response['body']['message']
            : (is_array($response) ? (string) $response['status'] : 'sudemi could not be reached');
        $this->logger->error('sudemi order ' . $sudemiId . ': sudemi did not refund this (' . $message
            . '). The buyer has NOT been paid back; contact sudemi support (support@sudemi.com).');

        return false;
    }
}
