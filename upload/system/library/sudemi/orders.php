<?php
namespace Sudemi;

/**
 * Answering sudemi's question: can this shop actually fulfil the order?
 *
 * Nobody but this shop can answer it. sudemi holds no stock — it carries a figure this extension
 * pushed, which was true when it was pushed and may not be now, because the same units are on
 * sale in this storefront at the same time. So sudemi does not decide. It authorises the buyer's
 * card, holds the money without taking it, and asks.
 *
 * Until this extension answers, nothing has been charged. Yes captures the money and splits it;
 * no releases the hold; saying nothing releases it too once the window closes. Silence is never
 * taken as agreement — a shop that has stopped answering is usually a shop that is gone, and
 * charging a buyer because nobody objected is the failure this mechanism exists to prevent.
 */
class Orders
{
    /** Written on the order so one sudemi order can never produce two local ones. */
    const COMMENT_PREFIX = 'sudemi:';

    /** OpenCart order_status_id 0 means "missing" — an order not yet confirmed to anyone. */
    const STATE_INCOMPLETE = 0;

    private $db;
    private $config;
    private $client;
    private $logger;
    private $prefix;

    public function __construct($db, $config, Client $client, Logger $logger)
    {
        $this->db = $db;
        $this->config = $config;
        $this->client = $client;
        $this->logger = $logger;
        $this->prefix = DB_PREFIX;
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

        $existing = $this->findLocalOrder($sudemiId);
        if ($existing) {
            // A previous run got this far and the answer was lost in transit. Re-sending is safe:
            // sudemi answers already_resolved if it landed the first time.
            $this->accept($sudemiId, $existing, $pending);
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
        $this->accept($sudemiId, $orderId, $pending);
    }

    /** Sends the acceptance, and undoes the local order if it is refused. */
    private function accept($sudemiId, $orderId, array $pending)
    {
        $response = $this->client->postSigned(
            '/integration/v1/orders/' . rawurlencode($sudemiId) . '/accept',
            array()
        );

        $status = isset($response['status']) ? (int) $response['status'] : 0;
        $body = isset($response['body']) ? $response['body'] : array();

        if ($status === 200 && isset($body['status']) && $body['status'] === 'ok') {
            // The address arrives only now. sudemi withholds it while the order is still being
            // decided, because answering "do I have stock" needs nobody's address -- so this is
            // the first moment there is a customer to put on the order at all.
            $this->fillCustomer($orderId, $sudemiId);
            $this->setStatus($orderId, (int) $this->config->get('config_order_status_id'));
            $this->logger->info(sprintf('Confirmed sudemi order %s as order %d.', $sudemiId, $orderId));
            return;
        }

        if ($status === 0) {
            // Unreachable rather than refused. The local order stays and the next heartbeat
            // carries the same pending order, at which point the branch above re-sends.
            $this->logger->warn(sprintf(
                'Could not reach sudemi to confirm order %s. Will try again on the next heartbeat.',
                $sudemiId
            ));
            return;
        }

        // Refused: the window closed, or the authorisation lapsed before this shop answered. No
        // money was taken, so the local order must not survive — and the stock it reserved has to
        // go back, or this shop slowly loses inventory to orders that never happened.
        $reason = isset($body['message']) ? (string) $body['message'] : sprintf('HTTP %d', $status);
        $this->restock($pending);
        $this->setStatus($orderId, 0);
        $this->logger->warn(sprintf(
            'sudemi refused the confirmation of order %s: %s. The local order was voided and '
            . 'stock restored.',
            $sudemiId,
            $reason
        ));
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
        $currency = isset($pending['currency_code']) ? (string) $pending['currency_code'] : 'EUR';
        $total = isset($pending['total_minor']) ? ((int) $pending['total_minor']) / 100 : 0.0;
        $country = isset($pending['destination_country']) ? (string) $pending['destination_country'] : '';

        // The comment is how this order is found again, which is what makes a retry safe. No
        // buyer identity is stored: sudemi does not send one with the question, because a shop
        // deciding whether it has stock does not need to know who is asking. It arrives on
        // confirmation.
        $this->db->query(
            "INSERT INTO " . $this->prefix . "order SET
                store_id = '" . (int) $this->config->get('config_store_id') . "',
                store_name = '" . $this->db->escape((string) $this->config->get('config_name')) . "',
                store_url = '" . $this->db->escape((string) $this->config->get('config_url')) . "',
                customer_id = '0',
                payment_method = 'sudemi',
                payment_code = 'sudemi',
                payment_iso_code_2 = '" . $this->db->escape($country) . "',
                shipping_iso_code_2 = '" . $this->db->escape($country) . "',
                comment = '" . $this->db->escape(self::COMMENT_PREFIX . $sudemiId) . "',
                total = '" . (float) $total . "',
                order_status_id = '" . (int) self::STATE_INCOMPLETE . "',
                currency_code = '" . $this->db->escape($currency) . "',
                currency_value = '1.0',
                date_added = NOW(),
                date_modified = NOW()"
        );
        $orderId = (int) $this->db->getLastId();
        if (!$orderId) {
            return null;
        }

        foreach ($pending['lines'] as $line) {
            $productId = isset($line['external_id']) ? (int) $line['external_id'] : 0;
            $quantity = isset($line['quantity']) ? (int) $line['quantity'] : 1;
            $name = isset($line['title']) ? (string) $line['title'] : '';
            $this->db->query(
                "INSERT INTO " . $this->prefix . "order_product SET
                    order_id = '" . $orderId . "',
                    product_id = '" . $productId . "',
                    name = '" . $this->db->escape($name) . "',
                    model = '',
                    quantity = '" . $quantity . "',
                    price = '0',
                    total = '0',
                    tax = '0',
                    reward = '0'"
            );
            // Taken now, with the order, so this storefront cannot sell the same unit while the
            // confirmation is in flight. Put back by accept() if sudemi refuses. Guarded by
            // subtract so a product the merchant does not track is left alone.
            $this->db->query(
                "UPDATE " . $this->prefix . "product
                    SET quantity = quantity - '" . $quantity . "'
                  WHERE product_id = '" . $productId . "' AND subtract = '1'"
            );
        }

        return $orderId;
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
        $countryRow = $this->db->query(
            "SELECT country_id, name FROM " . $this->prefix . "country
              WHERE iso_code_2 = '" . $this->db->escape($country) . "' LIMIT 1"
        );
        $countryId = $countryRow->num_rows ? (int) $countryRow->row['country_id'] : 0;
        $countryName = $countryRow->num_rows ? (string) $countryRow->row['name'] : $country;

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

    private function restock(array $pending)
    {
        $lines = isset($pending['lines']) && is_array($pending['lines']) ? $pending['lines'] : array();
        foreach ($lines as $line) {
            $productId = isset($line['external_id']) ? (int) $line['external_id'] : 0;
            $quantity = isset($line['quantity']) ? (int) $line['quantity'] : 1;
            if ($productId > 0) {
                $this->db->query(
                    "UPDATE " . $this->prefix . "product
                        SET quantity = quantity + '" . $quantity . "'
                      WHERE product_id = '" . $productId . "' AND subtract = '1'"
                );
            }
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
        $comment = (string) $query->row['comment'];
        if (strpos($comment, self::COMMENT_PREFIX) !== 0) {
            return null;
        }
        $id = trim(substr($comment, strlen(self::COMMENT_PREFIX)));

        return $id === '' ? null : $id;
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
            // shipping_method is the only thing core records -- so that is sent as the carrier
            // and the code is left out rather than invented. sudemi says "not given" rather than
            // withholding the dispatch notice over a missing field.
            $query = $this->db->query(
                "SELECT shipping_method FROM " . $this->prefix . "order
                  WHERE order_id = '" . (int) $orderId . "' LIMIT 1"
            );
            $carrier = $query->num_rows ? (string) $query->row['shipping_method'] : '';
            $body = array('carrier' => $carrier === '' ? null : $carrier, 'trackingCode' => null);
        }

        $response = $this->client->postSigned(
            '/integration/v1/orders/' . rawurlencode($sudemiId) . '/' . $event,
            $body
        );
        if ($response === null) {
            $this->logger->warn('Could not reach sudemi to report ' . $event . ' for ' . $sudemiId . '.');

            return;
        }
        $this->logger->info('Reported ' . $event . ' for sudemi order ' . $sudemiId . '.');
    }

    /** @return int|null The order_id already built for this sudemi order, if there is one. */
    private function findLocalOrder($sudemiId)
    {
        $query = $this->db->query(
            "SELECT order_id FROM " . $this->prefix . "order
              WHERE comment = '" . $this->db->escape(self::COMMENT_PREFIX . $sudemiId) . "'
              LIMIT 1"
        );
        return $query->num_rows ? (int) $query->row['order_id'] : null;
    }
}
