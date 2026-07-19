<?php

namespace ContAI\Tests\Unit\Admin\ContentGenerator\Helpers;

use ContaiLegalPagesHelper;
use Mockery;
use PHPUnit\Framework\TestCase;
use WP_Mock;

require_once __DIR__ . '/../../../../../includes/admin/content-generator/helpers/legal-pages-helper.php';
require_once __DIR__ . '/../../../../../includes/admin/content-generator/helpers/legacy-functions.php';

/**
 * The wizard step named "Cookie banner configured" used to turn the banner OFF (#48).
 *
 * contai_generate_cookies_banner() called save_cookie_settings(array()), and
 * that method reads its input as a submitted checkbox form: an absent field
 * means unchecked. So it wrote contai_cookie_notice_enabled = '0'.
 *
 * ContaiCookieNoticeHelper::render_cookie_notice() defaults an ABSENT option to
 * '1' and returns early on anything else, so on a fresh site the wizard was the
 * only thing that could disable the banner — and it reported success either way.
 * It also reset contai_consent_mode to 'opt_out' on every run, reverting an
 * admin who had chosen 'opt_in'.
 */
class CookieBannerDefaultsTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        $this->options = [];

        WP_Mock::userFunction('get_option', [
            'return' => function ($name, $default = false) {
                return $this->options[$name] ?? $default;
            },
        ]);
        WP_Mock::userFunction('update_option', [
            'return' => function ($name, $value) {
                $this->options[$name] = $value;
                return true;
            },
        ]);
        WP_Mock::userFunction('wp_kses_post', ['return' => function ($v) { return $v; }]);
        WP_Mock::userFunction('__', ['return' => function ($v) { return $v; }]);
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_the_wizard_turns_the_banner_on(): void
    {
        contai_generate_cookies_banner();

        $this->assertSame(
            '1',
            $this->options['contai_cookie_notice_enabled'] ?? null,
            'Generating the cookie banner must enable it, not disable it (#48)'
        );
    }

    public function test_the_wizard_writes_the_localized_banner_text(): void
    {
        contai_generate_cookies_banner();

        $this->assertNotEmpty($this->options['contai_cookie_notice_text'] ?? '');
    }

    public function test_an_admins_opt_in_choice_survives_a_wizard_re_run(): void
    {
        $this->options['contai_consent_mode'] = 'opt_in';

        contai_generate_cookies_banner();

        $this->assertSame(
            'opt_in',
            $this->options['contai_consent_mode'],
            'Consent mode is a policy decision; a re-run must not revert it (#48)'
        );
    }

    public function test_consent_mode_defaults_when_never_set(): void
    {
        contai_generate_cookies_banner();

        $this->assertSame('opt_out', $this->options['contai_consent_mode'] ?? null);
    }

    /**
     * The admin FORM path keeps checkbox semantics — absent really does mean
     * unchecked there, and an admin must still be able to switch the banner off.
     */
    public function test_the_admin_form_can_still_disable_the_banner(): void
    {
        ContaiLegalPagesHelper::save_cookie_settings([]);

        $this->assertSame('0', $this->options['contai_cookie_notice_enabled'] ?? null);
    }

    public function test_the_admin_form_can_enable_and_set_opt_in(): void
    {
        ContaiLegalPagesHelper::save_cookie_settings([
            'contai_cookie_notice_enabled' => 'on',
            'contai_consent_mode' => 'opt_in',
        ]);

        $this->assertSame('1', $this->options['contai_cookie_notice_enabled'] ?? null);
        $this->assertSame('opt_in', $this->options['contai_consent_mode'] ?? null);
    }
}
