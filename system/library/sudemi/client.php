<?php
/**
 * Every call this shop makes to sudemi, signed.
 *
 * @license GPL-3.0-or-later
 */

namespace Sudemi;

class Client
{
    const SETTING_INSTALL_ID = 'sudemi_install_id';
    const SETTING_API_BASE = 'sudemi_api_base';

    const DEFAULT_API_BASE = 'https://api.sudemi.com';
    const TIMEOUT_SECONDS = 20;

    /**
     * How many times to wait when sudemi says this shop is pushing too fast. Two, because the
     * window is a minute: a shop still throttled after two waits is sending far more than the
     * limit and should resume on its next run rather than hold a cron slot open.
     */
    const RATE_LIMIT_RETRIES = 2;

    /** A cap, so a strange Retry-After cannot park a cron run for an hour. */
    const RATE_LIMIT_MAX_WAIT = 90;
    const VERSION = '1.2.11';

    /**
     * Test seam, null in production. When set (only by PHP code running in this same process,
     * as the shop e2e does), every call goes through it as fn($method, $path, $body, $send) and its
     * return value is used as the response (null = unreachable); $send() makes the real call.
     * It lets a test fake a 5xx, a 429 or a lost answer for one endpoint.
     *
     * @var callable|null
     */
    public static $interceptor = null;

    private $keys;
    private $store;
    private $logger;
    private $siteUrl = null;

    public function __construct(Keys $keys, $store, Logger $logger)
    {
        $this->keys = $keys;
        $this->store = $store;
        $this->logger = $logger;
    }

    public function apiBase()
    {
        $configured = $this->store->read(self::SETTING_API_BASE);

        return rtrim((is_string($configured) && $configured !== '') ? $configured : self::DEFAULT_API_BASE, '/');
    }

    public function installId()
    {
        $id = $this->store->read(self::SETTING_INSTALL_ID);

        return (is_string($id) && $id !== '') ? $id : null;
    }

    /**
     * This shop's own base URL, sent as Sudemi-Site on every signed call: the storefront address,
     * HTTP_CATALOG in the admin and HTTP_SERVER in the storefront (OpenCart 4 has no config_url
     * for the default store). '' when it cannot be told.
     */
    public function siteUrl()
    {
        if ($this->siteUrl !== null) {
            $url = $this->siteUrl;
        } elseif (defined('HTTP_CATALOG')) {
            $url = (string) HTTP_CATALOG;
        } elseif (defined('HTTP_SERVER')) {
            $url = (string) HTTP_SERVER;
        } else {
            $url = '';
        }

        // A header value: no line breaks, whatever the configuration holds.
        return trim(preg_replace('/[\r\n]+/', '', $url));
    }

    /** Overrides the address above, where the caller knows it better (and in tests). */
    public function setSiteUrl($url)
    {
        $this->siteUrl = $url === null ? null : (string) $url;
    }

    /**
     * The headers that make a call signed, or null when this shop cannot sign (not connected).
     *
     * The signature covers the method, the path, the timestamp, a nonce and the body hash.
     * Sudemi-Site is sent beside it, unsigned: the address this shop believes it lives at. A
     * staging copy or a restored backup holds this shop's install id and key, and this is how
     * sudemi tells it apart from the live shop and refuses it (409 site_mismatch).
     *
     * @return string[]|null
     */
    public function signedHeaders($method, $path, $json)
    {
        $installId = $this->installId();
        if ($installId === null) {
            return null;
        }
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $canonical = implode("\n", [$method, $path, $timestamp, $nonce, hash('sha256', $json)]);
        $signature = $this->keys->sign($canonical);
        if ($signature === null) {
            return null;
        }
        $headers = [
            'Sudemi-Install: ' . $installId,
            'Sudemi-Timestamp: ' . $timestamp,
            'Sudemi-Nonce: ' . $nonce,
            'Sudemi-Signature: ' . $signature,
        ];
        $site = $this->siteUrl();
        if ($site !== '') {
            $headers[] = 'Sudemi-Site: ' . $site;
        }

        return $headers;
    }

    /**
     * Whether sudemi refused a call because it came from another site than the connected shop.
     *
     * @param array|null $response as request() returns it
     *
     * @return string|null null when it did not; otherwise the address the shop is connected
     *                     under, as sudemi named it, or '' when it could not be read
     */
    public static function siteMismatch($response)
    {
        if (!is_array($response) || !isset($response['status']) || (int) $response['status'] !== 409
            || !isset($response['body']['error']) || $response['body']['error'] !== 'site_mismatch') {
            return null;
        }
        $message = isset($response['body']['message']) ? (string) $response['body']['message'] : '';
        if (preg_match('#connected to sudemi as (https?://[A-Za-z0-9.:\[\]-]+)#', $message, $match)) {
            return rtrim($match[1], '.:');
        }

        return '';
    }

