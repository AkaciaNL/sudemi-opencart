<?php
/**
 * Serves the ownership challenge.
 *
 * This is what proves to sudemi that whoever started the pairing controls this shop — the only
 * thing between a stranger typing someone else's domain and listing products under their name.
 * It answers with the token and nothing else: no theme, no layout, no redirect.
 *
 * @license GPL-3.0-or-later
 */

namespace Opencart\Catalog\Controller\Extension\Sudemi;

class Challenge extends \Opencart\System\Engine\Controller
{
    public function index(): void
    {
        $this->load->model('setting/setting');
        $settings = $this->model_setting_setting->getSetting('sudemi');
        $token = isset($settings['sudemi_challenge_token']) ? (string) $settings['sudemi_challenge_token'] : '';

        if ($token === '') {
            $this->response->addHeader($this->request->server['SERVER_PROTOCOL'] . ' 404 Not Found');
            $this->response->addHeader('Content-Type: text/plain; charset=utf-8');
            $this->response->setOutput('no pairing in progress');

            return;
        }

        $this->response->addHeader('Content-Type: text/plain; charset=utf-8');
        // Not cacheable: the token changes per pairing, and a cached old one fails the check in
        // a way that looks like the shop is unreachable.
        $this->response->addHeader('Cache-Control: no-store, max-age=0');
        $this->response->addHeader('X-Robots-Tag: noindex');
        $this->response->setOutput($token);
    }
}
