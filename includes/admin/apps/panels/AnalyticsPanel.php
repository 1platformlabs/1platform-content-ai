<?php

if (!defined('ABSPATH')) exit;

class ContaiAnalyticsPanel
{
    public function render(): void
    {
        $measurement_id = get_option('1platform_ga4_measurement_id', '');
        $is_connected = !empty($measurement_id) && preg_match('/^G-[A-Z0-9]{8,12}$/', $measurement_id);

        $this->render_styles();
        ?>
        <div class="contai-settings-panel contai-panel-analytics">
            <div class="contai-panel-body">
                <?php if ($is_connected): ?>
                    <?php $this->render_connected_state($measurement_id); ?>
                <?php else: ?>
                    <?php $this->render_setup_state(); ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function render_styles(): void
    {
        ?>
        <style>
            .contai-analytics-empty {
                text-align: center;
                padding: var(--contai-spacing-xl) var(--contai-spacing-lg);
                max-width: 560px;
                margin: 0 auto;
            }
            .contai-analytics-empty__icon {
                width: 64px; height: 64px;
                border-radius: var(--contai-radius-lg);
                background: var(--contai-primary-glow);
                display: inline-flex; align-items: center; justify-content: center;
                margin-bottom: var(--contai-spacing-md);
            }
            .contai-analytics-empty__icon .dashicons {
                font-size: 32px; width: 32px; height: 32px; color: var(--contai-primary);
            }
            .contai-analytics-empty h3 {
                font-size: 20px; font-weight: 600; color: var(--contai-neutral-700);
                margin: 0 0 var(--contai-spacing-xs);
            }
            .contai-analytics-empty p {
                color: var(--contai-neutral-500); font-size: 14px; line-height: 1.6;
                margin: 0 0 var(--contai-spacing-lg);
            }

            /* Steps */
            .contai-analytics-steps {
                display: flex; flex-direction: column; gap: var(--contai-spacing-md);
                max-width: 460px; margin: 0 auto var(--contai-spacing-lg);
                text-align: left;
            }
            .contai-analytics-step {
                display: flex; align-items: flex-start; gap: var(--contai-spacing-sm);
                padding: var(--contai-spacing-sm) var(--contai-spacing-md);
                border-radius: var(--contai-radius-md);
                background: var(--contai-neutral-50);
                border: 1px solid var(--contai-neutral-200);
                transition: border-color 0.2s, background 0.2s;
            }
            .contai-analytics-step.active {
                border-color: var(--contai-primary-border);
                background: var(--contai-primary-glow);
            }
            .contai-analytics-step.done {
                border-color: var(--contai-success-border);
                background: var(--contai-success-bg);
            }
            .contai-analytics-step__num {
                width: 28px; height: 28px; border-radius: 50%;
                background: var(--contai-neutral-200); color: var(--contai-neutral-600);
                display: flex; align-items: center; justify-content: center;
                font-size: 13px; font-weight: 700; flex-shrink: 0;
            }
            .contai-analytics-step.active .contai-analytics-step__num {
                background: var(--contai-primary); color: #fff;
            }
            .contai-analytics-step.done .contai-analytics-step__num {
                background: var(--contai-success); color: #fff;
            }
            .contai-analytics-step h4 {
                font-size: 14px; font-weight: 600; color: var(--contai-neutral-700);
                margin: 0 0 2px;
            }
            .contai-analytics-step p {
                font-size: 12px; color: var(--contai-neutral-500); margin: 0; line-height: 1.4;
            }

            /* Buttons */
            .contai-btn-oauth {
                display: inline-flex; align-items: center; gap: 8px;
                padding: 12px 24px;
                background: var(--contai-primary); color: #fff; border: none;
                border-radius: var(--contai-radius-md);
                font-size: 14px; font-weight: 600; cursor: pointer;
                transition: background 0.2s, box-shadow 0.2s;
            }
            .contai-btn-oauth:hover { background: var(--contai-primary-dark); box-shadow: var(--contai-shadow-md); }
            .contai-btn-oauth:disabled { opacity: 0.6; cursor: not-allowed; }
            .contai-btn-oauth .dashicons { font-size: 18px; width: 18px; height: 18px; }

            .contai-btn-manual {
                display: block; margin-top: var(--contai-spacing-sm);
                background: none; border: none; color: var(--contai-neutral-400);
                font-size: 12px; cursor: pointer; text-decoration: underline;
            }
            .contai-btn-manual:hover { color: var(--contai-neutral-600); }

            /* Manual input (hidden by default) */
            .contai-analytics-manual { display: none; margin-top: var(--contai-spacing-md); }
            .contai-analytics-manual.visible { display: block; }
            .contai-analytics-manual label {
                display: block; text-align: left; font-weight: 600;
                font-size: 13px; color: var(--contai-neutral-600); margin-bottom: 6px;
            }
            .contai-analytics-manual input[type="text"] {
                width: 100%; padding: 10px 14px;
                border: 1px solid var(--contai-neutral-300);
                border-radius: var(--contai-radius-md);
                font-size: 14px; font-family: monospace;
                margin-bottom: var(--contai-spacing-xs);
            }
            .contai-analytics-manual input[type="text"]:focus {
                border-color: var(--contai-primary);
                box-shadow: 0 0 0 3px var(--contai-primary-glow);
                outline: none;
            }
            .contai-analytics-manual .contai-btn-save {
                width: 100%; padding: 10px;
                background: var(--contai-primary); color: #fff; border: none;
                border-radius: var(--contai-radius-md);
                font-size: 14px; font-weight: 600; cursor: pointer;
            }

            /* Connected state */
            .contai-analytics-connected { display: flex; flex-direction: column; gap: var(--contai-spacing-lg); }
            .contai-analytics-header {
                display: flex; align-items: center; justify-content: space-between;
                padding-bottom: var(--contai-spacing-md);
                border-bottom: 1px solid var(--contai-neutral-200);
            }
            .contai-analytics-header__left { display: flex; align-items: center; gap: var(--contai-spacing-sm); }
            .contai-analytics-badge {
                display: inline-flex; align-items: center; gap: 6px;
                padding: 4px 12px; border-radius: var(--contai-radius-pill);
                font-size: 13px; font-weight: 600;
                background: var(--contai-success-bg); color: var(--contai-success-text);
                border: 1px solid var(--contai-success-border);
            }
            .contai-analytics-badge .dashicons { font-size: 14px; width: 14px; height: 14px; color: var(--contai-success); }
            .contai-analytics-mid {
                font-family: monospace; font-size: 14px; color: var(--contai-neutral-600);
                background: var(--contai-neutral-100); padding: 4px 10px;
                border-radius: var(--contai-radius-sm);
            }
            .contai-analytics-features {
                display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
                gap: var(--contai-spacing-md);
            }
            .contai-analytics-feature {
                background: var(--contai-neutral-50); border: 1px solid var(--contai-neutral-200);
                border-radius: var(--contai-radius-md); padding: var(--contai-spacing-md);
                display: flex; align-items: flex-start; gap: var(--contai-spacing-sm);
            }
            .contai-analytics-feature__icon {
                width: 36px; height: 36px; border-radius: var(--contai-radius-sm);
                display: flex; align-items: center; justify-content: center; flex-shrink: 0;
            }
            .contai-analytics-feature__icon--blue { background: var(--contai-info-bg); color: var(--contai-primary); }
            .contai-analytics-feature__icon--green { background: var(--contai-success-bg); color: var(--contai-success); }
            .contai-analytics-feature__icon--amber { background: var(--contai-warning-bg); color: var(--contai-warning); }
            .contai-analytics-feature__icon--purple { background: #f3e8ff; color: #7c3aed; }
            .contai-analytics-feature__icon .dashicons { font-size: 18px; width: 18px; height: 18px; }
            .contai-analytics-feature h4 { font-size: 13px; font-weight: 600; color: var(--contai-neutral-700); margin: 0 0 2px; }
            .contai-analytics-feature p { font-size: 12px; color: var(--contai-neutral-500); margin: 0; line-height: 1.4; }
            .contai-analytics-disconnect { padding-top: var(--contai-spacing-md); border-top: 1px solid var(--contai-neutral-200); }
            .contai-btn-disconnect {
                padding: 6px 16px; background: #fff; color: var(--contai-error);
                border: 1px solid var(--contai-error-border); border-radius: var(--contai-radius-md);
                font-size: 13px; font-weight: 500; cursor: pointer;
            }
            .contai-btn-disconnect:hover { background: var(--contai-error-bg); }
            @media (max-width: 768px) {
                .contai-analytics-features { grid-template-columns: 1fr; }
                .contai-analytics-header { flex-direction: column; align-items: flex-start; gap: var(--contai-spacing-xs); }
            }
        </style>
        <?php
    }

    private function render_connected_state(string $measurement_id): void
    {
        ?>
        <div class="contai-analytics-connected">
            <div class="contai-analytics-header">
                <div class="contai-analytics-header__left">
                    <span class="contai-analytics-badge">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php esc_html_e('Connected', '1platform-content-ai'); ?>
                    </span>
                    <code class="contai-analytics-mid"><?php echo esc_html($measurement_id); ?></code>
                </div>
            </div>
            <div class="contai-analytics-features">
                <div class="contai-analytics-feature">
                    <div class="contai-analytics-feature__icon contai-analytics-feature__icon--blue"><span class="dashicons dashicons-chart-bar"></span></div>
                    <div><h4><?php esc_html_e('GA4 Tag', '1platform-content-ai'); ?></h4><p><?php esc_html_e('Injected in wp_head with GDPR Consent Mode v2', '1platform-content-ai'); ?></p></div>
                </div>
                <div class="contai-analytics-feature">
                    <div class="contai-analytics-feature__icon contai-analytics-feature__icon--purple"><span class="dashicons dashicons-analytics"></span></div>
                    <div><h4><?php esc_html_e('Custom Dimensions', '1platform-content-ai'); ?></h4><p><?php esc_html_e('content_source, target_keyword, content_cluster, content_type', '1platform-content-ai'); ?></p></div>
                </div>
                <div class="contai-analytics-feature">
                    <div class="contai-analytics-feature__icon contai-analytics-feature__icon--green"><span class="dashicons dashicons-cloud-upload"></span></div>
                    <div><h4><?php esc_html_e('Server Events', '1platform-content-ai'); ?></h4><p><?php esc_html_e('content_published, content_updated, comment_received, seo_action', '1platform-content-ai'); ?></p></div>
                </div>
                <div class="contai-analytics-feature">
                    <div class="contai-analytics-feature__icon contai-analytics-feature__icon--amber"><span class="dashicons dashicons-shield"></span></div>
                    <div><h4><?php esc_html_e('Privacy', '1platform-content-ai'); ?></h4><p><?php esc_html_e('Consent Mode v2 — analytics denied by default until user consent', '1platform-content-ai'); ?></p></div>
                </div>
            </div>
            <div class="contai-analytics-disconnect">
                <button type="button" class="contai-btn-disconnect" id="contai-analytics-disconnect">
                    <?php esc_html_e('Disconnect Google Analytics', '1platform-content-ai'); ?>
                </button>
            </div>
        </div>
        <script>
        document.getElementById('contai-analytics-disconnect')?.addEventListener('click', function() {
            if (!confirm('<?php echo esc_js(__('Are you sure? The GA4 tag will be removed from your site.', '1platform-content-ai')); ?>')) return;
            this.disabled = true; this.textContent = '<?php echo esc_js(__('Disconnecting...', '1platform-content-ai')); ?>';
            var d = new FormData(); d.append('action', 'contai_analytics_disconnect'); d.append('_wpnonce', '<?php echo esc_js(wp_create_nonce('contai_analytics_disconnect')); ?>');
            fetch(ajaxurl, {method:'POST', body:d}).then(function(r){return r.json()}).then(function(res){location.reload()}).catch(function(){location.reload()});
        });
        </script>
        <?php
    }

    private function render_setup_state(): void
    {
        $websiteProvider = new ContaiWebsiteProvider();
        $website_id = $websiteProvider->getWebsiteId() ?? '';
        ?>
        <div class="contai-analytics-empty">
            <div class="contai-analytics-empty__icon">
                <span class="dashicons dashicons-chart-area"></span>
            </div>
            <h3><?php esc_html_e('Connect Google Analytics', '1platform-content-ai'); ?></h3>
            <p><?php esc_html_e('Track your AI content performance, compare AI vs manual content ROI, and monitor real-time site traffic — all from your WordPress dashboard.', '1platform-content-ai'); ?></p>

            <!-- Steps -->
            <div class="contai-analytics-steps">
                <div class="contai-analytics-step active" id="contai-step-1">
                    <span class="contai-analytics-step__num">1</span>
                    <div>
                        <h4><?php esc_html_e('Authorize with Google', '1platform-content-ai'); ?></h4>
                        <p><?php esc_html_e('Sign in with your Google account that has access to Google Analytics', '1platform-content-ai'); ?></p>
                    </div>
                </div>
                <div class="contai-analytics-step" id="contai-step-2">
                    <span class="contai-analytics-step__num">2</span>
                    <div>
                        <h4><?php esc_html_e('Select GA4 Property', '1platform-content-ai'); ?></h4>
                        <p><?php esc_html_e('Choose which GA4 property to connect to this site', '1platform-content-ai'); ?></p>
                    </div>
                </div>
                <div class="contai-analytics-step" id="contai-step-3">
                    <span class="contai-analytics-step__num">3</span>
                    <div>
                        <h4><?php esc_html_e('Auto-Configure', '1platform-content-ai'); ?></h4>
                        <p><?php esc_html_e('Tag injection, custom dimensions, and server events set up automatically', '1platform-content-ai'); ?></p>
                    </div>
                </div>
            </div>

            <!-- OAuth button -->
            <button type="button" class="contai-btn-oauth" id="contai-oauth-connect">
                <span class="dashicons dashicons-google"></span>
                <?php esc_html_e('Connect with Google', '1platform-content-ai'); ?>
            </button>

            <!-- Manual fallback link -->
            <button type="button" class="contai-btn-manual" id="contai-show-manual">
                <?php esc_html_e('Or enter Measurement ID manually', '1platform-content-ai'); ?>
            </button>

            <!-- Manual input (hidden) -->
            <div class="contai-analytics-manual" id="contai-manual-form">
                <label for="contai-ga4-measurement-id"><?php esc_html_e('GA4 Measurement ID', '1platform-content-ai'); ?></label>
                <input type="text" id="contai-ga4-measurement-id" placeholder="G-XXXXXXXXXX" autocomplete="off" spellcheck="false" />
                <button type="button" class="contai-btn-save" id="contai-manual-connect">
                    <?php esc_html_e('Save Measurement ID', '1platform-content-ai'); ?>
                </button>
            </div>
        </div>

        <script>
        (function() {
            var websiteId = '<?php echo esc_js($website_id); ?>';
            var oauthBtn = document.getElementById('contai-oauth-connect');
            var manualBtn = document.getElementById('contai-show-manual');
            var manualForm = document.getElementById('contai-manual-form');
            var manualInput = document.getElementById('contai-ga4-measurement-id');
            var manualConnect = document.getElementById('contai-manual-connect');

            /* Toggle manual form */
            manualBtn?.addEventListener('click', function() {
                manualForm.classList.toggle('visible');
                manualInput?.focus();
            });

            /* Auto-uppercase */
            manualInput?.addEventListener('input', function() { this.value = this.value.toUpperCase(); });
            manualInput?.addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); manualConnect?.click(); } });

            /* Manual connect */
            manualConnect?.addEventListener('click', function() {
                var mid = manualInput.value.trim();
                if (!/^G-[A-Z0-9]{8,12}$/.test(mid)) {
                    manualInput.style.borderColor = 'var(--contai-error)';
                    manualInput.focus();
                    return;
                }
                this.disabled = true; this.textContent = '<?php echo esc_js(__('Saving...', '1platform-content-ai')); ?>';
                var d = new FormData(); d.append('action', 'contai_analytics_connect'); d.append('measurement_id', mid); d.append('_wpnonce', '<?php echo esc_js(wp_create_nonce('contai_analytics_connect')); ?>');
                fetch(ajaxurl, {method:'POST', body:d}).then(function(r){return r.json()}).then(function(res){if(res.success)location.reload();else{manualConnect.disabled=false;manualConnect.textContent='<?php echo esc_js(__('Save Measurement ID', '1platform-content-ai')); ?>';}});
            });

            /* OAuth connect */
            oauthBtn?.addEventListener('click', function() {
                if (!websiteId) {
                    alert('<?php echo esc_js(__('Website not configured. Go to Settings first.', '1platform-content-ai')); ?>');
                    return;
                }
                oauthBtn.disabled = true;
                oauthBtn.innerHTML = '<span class="dashicons dashicons-update spin"></span> <?php echo esc_js(__('Opening Google...', '1platform-content-ai')); ?>';

                /* Call API to get authorize URL */
                var d = new FormData();
                d.append('action', 'contai_analytics_get_oauth_url');
                d.append('website_id', websiteId);
                d.append('_wpnonce', '<?php echo esc_js(wp_create_nonce('contai_analytics_oauth')); ?>');

                fetch(ajaxurl, {method:'POST', body:d})
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (!res.success || !res.data?.authorize_url) {
                            alert(res.data?.message || '<?php echo esc_js(__('Failed to get authorization URL', '1platform-content-ai')); ?>');
                            oauthBtn.disabled = false;
                            oauthBtn.innerHTML = '<span class="dashicons dashicons-google"></span> <?php echo esc_js(__('Connect with Google', '1platform-content-ai')); ?>';
                            return;
                        }

                        /* Open popup */
                        var popup = window.open(res.data.authorize_url, '1platform_oauth', 'width=600,height=700,scrollbars=yes');

                        /* Listen for postMessage from callback page */
                        window.addEventListener('message', function handler(event) {
                            if (!event.data || event.data.type !== '1platform_oauth_complete') return;
                            if (event.data.service !== 'analytics') return;
                            window.removeEventListener('message', handler);

                            if (event.data.success) {
                                /* Step 1 done */
                                document.getElementById('contai-step-1')?.classList.replace('active', 'done');
                                document.getElementById('contai-step-2')?.classList.add('active');
                                oauthBtn.innerHTML = '<span class="dashicons dashicons-yes-alt"></span> <?php echo esc_js(__('Authorized! Configuring...', '1platform-content-ai')); ?>';

                                startSetupAndPoll();
                            } else {
                                alert(event.data.error || '<?php echo esc_js(__('Authorization failed', '1platform-content-ai')); ?>');
                                oauthBtn.disabled = false;
                                oauthBtn.innerHTML = '<span class="dashicons dashicons-google"></span> <?php echo esc_js(__('Connect with Google', '1platform-content-ai')); ?>';
                            }
                        });

                        /* Fallback: poll if popup closes without postMessage (COOP blocks window.closed) */
                        var popupFallback = setTimeout(function() {
                            /* Check OAuth status after a reasonable delay */
                            var sd = new FormData();
                            sd.append('action', 'contai_analytics_check_oauth');
                            sd.append('website_id', websiteId);
                            sd.append('_wpnonce', '<?php echo esc_js(wp_create_nonce('contai_analytics_oauth')); ?>');
                            fetch(ajaxurl, {method:'POST', body:sd}).then(function(r){return r.json()}).then(function(res){
                                if (res.success && res.data?.connected) {
                                    startSetupAndPoll();
                                }
                            });
                        }, 15000);
                    })
                    .catch(function() {
                        oauthBtn.disabled = false;
                        oauthBtn.innerHTML = '<span class="dashicons dashicons-google"></span> <?php echo esc_js(__('Connect with Google', '1platform-content-ai')); ?>';
                    });
            });

            function startSetupAndPoll() {
                /* Call setup — it returns 202 with status:provisioning */
                var sd = new FormData();
                sd.append('action', 'contai_analytics_setup');
                sd.append('website_id', websiteId);
                sd.append('_wpnonce', '<?php echo esc_js(wp_create_nonce('contai_analytics_setup')); ?>');

                document.getElementById('contai-step-2')?.classList.replace('active', 'done');
                document.getElementById('contai-step-3')?.classList.add('active');
                oauthBtn.innerHTML = '<span class="dashicons dashicons-update spin"></span> <?php echo esc_js(__('Provisioning GA4...', '1platform-content-ai')); ?>';

                fetch(ajaxurl, {method:'POST', body:sd})
                    .then(function(r) { return r.json(); })
                    .then(function(setupRes) {
                        if (setupRes.success && setupRes.data?.measurement_id) {
                            saveMidAndReload(setupRes.data.measurement_id);
                        } else {
                            /* Poll regardless — setup returns 202 provisioning or may fail, poll will catch active */
                            pollStatus(0);
                        }
                    })
                    .catch(function() { pollStatus(0); });
            }

            function saveMidAndReload(mid) {
                var cd = new FormData();
                cd.append('action', 'contai_analytics_connect');
                cd.append('measurement_id', mid);
                cd.append('_wpnonce', '<?php echo esc_js(wp_create_nonce('contai_analytics_connect')); ?>');
                fetch(ajaxurl, {method:'POST', body:cd}).then(function() { location.reload(); });
            }

            function showManualAfterOAuth() {
                document.getElementById('contai-step-1')?.classList.replace('active', 'done');
                document.getElementById('contai-step-1')?.classList.add('done');
                document.getElementById('contai-step-2')?.classList.add('active');

                oauthBtn.style.display = 'none';
                manualForm.classList.add('visible');
                manualForm.insertAdjacentHTML('beforebegin',
                    '<div style="background:var(--contai-warning-bg);border:1px solid var(--contai-warning-border);border-radius:var(--contai-radius-md);padding:12px 16px;margin-bottom:12px;font-size:13px;color:var(--contai-neutral-700);text-align:left">' +
                    '<strong><?php echo esc_js(__('Google account connected!', '1platform-content-ai')); ?></strong> ' +
                    '<?php echo esc_js(__('Enter your GA4 Measurement ID below to complete the setup.', '1platform-content-ai')); ?>' +
                    '<br><span style="color:var(--contai-neutral-500);font-size:12px"><?php echo esc_js(__('Find it in Google Analytics → Admin → Data Streams → your stream → Measurement ID', '1platform-content-ai')); ?></span>' +
                    '</div>'
                );
                manualInput?.focus();
            }

            function pollStatus(attempt) {
                if (attempt > 30) { showManualAfterOAuth(); return; }
                setTimeout(function() {
                    var pd = new FormData();
                    pd.append('action', 'contai_analytics_poll_status');
                    pd.append('website_id', websiteId);
                    pd.append('_wpnonce', '<?php echo esc_js(wp_create_nonce('contai_analytics_setup')); ?>');
                    fetch(ajaxurl, {method:'POST', body:pd}).then(function(r){return r.json()}).then(function(res){
                        if (res.success && res.data?.measurement_id && res.data?.status === 'active') {
                            saveMidAndReload(res.data.measurement_id);
                        } else if (res.success && res.data?.status === 'error') {
                            showManualAfterOAuth();
                        } else {
                            pollStatus(attempt + 1);
                        }
                    }).catch(function() { pollStatus(attempt + 1); });
                }, 3000);
            }
        })();
        </script>
        <?php
    }
}
