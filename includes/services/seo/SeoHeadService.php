<?php

if (!defined('ABSPATH')) exit;

class ContaiSeoHeadService {

    private const METATITLE_META_KEY = '_contai_metatitle';

    public function register(): void {
        add_filter('document_title_parts', [$this, 'overrideTitleParts']);
        add_action('wp_head', [$this, 'outputMetaDescription'], 2);
    }

    public function overrideTitleParts(array $title_parts): array {
        if (!is_singular('post')) {
            return $title_parts;
        }

        $post = get_queried_object();

        if (!$post instanceof WP_Post) {
            return $title_parts;
        }

        $metatitle = get_post_meta($post->ID, self::METATITLE_META_KEY, true);

        if (!empty($metatitle)) {
            $title_parts['title'] = $metatitle;
        }

        return $title_parts;
    }

    public function outputMetaDescription(): void {
        if (!is_singular('post')) {
            return;
        }

        $post = get_queried_object();

        if (!$post instanceof WP_Post) {
            return;
        }

        $description = $post->post_excerpt;

        if (empty($description)) {
            return;
        }

        $description = esc_attr(wp_strip_all_tags($description));

        echo '<meta name="description" content="' . $description . '" />' . "\n";
    }
}
