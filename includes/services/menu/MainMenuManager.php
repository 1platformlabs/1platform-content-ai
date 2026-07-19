<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../config/Config.php';
require_once __DIR__ . '/../../helpers/nav-location.php';
require_once __DIR__ . '/../../helpers/nav-menu-claim.php';
require_once __DIR__ . '/../../helpers/site-warnings.php';

class ContaiMainMenuManager {

    private const MENU_NAME = 'Main Navigation';
    private const HOME_IDENTIFIER = 'home-page-link';

    private ContaiConfig $config;

    public function __construct(?ContaiConfig $config = null) {
        $this->config = $config ?? ContaiConfig::getInstance();
    }

    public function updateMainMenuWithCategories(array $category_names): void {
        $menu_id = $this->getOrCreateMenu();
        $site_language = get_option('contai_site_language', 'spanish');

        $this->removePageItems($menu_id);
        $this->removeOrphanedCategoryItems($menu_id);
        $this->ensureHomeMenuItem($menu_id, $site_language);
        $this->addCategoryMenuItems($menu_id, $category_names);
    }

    private function getOrCreateMenu(): int {
        $menu = wp_get_nav_menu_object(self::MENU_NAME);

        if ($menu) {
            // Re-assert the location binding on every run, not only on
            // creation. The wizard calls switch_theme() before this step, and
            // theme mods (which hold nav_menu_locations) are stored per
            // stylesheet — so on a re-execution the menu survives while its
            // binding does not. Leaving it unbound makes the theme fall back
            // to wp_page_menu(), which lists pages: the generated legal pages.
            // That is the reported "main menu shows only legal pages" symptom
            // (#48). Assigning is idempotent when the binding is already
            // correct.
            $this->assignMenuToPrimaryLocation($menu->term_id);

            return $menu->term_id;
        }

        $menu_id = wp_create_nav_menu(self::MENU_NAME);

        if (is_wp_error($menu_id)) {
            return 0;
        }

        $this->assignMenuToPrimaryLocation($menu_id);

        return $menu_id;
    }

    private function assignMenuToPrimaryLocation(int $menu_id): void {
        $registered_menus = get_registered_nav_menus();

        // The wizard calls switch_theme() earlier in this same request, and
        // switch_theme() cannot repopulate the nav menu registry (it would have
        // to load the incoming theme's functions.php). So $registered_menus can
        // still describe the theme we just left: populated, therefore trusted,
        // and about the wrong theme (#48).
        $registry_is_stale = function_exists('contai_nav_registry_is_stale')
            && contai_nav_registry_is_stale();

        // Try static mapping first (reliable in cron/async context), but only
        // when the active theme actually registers that location. WordPress
        // silently drops nav_menu_locations entries for unregistered
        // locations, so returning here unconditionally made the runtime
        // detection below unreachable and the menu silently unbound (#48).
        if (function_exists('contai_get_primary_nav_location')) {
            $static_location = contai_get_primary_nav_location();
            if (contai_nav_location_is_usable($static_location, $registered_menus, $registry_is_stale)) {
                // Claims the assignment too, so core's post-switch remapping
                // cannot hand the location back to the previous theme (#48).
                contai_assign_nav_menu_location($static_location, $menu_id);
                return;
            }
        }

        // Fallback to runtime detection. Only meaningful against the ACTIVE
        // theme's registry: matching a stale one binds the menu to a location
        // this theme does not register, which WordPress drops silently (#48).
        if ($registry_is_stale || empty($registered_menus)) {
            if (function_exists('contai_record_site_warning')) {
                contai_record_site_warning(
                    'primary nav location',
                    sprintf(
                        "main menu left unbound for theme '%s' %s",
                        get_option('contai_wordpress_theme', 'astra'),
                        $registry_is_stale
                            ? '(registry still described the previous theme)'
                            : '(no static map entry and no registered menus)'
                    )
                );
            }
            return;
        }

        $target_location = $this->findPrimaryLocation($registered_menus);

        if ($target_location) {
            contai_assign_nav_menu_location($target_location, $menu_id);
        }
    }

    private function findPrimaryLocation(array $registered_menus): ?string {
        $priority_patterns = [
            'primary',
            'main',
            'header',
            'top',
            'navigation',
            'nav',
        ];

        $excluded_patterns = [
            'footer',
            'sidebar',
            'mobile',
            'secondary',
            'social',
        ];

        foreach ($priority_patterns as $pattern) {
            $found = $this->findLocationByPattern($registered_menus, $pattern, $excluded_patterns);
            if ($found) {
                return $found;
            }
        }

        return $this->findFirstNonExcludedLocation($registered_menus, $excluded_patterns);
    }

    private function findLocationByPattern(array $registered_menus, string $pattern, array $excluded_patterns): ?string {
        foreach ($registered_menus as $location => $description) {
            $location_lower = strtolower($location);
            $description_lower = strtolower($description);

            if ($this->matchesExcludedPattern($location_lower, $description_lower, $excluded_patterns)) {
                continue;
            }

            if (strpos($location_lower, $pattern) !== false || strpos($description_lower, $pattern) !== false) {
                return $location;
            }
        }

        return null;
    }

