<?php
/**
 * The shop's signing key.
 *
 * Generated on this host and never sent anywhere. sudemi receives the public half only, so a
 * breach of their database cannot produce a signature for this shop.
 *
 * @license GPL-3.0-or-later
 */

namespace Sudemi;

class Keys
{
    const SETTING_SECRET = 'sudemi_signing_secret';
    const SETTING_PUBLIC = 'sudemi_signing_public';

    /** @var \Opencart\System\Library\Config */
    private $config;

    /** @var object the model used to persist settings */
    private $store;

    public function __construct($config, $store)
    {
        $this->config = $config;
        $this->store = $store;
    }

    /**
     * Whether this host can sign at all. Checked before anything is offered, so a host without
     * libsodium is told on the settings screen rather than failing in a cron run later.
     */
    public static function isSupported()
    {
        return function_exists('sodium_crypto_sign_keypair')
            && function_exists('sodium_crypto_sign_detached');
    }

    public function regenerate()
    {
        if (!self::isSupported()) {
            return false;
        }
        $keypair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($keypair);
        $public = sodium_crypto_sign_publickey($keypair);

        $this->store->write(self::SETTING_SECRET, base64_encode($secret));
        $this->store->write(self::SETTING_PUBLIC, base64_encode($public));

        sodium_memzero($keypair);
        sodium_memzero($secret);

        return true;
    }

    /**
     * The public key as X.509 SubjectPublicKeyInfo, which is what the platform parses.
     * libsodium returns 32 raw bytes; the fixed 12-byte Ed25519 prefix makes it a DER key.
     */
    public function publicKeyDer()
    {
        $raw = $this->rawPublic();
        if ($raw === null) {
            return null;
        }
        $prefix = "\x30\x2a\x30\x05\x06\x03\x2b\x65\x70\x03\x21\x00";

        return base64_encode($prefix . $raw);
    }

    public function sign($message)
    {
        $secret = $this->rawSecret();
        if ($secret === null) {
            return null;
        }
        $signature = sodium_crypto_sign_detached($message, $secret);
        sodium_memzero($secret);

        return base64_encode($signature);
    }

    public function hasKey()
    {
        return $this->rawSecret() !== null;
    }

    public function forget()
    {
        $this->store->delete(self::SETTING_SECRET);
        $this->store->delete(self::SETTING_PUBLIC);
    }

    private function rawSecret()
    {
        $stored = $this->store->read(self::SETTING_SECRET);
        if (!is_string($stored) || $stored === '') {
            return null;
        }
        $raw = base64_decode($stored, true);

        return ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) ? null : $raw;
    }

    private function rawPublic()
    {
        $stored = $this->store->read(self::SETTING_PUBLIC);
        if (!is_string($stored) || $stored === '') {
            return null;
        }
        $raw = base64_decode($stored, true);

        return ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) ? null : $raw;
    }
}
