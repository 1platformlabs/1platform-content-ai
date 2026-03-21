# Changelog

All notable changes to Content AI are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

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
