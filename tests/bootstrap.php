<?php

define('ABSPATH', '/tmp/wordpress/');
define('OBJECT', 'OBJECT');
define('ARRAY_A', 'ARRAY_A');
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);

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
require_once __DIR__ . '/../includes/services/internal-links/KeywordMatcher.php';
require_once __DIR__ . '/../includes/services/internal-links/ContentLinkInjector.php';
require_once __DIR__ . '/../includes/services/http/RateLimiter.php';

class_alias('ContaiEnvironmentDetector', 'EnvironmentDetector');
class_alias('ContaiHeadingParser', 'HeadingParser');
class_alias('ContaiAnchorGenerator', 'AnchorGenerator');
class_alias('ContaiTocBuilder', 'TocBuilder');
class_alias('ContaiTocConfiguration', 'TocConfiguration');
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
require_once __DIR__ . '/../includes/admin/content-generator/handlers/PostGenerationQueueHandler.php';

// ── User Profile & License ─────────────────────────────────────
require_once __DIR__ . '/../includes/services/user-profile/UserProfileService.php';
require_once __DIR__ . '/../includes/admin/licenses/WPContentAILicensePanel.php';

// ── Post Pipeline ──────────────────────────────────────────────
require_once __DIR__ . '/../includes/services/post/ImageUploader.php';
require_once __DIR__ . '/../includes/services/post/ContentImageProcessor.php';
require_once __DIR__ . '/../includes/services/post/WordPressPostCreator.php';

// ── SEO ────────────────────────────────────────────────────────
require_once __DIR__ . '/../includes/services/seo/SeoHeadService.php';

// ── Category API ────────────────────────────────────────────────
require_once __DIR__ . '/../includes/services/category-api/CategoryAPIService.php';

// ── Cron ────────────────────────────────────────────────────────
require_once __DIR__ . '/../includes/cron/job-processor-cron.php';
require_once __DIR__ . '/../includes/cron/agent-actions-cron.php';
