<?php
/**
 * GitHub Update Checker for Simple Cookie Consent Plugin
 *
 * Checks GitHub releases for plugin updates and surfaces
 * update notifications in the WordPress admin.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Gliffen_Cookie_Consent_Update_Checker {
    private static $instance = null;

    private $github_repo = 'Gliffen-Designs/Simple-Cookie-Consent-Plugin';
    private $plugin_file = '';
    private $plugin_slug = 'gliffen-cookie-consent';
    private $transient_key = 'gliffen_cookie_consent_github_release_info';
    private $cache_duration = 12 * HOUR_IN_SECONDS;

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        $this->github_repo = apply_filters('gliffen_cookie_consent_github_repo', $this->github_repo);

        // Resolve plugin path dynamically in case folder name differs per install.
        $this->plugin_file = plugin_basename(GLIFFEN_COOKIE_CONSENT_PATH . 'gliffen-cookie-consent.php');

        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_updates'));
        add_filter('plugins_api', array($this, 'plugin_info'), 10, 3);
    }

    /**
     * Check for updates from GitHub.
     */
    public function check_for_updates($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $release_info = $this->get_github_release();
        if (!$release_info) {
            return $transient;
        }

        $current_version = $this->get_current_version();
        $remote_version = $release_info['version'];

        $plugin_data = (object) array(
            'slug'        => $this->plugin_slug,
            'plugin'      => $this->plugin_file,
            'new_version' => $remote_version,
            'url'         => $release_info['url'],
            'package'     => $release_info['package'],
            'tested'      => $release_info['tested'],
            'requires'    => '5.0',
            'icons'       => array(),
        );

        if (version_compare($remote_version, $current_version, '>')) {
            $transient->response[$this->plugin_file] = $plugin_data;
            if (isset($transient->no_update[$this->plugin_file])) {
                unset($transient->no_update[$this->plugin_file]);
            }
        } else {
            $transient->no_update[$this->plugin_file] = $plugin_data;
            if (isset($transient->response[$this->plugin_file])) {
                unset($transient->response[$this->plugin_file]);
            }
        }

        return $transient;
    }

    /**
     * Provide plugin info for the update details modal.
     */
    public function plugin_info($response, $action, $args) {
        if ($action !== 'plugin_information') {
            return $response;
        }

        $accepted_slugs = array(
            $this->plugin_slug,
            'simple-cookie-consent-plugin',
            'Simple-Cookie-Consent-Plugin'
        );

        if (!isset($args->slug) || !in_array($args->slug, $accepted_slugs, true)) {
            return $response;
        }

        $release_info = $this->get_github_release();
        if (!$release_info) {
            return $response;
        }

        return (object) array(
            'name'           => 'Simple Cookie Consent Plugin',
            'slug'           => $this->plugin_slug,
            'version'        => $release_info['version'],
            'author'         => 'Gliffen',
            'author_profile' => 'https://github.com/Gliffen-Designs',
            'requires'       => '5.0',
            'requires_php'   => '7.2',
            'tested'         => $release_info['tested'],
            'last_updated'   => $release_info['published_date'],
            'sections'       => array(
                'description'  => $release_info['description'] ?: 'Lightweight cookie consent plugin for WordPress.',
                'installation' => 'Install and activate the plugin. Go to Settings > Cookie Consent to configure.',
                'changelog'    => $release_info['body'] ?: 'See GitHub releases for details.',
            ),
            'download_link'  => $release_info['package'],
            'banners'        => array(),
            'url'            => $release_info['url'],
        );
    }

    /**
     * Fetch the latest release from GitHub.
     */
    private function get_github_release() {
        $cached = get_transient($this->transient_key);
        if ($cached) {
            return $cached;
        }

        $url = "https://api.github.com/repos/{$this->github_repo}/releases/latest";

        $response = wp_remote_get(
            $url,
            array(
                'headers'   => array(
                    'Accept'     => 'application/vnd.github+json',
                    'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url('/'),
                ),
                'sslverify' => true,
                'timeout'   => 10,
            )
        );

        if (is_wp_error($response)) {
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if (200 !== $code) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data['tag_name'])) {
            return false;
        }

        // Extract version from tag (e.g. v1.2.3 -> 1.2.3)
        $version = trim(preg_replace('/^[vV]/', '', $data['tag_name']));

        // Find release zip asset first.
        $package_url = false;
        if (!empty($data['assets'])) {
            foreach ($data['assets'] as $asset) {
                if (strpos($asset['name'], '.zip') !== false) {
                    $package_url = $asset['browser_download_url'];
                    break;
                }
            }
        }

        if (!$package_url) {
            $package_url = $data['zipball_url'];
        }

        $release_info = array(
            'version'        => $version,
            'url'            => $data['html_url'],
            'package'        => $package_url,
            'body'           => $data['body'],
            'description'    => $data['body'],
            'published_date' => $data['published_at'],
            'tested'         => '6.6',
        );

        set_transient($this->transient_key, $release_info, $this->cache_duration);

        return $release_info;
    }

    /**
     * Get plugin version from the plugin header.
     */
    private function get_current_version() {
        return gliffen_cookie_consent_get_version();
    }

    /**
     * Clear updater cache (useful during testing).
     */
    public static function clear_cache() {
        delete_transient('gliffen_cookie_consent_github_release_info');
        delete_site_transient('update_plugins');
    }
}
