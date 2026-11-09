<?php
/**
 * Connecting this shop to a sudemi seller account.
 *
 * The extension generates the keypair and asks sudemi to start a pairing, serves a challenge
 * token so sudemi can confirm the request came from this shop, and polls until a person has
 * claimed the shop in a browser. Signing in happens on sudemi: this extension never asks for a
 * sudemi password and could not use one.
 *
 * @license GPL-3.0-or-later
 */

namespace Sudemi;

class Pairing
{
    const SETTING_CHALLENGE = 'sudemi_challenge_token';
    const CHALLENGE_PATH = '/.well-known/sudemi-challenge';
    const SETTING_CODE = 'sudemi_pairing_code';
    const SETTING_POLL = 'sudemi_pairing_poll';
    const SETTING_CLAIM_URL = 'sudemi_claim_url';
    /** When this pairing was started (unix time), so the background poll knows its lifetime. */
    const SETTING_STARTED = 'sudemi_pairing_started';
    /** Not before this time (unix) does the background poll ask again. */
    const SETTING_NEXT_POLL = 'sudemi_pairing_next_poll';

    /** sudemi keeps a pairing open for 24 hours; after that there is nothing left to collect. */
    const LIFETIME = 86400;

    /**
     * The background poll asks at most once a minute for the first half hour (the shop owner is
     * usually confirming right now, in another tab), then every twenty minutes. That keeps a
     * whole 24-hour pairing within the 100 polls sudemi allows per pairing (PROTOCOL.md).
     */
    const POLL_EVERY = 60;
    const POLL_FAST_FOR = 1800;
    const POLL_SLOW_EVERY = 1200;

    private $client;
    private $keys;
    private $store;
    private $logger;

    public function __construct(Client $client, Keys $keys, $store, Logger $logger)
    {
        $this->client = $client;
        $this->keys = $keys;
        $this->store = $store;
        $this->logger = $logger;
    }

    /** @return array{ok:bool,message:string} */
    public function start($shopUrl, array $proposedProfile)
    {
        if (!Keys::isSupported()) {
            return ['ok' => false, 'message' => 'This server is missing the sodium extension, which the connection needs in order to sign requests. Ask your host to enable it.'];
        }
        if (!$this->keys->regenerate()) {
            return ['ok' => false, 'message' => 'A signing key could not be created on this server.'];
        }

        $result = $this->client->postUnsigned('/integration/v1/pair', [
            'shopUrl' => $shopUrl,
            'platform' => 'opencart',
            'pluginVersion' => Client::VERSION,
            'publicKey' => $this->keys->publicKeyDer(),
            'proposedProfile' => $proposedProfile,
        ]);

        if ($result === null) {
            return ['ok' => false, 'message' => 'sudemi could not be reached.'];
        }
        if ($result['status'] !== 200) {
            $message = isset($result['body']['message']) ? $result['body']['message'] : 'sudemi refused to start the connection.';

            return ['ok' => false, 'message' => $message];
        }

        $body = $result['body'];
        $this->storeChallenge(isset($body['challenge_token']) ? $body['challenge_token'] : '');
        $this->store->write(self::SETTING_CODE, isset($body['public_code']) ? $body['public_code'] : '');
        $this->store->write(self::SETTING_POLL, isset($body['poll_token']) ? $body['poll_token'] : '');
        $this->store->write(self::SETTING_CLAIM_URL, isset($body['claim_url']) ? $body['claim_url'] : '');
        $this->store->write(self::SETTING_STARTED, time());
        $this->store->delete(self::SETTING_NEXT_POLL);

        if (!$this->challengeFileServed()) {
            $this->logger->warn('The challenge file could not be written to ' . self::CHALLENGE_PATH
                . ' in the shop folder; sudemi will only reach the challenge if SEO URLs are on.');
        }

        return ['ok' => true, 'message' => 'Open the link and confirm the shop on sudemi.'];
    }

    /** Asks whether the shop has been claimed yet. Called by "Check again" and by pollInBackground(). */
    public function poll()
    {
        $code = $this->store->read(self::SETTING_CODE);
        $token = $this->store->read(self::SETTING_POLL);
        if (!is_string($code) || $code === '' || !is_string($token) || $token === '') {
            return false;
        }

        // Whoever asked (the button or the background), the background poll waits a while.
        $this->store->write(self::SETTING_NEXT_POLL, time() + $this->pollGap());
        $result = $this->client->postUnsigned('/integration/v1/pair/poll', [
            'publicCode' => $code,
            'pollToken' => $token,
        ]);
        if ($result === null) {
            return false;
        }

        // 429: sudemi asks for a pause. The background poll waits as long as it was told.
        if ($result['status'] === 429) {
            $wait = !empty($result['retry_after']) ? (int) $result['retry_after'] : 300;
            $this->store->write(self::SETTING_NEXT_POLL, time() + max($this->pollGap(), min(3600, $wait)));

            return false;
        }

        // 410: the pairing expired or was abandoned. Nothing left to wait for.
        if ($result['status'] === 410) {
            $this->forget();
            $this->logger->warn('The connection attempt expired. Start it again.');

            return false;
        }

        if ($result['status'] === 200 && isset($result['body']['install_id'])) {
            $this->store->write(Client::SETTING_INSTALL_ID, $result['body']['install_id']);
            // The challenge has done its job; leaving it served would keep a token on a public
            // path for no reason.
            $this->clearChallenge();
            $this->forget();
            $this->logger->info('Connected to sudemi.');

            return true;
        }

        return false;
    }

