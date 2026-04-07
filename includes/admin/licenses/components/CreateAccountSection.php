<?php
/**
 * Create Account Section — self-service registration via payment.
 *
 * Renders the registration form with email + amount selector.
 * JavaScript handles polling and auto-activation.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ContaiCreateAccountSection {

    /**
     * Render the create account UI.
     *
     * @param string|null $pending_session_id Session ID from transient (recovery).
     */
    public static function render( ?string $pending_session_id = null ): void {
        ?>
        <div class="contai-create-account" id="contai-create-account">
            <div class="contai-create-account-header">
                <span class="contai-create-account-badge">
                    <span class="dashicons dashicons-plus-alt2"></span>
                    <?php esc_html_e( 'New', '1platform-content-ai' ); ?>
                </span>
                <h3 class="contai-create-account-title">
                    <?php esc_html_e( 'Create Your Account', '1platform-content-ai' ); ?>
                </h3>
            </div>

            <?php if ( $pending_session_id ) : ?>
            <div class="contai-onboarding-recovery" id="contai-onboarding-recovery"
                 data-session-id="<?php echo esc_attr( $pending_session_id ); ?>">
                <span class="dashicons dashicons-update spin"></span>
                <p><?php esc_html_e( 'You have a pending registration. Checking status...', '1platform-content-ai' ); ?></p>
            </div>
            <?php endif; ?>

            <div class="contai-create-account-body" id="contai-create-account-form"
                 <?php echo $pending_session_id ? 'style="display:none;"' : ''; ?>>
                <p>
                    <?php esc_html_e( 'Create your account with an initial balance. Your API key will be generated automatically after payment.', '1platform-content-ai' ); ?>
                </p>

                <div class="contai-onboarding-field">
                    <label for="contai-onboarding-email">
                        <?php esc_html_e( 'Email Address', '1platform-content-ai' ); ?>
                    </label>
                    <input type="email"
                           id="contai-onboarding-email"
                           class="regular-text"
                           placeholder="<?php esc_attr_e( 'your@email.com', '1platform-content-ai' ); ?>"
                           required />
                </div>

                <div class="contai-onboarding-field">
                    <label>
                        <?php esc_html_e( 'Initial Balance (USD)', '1platform-content-ai' ); ?>
                    </label>
                    <div class="contai-onboarding-amounts">
                        <button type="button" class="contai-amount-btn" data-amount="5">$5</button>
                        <button type="button" class="contai-amount-btn active" data-amount="10">$10</button>
                        <button type="button" class="contai-amount-btn" data-amount="25">$25</button>
                        <input type="number"
                               id="contai-onboarding-custom-amount"
                               class="contai-amount-custom"
                               min="5"
                               max="10000"
                               step="0.01"
                               aria-label="<?php esc_attr_e( 'Custom amount', '1platform-content-ai' ); ?>"
                               placeholder="<?php esc_attr_e( 'Custom', '1platform-content-ai' ); ?>" />
                    </div>
                </div>

                <button type="button" id="contai-onboarding-submit" class="button button-primary button-hero">
                    <?php esc_html_e( 'Create Account & Pay', '1platform-content-ai' ); ?>
                </button>

                <div class="contai-onboarding-error" id="contai-onboarding-error" style="display:none;"></div>
            </div>

            <div class="contai-onboarding-status" id="contai-onboarding-status" style="display:none;">
                <div class="contai-onboarding-spinner">
                    <span class="dashicons dashicons-update spin"></span>
                </div>
                <p id="contai-onboarding-status-text">
                    <?php esc_html_e( 'Processing your payment...', '1platform-content-ai' ); ?>
                </p>
            </div>

            <div class="contai-onboarding-success" id="contai-onboarding-success" style="display:none;">
                <span class="dashicons dashicons-yes-alt"></span>
                <p><?php esc_html_e( 'Account created! Activating your license...', '1platform-content-ai' ); ?></p>
            </div>
        </div>

        <div class="contai-create-account-toggle">
            <a href="#" id="contai-toggle-existing-key">
                <?php esc_html_e( 'Already have an API key? Click here', '1platform-content-ai' ); ?>
            </a>
        </div>
        <?php
    }
}
