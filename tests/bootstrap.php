<?php

define('ABSPATH', '/tmp/wordpress/');
define('OBJECT', 'OBJECT');
define('ARRAY_A', 'ARRAY_A');
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);

// Create dummy WP admin files so ImageUploader::ensureMediaFunctionsLoaded() doesn't fatal
$wp_admin_includes = ABSPATH . 'wp-admin/includes/';
if (!is_dir($wp_admin_includes)) {
    mkdir($wp_admin_includes, 0777, true);
}
foreach (['file.php', 'media.php', 'image.php'] as $stub) {
    $path = $wp_admin_includes . $stub;
    if (!file_exists($path)) {
        file_put_contents($path, "<?php\n");
    }
}

require_once __DIR__ . '/../vendor/autoload.php';

WP_Mock::bootstrap();

// ── Models ──────────────────────────────────────────────────────
require_once __DIR__ . '/../includes/database/models/JobStatus.php';
require_once __DIR__ . '/../includes/database/models/Job.php';
require_once __DIR__ . '/../includes/database/models/Keyword.php';
require_once __DIR__ . '/../includes/database/models/InternalLink.php';

class_alias('ContaiJobStatus', 'JobStatus');
class_alias('ContaiJob', 'Job');
class_alias('ContaiKeyword', 'Keyword');
class_alias(WPContentAI\ContaiDatabase\Models\ContaiInternalLink::class, 'WPContentAI\Database\Models\InternalLink');

// ── Database ────────────────────────────────────────────────────
require_once __DIR__ . '/../includes/database/Database.php';
require_once __DIR__ . '/../includes/database/MigrationRunner.php';
require_once __DIR__ . '/../includes/database/repositories/JobRepository.php';
require_once __DIR__ . '/../includes/database/repositories/KeywordRepository.php';

class_alias('ContaiDatabase', 'Database');
class_alias('ContaiJobRepository', 'JobRepository');
class_alias('ContaiKeywordRepository', 'KeywordRepository');

// ── Helpers ─────────────────────────────────────────────────────
require_once __DIR__ . '/../includes/helpers/crypto.php';
require_once __DIR__ . '/../includes/helpers/security.php';
require_once __DIR__ . '/../includes/helpers/TimestampHelper.php';
require_once __DIR__ . '/../includes/helpers/JobDetailsFormatter.php';

class_alias('ContaiTimestampHelper', 'TimestampHelper');
class_alias('ContaiJobDetailsFormatter', 'JobDetailsFormatter');

// ── Services ────────────────────────────────────────────────────
require_once __DIR__ . '/../includes/services/config/EnvironmentDetector.php';
require_once __DIR__ . '/../includes/services/toc/HeadingParser.php';
require_once __DIR__ . '/../includes/services/toc/AnchorGenerator.php';
require_once __DIR__ . '/../includes/services/toc/TocBuilder.php';
require_once __DIR__ . '/../includes/services/toc/TocConfiguration.php';
require_once __DIR__ . '/../includes/services/toc/ContentInjector.php';
require_once __DIR__ . '/../includes/services/toc/TocGenerator.php';
require_once __DIR__ . '/../includes/services/toc/TocWordPressIntegration.php';
require_once __DIR__ . '/../includes/admin/apps/panels/TocSettingsPanel.php';
require_once __DIR__ . '/../includes/services/internal-links/KeywordMatcher.php';
require_once __DIR__ . '/../includes/services/internal-links/ContentLinkInjector.php';
require_once __DIR__ . '/../includes/services/http/RateLimiter.php';

class_alias('ContaiEnvironmentDetector', 'EnvironmentDetector');
class_alias('ContaiHeadingParser', 'HeadingParser');
class_alias('ContaiAnchorGenerator', 'AnchorGenerator');
class_alias('ContaiTocBuilder', 'TocBuilder');
class_alias('ContaiTocConfiguration', 'TocConfiguration');
class_alias('ContaiContentInjector', 'ContentInjector');
class_alias('ContaiTocGenerator', 'TocGenerator');
class_alias('ContaiTocWordPressIntegration', 'TocWordPressIntegration');
class_alias('ContaiTocSettingsPanel', 'TocSettingsPanel');
class_alias(WPContentAI\Services\InternalLinks\ContaiKeywordMatcher::class, 'WPContentAI\Services\InternalLinks\KeywordMatcher');
class_alias(WPContentAI\Services\InternalLinks\ContaiContentLinkInjector::class, 'WPContentAI\Services\InternalLinks\ContentLinkInjector');
class_alias('ContaiRateLimiter', 'RateLimiter');

