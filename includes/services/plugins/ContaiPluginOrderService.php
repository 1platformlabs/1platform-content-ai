<?php
/**
 * Plugin order service — polls the 1Platform API for pending plugin-management
 * orders (sync, activate, deactivate, delete, install) issued by the
 * dashboard, and applies each one to this WordPress installation (WPG-05 /
 * WPG-06 / WPG-08 / WPG-09, D-25).
 *
 * Mirrors the shape of ContaiAgentSyncService::pollAndProcessActions(): a
 * hard rate-limit floor, a MySQL GET_LOCK/RELEASE_LOCK pair so overlapping
 * cron runs cannot double-process the same order, and a per-order try/catch
 * so one bad order does not stop the batch.
 *
 * Every order is claimed (CAS to "claimed", skipped silently on any non-2xx —
 * lost race or no-longer-claimable) before it is applied, and reported
 * ("applied" or "failed") after. Win or lose, the site always re-publishes
 * its inventory afterward: the dashboard is watching the inventory to
 * reflect the change, not just the order status.
 *
 * cURL examples (import to Postman):
 *
 * # Poll pending orders
 * curl -X GET "https://api-qa.1platform.pro/api/v1/users/websites/<WEBSITE_ID>/plugin-orders?status=pending&limit=20" \
 *   -H "Authorization: Bearer <APP_TOKEN>" \
 *   -H "x-user-token: <USER_TOKEN>"
 *
 * # Claim / report
 * curl -X PATCH https://api-qa.1platform.pro/api/v1/users/websites/<WEBSITE_ID>/plugin-orders/<ORDER_ID> \
 *   -H "Content-Type: application/json" \
 *   -H "Authorization: Bearer <APP_TOKEN>" \
 *   -H "x-user-token: <USER_TOKEN>" \
 *   -d '{"status":"claimed"}'
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/ContaiPluginInventoryService.php';
require_once __DIR__ . '/../api/OnePlatformClient.php';
require_once __DIR__ . '/../api/OnePlatformEndpoints.php';
require_once __DIR__ . '/../../providers/WebsiteProvider.php';

class ContaiPluginOrderService {

    /** Hard minimum seconds between poll cycles (rate-limit guard). */
    const MIN_POLL_INTERVAL_SECONDS = 30;

    const OPTION_LAST_POLL = 'contai_plugin_orders_last_poll';

    const LOCK_NAME = 'contai_plugin_orders_poll_lock';

    /**
     * The platform's own plugin file. An order that targets this file for
     * delete/deactivate/activate is refused even though the server also
     * guards this (D-13) — defense in depth in case a response is ever
     * tampered with in transit.
     */
    const SELF_PLUGIN_FILE = '1platform-content-ai/1platform-content-ai.php';

    /** D-10: the only host a plugin download is ever fetched from. */
    const ALLOWED_DOWNLOAD_HOST = 'downloads.wordpress.org';

    private ContaiOnePlatformClient $client;
    private ContaiWebsiteProvider $websites;
    private ContaiPluginInventoryService $inventory;

    /** @var object|null Injected Plugin_Upgrader-compatible instance (tests only). */
    private $upgrader;

    public function __construct(
        ?ContaiOnePlatformClient $client = null,
        ?ContaiWebsiteProvider $websites = null,
        ?ContaiPluginInventoryService $inventory = null,
        $upgrader = null
    ) {
        $this->client    = $client ?? ContaiOnePlatformClient::create();
        $this->websites  = $websites ?? new ContaiWebsiteProvider();
        $this->inventory = $inventory ?? ContaiPluginInventoryService::create();
        $this->upgrader  = $upgrader;
    }

    public static function create(): self {
        return new self();
    }

    // ── Polling ──────────────────────────────────────────────────

    /**
     * Fetches all pending plugin orders from the API and processes them.
     *
     * Rate-limited: exits immediately if fewer than MIN_POLL_INTERVAL_SECONDS
     * have elapsed since the last poll, regardless of cron schedule. No-ops
     * silently (no error log spam) when the site has never been enrolled.
     */
    public function pollAndProcessOrders(): void {
        $last_poll = (int) get_option(self::OPTION_LAST_POLL, 0);
        if ((time() - $last_poll) < self::MIN_POLL_INTERVAL_SECONDS) {
            return;
        }

        global $wpdb;
        $got_lock = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', self::LOCK_NAME));
        if ('1' !== (string) $got_lock) {
            return; // Another process is already polling.
        }

        // Mark last poll time BEFORE the API call to prevent TOCTOU race.
        update_option(self::OPTION_LAST_POLL, time(), false);

        try {
            $this->pollOrdersLocked();
        } finally {
            // Always release the lock, even if anything above threw.
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::LOCK_NAME));
        }
    }

    /**
     * Runs the actual poll cycle. Only ever called while the poll lock is
     * held; split out purely so every return path inside it still reaches
     * the unconditional final push('poll') below, instead of a `return`
     * inside the try block skipping past it.
     */
    private function pollOrdersLocked(): void {
        $website_id = $this->websites->getWebsiteId();
        if (empty($website_id)) {
            return; // Never enrolled — nothing to poll for.
        }

        $response = $this->client->get(
            ContaiOnePlatformEndpoints::websitePluginOrders($website_id),
            ['status' => 'pending', 'limit' => 20]
        );

        if ($response->isSuccess()) {
            $data   = $response->getData();
            $orders = (is_array($data) && isset($data['orders']) && is_array($data['orders'])) ? $data['orders'] : [];

            foreach ($orders as $order) {
                if (!is_array($order)) {
                    continue;
                }
                try {
                    $this->processOrder($website_id, $order);
                } catch (\Throwable $e) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                    contai_log('Plugin order processing threw: ' . $e->getMessage());
                }
            }
        }

        // Catches changes made outside WordPress entirely (e.g. an
        // FTP-deleted plugin folder, which fires no hook), within one cron
        // cycle. push()'s own fingerprint check makes this free when nothing
        // changed, and push() itself no-ops safely if the GET above failed.
        $this->inventory->push('poll');
    }

    /**
     * Claims, applies, and reports a single order, then re-publishes the
     * inventory unconditionally.
     */
    private function processOrder(string $website_id, array $order): void {
        $order_id = (string) ($order['id'] ?? ($order['_id'] ?? ''));
        if ('' === $order_id) {
            return;
        }

        if (!$this->claim($website_id, $order_id)) {
            return; // Lost the CAS race, or no longer claimable — skip silently.
        }

        $result = $this->applyOrderAction($order);
        $this->report($website_id, $order_id, $result);
        $this->inventory->push('manual');
    }

    private function claim(string $website_id, string $order_id): bool {
        $endpoint = ContaiOnePlatformEndpoints::websitePluginOrderById($website_id, $order_id);
        $response = $this->client->patch($endpoint, ['status' => 'claimed']);
        return $response->isSuccess();
    }

    private function report(string $website_id, string $order_id, array $result): void {
        $endpoint = ContaiOnePlatformEndpoints::websitePluginOrderById($website_id, $order_id);

        if (!empty($result['success'])) {
            $this->client->patch($endpoint, ['status' => 'applied']);
            return;
        }

        $payload = [
            'status'     => 'failed',
            'error_code' => $result['error_code'] ?? 'unknown_error',
        ];
        if (!empty($result['error_detail'])) {
            $payload['error_detail'] = substr((string) $result['error_detail'], 0, 500);
        }

        $this->client->patch($endpoint, $payload);
    }

    // ── Action dispatch ──────────────────────────────────────────

    /**
     * Applies a single order's action to this WordPress installation.
     *
     * Public so each action can be exercised directly against a fabricated
     * order array without going through the polling/locking machinery.
     *
     * @param array $order One order object, shaped as documented by
     *                      GET .../plugin-orders.
     * @return array{success: bool, error_code?: string, error_detail?: string}
     */
    public function applyOrderAction(array $order): array {
        ContaiPluginInventoryService::ensureAdminIncludesLoaded();

        $action      = (string) ($order['action'] ?? '');
        $plugin_file = $order['plugin_file'] ?? null;

        // D-13: refuse to touch the platform's own plugin file, even if the
        // API sent an order that targets it. Do NOT touch the plugin.
        if (
            self::SELF_PLUGIN_FILE === $plugin_file
            && in_array($action, ['delete', 'deactivate', 'activate'], true)
        ) {
            return $this->fail('plugin_order_self_target', "Refusing to act on the platform's own plugin.");
        }

        switch ($action) {
            case 'sync':
                return $this->applySync();
            case 'activate':
                return $this->applyActivate($order);
            case 'deactivate':
                return $this->applyDeactivate($order);
            case 'delete':
                return $this->applyDelete($order);
            case 'install':
                return $this->applyInstall($order);
            default:
                return $this->fail('unknown_action', 'Unrecognized order action: ' . $action);
        }
    }

    /**
     * sync is a no-op besides the unconditional re-publish every processed
     * order triggers in processOrder() — that re-publish is the entire point
     * of a sync order.
     */
    private function applySync(): array {
        return ['success' => true];
    }

    private function applyActivate(array $order): array {
        $file = (string) ($order['plugin_file'] ?? '');
        if ('' === $file) {
            return $this->fail('missing_plugin_file', 'Activate order carried no plugin_file.');
        }

        // Silent mode (4th arg = true): a dry-run include first, returning a
        // WP_Error instead of a fatal white-screen if the plugin has a fatal
        // error — critical for an automated, unattended activation.
        $result = activate_plugin($file, '', false, true);

        if (is_wp_error($result)) {
            return $this->fail(
                $result->get_error_code() ?: 'activate_failed',
                (string) $result->get_error_message()
            );
        }

        return ['success' => true];
    }

    private function applyDeactivate(array $order): array {
        $file = (string) ($order['plugin_file'] ?? '');
        if ('' === $file) {
            return $this->fail('missing_plugin_file', 'Deactivate order carried no plugin_file.');
        }

        $validated = validate_plugin($file);
        if (is_wp_error($validated)) {
            return $this->fail(
                $validated->get_error_code() ?: 'invalid_plugin',
                (string) $validated->get_error_message()
            );
        }

        // deactivate_plugins() is void — it cannot itself return a WP_Error.
        deactivate_plugins([$file]);

        return ['success' => true];
    }

    private function applyDelete(array $order): array {
        $file = (string) ($order['plugin_file'] ?? '');
        if ('' === $file) {
            return $this->fail('missing_plugin_file', 'Delete order carried no plugin_file.');
        }

        // Sequence matters: an active plugin must be deactivated BEFORE it is
        // deleted, or delete_plugins() runs uninstall.php against a plugin
        // WordPress still considers loaded.
        if (is_plugin_active($file)) {
            deactivate_plugins([$file]);
        }

        $result = delete_plugins([$file]);

        if (is_wp_error($result)) {
            return $this->fail($result->get_error_code() ?: 'delete_failed', (string) $result->get_error_message());
        }

        if (true !== $result) {
            // delete_plugins() also returns false on a generic failure (e.g.
            // the filesystem method needs credentials it doesn't have) — not
            // only a WP_Error.
            return $this->fail('delete_failed', 'delete_plugins() did not confirm deletion.');
        }

        return ['success' => true];
    }

    private function applyInstall(array $order): array {
        $slug = (string) ($order['plugin_slug'] ?? '');
        if ('' === $slug) {
            return $this->fail('missing_plugin_slug', 'Install order carried no plugin_slug.');
        }

        $api = plugins_api('plugin_information', [
            'slug'   => $slug,
            'fields' => ['sections' => false],
        ]);

        if (is_wp_error($api)) {
            return $this->fail($api->get_error_code() ?: 'install_lookup_failed', (string) $api->get_error_message());
        }

        $download_link = (is_object($api) && isset($api->download_link)) ? (string) $api->download_link : '';
        $host          = '' !== $download_link ? parse_url($download_link, PHP_URL_HOST) : null;

        // D-10: never download from anywhere but the official WordPress.org
        // package host. The order never carries a URL — only a slug — so this
        // is the one moment an attacker-controlled host could ever reach the
        // downloader, and it is refused before the upgrader is ever touched.
        if (self::ALLOWED_DOWNLOAD_HOST !== $host) {
            contai_log('Plugin install refused: resolved download host is not ' . self::ALLOWED_DOWNLOAD_HOST);
            return $this->fail(
                'install_source_not_allowed',
                'The resolved download source is not an official WordPress.org package.'
            );
        }

        $result = $this->pluginUpgrader()->install($download_link);

        if (is_wp_error($result)) {
            return $this->fail($result->get_error_code() ?: 'install_failed', (string) $result->get_error_message());
        }

        if (true !== $result) {
            return $this->fail('install_failed', 'Plugin_Upgrader::install() did not confirm installation.');
        }

        // Installed inactive on purpose — activation is a separate order (WPG-08).
        return ['success' => true];
    }

    /**
     * Lazily builds the real Plugin_Upgrader, or returns the instance a test
     * injected. Never called for a refused install — the D-10 host check
     * above always runs first and returns before this is reached.
     */
    private function pluginUpgrader() {
        if (null !== $this->upgrader) {
            return $this->upgrader;
        }
        return new \Plugin_Upgrader(new \WP_Ajax_Upgrader_Skin());
    }

    private function fail(string $code, string $detail = ''): array {
        return [
            'success'      => false,
            'error_code'   => $code,
            'error_detail' => $detail,
        ];
    }
}
