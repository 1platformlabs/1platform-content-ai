<?php

if (!defined('ABSPATH')) exit;

class ContaiTocSettingsPanel {

    private ContaiTocConfiguration $config;

    public function __construct(ContaiTocConfiguration $config) {
        $this->config = $config;
    }

    public function render(): void {
        if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD']
            && isset( $_POST['toc_settings_nonce'] )
            && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['toc_settings_nonce'] ) ), 'contai_toc_settings_save' )
        ) {
            $this->handleSave();
        }

        $current = $this->config->getAll();
        ?>
        <div class="contai-settings-panel contai-panel-toc-settings">
            <form method="post" class="contai-toc-form">
                <?php wp_nonce_field('contai_toc_settings_save', 'toc_settings_nonce'); ?>

                <?php $this->renderGeneralSettings($current); ?>
                <?php $this->renderContentSettings($current); ?>
                <?php $this->renderAppearanceSettings($current); ?>
                <?php $this->renderAdvancedSettings($current); ?>

                <div class="contai-form-actions">
                    <button type="submit" class="button button-primary button-large">
                        <?php esc_html_e('Save Settings', '1platform-content-ai'); ?>
                    </button>
                    <button type="submit" name="reset_settings" value="1" class="button button-secondary"
                            onclick="return confirm('<?php esc_attr_e('Are you sure you want to reset all settings to defaults?', '1platform-content-ai'); ?>');">
                        <?php esc_html_e('Reset to Defaults', '1platform-content-ai'); ?>
                    </button>
                </div>
            </form>
        </div>
        <?php
    }

    private function renderGeneralSettings(array $current): void {
        ?>
        <div class="contai-settings-section">
            <h2 class="contai-section-title">
                <span class="dashicons dashicons-admin-generic"></span>
                <?php esc_html_e('General Settings', '1platform-content-ai'); ?>
            </h2>

            <div class="contai-settings-row">
                <label class="contai-settings-label">
                    <input type="checkbox" name="enabled" value="1" <?php checked($current['enabled']); ?>>
                    <span><?php esc_html_e('Enable Table of Contents', '1platform-content-ai'); ?></span>
                </label>
                <p class="contai-settings-help"><?php esc_html_e('Automatically add table of contents to your posts.', '1platform-content-ai'); ?></p>
            </div>

            <div class="contai-settings-row">
                <label class="contai-settings-label"><?php esc_html_e('Post Types', '1platform-content-ai'); ?></label>
                <div class="contai-checkbox-group">
                    <?php foreach ($this->getAvailablePostTypes() as $type => $label): ?>
                        <label class="contai-checkbox-item">
                            <input type="checkbox" name="post_types[]" value="<?php echo esc_attr($type); ?>"
                                <?php checked(in_array($type, $current['post_types'])); ?>>
                            <span><?php echo esc_html($label); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="contai-settings-help"><?php esc_html_e('Select which post types should display the table of contents.', '1platform-content-ai'); ?></p>
            </div>

            <div class="contai-settings-row">
                <label class="contai-settings-label" for="position"><?php esc_html_e('Position', '1platform-content-ai'); ?></label>
                <select name="position" id="position" class="contai-select">
                    <option value="before_first_heading" <?php selected($current['position'], 'before_first_heading'); ?>>
                        <?php esc_html_e('Before first heading', '1platform-content-ai'); ?>
                    </option>
                    <option value="after_first_heading" <?php selected($current['position'], 'after_first_heading'); ?>>
                        <?php esc_html_e('After first heading', '1platform-content-ai'); ?>
                    </option>
                    <option value="top" <?php selected($current['position'], 'top'); ?>>
                        <?php esc_html_e('Top of content', '1platform-content-ai'); ?>
                    </option>
                    <option value="bottom" <?php selected($current['position'], 'bottom'); ?>>
                        <?php esc_html_e('Bottom of content', '1platform-content-ai'); ?>
                    </option>
                </select>
                <p class="contai-settings-help"><?php esc_html_e('Where to display the table of contents.', '1platform-content-ai'); ?></p>
            </div>
        </div>
        <?php
    }

    private function renderContentSettings(array $current): void {
        ?>
        <div class="contai-settings-section">
            <h2 class="contai-section-title">
                <span class="dashicons dashicons-editor-ul"></span>
                <?php esc_html_e('Content Settings', '1platform-content-ai'); ?>
            </h2>

            <div class="contai-settings-row">
                <label class="contai-settings-label" for="min_headings"><?php esc_html_e('Minimum Headings', '1platform-content-ai'); ?></label>
                <input type="number" name="min_headings" id="min_headings"
                       value="<?php echo esc_attr($current['min_headings']); ?>"
                       min="1" max="20" class="contai-input-number">
                <p class="contai-settings-help"><?php esc_html_e('Minimum number of headings required to display TOC.', '1platform-content-ai'); ?></p>
            </div>

            <div class="contai-settings-row">
                <label class="contai-settings-label"><?php esc_html_e('Heading Levels', '1platform-content-ai'); ?></label>
                <div class="contai-checkbox-group contai-checkbox-group-inline">
                    <?php for ($i = 1; $i <= 6; $i++): ?>
                        <label class="contai-checkbox-item">
                            <input type="checkbox" name="heading_levels[]" value="<?php echo esc_attr( $i ); ?>"
                                <?php checked(in_array($i, $current['heading_levels'])); ?>>
                            <span>H<?php echo esc_html( $i ); ?></span>
                        </label>
                    <?php endfor; ?>
                </div>
                <p class="contai-settings-help"><?php esc_html_e('Which heading levels to include in the table of contents.', '1platform-content-ai'); ?></p>
            </div>

            <div class="contai-settings-row">
                <label class="contai-settings-label" for="exclude_patterns"><?php esc_html_e('Exclude Headings', '1platform-content-ai'); ?></label>
                <textarea name="exclude_patterns" id="exclude_patterns" rows="4" class="contai-textarea"
                          placeholder="<?php esc_attr_e('One pattern per line. Use * as wildcard.', '1platform-content-ai'); ?>"><?php
                    echo esc_textarea(implode("\n", $current['exclude_patterns']));
                ?></textarea>
                <p class="contai-settings-help">
                    <?php esc_html_e('Exclude headings matching these patterns. Examples:', '1platform-content-ai'); ?><br>
                    <code>References</code> - <?php esc_html_e('Exact match', '1platform-content-ai'); ?><br>
                    <code>Chapter *</code> - <?php esc_html_e('Starts with "Chapter"', '1platform-content-ai'); ?><br>
                    <code>* Summary</code> - <?php esc_html_e('Ends with "Summary"', '1platform-content-ai'); ?>
                </p>
            </div>
        </div>
        <?php
    }

    private function renderAppearanceSettings(array $current): void {
        ?>
        <div class="contai-settings-section">
            <h2 class="contai-section-title">
                <span class="dashicons dashicons-art"></span>
                <?php esc_html_e('Appearance', '1platform-content-ai'); ?>
            </h2>

            <div class="contai-settings-row">
                <label class="contai-settings-label" for="title"><?php esc_html_e('Title', '1platform-content-ai'); ?></label>
                <input type="text" name="title" id="title"
                       value="<?php echo esc_attr($current['title']); ?>"
                       class="contai-input-text">
                <p class="contai-settings-help"><?php esc_html_e('The title displayed above the table of contents.', '1platform-content-ai'); ?></p>
            </div>

            <div class="contai-settings-row">
                <label class="contai-settings-label">
                    <input type="checkbox" name="show_title" value="1" <?php checked($current['show_title']); ?>>
                    <span><?php esc_html_e('Show title', '1platform-content-ai'); ?></span>
                </label>
            </div>

            <div class="contai-settings-row">
                <label class="contai-settings-label">
                    <input type="checkbox" name="show_toggle" value="1" <?php checked($current['show_toggle']); ?>>
                    <span><?php esc_html_e('Show toggle button', '1platform-content-ai'); ?></span>
                </label>
                <p class="contai-settings-help"><?php esc_html_e('Allow users to show/hide the table of contents.', '1platform-content-ai'); ?></p>
            </div>

            <div class="contai-settings-row">
                <label class="contai-settings-label" for="initial_state"><?php esc_html_e('Initial State', '1platform-content-ai'); ?></label>
                <select name="initial_state" id="initial_state" class="contai-select">
                    <option value="show" <?php selected($current['initial_state'], 'show'); ?>>
                        <?php esc_html_e('Visible', '1platform-content-ai'); ?>
                    </option>
                    <option value="hide" <?php selected($current['initial_state'], 'hide'); ?>>
                        <?php esc_html_e('Hidden', '1platform-content-ai'); ?>
                    </option>
                </select>
                <p class="contai-settings-help"><?php esc_html_e('Whether the TOC should be visible or hidden by default.', '1platform-content-ai'); ?></p>
            </div>

            <div class="contai-settings-row">
                <label class="contai-settings-label" for="theme"><?php esc_html_e('Theme', '1platform-content-ai'); ?></label>
                <div class="contai-theme-selector">
                    <?php foreach ($this->getThemes() as $theme_id => $theme_label): ?>
                        <label class="contai-theme-option">
                            <input type="radio" name="theme" value="<?php echo esc_attr($theme_id); ?>"
                                <?php checked($current['theme'], $theme_id); ?>>
                            <span class="contai-theme-preview contai-theme-<?php echo esc_attr($theme_id); ?>">
                                <?php echo esc_html($theme_label); ?>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="contai-settings-row">
                <label class="contai-settings-label">
                    <input type="checkbox" name="show_hierarchy" value="1" <?php checked($current['show_hierarchy']); ?>>
                    <span><?php esc_html_e('Show hierarchy', '1platform-content-ai'); ?></span>
                </label>
                <p class="contai-settings-help"><?php esc_html_e('Display headings in a hierarchical structure with indentation.', '1platform-content-ai'); ?></p>
            </div>

            <div class="contai-settings-row">
                <label class="contai-settings-label">
                    <input type="checkbox" name="numbered_list" value="1" <?php checked($current['numbered_list']); ?>>
                    <span><?php esc_html_e('Numbered list', '1platform-content-ai'); ?></span>
                </label>
                <p class="contai-settings-help"><?php esc_html_e('Use numbered list instead of bullets.', '1platform-content-ai'); ?></p>
            </div>
        </div>
        <?php
    }

    private function renderAdvancedSettings(array $current): void {
        ?>
        <div class="contai-settings-section">
            <h2 class="contai-section-title">
                <span class="dashicons dashicons-admin-tools"></span>
                <?php esc_html_e('Advanced', '1platform-content-ai'); ?>
            </h2>

            <div class="contai-settings-row">
                <label class="contai-settings-label">
                    <input type="checkbox" name="smooth_scroll" value="1" <?php checked($current['smooth_scroll']); ?>>
                    <span><?php esc_html_e('Smooth scroll', '1platform-content-ai'); ?></span>
                </label>
                <p class="contai-settings-help"><?php esc_html_e('Enable smooth scrolling when clicking TOC links.', '1platform-content-ai'); ?></p>
            </div>

            <div class="contai-settings-row">
                <label class="contai-settings-label">
                    <input type="checkbox" name="lowercase_anchors" value="1" <?php checked($current['lowercase_anchors']); ?>>
                    <span><?php esc_html_e('Lowercase anchors', '1platform-content-ai'); ?></span>
                </label>
                <p class="contai-settings-help"><?php esc_html_e('Convert anchor IDs to lowercase.', '1platform-content-ai'); ?></p>
            </div>

            <div class="contai-settings-row">
                <label class="contai-settings-label">
                    <input type="checkbox" name="hyphenate_anchors" value="1" <?php checked($current['hyphenate_anchors']); ?>>
                    <span><?php esc_html_e('Hyphenate anchors', '1platform-content-ai'); ?></span>
                </label>
                <p class="contai-settings-help"><?php esc_html_e('Use hyphens instead of underscores in anchor IDs.', '1platform-content-ai'); ?></p>
            </div>
        </div>
        <?php
    }

    private function handleSave(): void {
        if (!check_admin_referer('contai_toc_settings_save', 'toc_settings_nonce')) {
            wp_die(esc_html__('Security check failed.', '1platform-content-ai'));
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', '1platform-content-ai'));
        }

        if (isset($_POST['reset_settings'])) {
            $this->config->reset();
            $this->purgePageCaches();
            $this->showNotice(__('Settings reset to defaults successfully.', '1platform-content-ai'), 'success');
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each field is sanitized individually below
        $raw_post = wp_unslash( $_POST );
        $data = [
            'enabled' => isset($raw_post['enabled']),
            'post_types' => isset($raw_post['post_types']) ? array_map('sanitize_text_field', (array) $raw_post['post_types']) : [],
            'heading_levels' => isset($raw_post['heading_levels']) ? array_map('intval', (array) $raw_post['heading_levels']) : [],
            'min_headings' => intval($raw_post['min_headings'] ?? 4),
            'position' => sanitize_text_field($raw_post['position'] ?? 'before_first_heading'),
            'title' => sanitize_text_field($raw_post['title'] ?? 'Table of Contents'),
            'show_title' => isset($raw_post['show_title']),
            'show_toggle' => isset($raw_post['show_toggle']),
            'initial_state' => sanitize_text_field($raw_post['initial_state'] ?? 'show'),
            'show_hierarchy' => isset($raw_post['show_hierarchy']),
            'numbered_list' => isset($raw_post['numbered_list']),
            'exclude_patterns' => sanitize_textarea_field($raw_post['exclude_patterns'] ?? ''),
            'theme' => sanitize_text_field($raw_post['theme'] ?? 'grey'),
            'lowercase_anchors' => isset($raw_post['lowercase_anchors']),
            'hyphenate_anchors' => isset($raw_post['hyphenate_anchors']),
            'smooth_scroll' => isset($raw_post['smooth_scroll']),
        ];

        if ($this->config->update($data)) {
            $this->purgePageCaches();
            $this->showNotice(__('Settings saved successfully.', '1platform-content-ai'), 'success');
        } else {
            $this->showNotice(__('Failed to save settings.', '1platform-content-ai'), 'error');
        }
    }

    private function purgePageCaches(): void {
        // LiteSpeed Cache
        if (has_action('litespeed_purge_all')) {
            do_action('litespeed_purge_all');
        }

        // WP Rocket
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }

        // WP Super Cache
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
        }

        // W3 Total Cache
        if (function_exists('w3tc_flush_posts')) {
            w3tc_flush_posts();
        }

        // Cachify
        if (has_action('cachify_flush_cache')) {
            do_action('cachify_flush_cache');
        }

        // WP Fastest Cache
        if (function_exists('wpfc_clear_all_cache')) {
            wpfc_clear_all_cache();
        }

        // Autoptimize
        if (class_exists('autoptimizeCache') && method_exists('autoptimizeCache', 'clearall')) {
            \autoptimizeCache::clearall();
        }
    }

    private function showNotice(string $message, string $type = 'success'): void {
        add_settings_error('toc_settings', 'toc_settings_message', $message, $type);
        settings_errors('toc_settings');
    }

    private function getAvailablePostTypes(): array {
        $types = get_post_types(['public' => true], 'objects');
        $result = [];

        foreach ($types as $type) {
            $result[$type->name] = $type->label;
        }

        return $result;
    }

    private function getThemes(): array {
        return [
            'grey' => __('Grey', '1platform-content-ai'),
            'light-blue' => __('Light Blue', '1platform-content-ai'),
            'white' => __('White', '1platform-content-ai'),
            'black' => __('Black', '1platform-content-ai'),
            'transparent' => __('Transparent', '1platform-content-ai'),
        ];
    }
}
