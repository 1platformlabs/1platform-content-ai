<?php

namespace ContAI\Tests\Unit\Admin\Apps;

use PHPUnit\Framework\TestCase;
use WP_Mock;

require_once __DIR__ . '/../../../../includes/admin/panels/ContaiLogsPanel.php';

/**
 * The link-building tool is shown to site owners as "Link Building", never by
 * the marketplace's brand (1platform-api#1043, reverting content-ai#44).
 *
 * Each surface the owner sees is rendered for real and its HTML inspected: the
 * Tools card, the Tools sidebar, the section page title and the Logs provider
 * filter. A scan over every translatable literal and every provider display
 * name keeps a new screen from bringing a vendor name back.
 */
class LinkBuildingLabelTest extends TestCase
{
    private const LABEL = 'Link Building';

    /** Vendor names that must never reach a client-facing string. */
    private const VENDOR_PATTERN = '/\b(publisuites|openai|anthropic|pixabay|pexels|valueserp|migo|tributax|stripe|hetzner|nicho\.ai|(?-i:(?<=\w\s)Resend))\b/i';

    /**
     * Translation functions whose first argument is shown to the owner. The
     * vendor "Resend" only counts capitalised mid-sentence ("Sent with Resend"):
     * "Resend code" and "resend the email" are the verb.
     */
    private const I18N_LITERAL = '/\b(?:__|_e|_x|_n|_nx|esc_html__|esc_html_e|esc_attr__|esc_attr_e|esc_html_x|esc_attr_x)\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1/s';

    /** The plural form of _n()/_nx(), which is shown as well. */
    private const I18N_PLURAL = '/\b_nx?\(\s*([\'"])(?:\\\\.|(?!\1).)*\1\s*,\s*([\'"])((?:\\\\.|(?!\2).)*)\2/s';

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        WP_Mock::userFunction('admin_url')->andReturnUsing(static fn($path = '') => 'https://site.test/wp-admin/' . $path);
        WP_Mock::userFunction('add_query_arg')->andReturnUsing(
            static fn(array $args, string $url) => $args === [] ? $url : $url . '&' . http_build_query($args)
        );
        WP_Mock::userFunction('wp_nonce_field')->andReturn('');
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    private function capture(callable $render): string
    {
        ob_start();
        try {
            $render();
        } finally {
            $html = (string) ob_get_clean();
        }
        return $html;
    }

    private function assertShowsLinkBuildingNotTheVendor(string $html): void
    {
        $this->assertStringContainsString(self::LABEL, $html);
        // A link to the marketplace's own site may carry its domain; no visible text may.
        $visible = preg_replace('/href="[^"]*"/', 'href=""', $html);
        $this->assertDoesNotMatchRegularExpression('/publisuites/i', strip_tags($visible));
        $this->assertDoesNotMatchRegularExpression('/>\s*Publisuites\s*</i', $visible);
    }

    public function test_tools_card_is_titled_link_building(): void
    {
        $html = $this->capture(static fn() => (new \ContaiAppsPanel())->render());

        $this->assertShowsLinkBuildingNotTheVendor($html);
        $this->assertMatchesRegularExpression('/<h3 class="contai-app-card-title">Link Building<\/h3>/', $html);
    }

    public function test_tools_sidebar_entry_is_link_building(): void
    {
        $html = $this->capture(static fn() => (new \ContaiAppsSidebar('publisuites'))->render());

        $this->assertShowsLinkBuildingNotTheVendor($html);
        $this->assertMatchesRegularExpression('/<li class="is-active">.*?<span>Link Building<\/span>/s', $html);
    }

    public function test_section_page_title_is_link_building(): void
    {
        $layout = new \ContaiAppsLayout('publisuites');
        $html   = $this->capture(static fn() => $layout->render_page_title(...\contai_apps_section_header('publisuites')));

        $this->assertShowsLinkBuildingNotTheVendor($html);
        $this->assertMatchesRegularExpression('/<h1 class="contai-page-title">.*?Link Building\s*<\/h1>/s', $html);
    }

    public function test_unknown_section_falls_back_to_the_tools_header(): void
    {
        $this->assertSame(\contai_apps_section_header('apps'), \contai_apps_section_header('nope'));
        $this->assertSame('Tools', \contai_apps_section_header('nope')[0]);
    }

