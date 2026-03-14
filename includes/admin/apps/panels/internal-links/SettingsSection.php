<?php

if (!defined('ABSPATH')) exit;

class ContaiInternalLinksSettingsSection
{
    private $config;

    public function __construct(ContaiConfig $config)
    {
        $this->config = $config;
    }

    public function render(): void
    {
        $settings = $this->getCurrentSettings();
        ?>
        <form method="post" class="contai-internal-links-form">
            <?php wp_nonce_field('contai_internal_links_settings_save', 'internal_links_settings_nonce'); ?>

            <?php $this->renderGeneralSettings($settings); ?>
            <?php $this->renderLimitSettings($settings); ?>
            <?php $this->renderMatchingSettings($settings); ?>
            <?php $this->renderExclusionSettings($settings); ?>

            <div class="contai-form-actions">
                <button type="submit" class="button button-primary">
                    <?php esc_html_e('Save Settings', '1platform-content-ai'); ?>
                </button>
                <button type="submit" name="reset_settings" value="1" class="button button-secondary"
                        onclick="return confirm('<?php esc_attr_e('Are you sure you want to reset all settings to defaults?', '1platform-content-ai'); ?>');">
                    <?php esc_html_e('Reset to Defaults', '1platform-content-ai'); ?>
                </button>
            </div>
        </form>
        <?php
    }

    private function renderGeneralSettings(array $settings): void
    {
        ?>
        <div class="contai-settings-section">
            <h2 class="contai-section-title">
                <span class="dashicons dashicons-admin-generic"></span>
                <?php esc_html_e('General Settings', '1platform-content-ai'); ?>
            </h2>

            <?php
            $this->renderCheckbox('enabled', $settings['enabled'],
                __('Enable Internal Links', '1platform-content-ai'),
                __('Automatically add internal links between posts based on keywords.', '1platform-content-ai')
            );

            $this->renderCheckbox('same_category_only', $settings['same_category_only'],
                __('Link Same Category Posts Only', '1platform-content-ai'),
                __('Only create links between posts in the same category for semantic relevance.', '1platform-content-ai')
            );

            $this->renderCheckbox('distribute_links', $settings['distribute_links'],
                __('Distribute Links Evenly', '1platform-content-ai'),
                __('Prevent link clustering by distributing links throughout content.', '1platform-content-ai')
            );

            $this->renderNumberInput('batch_size', $settings['batch_size'], 1, 50,
                __('Batch Size', '1platform-content-ai'),
                __('Number of posts to process in each batch job.', '1platform-content-ai')
            );
            ?>
        </div>
        <?php
    }

    private function renderLimitSettings(array $settings): void
    {
        ?>
        <div class="contai-settings-section">
            <h2 class="contai-section-title">
                <span class="dashicons dashicons-editor-break"></span>
                <?php esc_html_e('Link Limits', '1platform-content-ai'); ?>
            </h2>

            <?php
            $this->renderNumberInput('max_links_per_post', $settings['max_links_per_post'], 1, 50,
                __('Max Links Per Post', '1platform-content-ai'),
                __('Maximum number of internal links to add to a single post.', '1platform-content-ai')
            );

            $this->renderNumberInput('max_links_per_keyword', $settings['max_links_per_keyword'], 1, 20,
                __('Max Links Per ContaiKeyword', '1platform-content-ai'),
                __('Maximum number of times a keyword can be linked across all posts.', '1platform-content-ai')
            );

            $this->renderNumberInput('max_links_per_target', $settings['max_links_per_target'], 1, 20,
                __('Max Links To Target Post', '1platform-content-ai'),
                __('Maximum number of links pointing to a single target post.', '1platform-content-ai')
            );
            ?>
        </div>
        <?php
    }

