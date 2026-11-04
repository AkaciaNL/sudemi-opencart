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
    const SETTING_CODE = 'sudemi_pairing_code';
    const SETTING_POLL = 'sudemi_pairing_poll';
    const SETTING_CLAIM_URL = 'sudemi_claim_url';

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
        $this->store->write(self::SETTING_CHALLENGE, isset($body['challenge_token']) ? $body['challenge_token'] : '');
        $this->store->write(self::SETTING_CODE, isset($body['public_code']) ? $body['public_code'] : '');
        $this->store->write(self::SETTING_POLL, isset($body['poll_token']) ? $body['poll_token'] : '');
        $this->store->write(self::SETTING_CLAIM_URL, isset($body['claim_url']) ? $body['claim_url'] : '');

        return ['ok' => true, 'message' => 'Open the link and confirm the shop on sudemi.'];
    }

    public function poll()
    {
        $code = $this->store->read(self::SETTING_CODE);
        $token = $this->store->read(self::SETTING_POLL);
        if (!is_string($code) || $code === '' || !is_string($token) || $token === '') {
            return false;
        }

        $result = $this->client->postUnsigned('/integration/v1/pair/poll', [
            'publicCode' => $code,
            'pollToken' => $token,
        ]);
        if ($result === null) {
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
            $this->store->delete(self::SETTING_CHALLENGE);
            $this->forget();
            $this->logger->info('Connected to sudemi.');

            return true;
        }

        return false;
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
    }
}
