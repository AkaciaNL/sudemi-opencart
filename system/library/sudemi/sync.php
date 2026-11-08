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
    private $store;

    /**
     * @param Catalogue         $catalogue reads products out of OpenCart, so this class holds no
     *                                     SQL and can be exercised without a database
     * @param SettingStore|null $store     remembers which category tree sudemi holds
     */
    public function __construct(Client $client, Logger $logger, $catalogue, $store = null)
    {
        $this->client = $client;
        $this->logger = $logger;
        $this->catalogue = $catalogue;
        $this->store = $store;
    }

    /**
     * @param string|null $revision the catalogue revision taken when this sync started; the commit
     *                              carries it, so sudemi adopts it and anything changed while the
     *                              sync ran makes the next heartbeat disagree
     *
     * @return bool|string true, false, or 'held' (sent; sudemi held the commit and changed nothing)
     */
    public function runFull($revision = null)
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

        $this->sendCategories($this->catalogue->categories());

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

        $committed = $this->client->postSigned(
            '/integration/v1/sync/' . rawurlencode($syncId) . '/commit',
            $revision === null ? [] : ['catalogueRevision' => $revision]
        );
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

    /**
     * One product's change, as it is saved: the whole listing when it can be sent whole, with the
     * catalogue revision when nothing else is waiting to be sent; otherwise price and stock.
     *
     * A price push carries price and stock and nothing else, so sending the revision with it
     * would tell sudemi a renamed product had arrived when it had not; the whole listing carries
     * everything about the product. A disabled product or one with required options has no
     * listing and goes as a price push without the revision (its removal is a full sync's job,
     * which the revision asks for); a master product with variants is sent whole but without the
     * revision (saving it changes the variants, which this push does not carry).
     *
     * The revision is computed here, after OpenCart saved the change and after the change counter
     * moved, exactly as the next heartbeat computes it. If every item applied, sudemi adopts it
     * (catalogue_revision_agreed) and no full sync follows the edit. Price changes still queued
     * would make it a claim that they had arrived, so then it is left out.
     *
     * @param Queue     $queue     price changes waiting to be sent
     * @param Heartbeat $heartbeat the change counter and the revision
     */
    public function pushChange($productId, $queue, $heartbeat)
    {
        $productId = (int) $productId;
        if ($productId <= 0 || !$this->client->isConnected()) {
            return;
        }
        $listing = $this->catalogue->listing($productId);
        $update = $listing !== null ? [
            'externalId' => $listing['externalId'],
            'priceMinor' => $listing['priceMinor'],
            'currency' => $listing['currency'],
            'inStock' => $listing['inStock'],
            'quantity' => $listing['quantity'],
        ] : $this->catalogue->priceAndStock($productId);
        if ($update === null) {
            return;
        }

        // Counted before the attempt, not after a success: the counter is what lets sudemi
        // notice a change that never arrived, so it has to move even when the push fails.
        $heartbeat->noteChange();

        if ($listing === null) {
            $response = $this->client->postSigned('/integration/v1/products/prices', ['updates' => [$update]]);
        } else {
            // This push carries the product's newest state; an older queued price for it would
            // otherwise be sent after it and put the old price back.
            $queue->discard($update['externalId']);
            $body = ['products' => [$listing]];
            // A product moved into a category sudemi has not been sent would get past sudemi's
            // category checks until a full sync, which an agreed revision makes rare. So the
            // category tree is sent first when it changed, and without it there is no revision.
            if ($queue->count() === 0 && !$this->catalogue->hasVariants($productId) && $this->categoriesCurrent()) {
                $revision = $this->catalogue->revision($heartbeat->changes());
                if ($revision !== null) {
                    $body['catalogueRevision'] = $revision;
                }
            }
            $response = $this->client->postSigned('/integration/v1/products', $body);
        }

        // A dropped change is a wrong price on a marketplace until the next full sync, so a
        // failure is written down rather than logged and forgotten. The heartbeat drains it.
        if ($response === null || $response['status'] !== 200) {
            $queue->enqueue($update);
        }
    }

    /** A hash of the category tree last sent to sudemi, so a change can be noticed. */
    const SETTING_CATEGORIES = 'sudemi_categories_sent';

    /** Whether sudemi holds this shop's category tree as it is now; sends it when not. */
    private function categoriesCurrent()
    {
        $categories = $this->catalogue->categories();
        if ($this->store !== null && $this->store->read(self::SETTING_CATEGORIES) === $this->categoriesHash($categories)) {
            return true;
        }

        return $this->sendCategories($categories);
    }

    /** Tied to the connection: a shop connected again has sent its new connection nothing. */
    private function categoriesHash(array $categories)
    {
        return hash('sha256', (string) $this->client->installId() . '|' . json_encode($categories));
    }

    /** @return bool whether sudemi now holds this category tree */
    private function sendCategories(array $categories)
    {
        if (empty($categories)) {
            // sudemi takes at least one; a shop without categories has nothing to be out of date.
            return true;
        }
        $response = $this->client->postSigned('/integration/v1/categories', ['categories' => $categories]);
        if ($response === null || $response['status'] !== 200) {
            return false;
        }
        if ($this->store !== null) {
            $this->store->write(self::SETTING_CATEGORIES, $this->categoriesHash($categories));
        }

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