// ── Providers ───────────────────────────────────────────────────
require_once __DIR__ . '/../includes/providers/UserProvider.php';

class_alias('ContaiUserProvider', 'UserProvider');

// ── Agents ───────────────────────────────────────────────────
require_once __DIR__ . '/../includes/services/agents/ContaiAgentEndpoints.php';
require_once __DIR__ . '/../includes/services/agents/ContaiAgentSettingsService.php';
require_once __DIR__ . '/../includes/services/agents/ContaiAgentActionHandler.php';
require_once __DIR__ . '/../includes/services/agents/ContaiSendEmailActionHandler.php';

// ── API & Search Console ────────────────────────────────────────
require_once __DIR__ . '/../includes/services/http/HTTPClientService.php';
require_once __DIR__ . '/../includes/services/config/Config.php';
require_once __DIR__ . '/../includes/services/api/OnePlatformAuthService.php';
require_once __DIR__ . '/../includes/services/http/RequestLogger.php';
require_once __DIR__ . '/../includes/services/api/OnePlatformEndpoints.php';
require_once __DIR__ . '/../includes/services/api/OnePlatformClient.php';
require_once __DIR__ . '/../includes/providers/WebsiteProvider.php';
require_once __DIR__ . '/../includes/services/search-console/SearchConsoleService.php';
require_once __DIR__ . '/../includes/services/setup/SearchConsoleSetupService.php';
require_once __DIR__ . '/../includes/admin/apps/handlers/SearchConsoleFormHandler.php';

// ── Admin Handlers (Billing, Publisuites, Content Generator) ───
require_once __DIR__ . '/../includes/services/billing/BillingService.php';
require_once __DIR__ . '/../includes/services/billing/CreditGuard.php';
require_once __DIR__ . '/../includes/admin/billing/handlers/TopUpHandler.php';
require_once __DIR__ . '/../includes/services/publisuites/PublisuitesService.php';
require_once __DIR__ . '/../includes/services/setup/PublisuitesSetupService.php';
require_once __DIR__ . '/../includes/admin/apps/handlers/PublisuitesFormHandler.php';
require_once __DIR__ . '/../includes/services/jobs/KeywordExtractionJob.php';
require_once __DIR__ . '/../includes/admin/content-generator/handlers/KeywordExtractionHandler.php';
require_once __DIR__ . '/../includes/services/jobs/QueueManager.php';
require_once __DIR__ . '/../includes/services/jobs/JobInterface.php';
require_once __DIR__ . '/../includes/services/jobs/SiteGenerationJob.php';
require_once __DIR__ . '/../includes/services/jobs/recovery/JobRecoveryStrategy.php';
require_once __DIR__ . '/../includes/services/jobs/recovery/ResetToPendingStrategy.php';
require_once __DIR__ . '/../includes/services/jobs/recovery/MarkAsFailedStrategy.php';
require_once __DIR__ . '/../includes/services/jobs/recovery/JobRecoveryService.php';
require_once __DIR__ . '/../includes/services/jobs/JobProcessor.php';
require_once __DIR__ . '/../includes/admin/content-generator/handlers/PostGenerationQueueHandler.php';
require_once __DIR__ . '/../includes/admin/content-generator/panels/post-maintenance.php';

// ── User Profile & License ─────────────────────────────────────
require_once __DIR__ . '/../includes/services/user-profile/UserProfileService.php';
require_once __DIR__ . '/../includes/admin/licenses/WPContentAILicensePanel.php';

// ── Post Pipeline & Generation ─────────────────────────────────
require_once __DIR__ . '/../includes/services/post/ImageUploader.php';
require_once __DIR__ . '/../includes/services/post/ContentImageProcessor.php';
require_once __DIR__ . '/../includes/services/post/WordPressPostCreator.php';
require_once __DIR__ . '/../includes/services/post/PostMetadataBuilder.php';
require_once __DIR__ . '/../includes/services/category/CategoryService.php';
require_once __DIR__ . '/../includes/services/content/ContentGeneratorService.php';
require_once __DIR__ . '/../includes/services/post/PostGenerationOrchestrator.php';

