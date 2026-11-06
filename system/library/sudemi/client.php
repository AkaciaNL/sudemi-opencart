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
    const VERSION = '1.2.1';

    private $keys;
    private $store;
    private $logger;

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
        // A GET carries no body, and the signature covers the hash of the body actually sent --
        // so it has to be the hash of nothing, not the hash of "{}".
        $json = $method === 'GET' ? '' : json_encode($body);
        if ($json === false) {
            $this->logger->warn('A payload could not be encoded.');

            return null;
        }

        $headers = ['Content-Type: application/json'];

        if ($signed) {
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
            $headers[] = 'Sudemi-Install: ' . $installId;
            $headers[] = 'Sudemi-Timestamp: ' . $timestamp;
            $headers[] = 'Sudemi-Nonce: ' . $nonce;
            $headers[] = 'Sudemi-Signature: ' . $signature;
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
