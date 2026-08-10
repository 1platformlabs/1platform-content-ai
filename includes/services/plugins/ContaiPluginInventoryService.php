<?php
/**
 * Plugin inventory service — collects this site's installed-plugin state and
 * publishes it to the 1Platform API so the dashboard can display and manage
 * it (WPG-01 / WPG-02 / WPG-04).
 *
 * push() is a full-replace publish, never a merge: every call sends the
 * complete current plugin list, not a delta. A local fingerprint (a hash of
 * the sorted, normalized item list) avoids re-publishing when nothing
 * actually changed — except for "bootstrap" and "manual" sources, which
 * always publish, because those two callers already know something changed
 * and need the dashboard to see it immediately rather than wait for the
 * fingerprint to catch up.
 *
 * cURL example (import to Postman):
 *
 * # Publish inventory
 * curl -X PUT https://api-qa.1platform.pro/api/v1/users/websites/<WEBSITE_ID>/plugins \
 *   -H "Content-Type: application/json" \
 *   -H "Authorization: Bearer <APP_TOKEN>" \
 *   -H "x-user-token: <USER_TOKEN>" \
 *   -H "X-Site-URL: https://example.com" \
 *   -d '{"site_plugin_version":"3.1.0","total_reported":1,"source":"poll","items":[{"file":"akismet/akismet.php","name":"Akismet Anti-spam","version":"5.3","active":true,"network_active":false,"update_available":null,"auto_update":false}]}'
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../api/OnePlatformClient.php';
require_once __DIR__ . '/../api/OnePlatformEndpoints.php';
require_once __DIR__ . '/../../providers/WebsiteProvider.php';

class ContaiPluginInventoryService {

    const OPTION_FINGERPRINT = 'contai_plugin_inventory_fingerprint';

    /** Sources that publish even when the fingerprint has not changed. */
    const ALWAYS_PUBLISH_SOURCES = ['bootstrap', 'manual'];

    private ContaiOnePlatformClient $client;
    private ContaiWebsiteProvider $websites;

    public function __construct(?ContaiOnePlatformClient $client = null, ?ContaiWebsiteProvider $websites = null) {
        $this->client   = $client ?? ContaiOnePlatformClient::create();
        $this->websites = $websites ?? new ContaiWebsiteProvider();
    }

    public static function create(): self {
        return new self();
    }

    /**
     * Restriction 16 — get_plugins(), is_plugin_active_for_network(),
     * validate_plugin(), activate_plugin(), deactivate_plugins(),
     * delete_plugins(), Plugin_Upgrader, and plugins_api() are all declared
     * in wp-admin/includes files WordPress does not autoload outside
     * /wp-admin — including inside a cron request (Action Scheduler or
     * WP-Cron), which is exactly where this service and
     * ContaiPluginOrderService both run. This is the single place that loads
     * them; both callers call it before touching any of those symbols.
     */
    public static function ensureAdminIncludesLoaded(): void {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
    }

    /**
     * Collects the site's current plugin state, shaped for the PUT
     * .../plugins body's items[]. Never includes slug, is_platform_plugin, or
     * any file path beyond WordPress's own plugin basename — those are
     * derived server-side and rejected/ignored if sent.
     *
     * @return array<int, array{file: string, name: string, version: string,
     *   active: bool, network_active: bool, update_available: ?string,
     *   auto_update: bool}>
     */
    public function collect(): array {
        self::ensureAdminIncludesLoaded();

        $all_plugins = get_plugins();

        $active_plugins = get_option('active_plugins', []);
        $active_plugins = is_array($active_plugins) ? $active_plugins : [];

        $update_data = get_site_transient('update_plugins');

        $auto_update_plugins = get_site_option('auto_update_plugins', []);
        $auto_update_plugins = is_array($auto_update_plugins) ? $auto_update_plugins : [];

        $items = [];
        foreach ($all_plugins as $file => $data) {
            $items[] = [
                'file'             => (string) $file,
                'name'             => isset($data['Name']) ? (string) $data['Name'] : '',
                'version'          => isset($data['Version']) ? (string) $data['Version'] : '',
                'active'           => in_array($file, $active_plugins, true),
                'network_active'   => (bool) is_plugin_active_for_network($file),
                'update_available' => $this->resolveUpdateAvailable((string) $file, $update_data),
                'auto_update'      => in_array($file, $auto_update_plugins, true),
            ];
        }

        return $items;
    }

    /**
     * Reads the pending new_version off the update_plugins site transient,
     * if WordPress has one cached for this plugin file. Null when there is
     * no known update.
     */
    private function resolveUpdateAvailable(string $file, $update_data): ?string {
        if (!is_object($update_data) || empty($update_data->response) || !is_array($update_data->response)) {
            return null;
        }

        $entry = $update_data->response[$file] ?? null;
        if (!is_object($entry) || !isset($entry->new_version)) {
            return null;
        }

        return (string) $entry->new_version;
    }

    /**
     * Computes a stable fingerprint for a collected item list: normalized and
     * sorted by file, then hashed. Sorting first means reordering collect()'s
     * output never changes the fingerprint — only an actual content change
     * does.
     */
    public function fingerprint(array $items): string {
        $normalized = array_map(static function ($item): array {
            $item = is_array($item) ? $item : [];
            return [
                'file'             => (string) ($item['file'] ?? ''),
                'name'             => (string) ($item['name'] ?? ''),
                'version'          => (string) ($item['version'] ?? ''),
                'active'           => (bool) ($item['active'] ?? false),
                'network_active'   => (bool) ($item['network_active'] ?? false),
                'update_available' => $item['update_available'] ?? null,
                'auto_update'      => (bool) ($item['auto_update'] ?? false),
            ];
        }, $items);

        usort($normalized, static function (array $a, array $b): int {
            return strcmp($a['file'], $b['file']);
        });

        return md5((string) json_encode($normalized));
    }

    /**
     * Publishes the current inventory to the 1Platform API — a full replace,
     * never a merge. No-ops when the fingerprint has not changed, unless
     * $source always publishes (bootstrap, manual), and no-ops entirely when
     * the site has no websiteId yet (never enrolled, or licence not yet
     * activated) — the next successful hook/poll will catch up once it does.
     *
     * @param string $source One of: bootstrap, hook, poll, manual.
     */
    public function push(string $source): void {
        $website_id = $this->websites->getWebsiteId();
        if (empty($website_id)) {
            return;
        }

        $items       = $this->collect();
        $fingerprint = $this->fingerprint($items);
        $force       = in_array($source, self::ALWAYS_PUBLISH_SOURCES, true);

        if (!$force && get_option(self::OPTION_FINGERPRINT, '') === $fingerprint) {
            return;
        }

        $endpoint = ContaiOnePlatformEndpoints::websitePlugins($website_id);
        $payload  = [
            'site_plugin_version' => defined('CONTAI_VERSION') ? CONTAI_VERSION : '',
            'total_reported'      => count($items),
            'source'              => $source,
            'items'               => $items,
        ];

        $response = $this->client->put($endpoint, $payload);

        if ($response->isSuccess()) {
            update_option(self::OPTION_FINGERPRINT, $fingerprint, false);
        } else {
            contai_log('Plugin inventory push failed (source=' . $source . '): ' . (string) $response->getMessage());
        }
    }
}