    /** True while a pairing was started here and has not been collected or given up yet. */
    public function pending()
    {
        $code = $this->store->read(self::SETTING_CODE);
        $token = $this->store->read(self::SETTING_POLL);

        return is_string($code) && $code !== '' && is_string($token) && $token !== '';
    }

    /**
     * Collects a claimed pairing without anyone pressing "Check again".
     *
     * Called by every cron run and by the fallback beat on the settings page. While a pairing is
     * open it asks sudemi once (at most once a minute, later every twenty minutes, and never
     * sooner than a 429 said), and on success stores the install exactly as the button does, so
     * the same run's heartbeat already goes out signed and sudemi asks for the first full sync.
     * A pairing older than its 24-hour lifetime is given up with one line in the log.
     *
     * @return bool whether this call connected the shop
     */
    public function pollInBackground()
    {
        if (!$this->pending()) {
            return false;
        }
        $now = time();
        $started = (int) $this->store->read(self::SETTING_STARTED);
        if ($started <= 0) {
            // Started by a version that did not record it: count the lifetime from now. sudemi
            // still answers 410 when it really expired, which ends the polling sooner.
            $this->store->write(self::SETTING_STARTED, $now);
            $started = $now;
        }
        if ($now - $started >= self::LIFETIME) {
            $this->forget();
            $this->logger->warn('The connection attempt expired after 24 hours without being confirmed on sudemi. Start it again.');

            return false;
        }
        if ((int) $this->store->read(self::SETTING_NEXT_POLL) > $now) {
            return false;
        }

        return $this->poll();
    }

    /** Seconds until the background poll may ask again, by how long this pairing has been open. */
    private function pollGap()
    {
        $started = (int) $this->store->read(self::SETTING_STARTED);

        return ($started > 0 && time() - $started >= self::POLL_FAST_FOR) ? self::POLL_SLOW_EVERY : self::POLL_EVERY;
    }

    /**
     * Stores a token sudemi handed back in a heartbeat response.
     *
     * Ownership is re-proved on a schedule, not once at install: a domain can be sold or
     * repointed, and the listing names this one to buyers as the shop's identity. The same
     * controller serves both the pairing token and these later ones, because the platform asks
     * for one fixed path and does not care why a token is there.
     */
    public function storeChallenge($token)
    {
        $this->store->write(self::SETTING_CHALLENGE, (string) $token);
        $this->writeChallengeFile((string) $token);
    }

    public function clearChallenge()
    {
        $this->store->delete(self::SETTING_CHALLENGE);
        $this->writeChallengeFile('');
    }

    /** True when the token is in the static file (so SEO URLs do not matter). */
    public function challengeFileServed()
    {
        $file = self::challengeFile();

        return $file !== null && is_file($file) && trim((string) @file_get_contents($file)) !== '';
    }

    /**
     * The token as a plain file at <shop folder>/.well-known/sudemi-challenge, which any web
     * server serves as it is. OpenCart only routes that path to the extension when SEO URLs are
     * switched on; the file makes pairing independent of that setting. Removed again when the
     * token is cleared, so no token stays on a public path for longer than needed.
     */
    private function writeChallengeFile($token)
    {
        $file = self::challengeFile();
        if ($file === null) {
            return;
        }
        if ($token === '') {
            if (is_file($file)) {
                @unlink($file);
            }

            return;
        }
        // Only ever a token goes into the public web folder: strictly letters, digits, - and _,
        // so nothing a tampered response sends could become a script or markup there.
        if (!preg_match('/^[A-Za-z0-9_-]{20,128}$/', $token)) {
            $this->logger->error('Refused to write a challenge token with an unexpected format.');

            return;
        }
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (@file_put_contents($file, $token, LOCK_EX) === false) {
            $this->logger->warn('Could not write ' . $file . '.');
        }
    }

    private static function challengeFile()
    {
        return defined('DIR_OPENCART') ? rtrim(DIR_OPENCART, '/') . self::CHALLENGE_PATH : null;
    }

    public function challengeToken()
    {
        $token = $this->store->read(self::SETTING_CHALLENGE);

        return (is_string($token) && $token !== '') ? $token : null;
    }

    public function claimUrl()
    {
        $url = $this->store->read(self::SETTING_CLAIM_URL);

        return (is_string($url) && $url !== '') ? $url : null;
    }

    public function forget()
    {
        $this->store->delete(self::SETTING_CODE);
        $this->store->delete(self::SETTING_POLL);
        $this->store->delete(self::SETTING_CLAIM_URL);
        $this->store->delete(self::SETTING_STARTED);
        $this->store->delete(self::SETTING_NEXT_POLL);
    }
}