    public function isConnected()
    {
        return $this->installId() !== null && $this->keys->hasKey();
    }

    public function postUnsigned($path, array $body)
    {
        return $this->request($path, $body, false);
    }

    public function postSigned($path, array $body)
    {
        return $this->requestWithBackoff($path, $body, true);
    }

    /**
     * Sends, and waits if sudemi says this shop is going too fast.
     *
     * sudemi caps how much catalogue one shop may push per minute, so a large import cannot take
     * the service away from every other shop. A 429 is therefore an ordinary part of a full sync
     * rather than a failure: it means "not yet", and it says how long to wait. Treating it as a
     * failure is what makes a large catalogue impossible to sync at all.
     */
    /**
     * A signed GET.
     *
     * The canonical string carries the method, so a GET cannot be replayed as a POST or the
     * other way round. Used for reading a confirmed order's delivery address.
     */
    public function getSigned($path)
    {
        return $this->requestWithBackoff($path, array(), true, 'GET');
    }

    /**
     * A signed call sent exactly once, whatever the answer.
     *
     * For calls a person is waiting on in the admin (support tickets): there a 429 is an answer to
     * show, not a reason to sleep, and a POST must never be repeated behind their back.
     *
     * @return array|null ['status' => int, 'body' => array, 'retry_after' => int], null if unsent
     */
    public function sendSigned($method, $path, array $body = [])
    {
        return $this->request($path, $body, true, $method === 'GET' ? 'GET' : 'POST');
    }

    private function requestWithBackoff($path, array $body, $signed, $method = 'POST')
    {
        $attempt = 0;
        while (true) {
            $response = $this->request($path, $body, $signed, $method);
            if ($response === null || (int) $response['status'] !== 429) {
                return $response;
            }
            ++$attempt;
            if ($attempt > self::RATE_LIMIT_RETRIES) {
                $this->logger->warn(
                    'sudemi is still asking this shop to slow down. Stopping for now; this '
                    . 'continues on the next cron run and nothing has been lost.'
                );

                return $response;
            }
            $wait = isset($response['retry_after']) ? (int) $response['retry_after'] : 60;
            // Capped: a proxy could return an absurd value, and a cron run that sleeps for an
            // hour is worse than one that gives up and retries later.
            $wait = max(1, min(self::RATE_LIMIT_MAX_WAIT, $wait));
            $this->logger->info('sudemi asked this shop to slow down; waiting ' . $wait . 's.');
            sleep($wait);
        }
    }

    private function request($path, array $body, $signed, $method = 'POST')
    {
        if (self::$interceptor !== null) {
            $send = function () use ($path, $body, $signed, $method) {
                return $this->send($path, $body, $signed, $method);
            };

            return call_user_func(self::$interceptor, $method, $path, $body, $send);
        }

        return $this->send($path, $body, $signed, $method);
    }

    private function send($path, array $body, $signed, $method)
    {
        // A GET carries no body, and the signature covers the hash of the body actually sent --
        // so it has to be the hash of nothing, not the hash of "{}". Invalid UTF-8 in shop text
        // (an old import, a copy-paste) is replaced rather than failing the whole call.
        $json = $method === 'GET' ? '' : json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            $this->logger->warn('A payload could not be encoded.');

            return null;
        }

        $headers = ['Content-Type: application/json'];

        if ($signed) {
            $signedHeaders = $this->signedHeaders($method, $path, $json);
            if ($signedHeaders === null) {
                return null;
            }
            $headers = array_merge($headers, $signedHeaders);
        }

        $curl = curl_init($this->apiBase() . $path);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            // Never relaxed and deliberately not configurable: a shop that cannot verify the
            // certificate is a shop whose catalogue could be rewritten in transit.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'sudemi-opencart/' . self::VERSION,
        ]);
        // Only Retry-After is read, and only so a throttled sync knows how long to pause.
        $retryAfter = 0;
        curl_setopt($curl, CURLOPT_HEADERFUNCTION, function ($handle, $header) use (&$retryAfter) {
            if (stripos($header, 'retry-after:') === 0) {
                $retryAfter = (int) trim(substr($header, strlen('retry-after:')));
            }

            return strlen($header);
        });
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($raw === false) {
            $this->logger->warn('Could not reach sudemi: ' . $error);

            return null;
        }

        $parsed = json_decode($raw, true);
        // Fail closed: a response that does not parse is not success, whatever the status said.
        if (!is_array($parsed) && $status !== 204) {
            $this->logger->warn('sudemi answered ' . $status . ' with something that was not JSON.');

            return null;
        }

        return [
            'status' => $status,
            'body' => is_array($parsed) ? $parsed : [],
            'retry_after' => $retryAfter,
        ];
    }
}
