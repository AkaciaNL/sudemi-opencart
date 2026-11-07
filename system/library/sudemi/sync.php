<?php
/**
 * Turning this shop's catalogue into what sudemi stores.
 *
 * A full sync walks every enabled product and ends with a commit, which is the only thing that
 * may delist: a sync that dies halfway is never committed, so a timeout cannot empty a shop's
 * listings. A delta carries one product's price or stock.
 *
 * @license GPL-3.0-or-later
 */

namespace Sudemi;

class Sync
{
    const BATCH_SIZE = 100;

    private $client;
    private $logger;
    private $catalogue;

    /**
     * @param Catalogue $catalogue reads products out of OpenCart, so this class holds no SQL
     *                             and can be exercised without a database
     */
    public function __construct(Client $client, Logger $logger, $catalogue)
    {
        $this->client = $client;
        $this->logger = $logger;
        $this->catalogue = $catalogue;
    }

    /** @return bool|string true, false, or 'held' (sent; sudemi held the commit and changed nothing) */
    public function runFull()
    {
        if (!$this->client->isConnected()) {
            return false;
        }

        $started = $this->client->postSigned('/integration/v1/sync', []);
        if ($started === null || $started['status'] !== 200 || empty($started['body']['sync_id'])) {
            $this->logger->warn('Could not start a catalogue sync.');

            return false;
        }
        $syncId = (string) $started['body']['sync_id'];

        $this->client->postSigned('/integration/v1/shop', $this->catalogue->shop());

        $categories = $this->catalogue->categories();
        if (!empty($categories)) {
            $this->client->postSigned('/integration/v1/categories', ['categories' => $categories]);
        }

        $offset = 0;
        $sent = 0;
        while (true) {
            $products = $this->catalogue->products($offset, self::BATCH_SIZE);
            if (empty($products)) {
                break;
            }
            $result = $this->client->postSigned('/integration/v1/products', [
                'syncId' => $syncId,
                'products' => $products,
            ]);
            if ($result === null || $result['status'] !== 200) {
                // Abandon rather than commit. An uncommitted sync delists nothing.
                $this->logger->warn('A batch failed; the sync was abandoned and nothing was delisted.');

                return false;
            }
            $sent += count($products);
            $offset += self::BATCH_SIZE;
        }

        $committed = $this->client->postSigned('/integration/v1/sync/' . rawurlencode($syncId) . '/commit', []);
        if ($committed === null || $committed['status'] !== 200) {
            $this->logger->warn('The catalogue was sent but the sync could not be committed.');

            return false;
        }

        if (isset($committed['body']['status']) && $committed['body']['status'] === 'held') {
            // Sent, but the commit would have delisted a large share of the live catalogue, so
            // sudemi held it and changed nothing. Not a success to log as one.
            $this->logger->warn('The catalogue sync is on hold: it would remove '
                . (isset($committed['body']['would_delist']) ? (int) $committed['body']['would_delist'] : 0)
                . ' of ' . (isset($committed['body']['live_products']) ? (int) $committed['body']['live_products'] : 0)
                . ' listed products from sudemi, so nothing was changed. If those products really were '
                . 'removed from this shop, contact sudemi support (support@sudemi.com).');

            return 'held';
        }

        $this->logger->info('Synced ' . $sent . ' products.');

        return true;
    }

    public function pushDelta($productId)
    {
        if (!$this->client->isConnected()) {
            return;
        }
        $update = $this->catalogue->priceAndStock((int) $productId);
        if ($update === null) {
            return;
        }
        // Quiet on failure: this runs while someone is saving a product, and a sudemi outage
        // must not put an error on that screen.
        $this->client->postSigned('/integration/v1/products/prices', ['updates' => [$update]]);
    }
}
