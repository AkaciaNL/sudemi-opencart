<?php
/**
 * Catching a product change the moment it happens.
 *
 * Without this the extension would only be as current as its hourly full sync, which means a
 * shop could advertise a price on sudemi for most of an hour after changing it here. A price a
 * marketplace shows and a price the shop charges have to agree, so every save is pushed as it
 * happens and the full sync becomes a safety net rather than the mechanism.
 *
 * These are OpenCart events, registered when the extension is installed and removed when it is
 * uninstalled. They run after the model has written, so what is read back is what was saved.
 *
 * Every handler is wrapped: an exception here would surface as a failed product save, and an
 * extension that can break saving products in someone's shop has no business being installed.
 *
 * @license GPL-3.0-or-later
 */

namespace Opencart\Admin\Controller\Extension\Sudemi;

class Event extends \Opencart\System\Engine\Controller
{
    /** OpenCart's built-in "Shipped" status. Fixed since 1.x and not renamed by configuration. */
    private const STATUS_SHIPPED = 3;

    /** @param mixed $output the model's return value; product id for an add, ignored otherwise */
    public function productAdded(string &$route, array &$args, &$output): void
    {
        $this->push(is_numeric($output) ? (int) $output : 0);
    }

    public function productEdited(string &$route, array &$args, &$output): void
    {
        $this->push(isset($args[0]) ? (int) $args[0] : 0);
    }

    /**
     * A deletion cannot be pushed as a price, because there is no longer a price to send.
     *
     * The change counter is still bumped, which is the point: the next heartbeat reports a
     * revision sudemi cannot match, sudemi asks for a full sync, and the sync's commit is what
     * delists the product. Slower than a push and correct, where a push would have to be a
     * "delete" verb that a compromised plugin could aim at a whole catalogue.
     */
    public function productDeleted(string &$route, array &$args, &$output): void
    {
        try {
            $services = $this->services();
            $services['heartbeat']->noteChange();
        } catch (\Throwable $e) {
            // Swallowed deliberately: see the class comment.
            return;
        }
    }

    /**
     * Stock moved because an order was placed or its status changed.
     *
     * The case that matters most and is easiest to miss: nothing about the product was edited,
     * so no product hook fires, but the quantity sudemi holds is now wrong and it will keep
     * offering something this shop cannot ship.
     */
    public function orderHistoryAdded(string &$route, array &$args, &$output): void
    {
        try {
            $orderId = isset($args[0]) ? (int) $args[0] : 0;
            if ($orderId <= 0) {
                return;
            }
            $services = $this->services();
            if (!$services['client']->isConnected()) {
                return;
            }

            $query = $this->db->query(
                'SELECT product_id FROM ' . DB_PREFIX . 'order_product WHERE order_id = '
                . (int) $orderId
            );
            foreach ($query->rows as $row) {
                $this->pushWith($services, (int) $row['product_id']);
            }

            // The same event carries dispatch and delivery, because in OpenCart both are a
            // status change on the order and this is where status changes arrive. Reported back
            // so sudemi can tell the buyer: one who is not told their order shipped assumes it
            // did not, and delivery is what starts the withdrawal period running.
            $this->reportFulfilment($services, $orderId, isset($args[1]) ? (int) $args[1] : 0);
        } catch (\Throwable $e) {
            return;
        }
    }

    /**
     * Tells sudemi an order has shipped or arrived, if this status means either.
     *
     * Shipped is OpenCart's own status 3, which it has had since forever and no setting renames.
     * Delivered is read from the shop's configured "complete" statuses instead of a fixed id,
     * because which status means finished is a thing merchants genuinely change -- and getting it
     * wrong would either never start the buyer's withdrawal period or start it too early.
     *
     * @param array $services the shared collaborators
     * @param int   $orderId  the OpenCart order id
     * @param int   $statusId the status just applied
     */
    private function reportFulfilment(array $services, int $orderId, int $statusId): void
    {
        if ($statusId <= 0) {
            return;
        }
        $sudemiId = $services['orders']->sudemiIdOf($orderId);
        if ($sudemiId === null) {
            // Most of a shop's orders are not sudemi's, and this event sees all of them.
            return;
        }

        $complete = $this->config->get('config_complete_status');
        $complete = is_array($complete) ? array_map('intval', $complete) : [];

        $event = null;
        if (in_array($statusId, $complete, true)) {
            $event = 'delivered';
        } elseif (self::STATUS_SHIPPED === $statusId) {
            $event = 'shipped';
        }
        if ($event === null) {
            return;
        }
        $services['orders']->report($sudemiId, $event, $orderId);
    }

