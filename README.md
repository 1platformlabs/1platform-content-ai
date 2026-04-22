# 1Platform Content AI — WordPress plugin

<p align="center">
    <img src="wp-assets/banner.svg" alt="1Platform Content AI" width="720">
</p>

<p align="center">
  <strong>One platform. Every solution.</strong>
</p>

<p align="center">
  <a href="https://wordpress.org/plugins/1platform-content-ai/"><img src="https://img.shields.io/wordpress/plugin/v/1platform-content-ai?style=for-the-badge&label=Version" alt="Plugin version"></a>
  <a href="https://wordpress.org/plugins/1platform-content-ai/"><img src="https://img.shields.io/wordpress/plugin/wp-version/1platform-content-ai?style=for-the-badge&label=WordPress" alt="WordPress tested"></a>
  <a href="https://wordpress.org/plugins/1platform-content-ai/"><img src="https://img.shields.io/wordpress/plugin/required-php/1platform-content-ai?style=for-the-badge&label=PHP" alt="PHP required"></a>
  <a href="LICENSE.txt"><img src="https://img.shields.io/badge/License-GPLv2%2B-blue.svg?style=for-the-badge" alt="GPLv2+ License"></a>
</p>

**1Platform Content AI** is the _official WordPress client_ for the 1Platform cloud. It brings AI-powered blog posts, SEO metadata, keyword research, internal linking, tables of contents, legal pages, and site-wide setup directly into your WordPress admin — while all heavy AI processing runs on the 1Platform servers.

If you want a single plugin that researches keywords, writes posts with images and metadata, and ties them together with internal links and tables of contents, this is it.

Core capabilities include: AI blog post generation, AI featured images, keyword extraction by topic, internal linking engine, automatic tables of contents, Site Wizard, Job Monitor, legal pages & cookie banner, Google Analytics (GA4 + Consent Mode v2), Google Search Console, Publisuites sponsored posts, Google AdSense manager, AI Agents, billing & top-ups.