    private function renderMatchingSettings(array $settings): void
    {
        ?>
        <div class="contai-settings-section">
            <h2 class="contai-section-title">
                <span class="dashicons dashicons-search"></span>
                <?php esc_html_e('Matching Settings', '1platform-content-ai'); ?>
            </h2>

            <?php
            $this->renderCheckbox('case_insensitive', $settings['case_insensitive'],
                __('Case Insensitive Matching', '1platform-content-ai'),
                __('Match keywords regardless of capitalization.', '1platform-content-ai')
            );

            $this->renderCheckbox('word_boundaries', $settings['word_boundaries'],
                __('Use Word Boundaries', '1platform-content-ai'),
                __('Only match complete words, not partial matches within other words.', '1platform-content-ai')
            );

            $this->renderNumberInput('min_keyword_length', $settings['min_keyword_length'], 1, 10,
                __('Minimum ContaiKeyword Length', '1platform-content-ai'),
                __('Minimum character length for keywords to be matched.', '1platform-content-ai')
            );
            ?>
        </div>
        <?php
    }

    private function renderExclusionSettings(array $settings): void
    {
        $excluded_tags = is_array($settings['excluded_tags']) ? implode(', ', $settings['excluded_tags']) : '';
        ?>
        <div class="contai-settings-section">
            <h2 class="contai-section-title">
                <span class="dashicons dashicons-dismiss"></span>
                <?php esc_html_e('Exclusion Settings', '1platform-content-ai'); ?>
            </h2>

            <div class="contai-settings-row">
                <label class="contai-settings-label" for="excluded_tags">
                    <?php esc_html_e('Excluded HTML Tags', '1platform-content-ai'); ?>
                </label>
                <input type="text" name="excluded_tags" id="excluded_tags"
                       value="<?php echo esc_attr($excluded_tags); ?>"
                       class="regular-text" placeholder="h1, h2, h3, code, pre">
                <p class="contai-settings-help">
                    <?php esc_html_e('Comma-separated list of HTML tags where links should not be added (e.g., h1, h2, code, pre).', '1platform-content-ai'); ?>
                </p>
            </div>
        </div>
        <?php
    }

    private function renderCheckbox(string $name, bool $checked, string $label, string $help): void
    {
        ?>
        <div class="contai-settings-row">
            <label class="contai-settings-label">
                <input type="checkbox" name="<?php echo esc_attr($name); ?>" value="1" <?php checked($checked); ?>>
                <span><?php echo esc_html($label); ?></span>
            </label>
            <p class="contai-settings-help"><?php echo esc_html($help); ?></p>
        </div>
        <?php
    }

    private function renderNumberInput(string $name, int $value, int $min, int $max, string $label, string $help): void
    {
        ?>
        <div class="contai-settings-row">
            <label class="contai-settings-label" for="<?php echo esc_attr($name); ?>">
                <?php echo esc_html($label); ?>
            </label>
            <input type="number" name="<?php echo esc_attr($name); ?>" id="<?php echo esc_attr($name); ?>"
                   value="<?php echo esc_attr($value); ?>"
                   min="<?php echo esc_attr($min); ?>"
                   max="<?php echo esc_attr($max); ?>"
                   class="contai-input-number">
            <p class="contai-settings-help"><?php echo esc_html($help); ?></p>
        </div>
        <?php
    }

    private function getCurrentSettings(): array
    {
        return [
            'enabled' => $this->config->isInternalLinksEnabled(),
            'max_links_per_post' => $this->config->getMaxLinksPerPost(),
            'max_links_per_keyword' => $this->config->getMaxLinksPerKeyword(),
            'max_links_per_target' => $this->config->getMaxLinksPerTarget(),
            'batch_size' => $this->config->getInternalLinksBatchSize(),
            'excluded_tags' => $this->config->getExcludedTags(),
            'case_insensitive' => $this->config->isCaseInsensitiveMatching(),
            'word_boundaries' => $this->config->useWordBoundaries(),
            'same_category_only' => $this->config->isSameCategoryOnly(),
            'min_keyword_length' => $this->config->getMinKeywordLength(),
            'distribute_links' => $this->config->shouldDistributeLinks(),
        ];
    }
}
