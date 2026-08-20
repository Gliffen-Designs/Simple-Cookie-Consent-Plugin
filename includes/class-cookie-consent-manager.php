<?php
/**
 * Cookie Consent Manager
 * Handles consent display, storage, and cookie interception
 */

if (!defined('ABSPATH')) {
    exit;
}

class Gliffen_Cookie_Consent_Manager {
    private static $instance = null;
    private $settings;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->settings = get_option('gliffen_cookie_consent_settings', array());
        
        // Merge in default pre-configured cookies if not present
        if (!isset($this->settings['preconfigured_cookies'])) {
            $this->settings['preconfigured_cookies'] = array(
                'necessary' => array(
                    'PHPSESSID',
                    'wordpress_logged_in',
                    'NID',
                    'rc::a',
                    'rc::c'
                ),
                'analytics' => array(
                    '_ga',
                    '_gat',
                    '_gid',
                    '_gac_',
                    '__utma',
                    '__utmb',
                    '__utmc',
                    '__utmz'
                ),
                'marketing' => array(
                    '_fbp',
                    'fr',
                    'IDE',
                    'ANID',
                    '_gcl_au',
                    'MUID',
                    '_ttid'
                )
            );
        }
        
        if (!isset($this->settings['custom_cookies'])) {
            $this->settings['custom_cookies'] = array(
                'necessary' => array(),
                'analytics' => array(),
                'marketing' => array()
            );
        }
        
        // Only load if enabled
        if (empty($this->settings['enabled'])) {
            return;
        }
        
