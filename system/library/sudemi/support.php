<?php
/**
 * Support tickets between this shop and sudemi support.
 *
 * The shop owner opens and follows tickets from the extension's admin page; every call is a
 * signed /integration/v1/support call, so sudemi knows which shop is asking without a password.
 * The answer arrives by e-mail from sudemi (to the shop owner's sudemi account) and in the thread
 * shown on the admin page. This extension sends no e-mail itself.
 *
 * Every method returns ['ok' => bool, 'error' => null|string, 'data' => mixed]. The error is one
 * of the ERROR_* codes, which the admin page turns into a translated sentence: the message core
 * sends back is English and meant for logs, not for the shop owner.
 *
 * @license GPL-3.0-or-later
 */

namespace Sudemi;

class Support
{
    const PATH = '/integration/v1/support/tickets';

    const CATEGORIES = array('ORDER', 'PAYMENT', 'REFUND_RETURN', 'ACCOUNT', 'SHOP_PLUGIN', 'PAYOUTS', 'OTHER');
    const STATUSES = array('OPEN', 'WAITING_FOR_CUSTOMER', 'ANSWERED', 'CLOSED');
    const LOCALES = array('en', 'nl', 'tr', 'de', 'fr', 'es', 'it', 'pl', 'ru', 'zh', 'ar', 'he');

    const SUBJECT_MIN = 3;
    const SUBJECT_MAX = 200;
    const MESSAGE_MAX = 10000;

    /** What the shop owner typed is not acceptable (400, or caught here before sending). */
    const ERROR_INVALID = 'invalid';
    /** No such ticket for this shop (404). */
    const ERROR_NOT_FOUND = 'not_found';
    /** Too many open tickets or too many new ones this hour (429). */
    const ERROR_TOO_MANY = 'too_many';
    /** Not paired, sudemi unreachable, or an answer that made no sense. */
    const ERROR_UNAVAILABLE = 'unavailable';

    /** @var object anything with sendSigned($method, $path, array $body) -- \Sudemi\Client in the shop */
    private $client;

    public function __construct($client)
    {
        $this->client = $client;
    }

    /** This shop's tickets, newest activity first. */
    public function tickets()
    {
        $result = $this->call('GET', self::PATH, array());
        if (!$result['ok']) {
            return $result;
        }
        $tickets = array();
        if (isset($result['data']['tickets']) && is_array($result['data']['tickets'])) {
            foreach ($result['data']['tickets'] as $ticket) {
                if (is_array($ticket) && self::isId(isset($ticket['id']) ? $ticket['id'] : null)) {
                    $tickets[] = $ticket;
                }
            }
        }

        return self::success($tickets);
    }

    /**
     * Opens a ticket.
     *
     * @param string      $locale the admin's language, any form ("de-de", "zh-CN", "ar"); sent only
     *                            when it is one sudemi answers in
     */
    public function open($category, $subject, $message, $orderId = null, $locale = null)
    {
        $category = is_string($category) ? strtoupper(trim($category)) : '';
        $subject = self::clean($subject);
        $message = self::clean($message);
        $orderId = is_string($orderId) ? strtolower(trim($orderId)) : '';

        if (!in_array($category, self::CATEGORIES, true)
            || !self::lengthBetween($subject, self::SUBJECT_MIN, self::SUBJECT_MAX)
            || !self::lengthBetween($message, 1, self::MESSAGE_MAX)
            || ($orderId !== '' && !self::isId($orderId))) {
            return self::failure(self::ERROR_INVALID);
        }

        $body = array('category' => $category, 'subject' => $subject, 'message' => $message);
        if ($orderId !== '') {
            $body['orderId'] = $orderId;
        }
        $locale = self::locale($locale);
        if ($locale !== null) {
            $body['locale'] = $locale;
        }

        return $this->detail($this->call('POST', self::PATH, $body));
    }

    /** One ticket with its messages, oldest first. */
    public function ticket($id)
    {
        if (!self::isId($id)) {
            return self::failure(self::ERROR_NOT_FOUND);
        }

        return $this->detail($this->call('GET', self::PATH . '/' . strtolower($id), array()));
    }

    /** Adds the shop's reply; a reply reopens a closed ticket. */
    public function reply($id, $message)
    {
        if (!self::isId($id)) {
            return self::failure(self::ERROR_NOT_FOUND);
        }
        $message = self::clean($message);
        if (!self::lengthBetween($message, 1, self::MESSAGE_MAX)) {
            return self::failure(self::ERROR_INVALID);
        }

        return $this->detail(
            $this->call('POST', self::PATH . '/' . strtolower($id) . '/messages', array('message' => $message))
        );
    }

    /** "de-de" -> "de", "zh-CN" -> "zh"; null when sudemi does not answer in that language. */
    public static function locale($code)
    {
        if (!is_string($code)) {
            return null;
        }
        $short = strtolower(substr(trim($code), 0, 2));

        return in_array($short, self::LOCALES, true) ? $short : null;
    }

    /** A ticket id is a UUID; anything else never reaches a URL. */
    public static function isId($id)
    {
        return is_string($id)
            && preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $id) === 1;
    }

    private function detail(array $result)
    {
        if (!$result['ok']) {
            return $result;
        }
        $ticket = isset($result['data']['ticket']) ? $result['data']['ticket'] : null;
        if (!is_array($ticket) || !self::isId(isset($ticket['id']) ? $ticket['id'] : null)) {
            return self::failure(self::ERROR_UNAVAILABLE);
        }
        if (!isset($ticket['messages']) || !is_array($ticket['messages'])) {
            $ticket['messages'] = array();
        }

        return self::success($ticket);
    }

    /**
     * Sent once, never retried: a 429 here means "too many tickets", which waiting a minute does
     * not fix, and a retried POST could open the same ticket twice.
     */
    private function call($method, $path, array $body)
    {
        $response = $this->client->sendSigned($method, $path, $body);
        if (!is_array($response) || !isset($response['status'])) {
            return self::failure(self::ERROR_UNAVAILABLE);
        }
        $status = (int) $response['status'];
        if ($status === 200 || $status === 201) {
            return self::success(isset($response['body']) && is_array($response['body']) ? $response['body'] : array());
        }
        if ($status === 400) {
            return self::failure(self::ERROR_INVALID);
        }
        if ($status === 404) {
            return self::failure(self::ERROR_NOT_FOUND);
        }
        if ($status === 429) {
            return self::failure(self::ERROR_TOO_MANY);
        }

        return self::failure(self::ERROR_UNAVAILABLE);
    }

    /** Trimmed, line endings normalised, control characters other than newline and tab removed. */
    private static function clean($text)
    {
        if (!is_string($text)) {
            return '';
        }
        $text = str_replace(array("\r\n", "\r"), "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        return trim((string) $text);
    }

    private static function lengthBetween($text, $min, $max)
    {
        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);

        return $length >= $min && $length <= $max;
    }

    private static function success($data)
    {
        return array('ok' => true, 'error' => null, 'data' => $data);
    }

    private static function failure($error)
    {
        return array('ok' => false, 'error' => $error, 'data' => null);
    }
}
