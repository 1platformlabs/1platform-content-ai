<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../config/Config.php';

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

        $this->ensureHomeMenuItem($menu_id, $site_language);
        $this->addCategoryMenuItems($menu_id, $category_names);
    }

    private function getOrCreateMenu(): int {
        $menu = wp_get_nav_menu_object(self::MENU_NAME);

        if ($menu) {
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
        $locations = get_nav_menu_locations();
        $registered_menus = get_registered_nav_menus();

        if (empty($registered_menus)) {
            return;
        }

        $target_location = $this->findPrimaryLocation($registered_menus);

        if ($target_location) {
            $locations[$target_location] = $menu_id;
            set_theme_mod('nav_menu_locations', $locations);
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
        $category = get_term_by('name', $category_name, 'category');

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