    public function test_every_routable_section_has_its_own_header(): void
    {
        // The routable list, the headers and the panels must not drift apart:
        // a section routed but missing from the header switch would silently
        // show the Tools title.
        $tools = \contai_apps_section_header('apps');
        $sections = \contai_apps_valid_sections();

        $this->assertContains('publisuites', $sections);
        foreach ($sections as $section) {
            $this->assertNotSame($tools, \contai_apps_section_header($section), "section '{$section}' has no header");
        }
    }

    public function test_logs_provider_filter_offers_link_building(): void
    {
        $panel = (new \ReflectionClass(\ContaiLogsPanel::class))->newInstanceWithoutConstructor();
        $filters = new \ReflectionProperty(\ContaiLogsPanel::class, 'filters');
        $filters->setAccessible(true);
        $filters->setValue($panel, array_fill_keys(
            ['website_id', 'provider', 'success', 'error_type', 'source_type', 'trace_id', 'from_date', 'to_date'],
            ''
        ));
        $render = new \ReflectionMethod(\ContaiLogsPanel::class, 'renderFilters');
        $render->setAccessible(true);

        $html = $this->capture(static fn() => $render->invoke($panel));

        $this->assertShowsLinkBuildingNotTheVendor($html);
        // The option value stays the data key the API filters by.
        $this->assertStringContainsString('<option value="publisuites">Link Building</option>', $html);
    }

    public function test_logs_name_the_provider_by_its_neutral_label(): void
    {
        $this->assertSame(self::LABEL, \ContaiLogsAdapter::getProviderDisplayName('publisuites'));
    }

    public function test_an_unmapped_provider_is_not_shown_by_its_raw_name(): void
    {
        $this->assertSame('External Service', \ContaiLogsAdapter::getProviderDisplayName('somevendor'));
        $this->assertSame('—', \ContaiLogsAdapter::getProviderDisplayName(''));
    }

    public function test_the_platform_own_content_service_keeps_its_name(): void
    {
        // The Core logs its own content service as provider "content"
        // (app/api/v1/endpoints/logs.py); it is not an external service.
        $this->assertSame('Content AI', \ContaiLogsAdapter::getProviderDisplayName('content'));
    }

    public function test_no_provider_display_name_is_a_vendor(): void
    {
        // Only the VALUES are shown; the keys are the API's data identifiers.
        foreach (\ContaiLogsAdapter::PROVIDER_DISPLAY_NAMES as $key => $label) {
            $this->assertDoesNotMatchRegularExpression(self::VENDOR_PATTERN, $label, "provider '{$key}' is shown as '{$label}'");
        }
    }

    /** @return string[] translatable literals in $source that name a vendor */
    private static function vendorLiteralsIn(string $source): array
    {
        preg_match_all(self::I18N_LITERAL, $source, $singular);
        preg_match_all(self::I18N_PLURAL, $source, $plural);
        return array_values(array_filter(
            array_merge($singular[2], $plural[3]),
            static fn(string $literal): bool => (bool) preg_match(self::VENDOR_PATTERN, $literal)
        ));
    }

    public function test_the_scan_reads_plurals_and_tells_the_verb_from_the_vendor(): void
    {
        $source = <<<'PHP'
            _n('One order', 'Publisuites orders', $n, 'd');
            _nx('One post', 'Pexels posts', $n, 'ctx', 'd');
            __('Resend code', 'd');
            esc_html__('Didn\'t get it? resend the email', 'd');
            __('Not here? Resend it', 'd');
            __('Sent with Resend', 'd');
            PHP;

        $this->assertSame(['Sent with Resend', 'Publisuites orders', 'Pexels posts'], self::vendorLiteralsIn($source));
    }

    public function test_no_translatable_string_names_a_vendor(): void
    {
        $root  = dirname(__DIR__, 4) . '/includes';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $leaks = [];
        $scanned = 0;
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $scanned++;
            foreach (self::vendorLiteralsIn((string) file_get_contents($file->getPathname())) as $literal) {
                $leaks[] = substr($file->getPathname(), strlen($root) + 1) . ": {$literal}";
            }
        }

        $this->assertGreaterThan(50, $scanned, 'the scan must actually read the plugin sources');
        $this->assertSame([], $leaks);
    }
}
