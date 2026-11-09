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
    /** OpenCart's built-in Canceled and Refunded statuses. */
    private const STATUS_CANCELED = 7;
    private const STATUS_REFUNDED = 11;

    /** @param mixed $output the model's return value; product id for an add, ignored otherwise */
    public function productAdded(string &$route, array &$args, &$output): void
    {
        $productId = is_numeric($output) ? (int) $output : 0;
        $this->saveWithdrawal($productId, isset($args[0]) && is_array($args[0]) ? $args[0] : []);
        $this->push($productId);
    }

    public function productEdited(string &$route, array &$args, &$output): void
    {
        $productId = isset($args[0]) ? (int) $args[0] : 0;
        $this->saveWithdrawal($productId, isset($args[1]) && is_array($args[1]) ? $args[1] : []);
        $this->push($productId);
    }

    /**
     * The right-of-withdrawal choice from the product form, stored after OpenCart saved the
     * product (its own permission check has passed by then). Only when the form sent the field:
     * a product saved any other way (copy, an import, another extension) keeps what it had.
     * Variants carry their master's setting, so a variant's own save stores nothing. A change is
     * sent with a full sync on the next heartbeat: the price push carries price and stock only.
     *
     * @param array $data the data the model was called with (the posted form)
     */
    private function saveWithdrawal(int $productId, array $data): void
    {
        try {
            require_once DIR_EXTENSION . 'sudemi/system/library/sudemi/withdrawal.php';
            if ($productId <= 0 || !array_key_exists(\Sudemi\Withdrawal::FIELD, $data) || !empty($data['master_id'])) {
                return;
            }
            $services = $this->services();
            if ($services['withdrawal']->set($productId, $data[\Sudemi\Withdrawal::FIELD])) {
                $services['store']->write(\Sudemi\Withdrawal::SETTING_RESYNC_DUE, 1);
            }
        } catch (\Throwable $e) {
            $this->report('Right-of-withdrawal setting for product ' . $productId . ' could not be saved: ' . $e->getMessage());
        }
    }

    /**
     * Adds the right-of-withdrawal select to the product form (Data tab, after Status and Sort
     * order), by editing the rendered HTML so no OpenCart file is changed. Not shown on a variant,
     * which carries its master product's setting.
     */
    public function productForm(string &$route, array &$data, string &$output): void
    {
        try {
            if (!empty($data['master_id'])) {
                return;
            }
            $productId = isset($data['product_id']) ? (int) $data['product_id'] : 0;
            $services = $this->services();
            $current = $productId > 0 ? $services['withdrawal']->get($productId) : null;
            $text = $this->load->language('extension/sudemi/module/sudemi');
            $label = function ($key) use ($text) {
                return htmlspecialchars(isset($text[$key]) ? (string) $text[$key] : $key, ENT_QUOTES, 'UTF-8');
            };

            $options = '';
            foreach (array_merge([''], \Sudemi\Withdrawal::REASONS) as $reason) {
                $options .= '<option value="' . $reason . '"' . ($reason === (string) $current ? ' selected' : '') . '>'
                    . $label('text_withdrawal_' . ($reason === '' ? 'none' : $reason)) . '</option>';
            }
            $field = '<div class="row mb-3" id="sudemi-withdrawal">'
                . '<label for="input-sudemi-withdrawal" class="col-sm-2 col-form-label">' . $label('entry_withdrawal') . '</label>'
                . '<div class="col-sm-10"><select name="' . \Sudemi\Withdrawal::FIELD . '" id="input-sudemi-withdrawal" class="form-select">'
                . $options . '</select><div class="form-text">' . $label('help_withdrawal') . '</div></div></div>';

            // The last fieldset of the Data tab, which ends right before the Links tab.
            $links = strpos($output, 'id="tab-links"');
            $at = $links !== false ? strrpos(substr($output, 0, $links), '</fieldset>') : false;
            if ($at === false) {
                // Another admin theme: anywhere inside the product form still posts with it.
                $form = strpos($output, 'id="form-product"');
                $at = $form !== false ? strpos($output, '</form>', $form) : false;
                $field = '<fieldset><legend>sudemi</legend>' . $field . '</fieldset>';
            }
            if ($at === false) {
                return;
            }
            $output = substr($output, 0, $at) . $field . substr($output, $at);
        } catch (\Throwable $e) {
            // A product form that cannot render because of us is worse than a missing field.
            return;
        }
    }

    /**
     * The sudemi entries in the admin menu (Orders, Shipping, Settings), for users who may open
     * the extension. OpenCart prints menu names unescaped: these are this extension's own texts,
     * escaped here anyway.
     */
    public function menu(string &$route, array &$data): void
    {
        try {
            if (!$this->user->hasPermission('access', 'extension/sudemi/module/sudemi') || !isset($this->session->data['user_token'])) {
                return;
            }
            // Prefixed, so this extension's texts do not replace the menu's own language keys.
            $text = $this->load->language('extension/sudemi/module/sudemi', 'sudemi');
            $label = function ($key) use ($text) {
                return htmlspecialchars(isset($text['sudemi_' . $key]) ? (string) $text['sudemi_' . $key] : $key, ENT_QUOTES, 'UTF-8');
            };
            $token = 'user_token=' . rawurlencode((string) $this->session->data['user_token']);
            $children = [];
            foreach ([['text_menu_orders', 'extension/sudemi/module/sudemi.orders'], ['text_menu_shipping', 'extension/sudemi/module/sudemi.shipping'],
                ['text_menu_settings', 'extension/sudemi/module/sudemi']] as $item) {
                $children[] = ['name' => $label($item[0]), 'href' => $this->url->link($item[1], $token), 'children' => []];
            }
            $data['menus'][] = ['id' => 'menu-sudemi', 'icon' => 'fa-solid fa-store', 'name' => 'sudemi', 'href' => '', 'children' => $children];
        } catch (\Throwable $e) {
            return;
        }
    }

    /**
     * The sudemi panel on OpenCart's order page, for an order that came through sudemi: its status
     * on sudemi, the carrier and tracking code sent when the order is set to Shipped (OpenCart has
     * no tracking field of its own), and a link to the sudemi order page (refunds, timeline,
     * payout). Inserted into the rendered page, so no OpenCart file is changed.
     */
    public function orderInfo(string &$route, array &$data, string &$output): void
    {
        try {
            $orderId = isset($data['order_id']) ? (int) $data['order_id'] : 0;
            if ($orderId <= 0 || !$this->user->hasPermission('access', 'extension/sudemi/module/sudemi')) {
                return;
            }
            $services = $this->services();
            $sudemiId = $services['orders']->sudemiIdOf($orderId);
            if ($sudemiId === null) {
                return;
            }
            $loaded = $this->load->language('extension/sudemi/module/sudemi', 'sudemi');
            $text = [];
            foreach ($loaded as $key => $value) {
                if (strpos($key, 'sudemi_') === 0) {
                    $text[substr($key, 7)] = $value;
                }
            }
            $e = function ($value) {
                return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
            };
            $t = function ($key) use ($text, $e) {
                return $e(isset($text[$key]) ? $text[$key] : $key);
            };
            $row = $services['orders']->rowOf($sudemiId);
            $status = $row !== null ? (string) $row['status'] : '';
            $statusKey = 'text_status_' . strtolower($status);
            $token = (string) $this->session->data['user_token'];
            $detail = 'index.php?route=extension/sudemi/module/sudemi.order&user_token=' . rawurlencode($token) . '&order_id=' . rawurlencode($sudemiId);
            $save = 'index.php?route=extension/sudemi/module/sudemi.tracking&user_token=' . rawurlencode($token);
            $panel = '<div class="card mb-3" id="sudemi-order-panel" data-sudemi-order="' . $e($sudemiId) . '">'
                . '<div class="card-header"><i class="fa-solid fa-store"></i> sudemi</div><div class="card-body">'
                . '<p>' . $t('text_panel_order') . ' <a href="' . $e($detail) . '"><code>' . $e(substr($sudemiId, 0, 8)) . '</code></a>'
                . ($status !== '' ? ' &middot; ' . (isset($text[$statusKey]) ? $e($text[$statusKey]) : $e($status)) : '') . '</p>'
                . '<div class="row g-2 align-items-end">'
                . '<div class="col-sm-4"><label class="form-label" for="sudemi-carrier">' . $t('entry_carrier') . '</label>'
                . '<input type="text" id="sudemi-carrier" class="form-control" maxlength="100" value="' . $e($row !== null ? $row['tracking_carrier'] : '') . '"/></div>'
                . '<div class="col-sm-5"><label class="form-label" for="sudemi-tracking">' . $t('entry_tracking') . '</label>'
                . '<input type="text" id="sudemi-tracking" class="form-control" maxlength="100" value="' . $e($row !== null ? $row['tracking_code'] : '') . '"/></div>'
                . '<div class="col-sm-3"><button type="button" class="btn btn-secondary" id="sudemi-tracking-save">' . $t('button_tracking_save') . '</button></div>'
                . '</div><div class="form-text">' . $t('help_tracking') . '</div>'
                . '<p class="mt-2 mb-0"><a href="' . $e($detail) . '">' . $t('text_panel_manage') . '</a></p>'
                . '</div></div>'
                . '<script>document.getElementById("sudemi-tracking-save").addEventListener("click", function () {'
                . 'var b = this; b.disabled = true; var f = new FormData();'
                . 'f.append("order_id", ' . json_encode($sudemiId) . '); f.append("carrier", document.getElementById("sudemi-carrier").value);'
                . 'f.append("tracking", document.getElementById("sudemi-tracking").value);'
                . 'fetch(' . json_encode($save, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ', {method: "POST", body: f, credentials: "same-origin"})'
                . '.then(function (r) { return r.json(); }).then(function (j) { b.disabled = false; alert(j.error || j.success); })'
                . '.catch(function () { b.disabled = false; });});</script>';

            // Before the History card, so it sits where the merchant changes the status.
            // (The quote matters: the admin header has a "fa-comments" icon of its own.)
            $at = strpos($output, 'fa-comment"');
            $at = $at !== false ? strrpos(substr($output, 0, $at), '<div class="card') : false;
            if ($at === false) {
                $at = strrpos($output, '<footer');
                if ($at === false) {
                    return;
                }
            }
            $output = substr($output, 0, $at) . $panel . substr($output, $at);
        } catch (\Throwable $e) {
            // An order page that cannot render because of us is worse than a missing panel.
            $this->report('The sudemi panel on order ' . (isset($data['order_id']) ? (int) $data['order_id'] : 0) . ' could not be shown: ' . $e->getMessage());
        }
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
            // Its listings stay on sudemi until a full sync's commit removes them; until then no
            // change push may carry the catalogue revision.
            $services['sync']->noteRemoval();
            $services['withdrawal']->forget(isset($args[0]) ? (int) $args[0] : 0);
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
            $this->report('Order status change for order ' . (isset($args[0]) ? (int) $args[0] : 0)
                . ' could not be reported: ' . $e->getMessage());
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

        // An order cancelled or refunded in full on sudemi's side (the buyer cancelled before
        // shipping, or the portal refunded it; the change feed brought it here) was already paid
        // back there: a Canceled or Refunded set on it here must not ask sudemi a second time.
        if ((self::STATUS_REFUNDED === $statusId || self::STATUS_CANCELED === $statusId)
            && $services['orders']->isSettledOnSudemi($orderId)) {
            return;
        }

        // sudemi holds the buyer's payment: a refund or cancellation made here has to reach it,
        // or the shop records it and the buyer never gets the money back.
        if (self::STATUS_REFUNDED === $statusId) {
            $services['orders']->refund($sudemiId, null, 'Refunded by the shop');

            return;
        }
        if (self::STATUS_CANCELED === $statusId) {
            // /reject while it is not paid yet, /cancel with a full refund once it is.
            $services['orders']->cancelFromShop($sudemiId, $orderId, 'Cancelled by the shop');

            return;
        }

        // Shipped first: OpenCart's default "complete" statuses include Shipped (3), and reading
        // complete as delivered made every dispatch a delivery -- starting the buyer's withdrawal
        // period on the day the parcel left instead of the day it arrived.
        $event = null;
        if (self::STATUS_SHIPPED === $statusId) {
            $event = 'shipped';
        } elseif (in_array($statusId, $complete, true)) {
            $event = 'delivered';
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
        // The whole listing (with the catalogue revision when nothing else waits), or price and
        // stock; a failure is queued and the heartbeat drains it. See Sync::pushChange.
        $services['sync']->pushChange($productId, $services['queue'], $services['heartbeat']);
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
        require_once DIR_EXTENSION . 'sudemi/system/library/sudemi/withdrawal.php';
        require_once DIR_EXTENSION . 'sudemi/system/library/sudemi/currency.php';

        $this->load->model('setting/setting');
        $store = new \Sudemi\SettingStore($this->db);

        $logger = new \Sudemi\Logger($store);
        $keys = new \Sudemi\Keys($this->config, $store);
        $client = new \Sudemi\Client($keys, $store, $logger);
        $pairing = new \Sudemi\Pairing($client, $keys, $store, $logger);
        $catalogue = new \Sudemi\Catalogue($this->db, $this->config);
        $sync = new \Sudemi\Sync($client, $logger, $catalogue, $store);
        $queue = new \Sudemi\Queue($store, $client, $logger);
        $orders = new \Sudemi\Orders($this->db, $this->config, $client, $logger, $store);
        $heartbeat = new \Sudemi\Heartbeat(
            $store, $client, $logger, $queue, $catalogue, $pairing, $sync, $orders
        );

        // An extension upgraded in place never runs install() again; this is where it catches up.
        $withdrawal = new \Sudemi\Withdrawal($this->db);
        $withdrawal->ensureSchema($store);

        return compact('store', 'logger', 'keys', 'client', 'pairing', 'catalogue', 'sync',
            'queue', 'orders', 'heartbeat', 'withdrawal');
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
