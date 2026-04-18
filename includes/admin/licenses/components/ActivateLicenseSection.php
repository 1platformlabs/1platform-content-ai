<?php

if (!defined('ABSPATH')) exit;

class ContaiActivateLicenseSection
{
    private string $nonceAction;
    private string $nonceField;

    public function __construct(string $nonceAction, string $nonceField)
    {
        $this->nonceAction = $nonceAction;
        $this->nonceField = $nonceField;
    }

    public function render(): void
    {
        ?>
        <div class="contai-settings-panel contai-license-panel">
            <div class="contai-panel-header">
                <div class="contai-panel-title-group">
                    <h2 class="contai-panel-title">
                        <span class="dashicons dashicons-superhero-alt"></span>
                        <?php esc_html_e('Content AI License', '1platform-content-ai'); ?>
                    </h2>
                    <p class="contai-panel-description">
                        <?php esc_html_e('Activate your Content AI license to unlock premium features', '1platform-content-ai'); ?>
                    </p>
                </div>
            </div>

            <div class="contai-panel-body">
                <form method="post" class="contai-license-form">
                    <?php wp_nonce_field($this->nonceAction, $this->nonceField); ?>

                    <div class="contai-info-box contai-info-box-info">
                        <div class="contai-info-box-icon">
                            <span class="dashicons dashicons-info-outline"></span>
                        </div>
                        <div class="contai-info-box-content">
                            <p>
                                <?php esc_html_e('Enter your Content AI API key to activate premium features including Search Console integration, advanced content generation, and more.', '1platform-content-ai'); ?>
                            </p>
                        </div>
                    </div>

                    <div class="contai-form-group contai-license-input-group">
                        <label for="contai-api-key" class="contai-label">
                            <span class="dashicons dashicons-admin-network"></span>
                            <?php esc_html_e('API Key', '1platform-content-ai'); ?>
                        </label>
                        <input type="password"
                               id="contai-api-key"
                               name="contai_api_key"
                               class="contai-input contai-input-large"
                               placeholder="<?php esc_attr_e('Enter your Content AI API key', '1platform-content-ai'); ?>"
                               required>
                    </div>

                    <div class="contai-form-actions">
                        <button type="submit" name="contai_activate_license" class="button button-primary">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <?php esc_html_e('Activate License', '1platform-content-ai'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }
}
