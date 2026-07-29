<?php
/**
 * Search Console performance section (SCP-04).
 *
 * Rendered inside the Search Console panel for a VERIFIED website only — the
 * same gate ContaiSearchConsoleVerifiedSection uses, because an unverified
 * property has nothing for Google to report on.
 *
 * The markup is a shell; the numbers arrive from
 * `contai/v1/search-console/performance` so the date-range picker can refetch
 * without a page reload. Every value is escaped on insertion in JS
 * (textContent, never innerHTML) — the queries and page URLs come from real
 * searches by third parties and are not trusted input.
 */

if (!defined('ABSPATH')) exit;

class ContaiSearchConsolePerformanceSection
{
    public function render(): void
    {
        ?>
        <div class="contai-settings-section contai-sc-performance" id="contai-sc-performance">
            <h3 class="contai-section-title">
                <span class="dashicons dashicons-chart-bar"></span>
                <?php esc_html_e('Performance', '1platform-content-ai'); ?>
            </h3>

            <div class="contai-sc-perf-toolbar">
                <label for="contai-sc-period" class="screen-reader-text">
                    <?php esc_html_e('Date range', '1platform-content-ai'); ?>
                </label>
                <select id="contai-sc-period" class="contai-sc-perf-period">
                    <option value="24h"><?php esc_html_e('24 hours', '1platform-content-ai'); ?></option>
                    <option value="7d"><?php esc_html_e('7 days', '1platform-content-ai'); ?></option>
                    <option value="28d" selected><?php esc_html_e('28 days', '1platform-content-ai'); ?></option>
                    <option value="3m"><?php esc_html_e('3 months', '1platform-content-ai'); ?></option>
                </select>
            </div>

            <div class="contai-sc-perf-status" id="contai-sc-perf-status" role="status">
                <?php esc_html_e('Loading performance data…', '1platform-content-ai'); ?>
            </div>

            <div class="contai-sc-perf-kpis" id="contai-sc-perf-kpis" hidden>
                <?php
                $this->renderKpi('clicks', __('Clicks', '1platform-content-ai'));
                $this->renderKpi('impressions', __('Impressions', '1platform-content-ai'));
                $this->renderKpi('ctr', __('CTR', '1platform-content-ai'));
                $this->renderKpi('position', __('Position', '1platform-content-ai'));
                ?>
            </div>

            <div class="contai-sc-perf-note" id="contai-sc-perf-lag" hidden>
                <?php
                esc_html_e(
                    'Search Console takes 1–3 days to report new activity, so a very recent range can still look empty.',
                    '1platform-content-ai'
                );
                ?>
            </div>

            <div class="contai-sc-perf-tables" id="contai-sc-perf-tables" hidden>
                <div class="contai-sc-perf-tabs" role="tablist">
                    <button type="button" class="contai-sc-perf-tab is-active" data-dimension="queries" role="tab" aria-selected="true">
                        <?php esc_html_e('Queries', '1platform-content-ai'); ?>
                    </button>
                    <button type="button" class="contai-sc-perf-tab" data-dimension="pages" role="tab" aria-selected="false">
                        <?php esc_html_e('Pages', '1platform-content-ai'); ?>
                    </button>
                    <button type="button" class="contai-sc-perf-tab" data-dimension="countries" role="tab" aria-selected="false">
                        <?php esc_html_e('Countries', '1platform-content-ai'); ?>
                    </button>
                </div>

                <div class="contai-table-wrap">
                    <table class="contai-table contai-sc-perf-table">
                        <thead>
                            <tr>
                                <th id="contai-sc-perf-key-header"><?php esc_html_e('Query', '1platform-content-ai'); ?></th>
                                <th><?php esc_html_e('Clicks', '1platform-content-ai'); ?></th>
                                <th><?php esc_html_e('Impressions', '1platform-content-ai'); ?></th>
                                <th><?php esc_html_e('CTR', '1platform-content-ai'); ?></th>
                                <th><?php esc_html_e('Position', '1platform-content-ai'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="contai-sc-perf-rows"></tbody>
                    </table>
                </div>

                <p class="contai-sc-perf-empty" id="contai-sc-perf-empty" hidden>
                    <?php esc_html_e('Google reported no rows for this breakdown in the selected date range.', '1platform-content-ai'); ?>
                </p>
            </div>
        </div>
        <?php
    }

    private function renderKpi(string $id, string $label): void
    {
        ?>
        <div class="contai-sc-perf-kpi">
            <span class="contai-sc-perf-kpi-label"><?php echo esc_html($label); ?></span>
            <span class="contai-sc-perf-kpi-value" id="contai-sc-perf-<?php echo esc_attr($id); ?>">—</span>
            <span class="contai-sc-perf-kpi-delta" id="contai-sc-perf-<?php echo esc_attr($id); ?>-delta"></span>
        </div>
        <?php
    }
}
