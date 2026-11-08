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
        $data['support'] = $this->supportPage($data['connected']);
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
        $ok = $sudemi['heartbeat']->syncAnchored();
        if ($ok === 'held') {
            $json['error'] = $this->language->get('text_sync_held');
        } else {
            $json[$ok ? 'success' : 'error'] = $ok
                ? $this->language->get('text_synced')
                : $this->language->get('text_sync_failed');
        }

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
        $sudemi['store']->delete(\Sudemi\Sync::SETTING_CATEGORIES);
        // A sync in progress belongs to the old connection.
        $sudemi['store']->delete(\Sudemi\Sync::SETTING_STATE);
        $sudemi['store']->delete(\Sudemi\Client::SETTING_INSTALL_ID);
        $sudemi['pairing']->clearChallenge();
        $json['success'] = $this->language->get('text_disconnected');

        $this->respond($json);
    }

    /** Opens a support ticket for this shop (signed call to sudemi; sudemi sends the e-mails). */
    public function supportOpen(): void
    {
        $this->load->language('extension/sudemi/module/sudemi');
        $json = [];

        if (!$this->guard($json)) {
            $this->respond($json);

            return;
        }

        $sudemi = $this->services();
        if (!$sudemi['client']->isConnected()) {
            $json['error'] = $this->language->get('text_support_not_connected');
            $this->respond($json);

            return;
        }
        $post = $this->request->post;
        $result = $sudemi['support']->open(
            $this->posted('category'),
            $this->posted('subject'),
            $this->posted('message'),
            $this->posted('order_id'),
            $this->adminLocale()
        );
        if ($result['ok']) {
            $json['success'] = sprintf($this->language->get('text_support_opened'),
                isset($result['data']['number']) ? (string) $result['data']['number'] : '');
            $json['ticket_id'] = (string) $result['data']['id'];
        } else {
            $json['error'] = $this->supportError($result['error']);
        }

        $this->respond($json);
    }

    /** Adds the shop's reply to one of its tickets. */
    public function supportReply(): void
    {
        $this->load->language('extension/sudemi/module/sudemi');
        $json = [];

        if (!$this->guard($json)) {
            $this->respond($json);

            return;
        }

        $sudemi = $this->services();
        if (!$sudemi['client']->isConnected()) {
            $json['error'] = $this->language->get('text_support_not_connected');
            $this->respond($json);

            return;
        }
        $post = $this->request->post;
        $result = $sudemi['support']->reply(
            $this->posted('ticket_id'),
            $this->posted('message')
        );
        if ($result['ok']) {
            $json['success'] = $this->language->get('text_support_replied');
            $json['ticket_id'] = (string) $result['data']['id'];
        } else {
            $json['error'] = $this->supportError($result['error']);
        }

        $this->respond($json);
    }

    /** Accepts a buyer's return request (signed call to sudemi). */
    public function requestAccept(): void
    {
        $this->answerRequest(true);
    }

    /** Rejects a buyer's return request; the reason is required and shown to the buyer. */
    public function requestReject(): void
    {
        $this->answerRequest(false);
    }

    /**
     * Answers one open return request: permission and token first, like every action. On success
     * the request leaves the stored list (Requests does that) and the local order gets a note in
     * its history; after an accept the merchant is told to refund once the item is back, through
     * the usual path (order status Refunded, which reaches sudemi).
     */
    private function answerRequest(bool $accept): void
    {
        $this->load->language('extension/sudemi/module/sudemi');
        $json = [];

        if (!$this->guard($json)) {
            $this->respond($json);

            return;
        }

        $sudemi = $this->services();
        if (!$sudemi['client']->isConnected()) {
            $json['error'] = $this->language->get('error_request_unavailable');
            $this->respond($json);

            return;
        }
        $orderId = strtolower(trim($this->posted('order_id')));
        $reason = $this->posted('reason');
        $result = $accept ? $sudemi['requests']->accept($orderId) : $sudemi['requests']->reject($orderId, $reason);
        $json['outcome'] = $result['outcome'];

        switch ($result['outcome']) {
            case \Sudemi\Requests::OUTCOME_OK:
                $json['success'] = $this->language->get($accept ? 'text_request_accepted' : 'text_request_rejected');
                $local = $sudemi['orders']->localOrderOf($orderId);
                if ($local) {
                    // Stored HTML-escaped, the way OpenCart stores every history comment typed in
                    // its admin: its order page prints history comments without escaping.
                    $sudemi['orders']->note($local, htmlspecialchars($accept
                        ? $this->language->get('text_request_note_accepted')
                        : sprintf($this->language->get('text_request_note_rejected'), trim($reason)), ENT_QUOTES, 'UTF-8'));
                }
                $sudemi['logger']->info('Return request on sudemi order ' . $orderId . ' '
                    . ($accept ? 'accepted.' : 'rejected.'));
                break;
            case \Sudemi\Requests::OUTCOME_ALREADY:
                $json['success'] = $this->language->get('text_request_already');
                break;
            case \Sudemi\Requests::OUTCOME_NOT_FOUND:
                $json['error'] = $this->language->get('error_request_not_found');
                break;
            case \Sudemi\Requests::OUTCOME_REASON_REQUIRED:
            case \Sudemi\Requests::OUTCOME_INVALID:
                $json['error'] = $this->language->get('error_request_reason');
                break;
            case \Sudemi\Requests::OUTCOME_SITE:
                $json['error'] = $this->language->get('text_site_mismatch');
                break;
            default:
                $json['error'] = $this->language->get('error_request_unavailable');
        }

        $this->respond($json);
    }

    /**
     * The open return requests as the last heartbeat reported them, with the local order each
     * belongs to (a link to OpenCart's order page) and the deadline.
     */
    private function returnRequests(array $sudemi): array
    {
        $rows = [];
        $now = gmdate('Y-m-d\TH:i');
        foreach ($sudemi['requests']->all() as $request) {
            $local = $sudemi['orders']->localOrderOf($request['order_id']);
            $rows[] = [
                'order_id' => $request['order_id'],
                'short' => substr($request['order_id'], 0, 8),
                'local' => $local ? (int) $local : 0,
                'href' => $local ? 'index.php?route=sale/order.info&order_id=' . (int) $local . '&user_token='
                    . rawurlencode((string) $this->session->data['user_token']) : '',
                'reason' => $request['reason'],
                'requested_at' => $this->supportDate($request['requested_at']),
                'respond_by' => $this->supportDate($request['respond_by']),
                'overdue' => $request['respond_by'] !== '' && substr($request['respond_by'], 0, 16) < $now,
            ];
        }

        return $rows;
    }

    /**
     * The Support section: this shop's tickets, and the thread of the one asked for in
     * ?sudemi_ticket=<uuid>. Read-only; opening and replying go through the guarded actions.
     */
    private function supportPage(bool $connected): array
    {
        $categories = [];
        foreach (\Sudemi\Support::CATEGORIES as $category) {
            $categories[] = ['value' => $category, 'label' => $this->language->get('text_support_category_' . strtolower($category))];
        }
        $page = ['connected' => $connected, 'categories' => $categories, 'tickets' => [], 'error' => '',
            'ticket' => null, 'ticket_error' => ''];
        if (!$connected) {
            return $page;
        }
        // Tickets can carry order details and the shop owner's own words: only for those who may
        // also act on them.
        if (!$this->user->hasPermission('modify', self::PERMISSION)) {
            $page['error'] = $this->language->get('error_permission');

            return $page;
        }

        try {
            $support = $this->services()['support'];
            $list = $support->tickets();
            if ($list['ok']) {
                foreach ($list['data'] as $ticket) {
                    $page['tickets'][] = $this->supportSummary($ticket);
                }
            } else {
                $page['error'] = $this->supportError($list['error']);
            }

            $wanted = isset($this->request->get['sudemi_ticket']) ? (string) $this->request->get['sudemi_ticket'] : '';
            if ($wanted !== '') {
                $one = $support->ticket($wanted);
                if ($one['ok']) {
                    $ticket = $this->supportSummary($one['data']);
                    $ticket['messages'] = [];
                    foreach ($one['data']['messages'] as $message) {
                        if (!is_array($message)) {
                            continue;
                        }
                        $fromSupport = isset($message['author']) && $message['author'] === 'SUPPORT';
                        $ticket['messages'][] = [
                            'support' => $fromSupport,
                            'author' => $this->language->get($fromSupport ? 'text_support_from_support' : 'text_support_from_shop'),
                            'body' => isset($message['body']) ? (string) $message['body'] : '',
                            'date' => $this->supportDate(isset($message['createdAt']) ? $message['createdAt'] : ''),
                        ];
                    }
                    $page['ticket'] = $ticket;
                } else {
                    $page['ticket_error'] = $this->supportError($one['error']);
                }
            }
        } catch (\Throwable $e) {
            $page['error'] = $this->language->get('error_support_unavailable');
        }

        return $page;
    }

    private function supportSummary(array $ticket): array
    {
        $status = isset($ticket['status']) && in_array($ticket['status'], \Sudemi\Support::STATUSES, true)
            ? (string) $ticket['status'] : 'OPEN';
        $category = isset($ticket['category']) && in_array($ticket['category'], \Sudemi\Support::CATEGORIES, true)
            ? (string) $ticket['category'] : 'OTHER';

        return [
            'id' => (string) $ticket['id'],
            'number' => isset($ticket['number']) ? (string) $ticket['number'] : '',
            'subject' => isset($ticket['subject']) ? (string) $ticket['subject'] : '',
            'status' => $status,
            'status_label' => $this->language->get('text_support_status_' . strtolower($status)),
            'category_label' => $this->language->get('text_support_category_' . strtolower($category)),
            'order_id' => isset($ticket['orderId']) && is_string($ticket['orderId']) ? $ticket['orderId'] : '',
            'updated' => $this->supportDate(isset($ticket['lastMessageAt']) ? $ticket['lastMessageAt']
                : (isset($ticket['updatedAt']) ? $ticket['updatedAt'] : '')),
            'href' => 'index.php?route=extension/sudemi/module/sudemi&user_token=' . rawurlencode((string) $this->session->data['user_token'])
                . '&sudemi_ticket=' . rawurlencode((string) $ticket['id']) . '#sudemi-support',
        ];
    }

    /** "2026-11-06T14:03:22.123Z" -> "2026-11-06 14:03 UTC". */
    private function supportDate($iso): string
    {
        if (!is_string($iso) || !preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})/', $iso, $m)) {
            return '';
        }

        return $m[1] . ' ' . $m[2] . ' UTC';
    }

    /**
     * The last beat's outcome, with sudemi's site_mismatch refusal worded in the admin's language:
     * this site is not the connected shop (a staging copy, a restored backup, or the shop moved).
     */
    private function listingStatus(array $status): array
    {
        if (isset($status['problem']) && $status['problem'] === 'site_mismatch') {
            $paired = isset($status['paired']) ? (string) $status['paired'] : '';
            $status['message'] = $paired !== ''
                ? sprintf($this->language->get('text_site_mismatch_at'), $paired)
                : $this->language->get('text_site_mismatch');
        }

        return $status;
    }

    private function supportError(?string $code): string
    {
        switch ($code) {
            case \Sudemi\Support::ERROR_INVALID:
                return $this->language->get('error_support_invalid');
            case \Sudemi\Support::ERROR_NOT_FOUND:
                return $this->language->get('error_support_not_found');
            case \Sudemi\Support::ERROR_TOO_MANY:
                return $this->language->get('error_support_too_many');
            case \Sudemi\Support::ERROR_SITE:
                return $this->language->get('text_site_mismatch');
            default:
                return $this->language->get('error_support_unavailable');
        }
    }

    /**
     * A posted field as the person typed it. OpenCart's Request runs htmlspecialchars over all
     * input; sudemi stores plain text and escapes on output, so the encoding is undone here
     * (otherwise "<" would reach support as "&lt;").
     */
    private function posted(string $field): string
    {
        $value = isset($this->request->post[$field]) && is_string($this->request->post[$field])
            ? $this->request->post[$field] : '';

        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** The admin's language as sudemi knows it ("de-de" -> "de"), or null. */
    private function adminLocale(): ?string
    {
        $code = (string) $this->language->get('code');
        if ($code === '' || $code === 'code') {
            $code = (string) $this->config->get('config_language_admin');
        }

        return \Sudemi\Support::locale($code);
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

        // The right-of-withdrawal table and the product-form event (also run on upgrade, see
        // \Sudemi\Withdrawal::ensureSchema()).
        $sudemi = $this->services();
        $sudemi['withdrawal']->ensureSchema($sudemi['store'], true);
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
        // The exclusions themselves stay: OpenCart shops often uninstall and reinstall to upgrade,
        // and that must not silently give every excluded product back its right of withdrawal.
        $sudemi = $this->services();
        $sudemi['withdrawal']->removeEvents();
        $sudemi['store']->delete(\Sudemi\Withdrawal::SETTING_SCHEMA);
    }

    /**
     * The fallback beat, for a shop whose host has no cron entry for us.
     *
     * Not the supported path - a shop nobody logs into would go quiet and have its listings
     * paused, correctly - but it means the common case of "installed the extension, never set
     * up cron" keeps working rather than failing silently a week later. Only on this settings
     * page, so no storefront request ever waits on our network.
     *
     * Run after the page was produced (after it was sent, where PHP-FPM allows it): the beat
     * when one is due, and a small step (at most Sync::STEP_PRODUCTS products, about two
     * seconds) of a waiting full sync. Never the whole sync: on a large catalogue that held the
     * admin's screen for minutes. The cron entry runs the rest; without cron the following page
     * loads continue where the last one stopped.
     */
    private function beatIfDue(): void
    {
        try {
            $sudemi = $this->services();
            if (!$sudemi['client']->isConnected()
                || (!$sudemi['heartbeat']->isDue() && !$sudemi['sync']->pending())) {
                return;
            }
        } catch (\Throwable $e) {
            // A settings page that cannot render because a marketplace is down is worse than a
            // missed beat.
            return;
        }
        register_shutdown_function(function () use ($sudemi) {
            if (function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request')) {
                while (ob_get_level() > 0 && @ob_end_flush()) {
                }
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                } else {
                    litespeed_finish_request();
                }
            }
            ignore_user_abort(true);
            try {
                if ($sudemi['heartbeat']->isDue()) {
                    $sudemi['heartbeat']->run();
                }
                $sudemi['sync']->work(\Sudemi\Sync::STEP_PRODUCTS, \Sudemi\Sync::STEP_SECONDS);
            } catch (\Throwable $e) {
                $sudemi['logger']->warn('Background step failed: ' . $e->getMessage());
            }
        });
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
            'requests' => $sudemi['client']->isConnected() ? $this->returnRequests($sudemi) : [],
            'log' => $sudemi['logger']->entries(),
            'status' => $this->listingStatus($sudemi['heartbeat']->status()),
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
                'customer' => isset($order['customer_name']) ? (string) $order['customer_name'] : ' - ',
                // An open return request on this order (answered in the list above).
                'return_requested' => !empty($order['customer_request']) && is_array($order['customer_request']),
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
        require_once DIR_EXTENSION . 'sudemi/system/library/sudemi/withdrawal.php';
        require_once DIR_EXTENSION . 'sudemi/system/library/sudemi/support.php';
        require_once DIR_EXTENSION . 'sudemi/system/library/sudemi/requests.php';

        $this->load->model('setting/setting');
        $store = new \Sudemi\SettingStore($this->db);

        $logger = new \Sudemi\Logger($store);
        $keys = new \Sudemi\Keys($this->config, $store);
        $client = new \Sudemi\Client($keys, $store, $logger);
        // The address pairing announced, so Sudemi-Site names this shop the same way.
        $client->setSiteUrl($this->shopUrl());
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
        $support = new \Sudemi\Support($client);
        $requests = new \Sudemi\Requests($store, $client);

        return compact('store', 'logger', 'keys', 'client', 'pairing', 'catalogue', 'sync',
            'queue', 'orders', 'heartbeat', 'withdrawal', 'support', 'requests');
    }
}