    private function push(int $productId): void
    {
        if ($productId <= 0) {
            return;
        }
        try {
            $this->pushWith($this->services(), $productId);
        } catch (\Throwable $e) {
            // Never break the merchant's save over sudemi, but leave a trace: a silent failure
            // here is a price that stays wrong until the next full sync.
            $this->report('Price push for product ' . $productId . ' failed: ' . $e->getMessage());
        }
    }

    private function pushWith(array $services, int $productId): void
    {
        if ($productId <= 0 || !$services['client']->isConnected()) {
            return;
        }

        $update = $services['catalogue']->priceAndStock($productId);
        if ($update === null) {
            return;
        }

        // Counted before the attempt, not after a success: the counter is what lets sudemi
        // notice a change that never arrived, so it has to move even when the push fails.
        $services['heartbeat']->noteChange();

        $response = $services['client']->postSigned(
            '/integration/v1/products/prices',
            ['updates' => [$update]]
        );

        // A dropped change is a wrong price on a marketplace until the next full sync, so a
        // failure is written down rather than logged and forgotten. The heartbeat drains it.
        if ($response === null || $response['status'] !== 200) {
            $services['queue']->enqueue($update);
        }
    }

    private function services(): array
    {
        require_once DIR_EXTENSION . 'sudemi/system/library/sudemi/store.php';
        require_once DIR_EXTENSION . 'sudemi/system/library/sudemi/logger.php';
        require_once DIR_EXTENSION . 'sudemi/system/library/sudemi/keys.php';
        require_once DIR_EXTENSION . 'sudemi/system/library/sudemi/client.php';
        require_once DIR_EXTENSION . 'sudemi/system/library/sudemi/pairing.php';
        require_once DIR_EXTENSION . 'sudemi/system/library/sudemi/shipping.php';
        require_once DIR_EXTENSION . 'sudemi/system/library/sudemi/catalogue.php';
        require_once DIR_EXTENSION . 'sudemi/system/library/sudemi/sync.php';
        require_once DIR_EXTENSION . 'sudemi/system/library/sudemi/queue.php';
        require_once DIR_EXTENSION . 'sudemi/system/library/sudemi/orders.php';
        require_once DIR_EXTENSION . 'sudemi/system/library/sudemi/heartbeat.php';

        $this->load->model('setting/setting');
        $store = new \Sudemi\SettingStore($this->db);

        $logger = new \Sudemi\Logger($store);
        $keys = new \Sudemi\Keys($this->config, $store);
        $client = new \Sudemi\Client($keys, $store, $logger);
        $pairing = new \Sudemi\Pairing($client, $keys, $store, $logger);
        $catalogue = new \Sudemi\Catalogue($this->db, $this->config);
        $sync = new \Sudemi\Sync($client, $logger, $catalogue);
        $queue = new \Sudemi\Queue($store, $client);
        $orders = new \Sudemi\Orders($this->db, $this->config, $client, $logger);
        $heartbeat = new \Sudemi\Heartbeat(
            $store, $client, $logger, $queue, $catalogue, $pairing, $sync, $orders
        );

        return compact('store', 'logger', 'keys', 'client', 'pairing', 'catalogue', 'sync',
            'queue', 'orders', 'heartbeat');
    }

    private function report(string $message): void
    {
        try {
            $this->services()['logger']->error($message);
        } catch (\Throwable $e) {
            error_log('sudemi: ' . $message);
        }
    }
}
