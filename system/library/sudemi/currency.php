<?php
/**
 * Money and currencies as sudemi counts them (currency step 3, 1.2.12).
 *
 * A shop sells in one currency, its OpenCart default currency. sudemi charges the buyer's card in
 * that currency and sends orders in it. Every amount on the wire is in the currency's own minor
 * units as ISO 4217 defines them: cents for EUR, groszy for PLN, but whole yen for JPY. Multiplying
 * by 100 regardless would make a zero-decimal price a hundred times too high.
 *
 * @license GPL-3.0-or-later
 */

namespace Sudemi;

class Currency
{
    /** Shop currencies sudemi takes: EUR and every ECB currency except ISK (owner 2026-11-09, PROTOCOL.md). */
    const SUPPORTED = ['EUR', 'AUD', 'BRL', 'CAD', 'CHF', 'CNY', 'CZK', 'DKK', 'GBP', 'HKD', 'HUF', 'IDR', 'ILS', 'INR', 'JPY', 'KRW', 'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PLN', 'RON', 'SEK', 'SGD', 'THB', 'TRY', 'USD', 'ZAR'];

    /** The shop currency as sudemi last confirmed it on POST /shop. */
    const SETTING_SHOP_CURRENCY = 'sudemi_shop_currency';

    /** Set when sudemi refused the shop's currency on POST /shop: the currency it refused. */
    const SETTING_REFUSED = 'sudemi_shop_currency_refused';

    private const ZERO_DECIMALS = ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];
    private const THREE_DECIMALS = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];

    /** An ISO 4217 code in capitals, or null when the value is not a three-letter code. */
    public static function normalise($code)
    {
        $code = strtoupper(trim((string) $code));

        return preg_match('/^[A-Z]{3}$/', $code) ? $code : null;
    }

    /** How many decimals the currency's minor unit has (ISO 4217); 2 for anything not listed. */
    public static function decimals($code)
    {
        $code = self::normalise($code);
        if (in_array($code, self::ZERO_DECIMALS, true)) {
            return 0;
        }
        if (in_array($code, self::THREE_DECIMALS, true)) {
            return 3;
        }

        return 2;
    }

    /** A price as OpenCart keeps it (a decimal number) in the currency's minor units. */
    public static function toMinor($amount, $code)
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return (int) round((float) $amount * (10 ** self::decimals($code)));
    }

    /** Minor units back to a decimal amount. */
    public static function fromMinor($minor, $code)
    {
        return ((int) $minor) / (10 ** self::decimals($code));
    }

    /** "12.50 PLN", "1250 JPY": an amount in minor units for a person to read. */
    public static function format($minor, $code)
    {
        $code = self::normalise($code) ?: 'EUR';
        $decimals = self::decimals($code);

        return number_format(self::fromMinor($minor, $code), $decimals, '.', '') . ' ' . $code;
    }

    public static function isSupported($code)
    {
        return in_array(self::normalise($code), self::SUPPORTED, true);
    }
}
