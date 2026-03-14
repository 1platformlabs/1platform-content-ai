<?php

if (!defined('ABSPATH')) exit;

class ContaiBetaVipNotice
{
    private const CONTACT_EMAIL = 'doceer.ar@gmail.com';

    public static function render(): void
    {
        $mailto = 'mailto:' . self::CONTACT_EMAIL;
        ?>
        <div class="contai-beta-vip">
            <div class="contai-beta-vip-header">
                <span class="contai-beta-vip-badge">
                    <span class="dashicons dashicons-star-filled"></span>
                    Beta VIP
                </span>
                <h3 class="contai-beta-vip-title">
                    <?php esc_html_e('VIP Beta Access', '1platform-content-ai'); ?>
                </h3>
            </div>

            <div class="contai-beta-vip-body">
                <p>
                    <?php esc_html_e('To get your API key during this phase, send an email to:', '1platform-content-ai'); ?>
                </p>

                <a href="<?php echo esc_url($mailto); ?>" class="contai-beta-vip-email">
                    <span class="dashicons dashicons-email"></span>
                    <?php echo esc_html(self::CONTACT_EMAIL); ?>
                </a>

                <p>
                    <?php esc_html_e('We will reply with your API key.', '1platform-content-ai'); ?>
                </p>
                <p>
                    <?php esc_html_e('This is a VIP beta phase and access is granted exclusively via email.', '1platform-content-ai'); ?>
                </p>

                <p class="contai-beta-vip-hint">
                    <span class="dashicons dashicons-info-outline"></span>
                    <?php esc_html_e('This process is done only once. After that, you will have your API key to use in the field below.', '1platform-content-ai'); ?>
                </p>
            </div>
        </div>
        <?php
    }
}