// ── Menu ───────────────────────────────────────────────────────
require_once __DIR__ . '/../includes/services/menu/MainMenuManager.php';

// ── Legal ──────────────────────────────────────────────────────
require_once __DIR__ . '/../includes/services/setup/LegalInfoService.php';
require_once __DIR__ . '/../includes/services/legal/LegalPagesAPIClient.php';
require_once __DIR__ . '/../includes/services/legal/LegalPagesGenerator.php';

// ── SEO ────────────────────────────────────────────────────────
require_once __DIR__ . '/../includes/services/seo/SeoHeadService.php';

// ── Category API ────────────────────────────────────────────────
require_once __DIR__ . '/../includes/services/category-api/CategoryAPIService.php';

// ── Cron ────────────────────────────────────────────────────────
require_once __DIR__ . '/../includes/cron/job-processor-cron.php';
require_once __DIR__ . '/../includes/cron/agent-actions-cron.php';

// ── Plugin version & upgrade routines ──────────────────────────
define('CONTAI_VERSION', '2.21.8');

require_once __DIR__ . '/../includes/database/migrations/CreateKeywordsTable.php';
require_once __DIR__ . '/../includes/database/migrations/CreateAPILogsTable.php';
require_once __DIR__ . '/../includes/database/migrations/CreateJobsTable.php';
require_once __DIR__ . '/../includes/database/migrations/UpdateKeywordsTableStatus.php';
require_once __DIR__ . '/../includes/database/migrations/CreateInternalLinksTable.php';
require_once __DIR__ . '/../includes/database/migrations/BackfillAnalyticsMeta.php';

/**
 * Build the migration runner — mirrors the function in the main plugin file.
 */
if (!function_exists('contai_build_migration_runner')) {
    function contai_build_migration_runner(): ContaiMigrationRunner {
        $runner = new ContaiMigrationRunner();
        $runner->register(1, new ContaiCreateKeywordsTable());
        $runner->register(2, new ContaiCreateAPILogsTable());
        $runner->register(3, new ContaiCreateJobsTable());
        $runner->register(4, new ContaiUpdateKeywordsTableStatus());
        $runner->register(5, new ContaiCreateInternalLinksTable());
        $runner->register(6, new ContaiBackfillAnalyticsMeta());
        return $runner;
    }
}

/**
 * Upgrade routine — mirrors the function in the main plugin file.
 */
if (!function_exists('contai_maybe_upgrade')) {
    function contai_maybe_upgrade() {
        $stored_version = get_option('contai_plugin_version', '0');
        if (version_compare($stored_version, CONTAI_VERSION, '>=')) {
            return;
        }
        $runner = contai_build_migration_runner();
        $result = $runner->run();
        if (!$result['success']) {
            contai_log('Upgrade migration failed: ' . $result['message']);
        }
        contai_register_job_processor_cron();
        contai_register_agent_actions_cron();
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_contai_%'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_timeout_contai_%'));
        update_option('contai_plugin_version', CONTAI_VERSION, false);
        contai_log(sprintf('Plugin upgraded from %s to %s', $stored_version, CONTAI_VERSION));
    }
}

/**
 * Activation routine — mirrors the function in the main plugin file.
 */
if (!function_exists('contai_activate_plugin')) {
    function contai_activate_plugin() {
        $runner = contai_build_migration_runner();
        $result = $runner->run();
        if (!$result['success']) {
            contai_log('Migration batch failed: ' . $result['message']);
        }
        try {
            contai_register_job_processor_cron();
        } catch (\Exception $e) {
            contai_log("Cron registration error: " . $e->getMessage());
        }
        try {
            contai_register_agent_actions_cron();
        } catch (\Exception $e) {
            contai_log("Agent cron registration error: " . $e->getMessage());
        }
        add_option('contai_disable_feeds', '1');
        add_option('contai_disable_author_pages', '1');
        add_option('contai_redirect_404', '1');
        update_option('contai_plugin_version', CONTAI_VERSION, false);
    }
}
