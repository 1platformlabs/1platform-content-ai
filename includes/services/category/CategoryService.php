<?php

if (!defined('ABSPATH')) exit;

class ContaiCategoryService {

    private const TAXONOMY_CATEGORY = 'category';
    private const UNCATEGORIZED_CATEGORY_NAME = 'Uncategorized';

    private array $existing_categories_cache = [];
    private bool $cache_loaded = false;

    public function getAllCategoryNames(): array {
        $terms = get_terms([
            'taxonomy' => self::TAXONOMY_CATEGORY,
            'hide_empty' => false,
        ]);

        if (is_wp_error($terms) || empty($terms)) {
            return [];
        }

        $filtered_terms = array_filter($terms, function($term) {
            return $term->name !== self::UNCATEGORIZED_CATEGORY_NAME;
        });

        return array_values(array_map(function($term) {
            return $term->name;
        }, $filtered_terms));
    }

    public function findOrCreateCategoryId(string $category_name): ?int {
        if (empty($category_name)) {
            return null;
        }

        $slug = $this->generateSlug($category_name);

        $term = get_term_by('slug', $slug, self::TAXONOMY_CATEGORY);

        if ($term && !is_wp_error($term)) {
            return $term->term_id;
        }

        $term = get_term_by('name', $category_name, self::TAXONOMY_CATEGORY);

        if ($term && !is_wp_error($term)) {
            return $term->term_id;
        }

        $result = wp_insert_term($category_name, self::TAXONOMY_CATEGORY, [
            'slug' => $slug,
        ]);

        if (is_wp_error($result)) {
            return null;
        }

        return $result['term_id'] ?? null;
    }

    public function processCategoriesFromResponse(array $categories): ContaiCategoryProcessingResult {
        $this->loadCategoriesCache();

        $created = 0;
        $skipped = 0;
        $total_input_count = count($categories);

        $uncategorized_replaced = $this->replaceUncategorizedWithFirstCategory($categories);
        if ($uncategorized_replaced) {
            $created++;
        }

        foreach ($categories as $category_name) {
            if ($this->categoryExists($category_name)) {
                $skipped++;
                continue;
            }

            if ($this->createCategory($category_name)) {
                $created++;
                $this->addToCacheByName($category_name);
            }
        }

        $total_processed = $total_input_count + ($uncategorized_replaced ? 1 : 0);

        return new ContaiCategoryProcessingResult($created, $skipped, $total_processed);
    }

    private function replaceUncategorizedWithFirstCategory(array &$categories): bool {
        if (empty($categories)) {
            return false;
        }

        $uncategorized_term = $this->findUncategorizedTerm();
        if (!$uncategorized_term) {
            return false;
        }

        $replacement_category = array_shift($categories);

        return $this->updateCategoryTerm(
            $uncategorized_term->term_id,
            $replacement_category,
            $uncategorized_term
        );
    }

    private function findUncategorizedTerm() {
        $term = get_term_by('name', self::UNCATEGORIZED_CATEGORY_NAME, self::TAXONOMY_CATEGORY);

        return ($term && !is_wp_error($term)) ? $term : null;
    }

    private function updateCategoryTerm(int $term_id, string $new_name, $old_term): bool {
        $new_slug = $this->generateSlug($new_name);

        $update_result = wp_update_term(
            $term_id,
            self::TAXONOMY_CATEGORY,
            [
                'name' => $new_name,
                'slug' => $new_slug,
            ]
        );

        if (is_wp_error($update_result)) {
            return false;
        }

        $this->updateCacheAfterReplacement($old_term->name, $new_slug, $term_id);

        return true;
    }

    private function updateCacheAfterReplacement(string $old_name, string $new_slug, int $term_id): void {
        $old_slug = $this->generateSlug($old_name);
        unset($this->existing_categories_cache[$old_slug]);
        $this->existing_categories_cache[$new_slug] = $term_id;
    }

    private function loadCategoriesCache(): void {
        if ($this->cache_loaded) {
            return;
        }

        $terms = get_terms([
            'taxonomy' => self::TAXONOMY_CATEGORY,
            'hide_empty' => false,
        ]);

        if (is_wp_error($terms)) {
            $this->existing_categories_cache = [];
            $this->cache_loaded = true;
            return;
        }

        foreach ($terms as $term) {
            $this->existing_categories_cache[$this->generateSlug($term->name)] = $term->term_id;
        }

        $this->cache_loaded = true;
    }

    private function categoryExists(string $name): bool {
        $slug = $this->generateSlug($name);
        return isset($this->existing_categories_cache[$slug]);
    }

    private function createCategory(string $name): bool {
        $slug = $this->generateSlug($name);

        $result = wp_insert_term($name, self::TAXONOMY_CATEGORY, [
            'slug' => $slug,
        ]);

        return !is_wp_error($result);
    }

    private function generateSlug(string $name): string {
        return sanitize_title($name);
    }

    private function addToCacheByName(string $name): void {
        $term = get_term_by('slug', $this->generateSlug($name), self::TAXONOMY_CATEGORY);

        if ($term && !is_wp_error($term)) {
            $this->existing_categories_cache[$this->generateSlug($name)] = $term->term_id;
        }
    }
}

class ContaiCategoryProcessingResult {

    private int $created_count;
    private int $skipped_count;
    private int $total_count;

    public function __construct(int $created, int $skipped, int $total) {
        $this->created_count = $created;
        $this->skipped_count = $skipped;
        $this->total_count = $total;
    }

    public function getCreatedCount(): int {
        return $this->created_count;
    }

    public function getSkippedCount(): int {
        return $this->skipped_count;
    }

    public function getTotalCount(): int {
        return $this->total_count;
    }

    public function toArray(): array {
        return [
            'created' => $this->created_count,
            'skipped' => $this->skipped_count,
            'total' => $this->total_count,
        ];
    }
}
