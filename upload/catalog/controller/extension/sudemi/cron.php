<?php
/**
 * The entry point this shop's own cron calls, every five minutes.
 *
 * OpenCart has no scheduler, so the beat has to come from somewhere: either the host's crontab
 * calling this URL, or — as a fallback for shops with no cron at all — an admin page load.
 *
 * It is reachable without a session, which is why it carries a token. Without one a stranger
 * could trigger a full catalogue walk on this shop as often as they liked, and a cron endpoint
 * that is free to hammer is a denial of service with extra steps. The token is compared with
 * hash_equals, and nothing about the answer distinguishes a wrong token from a missing one.
 *
 * @license GPL-3.0-or-later
 */

namespace Opencart\Catalog\Controller\Extension\Sudemi;

class Cron extends \Opencart\System\Engine\Controller
{
    public function index(): void
    {
        require_once DIR_SYSTEM . 'library/sudemi/logger.php';
        require_once DIR_SYSTEM . 'library/sudemi/keys.php';
        require_once DIR_SYSTEM . 'library/sudemi/client.php';
        require_once DIR_SYSTEM . 'library/sudemi/pairing.php';
        require_once DIR_SYSTEM . 'library/sudemi/catalogue.php';
        require_once DIR_SYSTEM . 'library/sudemi/sync.php';
        require_once DIR_SYSTEM . 'library/sudemi/queue.php';
        require_once DIR_SYSTEM . 'library/sudemi/orders.php';
        require_once DIR_SYSTEM . 'library/sudemi/heartbeat.php';

        $this->load->model('setting/setting');
        $store = new \Opencart\Catalog\Controller\Extension\Sudemi\CronSettingStore(
            $this->model_setting_setting
        );

        $logger = new \Sudemi\Logger($store);
        $keys = new \Sudemi\Keys($this->config, $store);
        $client = new \Sudemi\Client($keys, $store, $logger);
        $pairing = new \Sudemi\Pairing($client, $keys, $store, $logger);
        $catalogue = new \Sudemi\Catalogue($this->db, $this->config);
        $sync = new \Sudemi\Sync($client, $logger, $catalogue);
        $queue = new \Sudemi\Queue($store, $client);
        $orders = new \Sudemi\Orders($this->db, $this->config, $client, $logger);
        $heartbeat = new \Sudemi\Heartbeat(
            $store, $client, $logger, $queue, $catalogue, $pairing, $sync, $orders
        );

        $presented = isset($this->request->get['token']) ? (string) $this->request->get['token'] : '';
        $expected = $heartbeat->cronToken();

        $this->response->addHeader('Content-Type: text/plain; charset=utf-8');
        $this->response->addHeader('Cache-Control: no-store, max-age=0');
        $this->response->addHeader('X-Robots-Tag: noindex');

        if ($presented === '' || !hash_equals($expected, $presented)) {
            $this->response->addHeader($this->request->server['SERVER_PROTOCOL'] . ' 403 Forbidden');
            $this->response->setOutput('no');

            return;
        }

        $answered = $heartbeat->run();

        // A beat that could not reach sudemi is still a successful cron run: the shop's own
        // scheduler must not start mailing failures because a marketplace was briefly down.
        $this->response->setOutput($answered ? 'ok' : 'queued');
    }
}

/**
 * The same one-setting-at-a-time adapter the admin controller uses, duplicated because the two
 * controllers live in different OpenCart namespaces and neither may load the other's file.
 */
class CronSettingStore
{
    private $model;
    private $cache;

    public function __construct($model)
    {
        $this->model = $model;
        $this->cache = $model->getSetting('sudemi');
        if (!is_array($this->cache)) {
            $this->cache = [];
        }
    }

    public function read($key)
    {
        return isset($this->cache[$key]) ? $this->cache[$key] : null;
    }

    public function write($key, $value)
    {
        $this->cache[$key] = $value;
        $this->model->editSetting('sudemi', $this->cache);
    }

    public function delete($key)
    {
        unset($this->cache[$key]);
        $this->model->editSetting('sudemi', $this->cache);
    }
}
