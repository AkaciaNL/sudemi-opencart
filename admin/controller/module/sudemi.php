<?php
/**
 * The settings screen.
 *
 * Every action checks the user's permission and a CSRF token before it does anything, and
 * everything the template prints is escaped. An admin screen is where an extension usually
 * gives away more than it meant to.
 *
 * @license GPL-3.0-or-later
 */

namespace Opencart\Admin\Controller\Extension\Sudemi\Module;

class Sudemi extends \Opencart\System\Engine\Controller
{
    const PERMISSION = 'extension/sudemi/module/sudemi';

    public function index(): void
    {
        $this->load->language('extension/sudemi/module/sudemi');
        $this->document->setTitle($this->language->get('heading_title'));

        $this->beatIfDue();

        $data = $this->common();
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/sudemi/module/sudemi', $data));
    }

    public function connect(): void
    {
        $this->load->language('extension/sudemi/module/sudemi');
        $json = [];

        if (!$this->guard($json)) {
            $this->respond($json);

            return;
        }

        $sudemi = $this->services();
        $result = $sudemi['pairing']->start($this->shopUrl(), $this->proposedProfile());
        $json[$result['ok'] ? 'success' : 'error'] = $result['message'];
        if ($result['ok']) {
            $json['claim_url'] = $sudemi['pairing']->claimUrl();
        }

        $this->respond($json);
    }

    public function check(): void
    {
        $this->load->language('extension/sudemi/module/sudemi');
        $json = [];

        if (!$this->guard($json)) {
            $this->respond($json);

            return;
        }

        $sudemi = $this->services();
        if ($sudemi['pairing']->poll()) {
            $json['success'] = $this->language->get('text_connected');
        } else {
            $json['error'] = $this->language->get('text_not_confirmed');
        }

        $this->respond($json);
    }

    public function sync(): void
    {
        $this->load->language('extension/sudemi/module/sudemi');
        $json = [];

        if (!$this->guard($json)) {
            $this->respond($json);

            return;
        }

        $sudemi = $this->services();
        // Called once and held: evaluating it twice would send the whole catalogue twice.
        $ok = $sudemi['sync']->runFull();
        $json[$ok ? 'success' : 'error'] = $ok
            ? $this->language->get('text_synced')
            : $this->language->get('text_sync_failed');

        $this->respond($json);
    }

    /** Delivery times for sudemi buyers; every product carries them, so the catalogue is sent again. */
    public function delivery(): void
    {
        $this->load->language('extension/sudemi/module/sudemi');
        $json = [];

        if (!$this->guard($json)) {
            $this->respond($json);

            return;
        }

        $sudemi = $this->services();
        foreach (['handling_days' => \Sudemi\Shipping::HANDLING_DAYS, 'transit_days' => \Sudemi\Shipping::TRANSIT_DAYS] as $field => $key) {
            if (isset($this->request->post[$field]) && is_numeric($this->request->post[$field])) {
                $sudemi['store']->write($key, max(0, min(60, (int) $this->request->post[$field])));
            }
        }
        $json['success'] = $this->language->get('text_delivery_saved');
        $this->respond($json);
    }

    public function disconnect(): void
    {
        $this->load->language('extension/sudemi/module/sudemi');
        $json = [];

        if (!$this->guard($json)) {
            $this->respond($json);

            return;
        }

        $sudemi = $this->services();
        $sudemi['keys']->forget();
        $sudemi['pairing']->forget();
        $sudemi['heartbeat']->forget();
        // Price changes that never got through go with the credential that would have signed
        // them: leaving them would mean a reconnect pushing stale prices.
        $sudemi['queue']->forget();
        $sudemi['store']->delete(\Sudemi\Client::SETTING_INSTALL_ID);
        $sudemi['pairing']->clearChallenge();
        $json['success'] = $this->language->get('text_disconnected');

        $this->respond($json);
    }

    /** Permission, then CSRF token, on every action without exception. */
    private function guard(array &$json): bool
    {
        if (!$this->user->hasPermission('modify', self::PERMISSION)) {
            $json['error'] = $this->language->get('error_permission');

            return false;
        }
        $presented = isset($this->request->get['user_token']) ? (string) $this->request->get['user_token'] : '';
        if ($presented === '' || !hash_equals((string) $this->session->data['user_token'], $presented)) {
            $json['error'] = $this->language->get('error_token');

            return false;
        }

        return true;
    }

