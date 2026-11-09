<?php
/**
 * Turning this shop's catalogue into what sudemi stores.
 *
 * A full sync walks every enabled product and ends with a commit, which is the only thing that
 * may delist: a sync that dies halfway is never committed, so a timeout cannot empty a shop's
 * listings. It remembers where it stopped, so the cron entry or later admin page loads continue
 * it (see work()). A change push carries one product family's listings.
 *
 * @license GPL-3.0-or-later
 */

namespace Sudemi;

require_once __DIR__ . '/currency.php';

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

    /** The full sync in progress: its id, where it stopped, the revision it started from. */
    const SETTING_STATE = 'sudemi_sync_state';

    /**
     * Listings that stopped being listed here (a product or variant deleted, disabled, given a
     * required option), counted; and the count the last committed full sync covered. Only a full
     * sync's commit can take a listing off sudemi, so while the two differ no push may carry the
     * catalogue revision: sudemi would adopt it and the gone listing would stay listed.
     */
    const SETTING_REMOVALS = 'sudemi_removals';
    const SETTING_REMOVALS_SYNCED = 'sudemi_removals_synced';

    /** Products found without a listing, so each is counted as a removal once. */
    const SETTING_UNLISTED = 'sudemi_unlisted';
    const MAX_UNLISTED = 5000;

    /** An admin page load sends at most this many products, for at most this many seconds. */
    const STEP_PRODUCTS = 25;
    const STEP_SECONDS = 2;

    /** sudemi drops an uncommitted sync after 6 hours; one older than this is started again. */
    const MAX_SYNC_AGE = 19800;

    /** Asks for a full sync; the cron entry (or the next steps after admin page loads) runs it. */
    public function request()
    {
        if ($this->store !== null) {
            $this->store->write(Withdrawal::SETTING_RESYNC_DUE, 1);
        }
    }

    /** Whether a full sync was started and has not finished yet. */
    public function inProgress()
    {
        return $this->state() !== null;
    }

    /** Whether there is full-sync work waiting: asked for, or started and not finished. */
    public function pending()
    {
        return $this->store !== null && ($this->store->read(Withdrawal::SETTING_RESYNC_DUE) || $this->inProgress());
    }

    /**
     * A whole full sync, now: for the "send the catalogue now" button. Continues a sync already
     * in progress rather than starting a second one.
     *
     * @return bool|string true, false, or 'held' (sent; sudemi held the commit and changed nothing)
     */
    public function runFull()
    {
        if (!$this->inProgress()) {
            $this->request();
        }
        do {
            $result = $this->work();
        } while ($result === 'more');

        return $result === 'done' ? true : ($result === 'held' ? 'held' : false);
    }

    /**
     * Runs the full sync that is due or in progress, for at most $maxProducts products and
     * $maxSeconds seconds (0 = until it is done), and remembers where it stopped.
     *
     * The cron entry runs it to the end. An admin page load only ever runs a small bounded step,
     * after the page was produced: a full sync of a large catalogue inside a page load held the
     * admin's screen for minutes. A database lock keeps two requests from sending it at once.
     *
     * @return string 'idle', 'busy' (another request is sending it), 'more' (stopped at the
     *                limit), 'done' (committed), 'held' (sudemi held the commit) or 'failed'
     */
    public function work($maxProducts = 0, $maxSeconds = 0)
    {
        if (!$this->client->isConnected() || !$this->pending()) {
            return 'idle';
        }
        if (!$this->catalogue->lock()) {
            return 'busy';
        }
        try {
            // Read again under the lock, from the database: another request may have moved it on
            // or finished it meanwhile.
            if (method_exists($this->store, 'reload')) {
                $this->store->reload();
            }
            $state = $this->state();
            if ($state !== null && time() - (int) $state['started'] > self::MAX_SYNC_AGE) {
                $this->logger->warn('An unfinished catalogue sync was too old and is started again.');
                $this->store->delete(self::SETTING_STATE);
                $this->request();
                $state = null;
            }
            if ($state === null) {
                if (!$this->store->read(Withdrawal::SETTING_RESYNC_DUE)) {
                    return 'idle';
                }
                $state = $this->start();
                if ($state === null) {
                    return 'failed';
                }
            }

            return $this->advance($state, (int) $maxProducts, (int) $maxSeconds);
        } finally {
            $this->catalogue->unlock();
        }
    }

    /** Opens a sync on sudemi and sends the shop and its categories; null when sudemi refused. */
    private function start()
    {
        // Cleared first: anything asking for a sync from here on asks for another one.
        $this->store->delete(Withdrawal::SETTING_RESYNC_DUE);
        $started = $this->client->postSigned('/integration/v1/sync', []);
        if ($started === null || $started['status'] !== 200 || empty($started['body']['sync_id'])) {
            $this->logger->warn('Could not start a catalogue sync.');

            return null;
        }
        $state = [
            'id' => (string) $started['body']['sync_id'],
            // What the catalogue was when this sync started reading it: the commit carries it,
            // so sudemi adopts it and anything changed while the sync ran makes the next
            // heartbeat disagree.
            'anchor' => $this->catalogue->revision((int) $this->store->read(Heartbeat::SETTING_CHANGES)),
            'removals' => $this->removals(),
            'cursor' => 0,
            'sent' => 0,
            'started' => time(),
        ];
        $this->saveState($state);
        $this->sendShop();
        $this->sendCategories($this->catalogue->categories());

        return $state;
    }

    /**
     * Sends the shop's details, and remembers which currency sudemi now has for the shop (or that
     * it refused the one sent), for the settings page.
     */
    private function sendShop()
    {
        $shop = $this->catalogue->shop();
        $response = $this->client->postSigned('/integration/v1/shop', $shop);
        if ($this->store === null || !is_array($response)) {
            return;
        }
        $status = (int) $response['status'];
        $body = isset($response['body']) && is_array($response['body']) ? $response['body'] : [];
        if ($status === 200 && isset($body['currency']) && Currency::normalise($body['currency']) !== null) {
            $this->store->write(Currency::SETTING_SHOP_CURRENCY, Currency::normalise($body['currency']));
            $this->store->delete(Currency::SETTING_REFUSED);
        } elseif ($status === 400 || $status === 422) {
            $sent = isset($shop['currency']) ? (string) Currency::normalise($shop['currency']) : '';
            $this->store->write(Currency::SETTING_REFUSED, $sent);
            $this->logger->error(sprintf(
                'sudemi did not accept this shop\'s currency %s (%s). Buyers cannot order until the default currency is one sudemi supports: %s.',
                $sent,
                isset($body['message']) ? (string) $body['message'] : $status,
                implode(', ', Currency::SUPPORTED)
            ));
        }
    }

    /** Sends products after the cursor, page by page, then commits; see work(). */
    private function advance(array $state, $maxProducts, $maxSeconds)
    {
        $began = microtime(true);
        $done = 0;
        while (true) {
            $size = $maxProducts > 0 ? min(self::BATCH_SIZE, $maxProducts - $done) : self::BATCH_SIZE;
            $page = $this->catalogue->productIdsAfter((int) $state['cursor'], $size);
            if (empty($page)) {
                return $this->commit($state);
            }
            $products = $this->catalogue->productsByIds($page);
            if (!empty($products)) {
                $result = $this->client->postSigned('/integration/v1/products', [
                    'syncId' => $state['id'],
                    'products' => $products,
                ]);
                if ($result === null || $result['status'] !== 200) {
                    return $this->failed($result, 'A batch failed');
                }
            }
            // Moved on only once the page is in: a step stopped halfway sends it again, which
            // sudemi takes as the same products once more.
            $state['cursor'] = (int) end($page);
            $state['sent'] = (int) $state['sent'] + count($products);
            $this->saveState($state);
            $done += count($page);
            if (($maxProducts > 0 && $done >= $maxProducts)
                || ($maxSeconds > 0 && microtime(true) - $began >= $maxSeconds)) {
                return 'more';
            }
        }
    }

    private function commit(array $state)
    {
        $anchor = isset($state['anchor']) ? $state['anchor'] : null;
        $committed = $this->client->postSigned(
            '/integration/v1/sync/' . rawurlencode((string) $state['id']) . '/commit',
            $anchor === null ? [] : ['catalogueRevision' => $anchor]
        );
        if ($committed === null || $committed['status'] !== 200) {
            return $this->failed($committed, 'The catalogue was sent but the sync could not be committed');
        }
        $this->store->delete(self::SETTING_STATE);

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
        // Everything removed before the sync started is gone from sudemi now.
        if ((int) $state['removals'] > (int) $this->store->read(self::SETTING_REMOVALS_SYNCED)) {
            $this->store->write(self::SETTING_REMOVALS_SYNCED, (int) $state['removals']);
        }

        // After a commit sudemi forgets the revision it held and adopts the next one it is told.
        // If that were the next regular beat, a product disabled in the minutes between the sync
        // and that beat would be adopted as already synced and stay listed. So the revision the
        // sync started from is sent at once.
        if ($anchor !== null) {
            $this->client->postSigned('/integration/v1/heartbeat', [
                'catalogueRevision' => $anchor,
                'productCount' => $this->catalogue->productCount(),
                'intervalSeconds' => Heartbeat::DEFAULT_INTERVAL,
                'pluginVersion' => Client::VERSION,
            ]);
        }
        $this->logger->info('Synced ' . (int) $state['sent'] . ' products.');

        return 'done';
    }

    /**
     * A request of the sync that did not get through. Unanswered, throttled or a server error:
     * kept, and the next step sends it again. Refused (the sync expired or closed, the shop is
     * suspended): abandoned, which delists nothing, and sudemi asks again when it needs one.
     */
    private function failed($response, $what)
    {
        $status = is_array($response) ? (int) $response['status'] : 0;
        if ($status === 0 || $status === 429 || $status >= 500) {
            $this->logger->warn($what . '; it is retried on the next run.');

            return 'failed';
        }
        $this->store->delete(self::SETTING_STATE);
        $this->logger->warn($what . ' (HTTP ' . $status . '); the sync was abandoned and nothing was delisted.');

        return 'failed';
    }

    private function state()
    {
        if ($this->store === null) {
            return null;
        }
        $raw = $this->store->read(self::SETTING_STATE);
        $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return is_array($state) && !empty($state['id']) ? $state : null;
    }

    private function saveState(array $state)
    {
        $this->store->write(self::SETTING_STATE, json_encode($state));
    }

    /** A listing stopped being listed here; see SETTING_REMOVALS. */
    public function noteRemoval()
    {
        if ($this->store !== null) {
            $this->store->write(self::SETTING_REMOVALS, $this->removals() + 1);
        }
    }

    private function removals()
    {
        return $this->store === null ? 0 : (int) $this->store->read(self::SETTING_REMOVALS);
    }

    /** Whether every listing removed here has been taken off sudemi by a committed full sync. */
    private function removalsSynced()
    {
        return $this->store !== null
            && $this->removals() <= (int) $this->store->read(self::SETTING_REMOVALS_SYNCED);
    }

    /** Remembers whether a product has a listing; losing it counts as a removal, once. */
    private function markListed($productId, $listed)
    {
        if ($this->store === null) {
            return;
        }
        $raw = $this->store->read(self::SETTING_UNLISTED);
        $unlisted = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        $unlisted = is_array($unlisted) ? $unlisted : [];
        $key = (string) (int) $productId;
        $known = isset($unlisted[$key]);
        if ($listed && $known) {
            unset($unlisted[$key]);
        } elseif (!$listed && !$known) {
            $this->noteRemoval();
            if (count($unlisted) >= self::MAX_UNLISTED) {
                // Past the cap such a product is counted again on each change: the safe side.
                return;
            }
            $unlisted[$key] = 1;
        } else {
            return;
        }
        $this->store->write(self::SETTING_UNLISTED, json_encode($unlisted));
    }

    /** Products changed in this request, pushed once when it ends; see pushChange(). */
    private static $changed = [];
    private static $pushWith = null;

    /**
     * A product changed: the listings of its family (the product, or the master product and
     * every variant) go to sudemi when this request ends.
     *
     * Once per family and at the end, not from each event: saving a master product edits every
     * variant after it, each with its own event, and the catalogue revision a push carries has
     * to be the one that holds after all of them.
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
        // Counted now, not after a push: the counter is what lets sudemi notice a change that
        // never arrived, so it has to move even when the push fails or never runs.
        $heartbeat->noteChange();
        if (empty(self::$changed)) {
            register_shutdown_function([__CLASS__, 'pushChanged']);
        }
        self::$changed[$this->catalogue->familyOf($productId)] = true;
        self::$pushWith = [$this, $queue, $heartbeat];
    }

    /** Sends what pushChange collected. Runs when the request ends. */
    public static function pushChanged()
    {
        $families = array_keys(self::$changed);
        self::$changed = [];
        if (self::$pushWith === null) {
            return;
        }
        list($sync, $queue, $heartbeat) = self::$pushWith;
        foreach ($families as $family) {
            try {
                $sync->pushFamily((int) $family, $queue, $heartbeat);
            } catch (\Throwable $e) {
                $sync->logger->warn('A product change could not be sent: ' . $e->getMessage());
            }
        }
    }

    /**
     * One family's listings, sent whole: the product, or the master product and every variant
     * (each variant is a product of its own and its own listing). A member without a listing
     * (disabled, with a required option) counts as a removal the first time it is seen so; with
     * no listing at all the product goes as a price push without the revision.
     */
    public function pushFamily($family, $queue, $heartbeat)
    {
        $members = $this->catalogue->familyIds($family);
        if (empty($members)) {
            // Deleted: its listings wait for a full sync to remove them.
            $this->noteRemoval();

            return;
        }
        $listings = [];
        foreach ($this->catalogue->productsByIds($members) as $listing) {
            $listings[$listing['externalId']] = $listing;
        }
        foreach ($members as $id) {
            $this->markListed($id, isset($listings[(string) $id]));
        }
        if (!empty($listings)) {
            $this->pushListings(array_values($listings), $queue, $heartbeat);

            return;
        }

        $update = $this->catalogue->priceAndStock($family);
        if ($update === null) {
            return;
        }
        $response = $this->client->postSigned('/integration/v1/products/prices', ['updates' => [$update]]);
        // A dropped change is a wrong price on a marketplace until the next full sync, so a
        // failure is written down rather than logged and forgotten. The heartbeat drains it.
        if ($response === null || $response['status'] !== 200) {
            $queue->enqueue($update);
        }
    }

    /**
     * Sends listings as a change push, with the catalogue revision when nothing else is waiting.
     *
     * A price push carries price and stock and nothing else, so sending the revision with it
     * would tell sudemi a renamed product had arrived when it had not; the whole listings carry
     * everything about the product.
     *
     * The revision is computed here, after OpenCart saved the change and after the change counter
     * moved, exactly as the next heartbeat computes it, and only sent when: no price change is
     * queued, no listing removed here is still waiting for a full sync to take it off sudemi, the
     * category tree sudemi holds is current, and every earlier request of this push was accepted
     * whole. If every item applied, sudemi adopts it (catalogue_revision_agreed) and no full sync
     * follows the edit.
     */
    private function pushListings(array $listings, $queue, $heartbeat)
    {
        foreach ($listings as $listing) {
            // This push carries the product's newest state; an older queued price for it would
            // otherwise be sent after it and put the old price back.
            $queue->discard($listing['externalId']);
        }
        $batches = array_chunk($listings, 500);
        $clean = true;
        $last = count($batches) - 1;
        foreach ($batches as $i => $batch) {
            $body = ['products' => $batch];
            // A product moved into a category sudemi has not been sent would get past sudemi's
            // category checks until a full sync, which an agreed revision makes rare. So the
            // category tree is sent first when it changed, and without it there is no revision.
            if ($i === $last && $clean && $queue->count() === 0 && $this->removalsSynced() && $this->categoriesCurrent()) {
                $revision = $this->catalogue->revision($heartbeat->changes());
                if ($revision !== null) {
                    $body['catalogueRevision'] = $revision;
                }
            }
            $response = $this->client->postSigned('/integration/v1/products', $body);
            if ($response === null || $response['status'] !== 200) {
                $clean = false;
                foreach ($batch as $listing) {
                    $queue->enqueue([
                        'externalId' => $listing['externalId'],
                        'priceMinor' => $listing['priceMinor'],
                        'currency' => $listing['currency'],
                        'inStock' => $listing['inStock'],
                        'quantity' => $listing['quantity'],
                    ]);
                }
            } elseif (!empty($response['body']['rejected'])) {
                $clean = false;
            }
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
