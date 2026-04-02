# Changelog

All notable changes to Content AI are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [2.18.0] - 2026-04-01

### Added
- **SEO alt text on images** (#49): All images uploaded via the content pipeline now receive descriptive alt text. Uses per-image AI-generated alt text from the API when available, falls back to the keyword. Sets `_wp_attachment_image_alt` post meta and fixes empty/missing `alt` attributes in HTML content
- **SEO meta description output** (#49): New `ContaiSeoHeadService` outputs `<meta name="description">` tag on singular posts via `wp_head` hook. Uses AI-generated meta description from the API when available, falls back to auto-generated 155-char excerpt from content
- **SEO title tag override** (#49): Hooks `document_title_parts` to use the API-generated `metatitle` (stored in `_contai_metatitle`) for the document `<title>` tag on singular posts
- **Auto-generated post excerpts**: `WordPressPostCreator` now sets `post_excerpt` on all generated posts for meta description and WordPress excerpt support
- **14 new tests**: ImageUploader (2), ContentImageProcessor (4), WordPressPostCreator (2), SeoHeadService (6)

## [2.17.1] - 2026-04-01

### Fixed
- **Site Wizard re-execution invisible failures** (#55): Re-running the Site Wizard after a failed generation showed the form with no error feedback — the user had no way to know the previous run failed or why. Root cause: `findActiveSiteGenerationJob()` only returned PENDING/PROCESSING jobs, so FAILED jobs were invisible
- **Missing website record blocks API operations** (#55): The `activateLicense` step did not call `ensureWebsiteExists()`, causing downstream API operations (tagline generation, theme tracking, site config sync) to silently fail when no website record existed
- **Stale profile cache prevents widget regeneration** (#55): `contai_fetch_generated_profile_from_api()` cached profiles for 6 hours via transient. Re-execution within that window reused stale/empty data instead of fetching a fresh profile for the "About Me" widget

### Added
- **Failed job error notice**: New `contai_render_last_job_notice()` function shows a detailed error box (failed step, error message, completed step count) above the re-run form when the last site generation failed
- **`findLastSiteGenerationJob()` repository method**: Returns the most recent site generation job regardless of status, enabling visibility into failed/completed jobs
- **8 regression tests**: `SiteGeneratorReExecutionTest` validates failed job visibility, `ensureWebsiteExists()` integration, and profile cache clearing

## [2.16.0] - 2026-04-01

### Added
- **Featured image deduplication**: Post generation now avoids reusing the same featured image across posts. The orchestrator queries `_contai_featured_image_source` post meta to find which candidate URLs are already in use and selects the first unused one, falling back to the first image only when all candidates are exhausted
- **Optimized dedup query**: Uses a scoped `IN` clause limited to candidate URLs instead of fetching all used URLs site-wide, keeping the query performant regardless of site size
- **Unit tests**: 4 new tests covering featured image selection (skip used, fallback, fresh site, empty images)

## [2.15.5] - 2026-04-01

### Fixed
- **Site Wizard silent refresh on submit** (#54): "Launch Site Generation" refreshed the page without executing any action or showing feedback. Root cause: `check_admin_referer()` called `wp_die()` on expired nonces (swallowed silently on production), and error messages via URL parameters were lost when `wp_safe_redirect()` failed due to headers already sent
- **Nonce expiration UX**: Replaced `check_admin_referer()` (which `wp_die()`s) with `wp_verify_nonce()` that redirects with a user-friendly "session expired" transient notice
- **Form action ambiguity**: Added explicit `action` attribute to the Site Wizard form to prevent browser URL resolution issues with HTTPS/redirect mismatches

### Changed
- **Error messaging**: Migrated all Site Wizard handler messages from URL query parameters to WordPress transients (`contai_site_gen_notice`) for reliable delivery across redirects
- **Error resilience**: Wrapped form processing in try/catch with `contai_log()` to surface unexpected errors instead of failing silently

## [2.15.3] - 2026-04-01

### Fixed
- **API error messages lost**: `OnePlatformClient::createErrorResponse()` only checked `$json['msg']` for error messages, but FastAPI dependency/validation errors return `{"detail": "..."}` — all such errors silently became generic "Request failed". Added `$json['detail']` fallback (#53)
- **Analytics OAuth silent network error**: JavaScript `.catch()` block in the Google Analytics panel silently reset the button on network errors without showing any message to the user (#53)

## [2.15.1] - 2026-04-01

### Fixed
- **Site Wizard navigation**: Integrated `MainMenuManager` (previously dead code) into the site generation flow as a new `setupNavigation` step — creates "Main Navigation" menu with Home + all generated categories assigned to the theme's primary menu location (#48)
- **Breadcrumbs missing**: Added per-theme breadcrumb configuration in `contai_apply_theme_defaults()` for 8 supported themes (Astra, Neve, Blocksy, Kadence, OceanWP, Sydney, Newsmatic, ColorMag) (#48)
- **Comments not enabled**: Set `default_comment_status` to `open` during site config setup so new posts accept comments by default (#48)

## [2.14.0] - 2026-04-01

### Added
- **Theme breadcrumb defaults**: Enabled breadcrumbs on 8 supported themes (Newsmatic, OceanWP, ColorMag, Astra, Neve, Blocksy, Kadence, Sydney) during site generation for better SEO and navigation
- **Navigation setup step**: New `setupNavigation` step in Site Wizard that auto-creates a main menu from generated categories using `MainMenuManager`
- **Default comment status**: Site generation now sets `default_comment_status` to `open` so new posts receive comments by default

### Fixed
- **Batch completion hang** (#55): Fixed `getBatchStatus()` where `total=0` (no posts enqueued) was never considered complete, causing the Site Wizard to hang at `waitForPosts`. Changed condition from `$total > 0 && $completed >= $total` to `$completed >= $total`

## [2.13.1] - 2026-03-31

### Fixed
- **AdSense website ID resolution**: Fixed `get_website_id()` to use `ContaiWebsiteProvider::getWebsiteId()` instead of raw `get_option()`, which returned an array instead of a string, causing "No website configured" errors on all AdSense endpoints
- **AdSense API response serialization**: Fixed all REST handlers passing the raw `ContaiOnePlatformResponse` object instead of `getData()`, causing `{"data":{}}` empty responses (e.g., missing `authorization_url` on OAuth authorize)
- **AdSense disconnect/revoke state sync**: Guarded `update_option('contai_adsense_connected')` behind `isSuccess()` check to prevent local state desync when the API call fails
- **AdSense Account tab CSS**: Added complete styles for empty state, OAuth stepper, connect button, connected state, features grid, earnings summary, and disconnect area

## [2.13.0] - 2026-03-31

### Added
- **AdSense Account management**: New "AdSense Account" tab in Ads Manager with OAuth popup flow for connecting/disconnecting Google AdSense, status display, and earnings overview
- **AdSense REST controller**: 11 REST endpoints (`authorize`, `connect`, `disconnect`, `revoke`, `status`, `earnings`, `sites`, `sync_sites`, `alerts`, `policy_issues`, `oauth_status`) with admin capability checks and nonce verification
- **OAuth popup flow**: Secure `postMessage` + origin validation for AdSense authorization with auto-sync of publisher ID after connect
- **AdSense account JS/CSS**: `adsense-account.js` (OAuth flow, status loading, earnings display) and `adsense-account.css` (account tab styling)
- **Policy notice**: Admin notice when AdSense policy issues are detected via API

### Fixed
- **Delete confirmation dialog**: Fixed dead `data-confirm` attribute on "Delete & Reset" button — now uses `onclick` confirm dialog
- **Period parameter validation**: Added whitelist `validate_callback` for earnings report period parameter
- **Non-JSON error handling**: JS `apiRequest()` now handles server errors (500 HTML, auth redirects) gracefully

## [2.12.4] - 2026-03-28

### Fixed
- **Site Wizard category sync**: `SiteConfigService` now saves `site_category` and PATCHes the API with `category_id` + `lang` during wizard flow, fixing sites created with `category_id: null` (#46)
- **Tagline generation**: `contai_configure_site_metadata()` now fetches AI-generated tagline from the API, fixing "My Blog" default tagline (#46)
- **Sidebar visibility in cron**: Static sidebar ID mapping per theme replaces unreliable `$wp_registered_sidebars` global in async/cron context (#46)
- **Nav menu assignment in cron**: Static nav menu location mapping per theme replaces `get_registered_nav_menus()` which returns empty in cron (#46)
- **Sidebar layout for all themes**: `contai_apply_theme_defaults()` now forces right-sidebar layout for all 9 supported themes (previously only 3) (#46)
- **Theme installation verification**: `contai_install_theme()` now checks `themes_api()` and `$upgrader->install()` return values and throws on failure (#46)
- **Category menu matching**: `MainMenuManager` now tries slug-based lookup before name-based, fixing case-sensitive mismatches (#46)
- **Tagline acceptance**: Plugin now always accepts AI tagline from API response, removing the `empty()` guard that blocked updates (#46)
- **$_POST in cron**: `contai_generate_cookies_banner()` no longer reads from `$_POST` in cron context (#46)
- **Redundant get_option calls**: Removed duplicate `get_option()` calls in `WebsiteProvider` (#46)
- **Echo in cron**: Removed HTML `echo` from widget/icon handlers that run in background jobs (#46)

### Added
- **Footer menu with legal pages**: New `contai_create_footer_menu_with_legal_pages()` creates a footer nav menu and assigns legal pages to the theme's footer location (#46)
- **SiteConfigService tests**: 9 new unit tests covering save, validate, get, and API sync failure scenarios (#46)
- **`contai_site_topic` option**: New option with backward compatibility for legacy `contai_site_theme` (#46)

## [2.12.2] - 2026-03-28

### Changed
- **Publisuites menu label**: Renamed "Link Building" to "Publisuites" in sidebar, page title, apps panel, and logs adapter (#44)

## [2.12.1] - 2026-03-28

### Fixed
- **Release pipeline**: Resolved orphaned git tag v2.13.0 that blocked CI tag & release job

## [2.12.0] - 2026-03-27

### Added
- **Google Analytics panel**: Full OAuth2 connection flow with 3-step visual wizard (Authorize → Select Property → Auto-Configure)
- **AnalyticsFormHandler**: Dedicated AJAX handler class for analytics operations (connect, disconnect, OAuth, setup, polling)
- **GA4 tag injection**: Automatic gtag.js with GDPR Consent Mode v2 (analytics denied by default)
- **Custom dimensions**: content_source, target_keyword, content_cluster, op_content_type sent on page views
- **Server-side events**: content_published, content_updated, comment_received, seo_action via Measurement Protocol
- **Analytics endpoint constants**: 5 new constants in OnePlatformEndpoints (ANALYTICS_OAUTH_AUTHORIZE, ANALYTICS_OAUTH_STATUS, ANALYTICS_SETUP, ANALYTICS_STATUS, ANALYTICS_MP_EVENT)
- **BackfillAnalyticsMeta migration**: Backfills _1platform_ai_generated and _1platform_keyword post meta from existing keywords table
- **PostMetadataBuilder**: Adds analytics meta (_1platform_ai_generated, _1platform_keyword, _1platform_cluster) during content generation

### Fixed
- **Server event error handling**: Replaced dead is_wp_error() check with correct $result->isSuccess() check
- **Event idempotency**: Added event_id (md5 hash) to prevent duplicate Measurement Protocol events
- **Rate limit logging**: Added error_log() when server event rate limit (60/hour) is reached

## [2.11.4] - 2026-03-26

### Added

- **One-click link building setup**: Unified the 3-step Publisuites flow (connect, create file, verify) into a single click, matching the Search Console one-click pattern. New `PublisuitesSetupService` orchestrates the full flow with error recovery via existing manual fallback panels
- **11 new tests**: 7 unit tests for `PublisuitesSetupService` (success, 3 failure points, idempotency, config validation) + 4 tests for `PublisuitesFormHandler` (setup success/failure, backward compatibility)

### Fixed

- **Provider name exposure**: Removed all instances of "Publisuites" from client-facing UI strings across ConnectSection, VerificationSection, ConnectedSection, PublisuitesPanel, AppsPanel, AppsSidebar, admin-apps, and FormHandler. Replaced with "marketplace" or "link building" per 1Platform branding rules

## [2.11.2] - 2026-03-26

### Fixed

- **Job queue stuck in PENDING**: Cron event `contai_process_job_queue` was only registered on plugin activation. If the WP-Cron event was lost (database operations, object-cache flushes, plugin auto-updates, caching-plugin interference), jobs would stay in PENDING forever. Added self-healing `init` hook to re-register the cron event automatically (#39)

### Added

- **Immediate job processing trigger**: After enqueuing jobs, the plugin now calls `spawn_cron()` to kick WP-Cron immediately instead of waiting up to 60 seconds for the next scheduled cycle
- **4 new regression tests**: Self-healing cron re-registration (2 tests) and immediate trigger behavior (2 tests)

## [2.10.6] - 2026-03-25

### Added

- **Admin form handler unit tests**: 38 new tests covering the 4 most critical admin form handlers (#22)
  - `TopUpHandlerTest` (9 tests): nonce/capability checks, amount validation ($5–$200), currency validation, payment URL redirect, API failure handling
  - `PublisuitesFormHandlerTest` (10 tests): nonce/capability checks, connect, verify, disconnect, verification file creation
  - `KeywordExtractionHandlerTest` (10 tests): nonce/capability checks, topic/language/country validation, job creation success and failure
  - `PostGenerationQueueHandlerTest` (9 tests): nonce/capability checks, post count/language/image provider validation, enqueue and clear queue

### Changed

- **`KeywordExtractionHandler`**: Constructor now accepts optional `ContaiJobRepository` for dependency injection
- **`PostGenerationQueueHandler`**: Constructor now accepts optional `ContaiQueueManager` for dependency injection
- **`phpunit.xml.dist`**: Admin handler directories now included in code coverage (previously all of `includes/admin` was excluded)

## [2.10.5] - 2026-03-25

### Fixed

- **Orphan agent cron on plugin uninstall**: `uninstall.php` only cleared `contai_process_job_queue` but not `contai_agent_actions_poll`, leaving an orphan cron event after plugin deletion (#23)
- **Job processor deactivation not clearing all instances**: `contai_unregister_job_processor_cron` used `wp_unschedule_event` (removes one instance) instead of `wp_clear_scheduled_hook` (removes all), which could leave orphan entries if a race condition caused double-scheduling. Now both cron hooks use `wp_clear_scheduled_hook` consistently

### Added

- **`CronDeactivationTest`**: 6 unit tests covering registration and unregistration of both cron hooks (`contai_process_job_queue` and `contai_agent_actions_poll`)

## [2.10.3] - 2026-03-25

### Fixed

- **Site Wizard categories not loading after previous attempt**: The `activateLicense()` step read the already-encrypted API key from the job payload and re-saved it through `saveApiKey()`, which encrypted it again. After double-encryption, all authenticated API calls failed, causing "No categories available" on subsequent wizard runs. Removed `license_key` from the job payload and changed `activateLicense()` to validate the existing key via `hasApiKey()` (#16)

### Added

- **`CategoryAPIServiceGetActiveCategoriesTest`**: 9 unit tests for `getActiveCategories()` covering API success, auth failure, cache hit, force refresh, inactive categories, non-array data, reindexing, and no-cache-on-failure
- **`SiteGeneratorPayloadNoLicenseKeyTest`**: 3 unit tests verifying the job payload contains no sensitive data

## [2.10.2] - 2026-03-25

### Fixed

- **Site Wizard ignoring language selection**: Selecting "Spanish" in the Site Wizard generated content in English because `keyword_extraction.target_language` and `post_generation.target_language` read from a non-existent form field (`contai_target_language`) instead of deriving from the actual `contai_site_language` field. Now uses `ContaiCategoryAPIService::normalizeLanguage()` to correctly map `spanish` → `es` and `english` → `en` (#15)

### Added

- **`CategoryAPIServiceTest`**: 10 unit tests for `normalizeLanguage()` and `getCategoryTitle()` covering all language mappings, edge cases, and fallbacks
- **`SiteGeneratorPayloadTest`**: 5 unit tests validating the Site Wizard payload correctly maps form language to API language codes

## [2.10.1] - 2026-03-25

### Fixed

- **Stale auth error banner persisting after connection recovery**: The "Failed to obtain user authentication token" admin notice now auto-clears when tokens are valid, preventing stale errors from a previous transient API failure (#20)
- **`validateConnectionStatus()` leaving stale errors**: Successful connection validation (first attempt or retry) now clears any leftover token error options from `wp_options`
- **`forceRefreshAllTokens()` not clearing errors early enough**: Errors are now cleared at the start of a force-refresh, not just at the end — prevents stale errors from persisting if the refresh partially fails
- **`WPContentAILicensePanel` testability**: Constructor now accepts optional `UserProfileService` and `AuthService` dependencies for unit testing

### Added

- **`OnePlatformAuthService::clearErrors()`**: Public method to clear both app and user token error options
- **`WPContentAILicensePanel::getAuthService()`**: Centralized auth service accessor supporting dependency injection
- **`OnePlatformAuthServiceTest`**: 7 unit tests covering error clearing, token validation, and auth header generation
- **`WPContentAILicensePanelTest`**: 3 unit tests covering stale error clearing on connection success, retry success, and error persistence on failure
## [2.9.1] - 2026-03-25

### Fixed

- **`SiteGenerationJob::setupAdsManager`**: Wrapped in try/catch to prevent AdSense setup failures from crashing the entire site generation job — now logs the error and continues, consistent with other optional steps (#13)
- **Null coalescing fallback**: Added `$config['adsense']['publisher_id'] ?? ''` to prevent `TypeError` when the `adsense` key is missing from the payload

## [2.9.0] - 2026-03-25

### Changed

- **Search Console one-click setup**: Unified the 3-step manual flow (add site → create verification file → verify) into a single click that runs all steps automatically via `SearchConsoleSetupService::activateSearchConsole()`
- **`SearchConsoleFormHandler`**: Replaced `handleAddWebsite()` with `handleSetup()` that delegates to the existing `SearchConsoleSetupService` for full orchestration
- **`AddWebsiteSection` UI**: Updated panel to show the 4 automated steps and use the new `contai_setup_search_console` form action

### Added

- **`SearchConsoleFormHandlerTest`**: Unit tests for one-click setup flow — success, failure at add, failure at verify, and graceful sitemap failure scenarios

## [2.8.0] - 2026-03-24

### Added

- **Topic-based keyword extraction**: Keyword extractor now discovers keywords by topic/theme instead of domain URL, using the new `POST /posts/keywords/topic/` API endpoint
- **`KeywordExtractorService::extractByTopicAndSave()`**: New method for topic-based extraction via `POSTS_KEYWORDS_TOPIC` endpoint
- **`POSTS_KEYWORDS_TOPIC` endpoint constant**: Added `/posts/keywords/topic/` to `OnePlatformEndpoints`
- **AdSense publisher ID early save**: Publisher ID is now saved immediately on form submission (before background job), fixing #12 where the ID wasn't available in Ads Manager until the job completed

### Changed

- **Keyword extractor UI**: Replaced domain URL input with topic/theme text field in the keyword extraction panel (`keyword-extractor.php`)
- **`KeywordExtractionHandler`**: Validates topic (3-200 chars) instead of URL, sends `topic` field to API
- **`KeywordExtractionJob`**: Uses `extractByTopicAndSave()` instead of `extractAndSaveKeywords()`
- **Site Generator form**: Replaced "Source URL" with "Source Topic" field (`source_url` → `source_topic`), redesigned form layout and CSS
- **`SiteGenerationJob`**: Uses topic-based extraction for keyword discovery step
- **Error message encoding**: Removed redundant `urlencode()` from error redirect messages (WordPress handles encoding)

## [2.6.0] - 2026-03-22

### Added

- **Category-based theme auto-selection**: Theme is now automatically assigned based on the selected category's `recommended_theme` field from the API. Replaces the manual theme dropdown in both Site Generator and Settings forms
- **Theme defaults per theme**: `contai_apply_theme_defaults()` applies theme-specific settings (sidebar layout, reading options) after installation for OceanWP, GeneratePress, ColorMag, and Newsmatic
- **Dynamic sidebar detection**: `contai_get_primary_sidebar_id()` detects the active theme's primary sidebar ID instead of hardcoding `sidebar-1`
- **Theme tracking in API**: After theme installation, the selected theme slug is sent to the 1Platform API via `WebsiteProvider::updateWebsite()` (non-critical, wrapped in try/catch)
- **Auth token retry on null**: `OnePlatformClient` now force-refreshes tokens and retries once when `obtainAuthHeaders()` returns null
- **License activation retry**: `WPContentAILicensePanel` retries profile fetch with force-refreshed tokens when the first attempt fails

### Changed

- **Default theme changed to Astra**: All default theme references changed from `blogfull` to `astra` across Site Generator, Settings, and SiteConfigService
- **Site Generator wizard restructured**: Reorganized into 3 steps (Website Identity → Legal Information → Content Generation) with improved help text and placeholders
- **Theme field is now readonly**: Settings form shows the auto-assigned theme as a readonly field with hidden input, updated via JavaScript when category changes

### Fixed

- **Crypto fallback for legacy keys**: `contai_decrypt_api_key()` now returns the original value when the stored value is not in encrypted format (legacy unencrypted keys), preventing silent data loss

## [2.5.0] - 2026-03-22

### Changed

- chore: bump version to 2.5.0 (minor)

## [2.4.0] - 2026-03-21

### Added

- **Agent platform integration**: Full WordPress admin UI and API integration for the 1Platform AI agent system
  - **Agents admin page** (`ContaiAgentsAdminPage`): List agents, view runs, trigger executions, and consume human-in-the-loop actions from wp-admin
  - **Agent API service** (`ContaiAgentApiService`): Client for all agent API endpoints (catalog, wizard, CRUD, runs, actions)
  - **Agent REST controller** (`ContaiAgentRestController`): WordPress REST API proxy routes under `contai/v1/` for AJAX calls from the admin UI
  - **Agent settings service** (`ContaiAgentSettingsService`): Manages agent-related WordPress options
  - **Agent sync service** (`ContaiAgentSyncService`): Synchronizes agent state between WordPress and the 1Platform API
  - **Action handlers**: `ContaiAgentActionHandler` base class + `ContaiSendEmailActionHandler` for processing agent-generated actions
  - **Agent actions cron** (`agent-actions-cron.php`): WP-Cron job for polling and processing pending agent actions
  - **Admin assets**: `contai-agents-admin.css` + `contai-agents-admin.js` for the Agents admin page
  - **Admin menu**: "Agents" submenu item under 1Platform Content AI settings

### Changed

- **HTTPClientService**: Empty arrays now encode as `{}` instead of `[]` in JSON request bodies (fixes API compatibility for endpoints expecting objects)

### Tests

- `AgentSettingsServiceTest`, `PublishContentActionHandlerTest`, `SendEmailActionHandlerTest`

## [2.3.5] - 2026-03-17

### Fixed

- **BillingHistoryPanel**: `_format_transaction_row()` ahora muestra la moneda del merchant (`currency`) directamente desde la transacción, en lugar de forzar `USD` cuando `usd_amount` está presente — alinea el plugin con el sistema multi-moneda de la API

## [2.3.4] - 2026-03-15

### Added
- Client-side logging system with `ContaiClientLogReporter`, `ContaiLogsService`, `ContaiLogsAdapter`, and Logs admin panel
- API trace ID propagation (`x-trace-id` header) through `OnePlatformResponse` for error traceability
- Trace ID links in error notices for Search Console, Publisuites, and Billing panels
- `ContaiNoticeHelper` for consistent error notice formatting with log references
- CI/CD pipeline: GitHub Actions workflows for QA (8 jobs) and PROD (5 jobs) with Claude AI code review, auto-fix, semantic version bump, SVN deploy to WordPress.org, and Slack notifications
- `.distignore` updated with linting/static analysis exclusions
- WordPress.org asset files (`wp-assets/`)

### Fixed
- Environment detection: `.local` domains now correctly resolve to `development` instead of `staging`

### Changed
- `OnePlatformClient` now logs failed API requests automatically via `ContaiClientLogReporter`
- Error panels in Billing use `wp_kses()` instead of `esc_html()` to allow safe HTML links in error messages

## [2.3.3] - 2026-03-14

### Fixed
- Search Console: Disconnect Website now deletes the website from the 1Platform API before clearing local state, preventing `initializeWebsiteStatus()` from re-syncing stale data
- Search Console: Treat HTTP 404 on disconnect as already-disconnected and proceed with local cleanup
- Fix corrupted string literals (`ContaiKeyword` in user-facing messages) caused by over-aggressive class rename in v2.3.2
- Fix 288 broken unit tests caused by class name mismatches after `Contai` prefix rename; added `class_alias` mappings in test bootstrap

### Changed
- Updated Disconnect Website UI description to accurately reflect that it removes both remote and local data

## [2.3.2] - 2026-03-14

### Fixed
- TOC: Escape all dynamic values in HTML attributes (`esc_attr()` for CSS classes and `aria-expanded`)
- TOC: Sanitize `the_content` filter return with `wp_kses_post()` to prevent XSS vulnerabilities

### Changed
- Add `Contai` prefix to all 137 class/interface names to avoid global namespace conflicts
- Rename JS localized variables: `tocConfig` → `contaiTocConfig`, `taiSiteGenI18n` → `contaiSiteGenI18n`

## [2.3.1] - 2026-02-28

### Fixed
- Publisuites verification file created with wrong name (`=` base64 padding stripped by `sanitize_file_name()`)

## [2.3.0] - 2026-02-18

### Added
- OnePlatform API integration with dual-token authentication (app token + user token)
- Centralized endpoint definitions via `OnePlatformEndpoints` class
- Admin notice system for authentication token errors

### Changed
- Migrated all services from `WPContentAIClient` to `OnePlatformClient`
- API base URLs updated to 1Platform domain (`api.1platform.pro`)
- User identity now managed server-side, removing `UserProvider` dependency from most services
- Simplified endpoint patterns (no more user ID in URL paths)
- Plugin URI updated to `https://1platform.pro/`

### Removed
- `WPContentAIAuthService` and `WPContentAIClient` (replaced by OnePlatform equivalents)
- Manual `UserProvider` lookups in `ImageGenerationService`, `LegalPagesAPIClient`, `CommentsService`, `SearchConsoleService`, and `PublisuitesService`

## [2.2.0] - 2026-02-13

### Changed
- Restructured admin menu for clearer navigation
- Security hardening across admin pages

### Removed
- Dead code cleanup throughout the plugin

## [2.1.1] - 2026-02-06

### Added
- Billing module with credit balance management and transaction history

### Fixed
- Publisuites UI/UX accessibility and responsive improvements

## [2.1.0] - 2026-02-04

### Added
- AI Site Generator (Site Wizard) for automated full-site setup
- Job Monitor with real-time background task tracking
- Enhanced job processing system with recovery strategies
- Publisuites integration with website verification

## [2.0.0] - 2026-01-24

### Changed
- Restructured admin menu organization

## [1.23.0] - 2026-01-24

### Changed
- Restructured admin menu organization

## [1.22.0] - 2026-01-20

### Added
- Google Search Console integration with website verification
- Strategy pattern for website status verification

### Changed
- Aligned Search Console styles with Internal Links design system

### Fixed
- Sitemap dynamic generation
- Legal pages location

## [1.21.0] - 2026-01-15

### Added
- Custom post slug support for generated posts

### Fixed
- Menu compatibility improvements
- Post generator bug fixes

## [1.20.0] - 2026-01-14

### Added
- Internal linking engine with Strategy Pattern (Incoming/Outgoing strategies)
- Automatic internal link injection between related posts

## [1.19.0] - 2026-01-11

### Added
- Keyword translation support for multi-language content
- Environment detection system (development/staging/production)

## [1.18.0] - 2026-01-09

### Added
- Table of Contents automatic generation system
- Apps management page for tools and integrations

## [1.17.0] - 2026-01-08

### Added
- Keywords in target language support

## [1.16.0] - 2026-01-06

### Added
- Main menu management
- Job queue enhancements with concurrency control

## [1.15.0] - 2026-01-04

### Added
- Multi-language content generation
- Legal information configuration for legal pages
- Category selection system
- Centralized config pattern for API keys

### Fixed
- PHP 8.x compatibility for API key decryption
- Missing CSS for Legal Information section

## [1.14.0] - 2025-12-30

### Added
- Post generation queue system with background job processing
- WordPress cron integration for automated content creation

## [1.13.0] - 2025-12-30

### Added
- Content AI API integration with OAuth authentication
- Keyword Extractor service with competitor analysis

## [1.11.0] - 2025-12-30

### Added
- Content Generator UI/UX redesign
- Database layer with custom tables and repository pattern

## [1.10.4] - 2025-12-30

### Added
- Legal pages generation (Privacy Policy, Terms of Service, Cookie Policy)

### Fixed
- Bug fixes in legal pages module

## [1.10.0] - 2025-11-24

### Changed
- Refactored Content Generator and Keyword Extractor UI

## [1.9.8] - 2025-11-19

### Added
- Responsive About Me profile card for sidebar widgets

## [1.9.7] - 2025-11-19

### Added
- Widget generation with search, comments, and recent posts

## [1.9.6] - 2025-11-19

### Added
- Complete admin interface redesign with theme selector
- AdSense settings, comments, and legal pages UI

## [1.0.0] - 2025-11-19

### Added
- Initial release
- WordPress plugin bootstrap
- Basic admin interface
