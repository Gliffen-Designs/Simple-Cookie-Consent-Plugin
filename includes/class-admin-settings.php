<?php
/**
 * Admin Settings Handler
 * Manages admin pages for cookie consent configuration
 */

if (!defined('ABSPATH')) {
    exit;
}

class Gliffen_Admin_Settings {
    private static $instance = null;
    private $settings;
    private $page_slug = 'gliffen-cookie-consent';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->settings = get_option('gliffen_cookie_consent_settings', array());
        
        // Merge in default pre-configured cookies if not present
        $this->settings = $this->merge_default_cookies($this->settings);
        
        // Remove deprecated Preferences category if present
        if (isset($this->settings['categories']['preferences'])) {
            unset($this->settings['categories']['preferences']);
        }
        if (isset($this->settings['custom_cookies']['preferences'])) {
            unset($this->settings['custom_cookies']['preferences']);
        }
        
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    /**
     * Merge in default pre-configured cookies if not present
     */
    private function merge_default_cookies($settings) {
        $defaults = array(
            'preconfigured_cookies' => array(
                'necessary' => array(
                    'PHPSESSID',
                    'wordpress_logged_in',
                    'NID',  // reCAPTCHA
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
                    '_fbp',     // Meta Pixel
                    'fr',        // Meta
                    'IDE',       // Google Ads
                    'ANID',      // Google Ads
                    '_gcl_au',   // Google
                    'MUID',      // Bing
                    '_ttid'      // TikTok
                )
            ),
            'custom_cookies' => array(
                'necessary' => array(),
                'analytics' => array(),
                'marketing' => array()
            )
        );

        if (!isset($settings['preconfigured_cookies'])) {
            $settings['preconfigured_cookies'] = $defaults['preconfigured_cookies'];
        }
        if (!isset($settings['custom_cookies'])) {
            $settings['custom_cookies'] = $defaults['custom_cookies'];
        }

        return $settings;
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_options_page(
            'Cookie Consent',
            'Cookie Consent',
            'manage_options',
            $this->page_slug,
            array($this, 'render_settings_page')
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('gliffen_cookie_consent_group', 'gliffen_cookie_consent_settings', array(
            'sanitize_callback' => array($this, 'sanitize_settings'),
            'default' => array()
        ));
    }

    /**
     * Sanitize settings on save
     */
    public function sanitize_settings($input) {
        if (!is_array($input)) {
            return $this->settings;
        }

        $sanitized = $this->settings;

        // Always preserve preconfigured cookies (admins cannot modify these)

        // Sanitize basic fields
        if (isset($input['enabled'])) {
            $sanitized['enabled'] = (bool) $input['enabled'];
        }
        if (isset($input['banner_position'])) {
            $sanitized['banner_position'] = sanitize_text_field($input['banner_position']);
        }
        if (isset($input['banner_bg_color'])) {
            $sanitized['banner_bg_color'] = sanitize_hex_color($input['banner_bg_color']);
        }
        if (isset($input['banner_text_color'])) {
            $sanitized['banner_text_color'] = sanitize_hex_color($input['banner_text_color']);
        }
        if (isset($input['button_bg_color'])) {
            $sanitized['button_bg_color'] = sanitize_hex_color($input['button_bg_color']);
        }
        if (isset($input['button_text_color'])) {
            $sanitized['button_text_color'] = sanitize_hex_color($input['button_text_color']);
        }
        if (isset($input['privacy_policy_url'])) {
            $sanitized['privacy_policy_url'] = esc_url_raw($input['privacy_policy_url']);
        }
        if (isset($input['cookie_policy_url'])) {
            $sanitized['cookie_policy_url'] = esc_url_raw($input['cookie_policy_url']);
        }
        if (isset($input['consent_expiry_days'])) {
            $sanitized['consent_expiry_days'] = intval($input['consent_expiry_days']);
        }

        // Sanitize custom cookies only (preserve preconfigured)
        if (isset($input['custom_cookies']) && is_array($input['custom_cookies'])) {
            foreach ($input['custom_cookies'] as $category => $cookies) {
                if (isset($sanitized['custom_cookies'][$category])) {
                    // Convert textarea (newline-separated) to array
                    if (is_string($cookies)) {
                        $cookies = array_filter(array_map(function($cookie) {
                            return sanitize_text_field(trim($cookie));
                        }, explode("\n", $cookies)));
                    } elseif (is_array($cookies)) {
                        $cookies = array_filter(array_map(function($cookie) {
                            return sanitize_text_field(trim($cookie));
                        }, $cookies));
                    }
                    $sanitized['custom_cookies'][$category] = $cookies;
                }
            }
        }

        // Remove deprecated Preferences category if present
        if (isset($sanitized['categories']['preferences'])) {
            unset($sanitized['categories']['preferences']);
        }
        if (isset($sanitized['custom_cookies']['preferences'])) {
            unset($sanitized['custom_cookies']['preferences']);
        }

        return $sanitized;
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, $this->page_slug) === false) {
            return;
        }

        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');

        wp_enqueue_style(
            'gliffen-admin',
            GLIFFEN_COOKIE_CONSENT_URL . 'admin/assets/css/admin.css',
            array(),
            gliffen_cookie_consent_get_version()
        );

        wp_enqueue_script(
            'gliffen-admin',
            GLIFFEN_COOKIE_CONSENT_URL . 'admin/assets/js/admin.js',
            array('jquery', 'wp-color-picker'),
            gliffen_cookie_consent_get_version(),
            true
        );
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        // Save merged settings back to database to ensure defaults are persisted
        update_option('gliffen_cookie_consent_settings', $this->settings);

        include GLIFFEN_COOKIE_CONSENT_PATH . 'admin/pages/settings.php';
    }

    /**
     * Get all settings
     */
    public function get_settings() {
        return $this->settings;
    }
}
