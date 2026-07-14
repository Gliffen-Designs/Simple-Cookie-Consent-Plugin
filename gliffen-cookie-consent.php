<?php
/**
 * Plugin Name: Simple Cookie Consent
 * Plugin URI: https://github.com/Gliffen-Designs/Simple-Cookie-Consent-Plugin
 * Description: Lightweight GDPR-compliant cookie consent plugin with third-party tracking control
 * Version: 1.0.1
 * Update URI: https://github.com/Gliffen-Designs/Simple-Cookie-Consent-Plugin
 * Author: Gliffen
 * Author URI: https://gliffen.com
 * License: GPL v2 or later
 * Text Domain: simple-cookie-consent
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define plugin constants
define('GLIFFEN_COOKIE_CONSENT_PATH', plugin_dir_path(__FILE__));
define('GLIFFEN_COOKIE_CONSENT_URL', plugin_dir_url(__FILE__));

/**
 * Get plugin version from the registered plugin header.
 */
function gliffen_cookie_consent_get_version() {
    static $version = null;

    if ($version !== null) {
        return $version;
    }

    $plugin_data = get_file_data(__FILE__, array('Version' => 'Version'));
    $version = !empty($plugin_data['Version']) ? $plugin_data['Version'] : '0.0.0';

    return $version;
}

// Include core classes
require_once GLIFFEN_COOKIE_CONSENT_PATH . 'includes/class-cookie-consent-manager.php';
require_once GLIFFEN_COOKIE_CONSENT_PATH . 'includes/class-admin-settings.php';
require_once GLIFFEN_COOKIE_CONSENT_PATH . 'includes/class-update-checker.php';

// Initialize the plugin
add_action('plugins_loaded', 'gliffen_cookie_consent_init');

function gliffen_cookie_consent_init() {
    // Initialize manager
    Gliffen_Cookie_Consent_Manager::get_instance();

    // Initialize GitHub update checker
    Gliffen_Cookie_Consent_Update_Checker::init();
    
    // Initialize admin if in admin panel
    if (is_admin()) {
        Gliffen_Admin_Settings::get_instance();
    }
}

// Activation hook
register_activation_hook(__FILE__, 'gliffen_cookie_consent_activate');

function gliffen_cookie_consent_activate() {
    // Set default options
    $defaults = array(
        'enabled' => true,
        'banner_position' => 'bottom',
        'banner_bg_color' => '#222222',
        'banner_text_color' => '#ffffff',
        'button_bg_color' => '#007cba',
        'button_text_color' => '#ffffff',
        'privacy_policy_url' => home_url('/privacy-policy/'),
        'cookie_policy_url' => home_url('/cookie-policy/'),
        'consent_expiry_days' => 30,
        'categories' => array(
            'necessary' => array(
                'name' => 'Necessary',
                'description' => 'Essential cookies for website functionality',
                'always_enabled' => true
            ),
            'analytics' => array(
                'name' => 'Analytics',
                'description' => 'Help us understand how you use our website',
                'always_enabled' => false
            ),
            'marketing' => array(
                'name' => 'Marketing',
                'description' => 'Used to track you across websites for personalized ads',
                'always_enabled' => false
            )
        ),
        // Pre-configured cookies by category (admins cannot remove these)
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
                '_fbp',      // Meta Pixel
                'fr',        // Meta
                'IDE',       // Google Ads
                'ANID',      // Google Ads
                '_gcl_au',   // Google
                'MUID',      // Bing
                '_ttid'      // TikTok
            )
        ),
        // Custom cookies added by admin (can be added/removed)
        'custom_cookies' => array(
            'necessary' => array(),
            'analytics' => array(),
            'marketing' => array()
        )
    );
    
    if (!get_option('gliffen_cookie_consent_settings')) {
        add_option('gliffen_cookie_consent_settings', $defaults);
    }
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'gliffen_cookie_consent_deactivate');

function gliffen_cookie_consent_deactivate() {
    // Cleanup if needed
}
