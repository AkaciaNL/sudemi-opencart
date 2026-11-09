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
            // The cron URL has to work before this page is shown again: the next cron run is what
            // collects this pairing once the shop owner has confirmed it on sudemi.
            $sudemi['heartbeat']->cronToken();
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
        // Also connected when cron (or the background poll) collected it a moment earlier.
        if ($sudemi['pairing']->poll() || $sudemi['client']->isConnected()) {
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

        // Only asked for: a full sync never runs inside a page request. The cron entry sends it
        // (or, without cron, small steps after this page is opened; see Sync::work).
        $this->services()['sync']->request();
        $json['success'] = $this->language->get('text_sync_started');

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
        // Every product carries the times: the catalogue is sent again (by the cron entry).
        $sudemi['sync']->request();
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

    // ------------------------------------------------------------------------------------------
    // 1.3.0: sudemi orders, shipping and payouts inside the OpenCart admin. sudemi is the source
    // of truth: every page reads from sudemi, every action is sent to sudemi, and sudemi's answer
    // (the order page read back) is what the local order is brought to.
    // ------------------------------------------------------------------------------------------

    /** The portal's order statuses, in its order. */
    const ORDER_STATUSES = ['AWAITING_SELLER', 'ACCEPTED', 'PAID', 'SHIPPED', 'DELIVERED', 'DECLINED', 'CANCELLED', 'REFUNDED'];
    const PAGE_SIZE = 25;
    const BULK_MAX = 100;

    /** Extensions > sudemi > Orders: the portal's order table, waiting orders, payouts, bulk actions. */
    public function orders(): void
    {
        $this->load->language('extension/sudemi/module/sudemi');
        $this->document->setTitle($this->language->get('text_orders_title'));
        $sudemi = $this->services();
        $data = $this->shell();
        $data['connected'] = $sudemi['client']->isConnected();
        $data['auto_confirm'] = $sudemi['orders']->autoConfirm();
        $filters = $this->orderFilters();
        $data['filters'] = $filters;
        $data['statuses'] = [];
        foreach (self::ORDER_STATUSES as $status) {
            $data['statuses'][] = ['value' => $status, 'label' => $this->statusLabel($status)];
        }
        $data['orders'] = [];
        $data['waiting'] = [];
        $data['error'] = '';
        $data['payouts'] = null;
        $data['total'] = 0;
        $data['prev_href'] = '';
        $data['next_href'] = '';
        $data['export_href'] = '';
        if ($data['connected']) {
            $page = max(1, (int) $filters['page']);
            $answer = $sudemi['client']->sendSigned('GET', '/integration/v1/orders' . \Sudemi\Client::query([
                'status' => $filters['status'], 'from' => $filters['from'], 'to' => $filters['to'], 'q' => $filters['q'],
                'sort' => $filters['sort'], 'dir' => $filters['dir'], 'limit' => self::PAGE_SIZE, 'offset' => ($page - 1) * self::PAGE_SIZE,
            ]));
            if (is_array($answer) && (int) $answer['status'] === 200 && isset($answer['body']['orders'])) {
                foreach ($answer['body']['orders'] as $row) {
                    if (is_array($row) && isset($row['order_id'])) {
                        $data['orders'][] = $this->orderRow($sudemi, $row);
                    }
                }
                $data['total'] = isset($answer['body']['total']) ? (int) $answer['body']['total'] : count($data['orders']);
                $data['counts'] = isset($answer['body']['counts']) && is_array($answer['body']['counts']) ? $answer['body']['counts'] : [];
                if ($page > 1) {
                    $data['prev_href'] = $this->ordersHref(array_merge($filters, ['page' => $page - 1]));
                }
                if (isset($answer['body']['next_offset']) && $answer['body']['next_offset'] !== null) {
                    $data['next_href'] = $this->ordersHref(array_merge($filters, ['page' => $page + 1]));
                }
            } elseif (is_array($answer) && (int) $answer['status'] === 400) {
                $data['error'] = $this->language->get('error_orders_filter');
            } else {
                $data['error'] = $this->language->get('error_orders_unavailable');
            }
            // Orders waiting for this shop's answer, oldest first (their deadline is nearest).
            $waiting = $sudemi['client']->sendSigned('GET', '/integration/v1/orders' . \Sudemi\Client::query([
                'status' => 'AWAITING_SELLER', 'sort' => 'placed', 'dir' => 'asc', 'limit' => 50, 'offset' => 0]));
            if (is_array($waiting) && (int) $waiting['status'] === 200 && isset($waiting['body']['orders'])) {
                foreach ($waiting['body']['orders'] as $row) {
                    if (is_array($row) && isset($row['order_id'])) {
                        $data['waiting'][] = $this->orderRow($sudemi, $row);
                    }
                }
            }
            $data['payouts'] = $this->payoutsPanel($sudemi['client']);
            $export = ['status' => $filters['status'], 'from' => $filters['from'], 'to' => $filters['to'], 'q' => $filters['q'],
                'sort' => $filters['sort'], 'dir' => $filters['dir']];
            $data['export_href'] = 'index.php?route=extension/sudemi/module/sudemi.export&user_token=' . rawurlencode((string) $this->session->data['user_token'])
                . '&' . http_build_query(array_filter($export, 'strlen'));
        }
        $data['bulk_max'] = self::BULK_MAX;
        $this->response->setOutput($this->load->view('extension/sudemi/module/sudemi_orders', $data));
    }

    /** One sudemi order: lines, money, tracking, timeline, payout, the buyer's request and the actions sudemi allows now. */
    public function order(): void
    {
        $this->load->language('extension/sudemi/module/sudemi');
        $this->document->setTitle($this->language->get('text_order_title'));
        $sudemi = $this->services();
        $data = $this->shell();
        $id = strtolower(isset($this->request->get['order_id']) ? (string) $this->request->get['order_id'] : '');
        $data['order'] = null;
        $data['error'] = '';
        $data['back_href'] = 'index.php?route=extension/sudemi/module/sudemi.orders&user_token=' . rawurlencode((string) $this->session->data['user_token']);
        if (!\Sudemi\Requests::isId($id) || !$sudemi['client']->isConnected()) {
            $data['error'] = $this->language->get('error_order_not_found');
        } else {
            $response = $sudemi['client']->sendSigned('GET', '/integration/v1/orders/' . rawurlencode($id));
            if (is_array($response) && (int) $response['status'] === 200 && is_array($response['body'])) {
                // What the page shows is what sudemi holds: brought here too, so this shop's copy
                // never lags behind what the merchant is looking at.
                $sudemi['orders']->applyChange(\Sudemi\Orders::changeFromDetail($response['body']), $response['body']);
                $data['order'] = $this->orderDetail($sudemi, $response['body']);
            } elseif (is_array($response) && in_array((int) $response['status'], [404, 409], true)) {
                $data['error'] = $this->language->get('error_order_not_found');
            } else {
                $data['error'] = $this->language->get('error_orders_unavailable');
            }
        }
        $this->response->setOutput($this->load->view('extension/sudemi/module/sudemi_order', $data));
    }

    /**
     * The CSV export, signed and fetched from sudemi, handed to the browser as a download. A GET
     * (a link), so the user token is checked here like every action, and the access permission.
     */
    public function export(): void
    {
        $this->load->language('extension/sudemi/module/sudemi');
        $presented = isset($this->request->get['user_token']) ? (string) $this->request->get['user_token'] : '';
        if (!$this->user->hasPermission('access', self::PERMISSION)
            || $presented === '' || !hash_equals((string) $this->session->data['user_token'], $presented)) {
            $this->response->addHeader('Content-Type: text/plain; charset=utf-8');
            $this->response->setOutput($this->language->get('error_permission'));

            return;
        }
        $sudemi = $this->services();
        $filters = $this->orderFilters();
        $decimal = isset($this->request->get['decimal']) && $this->request->get['decimal'] === 'comma' ? 'comma' : '';
        $answer = $sudemi['client']->getRaw('/integration/v1/orders/export.csv' . \Sudemi\Client::query([
            'status' => $filters['status'], 'from' => $filters['from'], 'to' => $filters['to'], 'q' => $filters['q'],
            'sort' => $filters['sort'], 'dir' => $filters['dir'], 'lang' => $this->adminLocale() ?: 'en', 'decimal' => $decimal,
        ]));
        if (!is_array($answer) || (int) $answer['status'] !== 200) {
            $this->response->addHeader('Content-Type: text/plain; charset=utf-8');
            $this->response->setOutput($this->language->get('error_orders_unavailable'));

            return;
        }
        $name = 'sudemi-orders.csv';
        if (isset($answer['headers']['content-disposition'])
            && preg_match('/filename="(sudemi-orders-[0-9-]+\.csv)"/', $answer['headers']['content-disposition'], $m)) {
            $name = $m[1];
        }
        $this->response->addHeader('Content-Type: text/csv; charset=UTF-8');
        $this->response->addHeader('Content-Disposition: attachment; filename="' . $name . '"');
        $this->response->addHeader('Cache-Control: no-store');
        $this->response->addHeader('X-Content-Type-Options: nosniff');
        foreach (['x-total-count' => 'X-Total-Count', 'x-export-truncated' => 'X-Export-Truncated'] as $from => $header) {
            if (isset($answer['headers'][$from]) && preg_match('/^[a-z0-9]+$/i', $answer['headers'][$from])) {
                $this->response->addHeader($header . ': ' . $answer['headers'][$from]);
            }
        }
        $this->response->setOutput($answer['raw']);
    }

    /** Pending orders: answered automatically (the default) or by a person on the Orders page. */
    public function autoConfirm(): void
    {
        $this->load->language('extension/sudemi/module/sudemi');
        $json = [];
        if (!$this->guard($json)) {
            $this->respond($json);

            return;
        }
        $on = $this->posted('auto_confirm') === '1';
        $this->services()['orders']->setAutoConfirm($on);
        $json['success'] = $this->language->get($on ? 'text_auto_confirm_on' : 'text_auto_confirm_off');
        $json['auto_confirm'] = $on;
        $this->respond($json);
    }

    /** The carrier and tracking code typed on OpenCart's order page (the sudemi panel). */
    public function tracking(): void
    {
        $this->load->language('extension/sudemi/module/sudemi');
        $json = [];
        if (!$this->guard($json)) {
            $this->respond($json);

            return;
        }
        $sudemi = $this->services();
        $orders = $sudemi['orders'];
        $id = strtolower($this->posted('order_id'));
        $result = \Sudemi\Requests::isId($id) ? $orders->updateTracking($id, $this->posted('carrier'), $this->posted('tracking')) : 'invalid';
        // Saved here in every case but invalid; sent to sudemi at once when the order was already
        // reported shipped (tracking added or corrected after shipping).
        $messages = [
            'saved' => ['success', 'text_tracking_saved'],
            'sent' => ['success', 'text_tracking_sent'],
            'unchanged' => ['success', 'text_tracking_unchanged'],
            'retry' => ['success', 'text_tracking_retry'],
            'final' => ['error', 'error_tracking_final'],
            'failed' => ['error', 'error_tracking_failed'],
            'code_required' => ['error', 'error_tracking_code_required'],
            'invalid' => ['error', 'error_order_not_found'],
        ];
        list($kind, $key) = isset($messages[$result]) ? $messages[$result] : $messages['invalid'];
        $json[$kind] = $this->language->get($key);
        $json['result'] = $result;
        if (in_array($result, ['sent', 'unchanged'], true)) {
            // sudemi's answer, applied here (new revision; the tracking it now holds).
            $orders->refresh($id);
        }
        $this->respond($json);
    }

    /**
     * One action on one order, as sudemi's allowed_actions offer it: accept, reject, shipped,
     * update_tracking, delivered, refund (part or all), cancel, request_accept, request_reject. Sent to sudemi;
     * then the order is read back and the local order brought to it.
     */
    public function orderAction(): void
    {
        $this->load->language('extension/sudemi/module/sudemi');
        $json = [];
        if (!$this->guard($json)) {
            $this->respond($json);

            return;
        }
        $sudemi = $this->services();
        $id = strtolower($this->posted('order_id'));
        $action = $this->posted('action');
        $reason = trim($this->posted('reason'));
        if (!\Sudemi\Requests::isId($id) || !$sudemi['client']->isConnected()) {
            $json['error'] = $this->language->get('error_order_not_found');
            $this->respond($json);

            return;
        }
        $orders = $sudemi['orders'];
        $path = '/integration/v1/orders/' . rawurlencode($id) . '/';
        $key = preg_match('/^[A-Za-z0-9-]{8,64}$/', $this->posted('key')) ? 'oc-' . $this->posted('key') : null;
        switch ($action) {
            case 'accept':
                $result = $orders->acceptManually($id);
                if ($result['outcome'] === 'paid') {
                    $json['success'] = $this->language->get('text_action_accepted');
                } elseif ($result['outcome'] === 'waiting') {
                    $json['success'] = $this->language->get('text_action_accepted_waiting');
                } elseif ($result['outcome'] === 'refused' && $result['error'] === 'shortfall') {
                    $json['error'] = sprintf($this->language->get('error_action_shortfall'), (string) $result['reason']);
                } elseif ($result['outcome'] === 'declined' || $result['outcome'] === 'cancelled') {
                    $json['error'] = $this->language->get('error_action_too_late');
                } elseif ($result['outcome'] === 'refused' && $result['error'] === 'wrong_state') {
                    $json['error'] = $this->language->get('error_action_wrong_state');
                } else {
                    $json['error'] = $this->language->get('error_orders_unavailable');
                }
                break;
            case 'reject':
                if ($reason === '') {
                    $json['error'] = $this->language->get('error_action_reason');
                    break;
                }
                $outcome = $orders->rejectManually($id, $reason);
                if ($outcome === 'declined') {
                    $json['success'] = $this->language->get('text_action_declined');
                } elseif ($outcome === 'retry') {
                    $json['error'] = $this->language->get('error_orders_unavailable');
                } else {
                    $json['error'] = $this->language->get('error_action_wrong_state');
                }
                break;
            case 'shipped':
                $carrier = $this->posted('carrier');
                $code = $this->posted('tracking');
                $orders->saveTracking($id, $carrier, $code);
                $this->actionAnswer($json, $sudemi['client']->sendSigned('POST', $path . 'shipped',
                    ['carrier' => trim($carrier) === '' ? null : trim($carrier), 'trackingCode' => trim($code) === '' ? null : trim($code)]),
                    'text_action_shipped');
                break;
            case 'update_tracking':
                // Tracking added or corrected after shipping (sudemi offers it while SHIPPED).
                $carrier = trim($this->posted('carrier'));
                $code = trim($this->posted('tracking'));
                if ($code === '') {
                    $json['error'] = $this->language->get('error_tracking_code_required');
                    break;
                }
                $orders->saveTracking($id, $carrier, $code);
                $this->actionAnswer($json, $sudemi['client']->sendSigned('POST', $path . 'tracking',
                    ['carrier' => $carrier === '' ? null : $carrier, 'trackingCode' => $code]), 'text_action_tracking_updated');
                break;
            case 'delivered':
                $this->actionAnswer($json, $sudemi['client']->sendSigned('POST', $path . 'delivered', []), 'text_action_delivered');
                break;
            case 'refund':
                $currency = $this->posted('currency');
                $amount = str_replace(',', '.', trim($this->posted('amount')));
                $minor = null;
                if ($amount !== '') {
                    if (!preg_match('/^\d+(\.\d{1,3})?$/', $amount) || ($minor = \Sudemi\Currency::toMinor($amount, $currency)) <= 0) {
                        $json['error'] = $this->language->get('error_action_amount');
                        break;
                    }
                }
                $orders->refund($id, $minor, $reason === '' ? 'Refunded by the shop' : $reason, $currency !== '' ? $currency : null, $key);
                $this->actionAnswer($json, $orders->lastResponse, 'text_action_refunded');
                break;
            case 'cancel':
                $orders->cancel($id, $reason === '' ? 'Cancelled by the shop' : $reason, $key);
                $this->actionAnswer($json, $orders->lastResponse, 'text_action_cancelled');
                break;
            case 'request_accept':
            case 'request_reject':
                $result = $action === 'request_accept' ? $sudemi['requests']->accept($id) : $sudemi['requests']->reject($id, $reason);
                if ($result['outcome'] === \Sudemi\Requests::OUTCOME_OK) {
                    $json['success'] = $this->language->get($action === 'request_accept' ? 'text_request_accepted' : 'text_request_rejected');
                    $local = $orders->localOrderOf($id);
                    if ($local) {
                        $orders->note($local, htmlspecialchars($action === 'request_accept'
                            ? $this->language->get('text_request_note_accepted')
                            : sprintf($this->language->get('text_request_note_rejected'), $reason), ENT_QUOTES, 'UTF-8'));
                    }
                } elseif ($result['outcome'] === \Sudemi\Requests::OUTCOME_ALREADY) {
                    $json['success'] = $this->language->get('text_request_already');
                } elseif ($result['outcome'] === \Sudemi\Requests::OUTCOME_REASON_REQUIRED || $result['outcome'] === \Sudemi\Requests::OUTCOME_INVALID) {
                    $json['error'] = $this->language->get('error_request_reason');
                } elseif ($result['outcome'] === \Sudemi\Requests::OUTCOME_NOT_FOUND) {
                    $json['error'] = $this->language->get('error_request_not_found');
                } else {
                    $json['error'] = $this->language->get('error_request_unavailable');
                }
                break;
            default:
                $json['error'] = $this->language->get('error_action_unknown');
        }
        if (!in_array($action, ['accept', 'reject'], true) && empty($json['error'])) {
            // sudemi's answer, applied here (accept and reject read it back themselves). The order
            // page does not say who cancelled; a cancel pressed here was the shop's.
            $orders->refresh($id, $action === 'cancel' ? 'shop' : null);
        }
        $this->respond($json);
    }

    /** Fills $json from sudemi's answer to an action: success, or sudemi's refusal in its own words. */
    private function actionAnswer(array &$json, $response, string $successKey): void
    {
        if (is_array($response) && (int) $response['status'] === 200) {
            $already = isset($response['body']['status']) && $response['body']['status'] === 'already_resolved';
            $json['success'] = $this->language->get($already ? 'text_action_already' : $successKey);

            return;
        }
        if (!is_array($response) || (int) $response['status'] >= 500 || (int) $response['status'] === 429) {
            $json['error'] = $this->language->get('error_orders_unavailable');

            return;
        }
        $message = isset($response['body']['message']) ? (string) $response['body']['message']
            : (isset($response['body']['error']) ? (string) $response['body']['error'] : (string) $response['status']);
        $json['error'] = sprintf($this->language->get('error_action_refused'), $message);
    }

    /**
     * Several orders at once, as the portal's bulk action: accept, reject (one reason for all),
     * shipped (a carrier, a tracking code per order) or delivered; at most 100.
     */
    public function bulk(): void
    {
        $this->load->language('extension/sudemi/module/sudemi');
        $json = [];
        if (!$this->guard($json)) {
            $this->respond($json);

            return;
        }
        $sudemi = $this->services();
        $action = $this->posted('action');
        $reason = trim($this->posted('reason'));
        $ids = [];
        foreach ((isset($this->request->post['ids']) && is_array($this->request->post['ids']) ? $this->request->post['ids'] : []) as $raw) {
            $id = strtolower(trim((string) $raw));
            if (\Sudemi\Requests::isId($id) && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        if (!in_array($action, ['accept', 'reject', 'shipped', 'delivered'], true) || empty($ids) || count($ids) > self::BULK_MAX) {
            $json['error'] = $this->language->get('error_bulk_choose');
            $this->respond($json);

            return;
        }
        if ($action === 'reject' && $reason === '') {
            $json['error'] = $this->language->get('error_action_reason');
            $this->respond($json);

            return;
        }
        $orders = $sudemi['orders'];
        $tracking = isset($this->request->post['tracking']) && is_array($this->request->post['tracking']) ? $this->request->post['tracking'] : [];
        $carrier = trim($this->posted('carrier'));
        $items = [];
        $local = [];
        $skipped = [];
        foreach ($ids as $id) {
            $item = ['orderId' => $id];
            if ($action === 'accept') {
                // Booked here first, as a single accept is, so the stock is taken before the money.
                $ready = $orders->prepareAccept($id);
                if (!$ready['ok']) {
                    $skipped[] = substr($id, 0, 8) . ': ' . ($ready['error'] === 'shortfall' ? (string) $ready['reason'] : $this->language->get('error_action_wrong_state'));
                    continue;
                }
                $local[$id] = $ready['order_id'];
            }
            if ($action === 'shipped') {
                $code = isset($tracking[$id]) && is_string($tracking[$id]) ? trim(html_entity_decode($tracking[$id], ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
                $item['carrier'] = $carrier === '' ? null : $carrier;
                $item['trackingCode'] = $code === '' ? null : $code;
                $orders->saveTracking($id, $carrier, $code);
            }
            $items[] = $item;
        }
        $done = 0;
        $refused = 0;
        $messages = $skipped;
        if (!empty($items)) {
            $body = ['action' => $action, 'items' => $items];
            if ($action === 'reject') {
                $body['reason'] = $reason;
            }
            $response = $sudemi['client']->sendSigned('POST', '/integration/v1/orders/bulk', $body);
            if (!is_array($response) || (int) $response['status'] !== 200 || !isset($response['body']['results'])) {
                $json['error'] = is_array($response) && (int) $response['status'] === 403
                    ? $this->language->get('error_bulk_suspended') : $this->language->get('error_orders_unavailable');
                $this->respond($json);

                return;
            }
            foreach ($response['body']['results'] as $result) {
                if (!is_array($result) || !isset($result['order_id'])) {
                    continue;
                }
                $oid = strtolower((string) $result['order_id']);
                if ($action === 'accept' && isset($local[$oid])) {
                    $orders->applyBulkAccept($result, $local[$oid]);
                } else {
                    $orders->refresh($oid);
                }
                if (isset($result['result']) && $result['result'] === 'done') {
                    ++$done;
                } else {
                    ++$refused;
                    $messages[] = substr($oid, 0, 8) . ': ' . (isset($result['message']) ? (string) $result['message']
                        : (isset($result['error']) ? (string) $result['error'] : ''));
                }
            }
        }
        $json['done'] = $done;
        $json['refused'] = $refused + count($skipped);
        $json['messages'] = $messages;
        $json['success'] = sprintf($this->language->get('text_bulk_result'), $done, $refused + count($skipped));
        $this->respond($json);
    }

    /** Extensions > sudemi > Shipping: where this shop delivers sudemi buyers to, and at what price. */
    public function shipping(): void
    {
        $this->load->language('extension/sudemi/module/sudemi');
        $this->document->setTitle($this->language->get('text_shipping_title'));
        $sudemi = $this->services();
        $data = $this->shell();
        $data['connected'] = $sudemi['client']->isConnected();
        $data['settings'] = null;
        $data['error'] = '';
        if ($data['connected']) {
            $response = $sudemi['client']->sendSigned('GET', '/integration/v1/shipping');
            if (is_array($response) && (int) $response['status'] === 200 && is_array($response['body'])) {
                $data['settings'] = $this->shippingView($response['body']);
            } else {
                $data['error'] = $this->language->get('error_shipping_unavailable');
            }
        }
        $this->response->setOutput($this->load->view('extension/sudemi/module/sudemi_shipping', $data));
    }

    /** Saves the shipping settings on sudemi (POST /shipping), with sudemi's checks. */
    public function shippingSave(): void
    {
        $this->load->language('extension/sudemi/module/sudemi');
        $json = [];
        if (!$this->guard($json)) {
            $this->respond($json);

            return;
        }
        $sudemi = $this->services();
        $mode = $this->posted('mode') === 'custom' ? 'custom' : 'shop';
        $currency = \Sudemi\Currency::normalise($this->posted('currency')) ?: (string) $this->config->get('config_currency');
        $body = ['mode' => $mode];
        if ($mode === 'custom') {
            $countries = [];
            foreach ((isset($this->request->post['countries']) && is_array($this->request->post['countries']) ? $this->request->post['countries'] : []) as $code => $row) {
                $code = strtoupper((string) $code);
                if (!preg_match('/^[A-Z]{2}$/', $code) || !is_array($row)) {
                    continue;
                }
                $price = isset($row['price']) && is_string($row['price']) ? str_replace(',', '.', trim($row['price'])) : '';
                if ($price !== '' && !preg_match('/^\d+(\.\d{1,3})?$/', $price)) {
                    $json['error'] = sprintf($this->language->get('error_shipping_price'), $code);
                    $this->respond($json);

                    return;
                }
                $countries[] = ['country' => $code, 'enabled' => !empty($row['enabled']),
                    'priceMinor' => $price === '' ? null : \Sudemi\Currency::toMinor($price, $currency)];
            }
            $body['countries'] = $countries;
        }
        $response = $sudemi['client']->sendSigned('POST', '/integration/v1/shipping', $body);
        if (is_array($response) && (int) $response['status'] === 200) {
            $json['success'] = $this->language->get('text_shipping_saved');
        } elseif (is_array($response) && (int) $response['status'] === 400) {
            $json['error'] = sprintf($this->language->get('error_action_refused'),
                isset($response['body']['message']) ? (string) $response['body']['message'] : 'invalid_request');
        } else {
            $json['error'] = $this->language->get('error_shipping_unavailable');
        }
        $this->respond($json);
    }

    /** GET /shipping for the page: rows per country, with the shop's own price as the prefill. */
    private function shippingView(array $body): array
    {
        $currency = isset($body['currency']) && $body['currency'] ? (string) $body['currency'] : (string) $this->config->get('config_currency');
        $effective = [];
        foreach ((isset($body['effective']) && is_array($body['effective']) ? $body['effective'] : []) as $e) {
            if (is_array($e) && isset($e['country'])) {
                $effective[(string) $e['country']] = $e;
            }
        }
        // The shop's own price per country (OpenCart's geo zones and shipping methods, one item of
        // the first product), as the catalogue sync sends it: the "fill in from my shop" values.
        $prefill = [];
        foreach ($this->shippingRates() as $rate) {
            $prefill[$rate['country']] = $this->decimal($rate['costMinor'], $currency);
        }
        $names = $this->countryNames();
        $rows = [];
        foreach ((isset($body['countries']) && is_array($body['countries']) ? $body['countries'] : []) as $c) {
            if (!is_array($c) || !isset($c['country'])) {
                continue;
            }
            $code = (string) $c['country'];
            $e = isset($effective[$code]) ? $effective[$code] : [];
            $range = function ($low, $high) use ($currency) {
                if ($low === null) {
                    return '';
                }
                $text = \Sudemi\Currency::format((int) $low, $currency);

                return $high !== null && (int) $high !== (int) $low ? $text . ' - ' . \Sudemi\Currency::format((int) $high, $currency) : $text;
            };
            $source = isset($e['source']) ? (string) $e['source'] : '';
            $rows[] = [
                'country' => $code,
                'name' => isset($names[$code]) ? $names[$code] : $code,
                'enabled' => !empty($c['enabled']),
                'price' => isset($c['price_minor']) && $c['price_minor'] !== null ? $this->decimal((int) $c['price_minor'], $currency) : '',
                'shop_price' => $range(isset($c['shop_price_min_minor']) ? $c['shop_price_min_minor'] : null, isset($c['shop_price_max_minor']) ? $c['shop_price_max_minor'] : null),
                'prefill' => isset($prefill[$code]) ? $prefill[$code] : '',
                'offered' => !empty($e['offered']),
                'effective' => !empty($e['offered']) ? $range(isset($e['min_minor']) ? $e['min_minor'] : null, isset($e['max_minor']) ? $e['max_minor'] : null) : '',
                'source' => $source === 'sudemi_price' ? $this->language->get('text_shipping_source_sudemi')
                    : ($source === 'shop_price' ? $this->language->get('text_shipping_source_shop') : ''),
            ];
        }

        return [
            'mode' => isset($body['mode']) && $body['mode'] === 'custom' ? 'custom' : 'shop',
            'currency' => $currency,
            'max_price' => isset($body['max_price_minor']) ? \Sudemi\Currency::format((int) $body['max_price_minor'], $currency) : '',
            'countries' => $rows,
        ];
    }

    /** The shop's own shipping price per country, for one item of the first product. */
    private function shippingRates(): array
    {
        $catalogue = new \Sudemi\Catalogue($this->db, $this->config);
        $first = $catalogue->products(0, 1);

        return empty($first) || empty($first[0]['shipping']) ? [] : $first[0]['shipping'];
    }

    /** ISO code => the country's name in the admin's language (English names as a fallback). */
    private function countryNames(): array
    {
        $this->load->model('localisation/country');
        $names = [];
        foreach ($this->model_localisation_country->getCountries() as $country) {
            if (isset($country['iso_code_2'], $country['name'])) {
                $names[(string) $country['iso_code_2']] = html_entity_decode((string) $country['name'], ENT_QUOTES, 'UTF-8');
            }
        }

        return $names;
    }

    private function decimal(int $minor, string $currency): string
    {
        $decimals = \Sudemi\Currency::decimals($currency);

        return number_format(\Sudemi\Currency::fromMinor($minor, $currency), $decimals, '.', '');
    }

    /** The payouts panel (GET /payouts): the payout account's state and this shop's money per currency. */
    private function payoutsPanel($client): ?array
    {
        $response = $client->sendSigned('GET', '/integration/v1/payouts');
        if (!is_array($response) || (int) $response['status'] !== 200 || !is_array($response['body'])) {
            return null;
        }
        $body = $response['body'];
        $state = isset($body['account']['state']) ? (string) $body['account']['state'] : '';
        $currencies = [];
        foreach ((isset($body['currencies']) && is_array($body['currencies']) ? $body['currencies'] : []) as $c) {
            if (!is_array($c) || !isset($c['currency_code'])) {
                continue;
            }
            $code = (string) $c['currency_code'];
            $money = function ($key) use ($c, $code) {
                return \Sudemi\Currency::format(isset($c[$key]) ? (int) $c[$key] : 0, $code);
            };
            $currencies[] = [
                'currency' => $code,
                'orders' => isset($c['orders']) ? (int) $c['orders'] : 0,
                'paid_out' => $money('paid_out_minor'),
                'outstanding' => $money('outstanding_minor'),
                'waiting' => $money('waiting_for_shipment_minor'),
                'scheduled' => $money('scheduled_minor'),
                'processing' => $money('processing_minor'),
                'held' => $money('held_minor'),
                'next_due' => $this->supportDate(isset($c['next_due_at']) ? $c['next_due_at'] : ''),
            ];
        }
        $key = 'text_account_' . strtolower($state);
        $label = $this->language->get($key);

        return [
            'state' => $state,
            'state_label' => $label !== $key ? $label : $state,
            'active' => $state === 'ACTIVE',
            'delay_days' => isset($body['payout_delay_days']) ? (int) $body['payout_delay_days'] : 0,
            'currencies' => $currencies,
        ];
    }

    /** One row of the order table. */
    private function orderRow(array $sudemi, array $row): array
    {
        $id = (string) $row['order_id'];
        $currency = isset($row['currency_code']) ? (string) $row['currency_code'] : 'EUR';
        $items = [];
        foreach ((isset($row['lines']) && is_array($row['lines']) ? $row['lines'] : []) as $line) {
            $qty = isset($line['quantity']) ? (int) $line['quantity'] : 1;
            $items[] = (isset($line['title']) ? (string) $line['title'] : '') . ($qty > 1 ? ' x ' . $qty : '');
        }
        $local = $sudemi['orders']->localOrderOf($id);
        $status = isset($row['status']) ? (string) $row['status'] : '';
        $payout = isset($row['payout']) && is_array($row['payout']) ? $row['payout'] : null;
        $tracking = isset($row['tracking']) && is_array($row['tracking']) ? $row['tracking'] : null;

        return [
            'order_id' => $id,
            'short' => substr($id, 0, 8),
            'href' => 'index.php?route=extension/sudemi/module/sudemi.order&user_token=' . rawurlencode((string) $this->session->data['user_token']) . '&order_id=' . rawurlencode($id),
            'placed' => $this->supportDate(isset($row['placed_at']) ? $row['placed_at'] : ''),
            'items' => implode(', ', $items),
            'country' => isset($row['destination_country']) ? (string) $row['destination_country'] : '',
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'request' => !empty($row['customer_request']),
            'total' => \Sudemi\Currency::format(isset($row['total_minor']) ? (int) $row['total_minor'] : 0, $currency),
            'net' => \Sudemi\Currency::format(isset($row['your_net_minor']) ? (int) $row['your_net_minor'] : 0, $currency),
            'refunded' => !empty($row['refunded_minor']) ? \Sudemi\Currency::format((int) $row['refunded_minor'], $currency) : '',
            'payout' => $payout !== null ? $this->payoutLabel(isset($payout['state']) ? (string) $payout['state'] : '') : '',
            'tracking' => $tracking !== null ? trim((isset($tracking['carrier']) ? (string) $tracking['carrier'] : '') . ' ' . (isset($tracking['code']) ? (string) $tracking['code'] : '')) : '',
            'customer' => isset($row['customer_name']) ? (string) $row['customer_name'] : '',
            'respond_by' => $this->supportDate(isset($row['respond_by']) ? $row['respond_by'] : ''),
            'local' => $local ? (int) $local : 0,
            'local_href' => $local ? 'index.php?route=sale/order.info&order_id=' . (int) $local . '&user_token=' . rawurlencode((string) $this->session->data['user_token']) : '',
        ];
    }

    /** sudemi's order page, ready for the template. */
    private function orderDetail(array $sudemi, array $d): array
    {
        $id = isset($d['order_id']) ? (string) $d['order_id'] : (string) $d['id'];
        $money = isset($d['money']) && is_array($d['money']) ? $d['money'] : [];
        $currency = isset($money['currency_code']) ? (string) $money['currency_code'] : 'EUR';
        $format = function ($minor, $code = null) use ($currency) {
            return $minor === null ? '' : \Sudemi\Currency::format((int) $minor, $code ?: $currency);
        };
        $token = rawurlencode((string) $this->session->data['user_token']);
        $lines = [];
        foreach ((isset($d['lines']) && is_array($d['lines']) ? $d['lines'] : []) as $line) {
            $external = isset($line['external_id']) ? (string) $line['external_id'] : '';
            $lines[] = [
                'title' => isset($line['title']) ? (string) $line['title'] : '',
                'quantity' => isset($line['quantity']) ? (int) $line['quantity'] : 1,
                'unit' => $format(isset($line['unit_price_minor']) ? $line['unit_price_minor'] : null),
                'goods' => $format(isset($line['goods_minor']) ? $line['goods_minor'] : null),
                'external' => $external,
                'product_href' => ctype_digit($external) ? 'index.php?route=catalog/product.form&product_id=' . $external . '&user_token=' . $token : '',
            ];
        }
        $timeline = [];
        foreach ((isset($d['timeline']) && is_array($d['timeline']) ? $d['timeline'] : []) as $event) {
            if (!is_array($event) || !isset($event['type'])) {
                continue;
            }
            $type = (string) $event['type'];
            $key = 'text_timeline_' . strtolower($type);
            $label = $this->language->get($key);
            $extra = [];
            if (!empty($event['carrier']) || !empty($event['tracking_code'])) {
                $extra[] = trim((isset($event['carrier']) ? (string) $event['carrier'] : '') . ' ' . (isset($event['tracking_code']) ? (string) $event['tracking_code'] : ''));
            }
            if (isset($event['amount_minor'])) {
                $extra[] = $format($event['amount_minor']);
            }
            if (!empty($event['kind'])) {
                $extra[] = $this->requestKind((string) $event['kind']) . (!empty($event['outcome']) ? ': ' . $this->requestOutcome((string) $event['outcome']) : '');
            }
            if (!empty($event['reason'])) {
                $extra[] = '"' . (string) $event['reason'] . '"';
            }
            $timeline[] = ['label' => $label !== $key ? $label : $type, 'at' => $this->supportDate(isset($event['at']) ? $event['at'] : ''),
                'extra' => implode('; ', array_filter($extra, 'strlen'))];
        }
        $payout = isset($d['payout']) && is_array($d['payout']) ? $d['payout'] : [];
        $payoutCurrency = isset($payout['currency_code']) && $payout['currency_code'] ? (string) $payout['currency_code'] : $currency;
        $actions = isset($d['allowed_actions']) && is_array($d['allowed_actions']) ? array_values(array_filter($d['allowed_actions'], 'is_string')) : [];
        $tracking = isset($d['tracking']) && is_array($d['tracking']) ? $d['tracking'] : null;
        $typed = $sudemi['orders']->trackingOf($id);
        $local = $sudemi['orders']->localOrderOf($id);
        $request = isset($d['customer_request']) && is_array($d['customer_request']) ? $d['customer_request'] : null;
        $refunded = isset($money['refunded_minor']) ? (int) $money['refunded_minor'] : 0;
        $total = isset($money['total_minor']) ? (int) $money['total_minor'] : 0;

        return [
            'id' => $id,
            'short' => substr($id, 0, 8),
            'status' => isset($d['status']) ? (string) $d['status'] : '',
            'status_label' => $this->statusLabel(isset($d['status']) ? (string) $d['status'] : ''),
            'placed' => $this->supportDate(isset($d['placed_at']) ? $d['placed_at'] : ''),
            'country' => isset($d['destination_country']) ? (string) $d['destination_country'] : '',
            'customer' => isset($d['customer_name']) ? (string) $d['customer_name'] : '',
            'respond_by' => $this->supportDate(isset($d['respond_by']) ? $d['respond_by'] : ''),
            'note' => isset($d['note']) ? (string) $d['note'] : '',
            'declined_reason' => isset($d['declined_reason']) ? (string) $d['declined_reason'] : '',
            'shipped_at' => $this->supportDate(isset($d['shipped_at']) ? $d['shipped_at'] : ''),
            'delivered_at' => $this->supportDate(isset($d['delivered_at']) ? $d['delivered_at'] : ''),
            'withdrawal_deadline' => $this->supportDate(isset($d['withdrawal_deadline']) ? $d['withdrawal_deadline'] : ''),
            'tracking' => $tracking !== null ? trim((isset($tracking['carrier']) ? (string) $tracking['carrier'] : '') . ' ' . (isset($tracking['code']) ? (string) $tracking['code'] : '')) : '',
            'carrier_prefill' => $tracking !== null && !empty($tracking['carrier']) ? (string) $tracking['carrier'] : $typed['carrier'],
            'tracking_prefill' => $tracking !== null && !empty($tracking['code']) ? (string) $tracking['code'] : $typed['code'],
            'lines' => $lines,
            'currency' => $currency,
            'decimals' => \Sudemi\Currency::decimals($currency),
            'goods' => $format(isset($money['goods_minor']) ? $money['goods_minor'] : null),
            'shipping' => $format(isset($money['shipping_minor']) ? $money['shipping_minor'] : null),
            'total' => $format($total),
            'commission' => $format(isset($money['commission_minor']) ? $money['commission_minor'] : null),
            'net' => $format(isset($money['your_net_minor']) ? $money['your_net_minor'] : null),
            'refunded' => $format($refunded),
            'refundable' => $format(max(0, $total - $refunded)),
            'timeline' => $timeline,
            'payout' => [
                'state' => $this->payoutLabel(isset($payout['state']) ? (string) $payout['state'] : ''),
                'expected' => isset($payout['expected_minor']) && $payout['expected_minor'] !== null ? $format($payout['expected_minor'], $payoutCurrency) : '',
                'paid_out' => $format(isset($payout['paid_out_minor']) ? $payout['paid_out_minor'] : 0, $payoutCurrency),
                'due' => $this->supportDate(isset($payout['due_at']) ? $payout['due_at'] : ''),
            ],
            'request' => $request === null ? null : [
                'kind' => $this->requestKind(isset($request['kind']) ? (string) $request['kind'] : ''),
                'reason' => isset($request['reason']) ? (string) $request['reason'] : '',
                'requested_at' => $this->supportDate(isset($request['requested_at']) ? $request['requested_at'] : ''),
                'respond_by' => $this->supportDate(isset($request['respond_by']) ? $request['respond_by'] : ''),
            ],
            'actions' => array_fill_keys($actions, true),
            'local' => $local ? (int) $local : 0,
            'local_href' => $local ? 'index.php?route=sale/order.info&order_id=' . (int) $local . '&user_token=' . $token : '',
            'action_key' => bin2hex(random_bytes(12)),
        ];
    }

    private function statusLabel(string $status): string
    {
        $key = 'text_status_' . strtolower($status);
        $label = $this->language->get($key);

        return $label !== $key ? $label : $status;
    }

    private function payoutLabel(string $state): string
    {
        $key = 'text_payout_' . strtolower($state);
        $label = $this->language->get($key);

        return $label !== $key ? $label : $state;
    }

    private function requestKind(string $kind): string
    {
        $key = 'text_note_kind_' . strtolower($kind);
        $label = $this->language->get($key);

        return $label !== $key ? $label : $kind;
    }

    private function requestOutcome(string $outcome): string
    {
        $key = 'text_note_req_' . strtolower($outcome);
        $label = $this->language->get($key);

        return $label !== $key ? $label : $outcome;
    }

    /** The filters of the order table from the query string, each checked. */
    private function orderFilters(): array
    {
        $get = $this->request->get;
        $text = function ($key) use ($get) {
            return isset($get[$key]) && is_string($get[$key]) ? trim(html_entity_decode($get[$key], ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
        };
        $date = function ($value) {
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
        };
        $status = strtoupper($text('status'));
        $sort = $text('sort');
        $dir = $text('dir');
        $q = $text('q');

        return [
            'status' => in_array($status, self::ORDER_STATUSES, true) ? $status : '',
            'from' => $date($text('from')),
            'to' => $date($text('to')),
            'q' => function_exists('mb_substr') ? mb_substr($q, 0, 100, 'UTF-8') : substr($q, 0, 100),
            'sort' => in_array($sort, ['placed', 'total', 'status'], true) ? $sort : 'placed',
            'dir' => $dir === 'asc' ? 'asc' : 'desc',
            'page' => isset($get['page']) && ctype_digit((string) $get['page']) ? max(1, (int) $get['page']) : 1,
        ];
    }

    private function ordersHref(array $filters): string
    {
        return 'index.php?route=extension/sudemi/module/sudemi.orders&user_token=' . rawurlencode((string) $this->session->data['user_token'])
            . '&' . http_build_query(array_filter($filters, function ($v) { return $v !== '' && $v !== null; }));
    }

    /** What every page of the extension has: OpenCart's header, menu and footer, the token, the links. */
    private function shell(): array
    {
        $token = rawurlencode((string) $this->session->data['user_token']);

        return [
            'user_token' => $this->session->data['user_token'],
            'header' => $this->load->controller('common/header'),
            'column_left' => $this->load->controller('common/column_left'),
            'footer' => $this->load->controller('common/footer'),
            'orders_href' => 'index.php?route=extension/sudemi/module/sudemi.orders&user_token=' . $token,
            'shipping_href' => 'index.php?route=extension/sudemi/module/sudemi.shipping&user_token=' . $token,
            'settings_href' => 'index.php?route=extension/sudemi/module/sudemi&user_token=' . $token,
        ];
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
     * Run after the page was produced (after it was sent, where PHP-FPM allows it): collecting a
     * pairing the shop owner confirmed on sudemi while one is open, otherwise the beat when one
     * is due, and a small step (at most Sync::STEP_PRODUCTS products, about two
     * seconds) of a waiting full sync. Never the whole sync: on a large catalogue that held the
     * admin's screen for minutes. The cron entry runs the rest; without cron the following page
     * loads continue where the last one stopped.
     */
    private function beatIfDue(): void
    {
        try {
            $sudemi = $this->services();
            // Not connected: only a pairing waiting to be collected, asked at most once a minute
            // (see Pairing::pollInBackground), for shops with no cron entry.
            $connected = $sudemi['client']->isConnected();
            $collect = !$connected && $sudemi['pairing']->pending();
            if (!$collect && (!$connected || (!$sudemi['heartbeat']->isDue() && !$sudemi['sync']->pending()))) {
                return;
            }
        } catch (\Throwable $e) {
            // A settings page that cannot render because a marketplace is down is worse than a
            // missed beat.
            return;
        }
        register_shutdown_function(function () use ($sudemi, $collect) {
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
                if ($collect) {
                    $sudemi['pairing']->pollInBackground();

                    return;
                }
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
            'currency' => $this->currencyInfo($sudemi['store']),
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
            $currency = isset($order['currency_code']) ? (string) $order['currency_code'] : 'EUR';
            $label = $this->language->get('text_status_' . strtolower($status));
            $rows[] = [
                'id' => substr((string) $order['order_id'], 0, 8),
                'date' => isset($order['placed_at']) ? substr((string) $order['placed_at'], 0, 10) : '',
                'items' => implode(', ', $items),
                'status' => $label !== 'text_status_' . strtolower($status) ? $label : $status,
                // In the currency the buyer was charged in, with that currency's own decimals.
                'paid' => \Sudemi\Currency::format(isset($order['total_minor']) ? (int) $order['total_minor'] : 0, $currency),
                'net' => \Sudemi\Currency::format(isset($order['your_net_minor']) ? (int) $order['your_net_minor'] : 0, $currency),
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
        $currency = (string) $this->config->get('config_currency');
        $rates = [];
        foreach (empty($first) ? [] : $first[0]['shipping'] as $rate) {
            $rates[] = ['country' => $rate['country'], 'amount' => \Sudemi\Currency::format($rate['costMinor'], $currency)];
        }

        return $rates;
    }

    /**
     * The shop currency as sudemi sees it, for the settings page: what buyers are charged in,
     * and a warning when sudemi cannot take payments in it or still has an older one.
     */
    private function currencyInfo($store): array
    {
        $shop = \Sudemi\Currency::normalise($this->config->get('config_currency'));
        $seen = \Sudemi\Currency::normalise($store->read(\Sudemi\Currency::SETTING_SHOP_CURRENCY)) ?: $shop;
        $refused = (string) $store->read(\Sudemi\Currency::SETTING_REFUSED);
        $info = [
            'code' => (string) $seen,
            'text' => $seen ? sprintf($this->language->get('text_currency_seen'), $seen) : '',
            'fee' => $seen && $seen !== 'EUR' ? $this->language->get('text_currency_fee') : '',
            'warning' => '',
            'changed' => '',
        ];
        if (!\Sudemi\Currency::isSupported($shop) || ($refused !== '' && $refused === $shop)) {
            $info['warning'] = sprintf($this->language->get('text_currency_unsupported'), $shop ?: '?', implode(', ', \Sudemi\Currency::SUPPORTED));
        } elseif ($seen !== $shop) {
            $info['changed'] = sprintf($this->language->get('text_currency_changed'), $shop, $seen);
        }

        return $info;
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
            // Stored HTML-escaped by OpenCart, like everything typed in its admin.
            'displayName' => \Sudemi\Catalogue::text($this->config->get('config_name')),
            'legalName' => \Sudemi\Catalogue::text($this->config->get('config_owner')),
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
        require_once DIR_EXTENSION . 'sudemi/system/library/sudemi/changes.php';

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