    private function respond(array $json): void
    {
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    /**
     * Registers the change events, so a price edit reaches sudemi as it happens.
     *
     * Called when the extension is installed. Without these the extension would be only as
     * current as its hourly full sync, and a shop could advertise a price it changed fifty
     * minutes ago.
     */
    public function install(): void
    {
        $this->load->model('setting/event');

        // OpenCart 4.1 fires model events as "<app>/model/<path>.<method>/after" (a dot before
        // the method). The slash form registered before never matched, so no event ever fired.
        $events = [
            ['sudemi_product_add', 'admin/model/catalog/product.addProduct/after',
                'extension/sudemi/event.productAdded'],
            ['sudemi_product_edit', 'admin/model/catalog/product.editProduct/after',
                'extension/sudemi/event.productEdited'],
            ['sudemi_product_delete', 'admin/model/catalog/product.deleteProduct/after',
                'extension/sudemi/event.productDeleted'],
            // Stock moving because something was ordered is the change that is easiest to
            // miss: no product was edited, so no product event fires, and sudemi would keep
            // offering stock this shop no longer has.
            ['sudemi_order_history', 'catalog/model/checkout/order.addHistory/after',
                'extension/sudemi/event.orderHistoryAdded'],
        ];

        foreach ($events as $event) {
            $this->model_setting_event->deleteEventByCode($event[0]);
            $this->model_setting_event->addEvent([
                'code' => $event[0],
                'description' => 'sudemi: keep prices and stock current',
                'trigger' => $event[1],
                'action' => $event[2],
                'status' => 1,
                'sort_order' => 1,
            ]);
        }

        $this->addChallengeRoute();
    }

    /**
     * /.well-known/sudemi-challenge -> extension/sudemi/challenge.
     *
     * OpenCart resolves a friendly URL one path segment at a time against its SEO keywords, so the
     * fixed path sudemi checks needs two keywords: ".well-known" (a marker) and "sudemi-challenge"
     * (the route). Works with SEO URLs switched on, which the settings page checks and says.
     */
    private function addChallengeRoute(): void
    {
        $this->load->model('design/seo_url');
        $this->load->model('localisation/language');
        $this->removeChallengeRoute();
        foreach ($this->model_localisation_language->getLanguages() as $language) {
            foreach ([['sudemi_well_known', '1', '.well-known'], ['route', 'extension/sudemi/challenge', 'sudemi-challenge']] as $row) {
                // OpenCart 4.1: addSeoUrl(key, value, keyword, store_id, language_id, sort_order).
                $this->model_design_seo_url->addSeoUrl($row[0], $row[1], $row[2], 0, (int) $language['language_id'], 0);
            }
        }
    }

    private function removeChallengeRoute(): void
    {
        $this->db->query("DELETE FROM `" . DB_PREFIX . "seo_url` WHERE (`key` = 'sudemi_well_known' AND `value` = '1')
            OR (`key` = 'route' AND `value` = 'extension/sudemi/challenge')");
    }

    /** Removes them again, so an uninstalled extension stops being called. */
    public function uninstall(): void
    {
        $this->load->model('setting/event');
        foreach (['sudemi_product_add', 'sudemi_product_edit', 'sudemi_product_delete',
            'sudemi_order_history'] as $code) {
            $this->model_setting_event->deleteEventByCode($code);
        }
        $this->removeChallengeRoute();
    }

    /**
     * The fallback beat, for a shop whose host has no cron entry for us.
     *
     * Not the supported path — a shop nobody logs into would go quiet and have its listings
     * paused, correctly — but it means the common case of "installed the extension, never set
     * up cron" keeps working rather than failing silently a week later. Guarded by the interval,
     * and only on this settings page, so no storefront request ever waits on our network.
     */
    private function beatIfDue(): void
    {
        try {
            $sudemi = $this->services();
            if ($sudemi['client']->isConnected() && $sudemi['heartbeat']->isDue()) {
                $sudemi['heartbeat']->run();
            }
        } catch (\Throwable $e) {
            // A settings page that cannot render because a marketplace is down is worse than a
            // missed beat.
            return;
        }
    }

    private function common(): array
    {
        $sudemi = $this->services();

        return [
            'user_token' => $this->session->data['user_token'],
            'connected' => $sudemi['client']->isConnected(),
            'supported' => \Sudemi\Keys::isSupported(),
            'claim_url' => $sudemi['pairing']->claimUrl(),
            'shop_url' => $this->shopUrl(),
            'install_id' => $sudemi['client']->installId(),
            'history' => $sudemi['client']->isConnected() ? $this->orderHistory($sudemi['client']) : null,
            'log' => $sudemi['logger']->entries(),
            'status' => $sudemi['heartbeat']->status(),
            'pending' => $sudemi['queue']->count(),
            'handling_days' => (new \Sudemi\Shipping($this->db, $this->config))->handlingDays(),
            'transit_days' => (new \Sudemi\Shipping($this->db, $this->config))->transitDays(),
            'shipping_preview' => $this->shippingPreview(),
            'options_skipped' => (new \Sudemi\Catalogue($this->db, $this->config))->withRequiredOptions(),
            // Printed for the shop owner to paste into their crontab. The token is in it, so
            // the template must not put it anywhere a non-admin can read.
            'cron_url' => $this->shopUrl() . 'index.php?route=extension/sudemi/cron&token='
                . rawurlencode($sudemi['heartbeat']->cronToken()),
        ];
    }

    /**
     * The shop's orders as sudemi holds them (approved, declined, shipped, who bought), for this
     * page. Read from sudemi, not from OpenCart's orders: an order sudemi declined because nobody
     * answered in time leaves no local order at all.
     *
     * @return array|null null when sudemi could not be reached
     */
    private function orderHistory($client): ?array
    {
        $response = $client->getSigned('/integration/v1/orders?limit=20');
        if ($response === null || (int) $response['status'] !== 200 || !is_array($response['body'])) {
            return null;
        }
        $body = $response['body'];
        $rows = [];
        foreach ((isset($body['orders']) && is_array($body['orders']) ? $body['orders'] : []) as $order) {
            $items = [];
            foreach ((isset($order['lines']) && is_array($order['lines']) ? $order['lines'] : []) as $line) {
                $qty = isset($line['quantity']) ? (int) $line['quantity'] : 1;
                $items[] = (isset($line['title']) ? (string) $line['title'] : '') . ($qty > 1 ? ' × ' . $qty : '');
            }
            $status = isset($order['status']) ? (string) $order['status'] : '';
            $label = $this->language->get('text_status_' . strtolower($status));
            $rows[] = [
                'id' => substr((string) $order['order_id'], 0, 8),
                'date' => isset($order['placed_at']) ? substr((string) $order['placed_at'], 0, 10) : '',
                'items' => implode(', ', $items),
                'status' => $label !== 'text_status_' . strtolower($status) ? $label : $status,
                'net' => number_format((isset($order['your_net_minor']) ? (int) $order['your_net_minor'] : 0) / 100, 2)
                    . ' ' . (isset($order['currency_code']) ? (string) $order['currency_code'] : 'EUR'),
                // Only present once sudemi has released it, which is only after this shop confirmed.
                'customer' => isset($order['customer_name']) ? (string) $order['customer_name'] : '—',
            ];
        }
        $awaiting = isset($body['counts']['AWAITING_SELLER']) ? (int) $body['counts']['AWAITING_SELLER'] : 0;

        return [
            'rows' => $rows,
            'awaiting' => $awaiting > 0 ? sprintf($this->language->get('text_history_awaiting'), $awaiting) : '',
        ];
    }

    /** Shipping prices as sudemi buyers will see them, for the first enabled product. */
    private function shippingPreview(): array
    {
        require_once DIR_EXTENSION . 'sudemi/system/library/sudemi/shipping.php';
        require_once DIR_EXTENSION . 'sudemi/system/library/sudemi/catalogue.php';
        $catalogue = new \Sudemi\Catalogue($this->db, $this->config);
        $first = $catalogue->products(0, 1);

        return empty($first) ? [] : $first[0]['shipping'];
    }

    private function shopUrl(): string
    {
        // OpenCart 4 has no config_url for the default store (it was empty, so pairing sent "/" and
        // was refused); the storefront's address is HTTP_CATALOG.
        $url = (string) $this->config->get('config_url');
        if ($url === '' && defined('HTTP_CATALOG')) {
            $url = HTTP_CATALOG;
        }

        return rtrim($url, '/') . '/';
    }

    /**
     * What this shop knows about itself, offered as a draft.
     *
     * A draft only: the legal name and country decide a buyer's withdrawal rights, so sudemi
     * shows them for a person to confirm and locks them afterwards.
     */
    private function proposedProfile(): array
    {
        return [
            'displayName' => $this->config->get('config_name'),
            'legalName' => $this->config->get('config_owner'),
            'establishmentCountry' => $this->countryCode(),
            'dispatchCountry' => $this->countryCode(),
            'traderStatus' => 'TRADER',
            'vatNumber' => '',
            'tradeRegisterNumber' => '',
        ];
    }

    private function countryCode(): string
    {
        $this->load->model('localisation/country');
        $country = $this->model_localisation_country->getCountry((int) $this->config->get('config_country_id'));

        return isset($country['iso_code_2']) ? (string) $country['iso_code_2'] : '';
    }

    /** Wires the library up with OpenCart's settings store. */
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
            'queue', 'heartbeat');
    }
}
