# Changelog

All notable changes to Content AI are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

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
