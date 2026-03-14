<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get asset version based on file hash
 *
 * This function generates a unique version string based on the file's
 * modification time and content, ensuring that browser cache is cleared
 * whenever the asset file changes.
 *
 * @param string $file_path Absolute path to the asset file
 * @return string Version string or fallback version if file doesn't exist
 */
function contai_get_asset_version($file_path) {
    if (!file_exists($file_path)) {
        // Fallback version if file doesn't exist
        return '1.0.0';
    }

    // Use file modification time for cache busting
    return (string) filemtime($file_path);
}

/**
 * Enqueue style with automatic version management
 *
 * @param string $handle Style handle
 * @param string $src Style URL
 * @param array $deps Dependencies
 * @param string $media Media type
 */
function contai_enqueue_style_with_version($handle, $src, $deps = [], $media = 'all') {
    // Extract file path from plugin URL
    $plugin_dir = plugin_dir_path(dirname(dirname(__FILE__)));
    $file_path = str_replace(plugin_dir_url(dirname(dirname(__FILE__))), $plugin_dir, $src);

    $version = contai_get_asset_version($file_path);

    wp_enqueue_style($handle, $src, $deps, $version, $media);
}

/**
 * Enqueue script with automatic version management
 *
 * @param string $handle Script handle
 * @param string $src Script URL
 * @param array $deps Dependencies
 * @param bool $in_footer Load in footer
 */
function contai_enqueue_script_with_version($handle, $src, $deps = [], $in_footer = false) {
    // Extract file path from plugin URL
    $plugin_dir = plugin_dir_path(dirname(dirname(__FILE__)));
    $file_path = str_replace(plugin_dir_url(dirname(dirname(__FILE__))), $plugin_dir, $src);

    $version = contai_get_asset_version($file_path);

    wp_enqueue_script($handle, $src, $deps, $version, $in_footer);
}