    private function matchesExcludedPattern(string $location, string $description, array $excluded_patterns): bool {
        foreach ($excluded_patterns as $excluded) {
            if (strpos($location, $excluded) !== false || strpos($description, $excluded) !== false) {
                return true;
            }
        }

        return false;
    }

    private function findFirstNonExcludedLocation(array $registered_menus, array $excluded_patterns): ?string {
        foreach ($registered_menus as $location => $description) {
            $location_lower = strtolower($location);
            $description_lower = strtolower($description);

            if (!$this->matchesExcludedPattern($location_lower, $description_lower, $excluded_patterns)) {
                return $location;
            }
        }

        return array_key_first($registered_menus);
    }

    private function ensureHomeMenuItem(int $menu_id, string $language): void {
        if ($this->homeMenuItemExists($menu_id)) {
            return;
        }

        $home_title = $language === 'spanish' ? 'Inicio' : 'Home';
        $home_url = home_url('/');

        wp_update_nav_menu_item($menu_id, 0, [
            'menu-item-title' => $home_title,
            'menu-item-url' => $home_url,
            'menu-item-status' => 'publish',
            'menu-item-type' => 'custom',
            'menu-item-position' => 1,
            'menu-item-classes' => self::HOME_IDENTIFIER,
        ]);
    }

    private function homeMenuItemExists(int $menu_id): bool {
        $menu_items = wp_get_nav_menu_items($menu_id);

        if (!$menu_items) {
            return false;
        }

        foreach ($menu_items as $item) {
            if (in_array(self::HOME_IDENTIFIER, $item->classes, true)) {
                return true;
            }

            $home_url = trailingslashit(home_url('/'));
            $item_url = trailingslashit($item->url);

            if ($item_url === $home_url && $item->type === 'custom') {
                return true;
            }
        }

        return false;
    }

    private function removePageItems(int $menu_id): void {
        $menu_items = wp_get_nav_menu_items($menu_id);
        if (!$menu_items) {
            return;
        }

        foreach ($menu_items as $item) {
            if ($item->type === 'post_type' && $item->object === 'page') {
                wp_delete_post($item->ID, true);
            }
        }
    }

    private function removeOrphanedCategoryItems(int $menu_id): void {
        $menu_items = wp_get_nav_menu_items($menu_id);
        if (!$menu_items) {
            return;
        }

        foreach ($menu_items as $item) {
            if ($item->type === 'taxonomy' && $item->object === 'category') {
                $category = get_category($item->object_id);
                if (!$category || is_wp_error($category)) {
                    wp_delete_post($item->ID, true);
                }
            }
        }
    }

    private function addCategoryMenuItems(int $menu_id, array $category_names): void {
        $existing_category_slugs = $this->getExistingCategorySlugs($menu_id);
        $categories_to_add = $this->filterCategoriesToAdd($category_names, $existing_category_slugs);

        foreach ($categories_to_add as $category_name) {
            $this->addCategoryMenuItem($menu_id, $category_name);
        }
    }

    private function getExistingCategorySlugs(int $menu_id): array {
        $menu_items = wp_get_nav_menu_items($menu_id);
        $slugs = [];

        if (!$menu_items) {
            return $slugs;
        }

        foreach ($menu_items as $item) {
            if ($item->type === 'taxonomy' && $item->object === 'category') {
                $category = get_category($item->object_id);

                if ($category && !is_wp_error($category)) {
                    $slugs[] = $category->slug;
                }
            }
        }

        return $slugs;
    }

    private function filterCategoriesToAdd(array $category_names, array $existing_slugs): array {
        $current_count = count($existing_slugs);
        $max_categories = $this->config->getMaxMenuCategories();
        $available_slots = $max_categories - $current_count;

        if ($available_slots <= 0) {
            return [];
        }

        $categories_to_add = [];

        foreach ($category_names as $category_name) {
            if (count($categories_to_add) >= $available_slots) {
                break;
            }

            $slug = sanitize_title($category_name);

            if (!in_array($slug, $existing_slugs, true)) {
                $categories_to_add[] = $category_name;
            }
        }

        return $categories_to_add;
    }

    private function addCategoryMenuItem(int $menu_id, string $category_name): void {
        // Try slug first (case-insensitive), then fall back to name match
        $slug     = sanitize_title($category_name);
        $category = get_term_by('slug', $slug, 'category');

        if (!$category || is_wp_error($category)) {
            $category = get_term_by('name', $category_name, 'category');
        }

        if (!$category || is_wp_error($category)) {
            return;
        }

        wp_update_nav_menu_item($menu_id, 0, [
            'menu-item-title' => $category->name,
            'menu-item-object' => 'category',
            'menu-item-object-id' => $category->term_id,
            'menu-item-type' => 'taxonomy',
            'menu-item-status' => 'publish',
        ]);
    }
}
