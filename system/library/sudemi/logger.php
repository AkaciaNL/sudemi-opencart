<?php
/**
 * A short, bounded log a shop owner can read, which never contains key material.
 *
 * @license GPL-3.0-or-later
 */

namespace Sudemi;

class Logger
{
    const SETTING = 'sudemi_log';
    const LIMIT = 50;

    private $store;

    public function __construct($store)
    {
        $this->store = $store;
    }

    public function info($message)
    {
        $this->write('info', $message);
    }

    public function warn($message)
    {
        $this->write('warn', $message);
    }

    public function error($message)
    {
        $this->write('error', $message);
    }

    public function entries()
    {
        $raw = $this->store->read(self::SETTING);
        $log = (is_string($raw) && $raw !== '') ? json_decode($raw, true) : [];

        return is_array($log) ? $log : [];
    }

    public function clear()
    {
        $this->store->delete(self::SETTING);
    }

    private function write($level, $message)
    {
        $log = $this->entries();
        array_unshift($log, [
            'at' => gmdate('c'),
            'level' => $level,
            // The key class never exposes key material, so nothing here can carry it; the
            // message is still truncated so a long upstream error cannot fill the row.
            'message' => substr(strip_tags((string) $message), 0, 300),
        ]);
        $this->store->write(self::SETTING, json_encode(array_slice($log, 0, self::LIMIT)));
    }
}