[Website](https://1platform.pro) · [API docs](https://developer.1platform.pro) · [WordPress.org listing](https://wordpress.org/plugins/1platform-content-ai/) · [Changelog](CHANGELOG.md) · [Terms](https://1platform.pro/terms) · [Privacy](https://1platform.pro/privacy) · [Support](https://1platform.pro/contact)

New install? Start at **1Platform Content AI → License** in your WordPress admin and paste your API key from [1platform.pro](https://1platform.pro).

Preferred setup: use the **Site Wizard** to configure your theme, generate your first posts, create legal pages, and connect integrations in one guided flow.
Works on any WordPress theme — no build tools or Node required.

## Install (recommended)

Runtime: **WordPress 5.9+** and **PHP 7.4+** (tested up to WordPress 6.9).

1. Install from the WordPress admin: **Plugins → Add New → search "1Platform Content AI"**.
2. Activate the plugin.
3. Go to **1Platform Content AI → License** and paste your API key from [1platform.pro](https://1platform.pro).
4. Configure your site under **Settings** (topic, language, category, theme).
5. Generate content from the **Content** menu, or run the **Site Wizard** for a full-site setup.

Local features (Table of Contents, Internal Links) work immediately without an API key.

## Quick start (TL;DR)

```text
wp-admin → 1Platform Content AI → License        # paste API key
wp-admin → 1Platform Content AI → Settings       # topic, language, category, theme
wp-admin → 1Platform Content AI → Site Wizard    # guided full-site setup
wp-admin → 1Platform Content AI → Content        # generate a single post or batch
wp-admin → 1Platform Content AI → Job Monitor    # track background jobs in real time
```

Full user guide: **[1platform.pro/docs](https://1platform.pro)**. Developer reference: **[developer.1platform.pro](https://developer.1platform.pro)**.

Upgrading? Updates ship automatically via WordPress.org. The plugin runs an upgrade routine on activation that re-registers crons and applies migrations — no manual steps required.

## Data & security (important)

1Platform Content AI is a **SaaS client** — AI work runs on the 1Platform servers, not on your hosting.

- **Cloud features** (post generation, keywords, images, comments, legal pages, wizard) require an **API key** and talk to `https://api.1platform.pro/api/v1` over HTTPS.
- **Local features** (Table of Contents, Internal Links) run entirely on your site and require no API key.
- The plugin **does not expose any public REST endpoint** on your WordPress site — it is a client, not a server.
- The plugin **does not modify WordPress core files**. All state lives in plugin-prefixed options, post meta (`contai_*`, `_seo_*`), and custom tables.
- No data leaves your site until you configure an API key and explicitly trigger a feature.
- Security practices: nonce verification on every form, capability checks on every admin screen, `wpdb::prepare()` on every SQL call, output escaping end-to-end, encrypted storage of the API key at rest.

Full disclosure of every external service (1Platform API, Google AdSense, Pexels/Pixabay proxy) lives in [readme.txt](readme.txt) under **External Services**.

## Highlights

- **AI blog post generation** — complete SEO-optimized articles with featured images, alt text, meta title, meta description, and excerpt. Runs as a background job; images are downloaded into the Media Library.
- **Keyword extraction** — discover ranking opportunities by topic (no competitor URL required).
- **Internal Links** — automatic internal linking between your posts to improve SEO and navigation. Local, no API key needed.
- **Table of Contents** — hierarchical TOC injected via WordPress filters, with 8+ theme presets. Local, no API key needed.
- **Site Wizard** — guided theme selection, content generation, legal pages, menu/breadcrumbs/comments, and integrations in a single flow.
- **Job Monitor** — real-time dashboard for every background task (pending / processing / completed / failed) with retry and error details.
- **AI Agents** — connect AI agents from 1Platform and let them act inside WordPress via the plugin's action queue.
- **Billing & top-ups** — check credit balance, review history, and top up directly from the admin.

## Integrations

- **Google Analytics 4** — OAuth2 connect, GA4 tag injection with **GDPR Consent Mode v2** (analytics denied by default), custom dimensions, server-side events via Measurement Protocol.
- **Google Search Console** — one-click connect through 1Platform to pull performance data.
- **Publisuites** — sync sponsored-post orders from the admin: view, accept, reject, reopen, and deliver.
- **Google AdSense** — publisher ID management, ads.txt auto-generation, custom header code injection, OAuth earnings overview and policy alerts.
- **Legal pages & cookie banner** — generate privacy policy, terms, and cookie policy with a consent banner that feeds Consent Mode v2.

## FAQ (short)

- **Do I need an API key?** Only for cloud features. Internal Links and Table of Contents work without one.
- **Where is content generated?** On the 1Platform servers. Your site receives finished posts over HTTPS.
- **Does it work with my theme?** Yes. The plugin is theme-independent and injects features through standard WordPress filters. The Site Wizard has presets for 8+ popular themes.
- **Does it modify core?** No. Core files are untouched; the plugin uses standard APIs and prefixed tables.
- **How are background jobs processed?** Via WP-Cron. The Job Monitor surfaces status and errors in real time, and the plugin self-heals cron re-registration if events are lost.

Full FAQ + external-services disclosure: [readme.txt](readme.txt).

## From source (development)

```bash
git clone <your-fork-of-this-plugin>.git 1platform-content-ai
cd 1platform-content-ai

# Install dev dependencies (PHPUnit, Mockery, WP_Mock)
composer install

# Run the test suite
vendor/bin/phpunit

# Run a single file
vendor/bin/phpunit tests/path/to/MyTest.php
```

For live development, symlink this checkout into your WordPress install:

```bash
ln -s "$(pwd)" "/path/to/wp-content/plugins/1platform-content-ai"
```

The repository has no build step — it is pure PHP with enqueued CSS/JS assets. Run `composer dump-autoload` after adding new classes under `includes/`.

## Configuration

Most configuration lives in the WordPress admin (**1Platform Content AI** menu). Key options:

| Setting | Where | Purpose |
|---|---|---|
| API key | License | Authenticates the site against the 1Platform cloud |
| Site topic, language, category | Settings | Defaults used by content generation and the Site Wizard |
| Theme preset | Settings | Drives breadcrumbs, menu, and TOC presets |
| AdSense publisher ID | Tools → Ads Manager | Injects AdSense + ads.txt |
| Google Analytics | Tools → Analytics | GA4 tag + Consent Mode v2 |
| Search Console | Tools → Search Console | One-click connect |
| Publisuites | Tools → Publisuites | Sponsored-post order sync |

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the full history. Short-form release notes are mirrored in [readme.txt](readme.txt) for the WordPress.org listing.

## Community & support

- WordPress.org listing: **[wordpress.org/plugins/1platform-content-ai](https://wordpress.org/plugins/1platform-content-ai/)**
- Product site: **[1platform.pro](https://1platform.pro)**
- API developer docs: **[developer.1platform.pro](https://developer.1platform.pro)**
- Support: **[1platform.pro/contact](https://1platform.pro/contact)**

## License

GPLv2 or later. See [LICENSE.txt](LICENSE.txt).

1Platform Content AI is a product of **1Platform Labs**.