        // Frontend hooks
        add_action('wp_head', array($this, 'output_cookie_interceptor'), 1);
        add_action('wp_footer', array($this, 'output_consent_banner'), 999);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // AJAX handlers
        add_action('wp_ajax_gliffen_save_consent', array($this, 'handle_save_consent'));
        add_action('wp_ajax_nopriv_gliffen_save_consent', array($this, 'handle_save_consent'));
    }

    /**
     * Enqueue frontend scripts and styles
     */
    public function enqueue_scripts() {
        // Consent banner styles
        wp_enqueue_style(
            'gliffen-consent-banner',
            GLIFFEN_COOKIE_CONSENT_URL . 'public/assets/css/consent-banner.css',
            array(),
            gliffen_cookie_consent_get_version()
        );

        // Consent banner JS
        wp_enqueue_script(
            'gliffen-consent-banner',
            GLIFFEN_COOKIE_CONSENT_URL . 'public/assets/js/consent-banner.js',
            array(),
            gliffen_cookie_consent_get_version(),
            true
        );

        // Localize script with settings
        wp_localize_script('gliffen-consent-banner', 'glifCookieSettings', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'bannerPosition' => $this->settings['banner_position'] ?? 'bottom',
            'bannerBgColor' => $this->settings['banner_bg_color'] ?? '#222222',
            'bannerTextColor' => $this->settings['banner_text_color'] ?? '#ffffff',
            'buttonBgColor' => $this->settings['button_bg_color'] ?? '#007cba',
            'buttonTextColor' => $this->settings['button_text_color'] ?? '#ffffff',
            'categories' => $this->settings['categories'] ?? array(),
            'privacyUrl' => $this->settings['privacy_policy_url'] ?? '',
            'cookieUrl' => $this->settings['cookie_policy_url'] ?? '',
            'consentExpiryDays' => $this->settings['consent_expiry_days'] ?? 30,
            'analyticsId' => $this->get_analytics_id_from_page(),
        ));
    }

    /**
     * Output cookie interceptor in head (must be first)
     * This blocks document.cookie writes before any third-party scripts run
     */
    public function output_cookie_interceptor() {
        ?>
        <script>
        (function() {
            // Retrieve user consent from localStorage
            var userConsent = {};
            var consentData = localStorage.getItem('gliffen_consent');
            if (consentData) {
                try {
                    userConsent = JSON.parse(consentData);
                } catch (e) {
                    userConsent = {};
                }
            }

            // No explicit decision yet: opt-in by default (US model), unless Global Privacy Control
            // requests opt-out - keeps this in sync with the default consent-banner.js applies
            if (!consentData) {
                var hasGPC = (typeof navigator !== 'undefined' && navigator.globalPrivacyControl === true);
                userConsent = hasGPC
                    ? { necessary: true, analytics: false, marketing: false }
                    : { necessary: true, analytics: true, marketing: true };
            }

            // Cookie to category mapping
            var cookieRegistry = <?php echo json_encode($this->get_cookie_registry()); ?>;

            // Get the category for a given cookie name
            function getCookieCategory(cookieName) {
                // Exact match
                if (cookieRegistry[cookieName]) {
                    return cookieRegistry[cookieName];
                }
                // Partial match (e.g., "_ga" matches "_gat")
                for (var cookie in cookieRegistry) {
                    if (cookieName.indexOf(cookie) === 0) {
                        return cookieRegistry[cookie];
                    }
                }
                return 'necessary'; // Default to necessary if not found
            }

            // Make consent data globally available for third-party triggers
            window.glifCookieConsent = userConsent;

            // Define dataLayer/gtag before GTM's container script loads so Consent Mode
            // signals reach GTM even when GA is only installed via a GTM tag (no direct gtag.js)
            window.dataLayer = window.dataLayer || [];
            function gtag() { dataLayer.push(arguments); }
            window.gtag = window.gtag || gtag;

            gtag('consent', 'default', {
                'ad_storage': userConsent.marketing === true ? 'granted' : 'denied',
                'ad_user_data': userConsent.marketing === true ? 'granted' : 'denied',
                'ad_personalization': userConsent.marketing === true ? 'granted' : 'denied',
                'analytics_storage': userConsent.analytics === true ? 'granted' : 'denied',
                'wait_for_update': 500
            });

            // Check if cookie is allowed by consent
            // Reads window.glifCookieConsent live so cookies unblock immediately after "Accept" without a page reload
            function isCookieAllowed(cookieName) {
                var category = getCookieCategory(cookieName);

                // Necessary cookies are always allowed
                if (category === 'necessary') {
                    return true;
                }

                var currentConsent = window.glifCookieConsent || userConsent;

                // Check if user has consented to this category
                return currentConsent[category] === true;
            }

            // Override document.cookie setter
            var originalDescriptor = Object.getOwnPropertyDescriptor(Document.prototype, 'cookie');
            
            Object.defineProperty(Document.prototype, 'cookie', {
                set: function(value) {
                    var cookieName = value.split('=')[0].trim();
                    
                    if (isCookieAllowed(cookieName)) {
                        // User consented - allow the cookie
                        originalDescriptor.set.call(this, value);
                        console.debug('[Simple Cookie Consent Plugin] Issued cookie: ' + cookieName);
                    } else {
                        // User did not consent - block silently
                        console.debug('[Simple Cookie Consent Plugin] Blocked cookie: ' + cookieName);
                    }
                },
                get: originalDescriptor.get,
                enumerable: true,
                configurable: true
            });

            window.glifCookieAllowed = isCookieAllowed;
        })();
        </script>
        <?php
    }

    /**
     * Output consent banner in footer
     */
    public function output_consent_banner() {
        // Check if user has already made a consent decision
        $consent_data = $this->get_user_consent();
        
        // Don't show banner if consent already decided
        if ($consent_data !== null) {
            return;
        }

        // Template is optional because the JS renderer can build the banner UI.
        $template_candidates = array(
            GLIFFEN_COOKIE_CONSENT_PATH . 'templates/consent-banner.php',
            GLIFFEN_COOKIE_CONSENT_PATH . 'public/templates/consent-banner.php',
        );

        foreach ($template_candidates as $template_path) {
            if (is_readable($template_path)) {
                include $template_path;
                return;
            }
        }
    }

    /**
     * Get combined cookie registry (pre-configured + custom)
     */
    private function get_cookie_registry() {
        $registry = array();
        
        // Add pre-configured cookies
        $preconfigured = $this->settings['preconfigured_cookies'] ?? array();
        foreach ($preconfigured as $category => $cookies) {
            foreach ($cookies as $cookie) {
                $registry[$cookie] = $category;
            }
        }
        
        // Add custom cookies
        $custom = $this->settings['custom_cookies'] ?? array();
        foreach ($custom as $category => $cookies) {
            foreach ($cookies as $cookie) {
                $registry[$cookie] = $category;
            }
        }
        
        return $registry;
    }

    /**
     * Extract Google Analytics ID from gtag script tag
     * Looks for the GA ID in the gtag script src attribute (id=G-XXXXXXXXXX)
     */
    private function get_analytics_id_from_page() {
        // Check if GA script is already registered
        global $wp_scripts;
        
        // Look for google analytics script
        if ($wp_scripts && isset($wp_scripts->registered['google-analytics'])) {
            $script = $wp_scripts->registered['google-analytics'];
            // Extract ID from src if it contains gtag
            if (strpos($script->src, 'gtag') !== false) {
                // Parse out id parameter: https://www.googletagmanager.com/gtag/js?id=G-XXXXX
                if (preg_match('/[?&]id=([A-Z0-9\-]+)/', $script->src, $matches)) {
                    return $matches[1];
                }
            }
        }
        
        // Fallback: check for google-analytics-4 or ga4
        if ($wp_scripts && isset($wp_scripts->registered['google-analytics-4'])) {
            $script = $wp_scripts->registered['google-analytics-4'];
            if (preg_match('/[?&]id=([A-Z0-9\-]+)/', $script->src, $matches)) {
                return $matches[1];
            }
        }
        
        // Fallback: return empty string (dataLayer will be checked in JS)
        return '';
    }

    /**
     * Get user's current consent decision
     * Returns array of consent or null if not decided
     */
    private function get_user_consent() {
        // In JavaScript, this will be stored in localStorage
        // Here we check via nonce/verification if needed
        // For now, return null to trigger banner display
        
        // In a real scenario, you'd check a cookie or session
        // For this implementation, consent is purely client-side
        return null;
    }

    /**
     * Handle AJAX consent save
     */
    public function handle_save_consent() {
        // Verify nonce if needed
        $consent = isset($_POST['consent']) ? sanitize_text_field($_POST['consent']) : '{}';
        
        // Log consent for auditing (optional)
        $log_data = array(
            'timestamp' => current_time('mysql'),
            'consent' => $consent,
            'ip_address' => $this->get_client_ip(),
            'user_id' => get_current_user_id()
        );
        
        // Store in a transient or log file for auditing
        // For now, just acknowledge
        wp_send_json_success(array('message' => 'Consent saved'));
    }

    /**
     * Get client IP address
     */
    private function get_client_ip() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return $ip;
    }
}
