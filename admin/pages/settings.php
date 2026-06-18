<?php
/**
 * Admin Settings Page Template
 */

if (!defined('ABSPATH')) {
    exit;
}

$admin_settings = Gliffen_Admin_Settings::get_instance();
$settings = $admin_settings->get_settings();
?>

<div class="wrap gliffen-cookie-consent-settings">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <form action="options.php" method="POST" class="gliffen-settings-form">
        <?php settings_fields('gliffen_cookie_consent_group'); ?>

        <div class="gliffen-tabs">
            <nav class="gliffen-tab-nav">
                <button type="button" class="gliffen-tab-btn active" data-tab="general">General</button>
                <button type="button" class="gliffen-tab-btn" data-tab="appearance">Appearance</button>
                <button type="button" class="gliffen-tab-btn" data-tab="cookies">Cookies</button>
                <button type="button" class="gliffen-tab-btn" data-tab="legal">Legal</button>
            </nav>

            <!-- General Tab -->
            <div id="general" class="gliffen-tab-content active">
                <h2>General Settings</h2>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="enabled">Enable Plugin</label>
                        </th>
                        <td>
                            <input type="checkbox" id="enabled" name="gliffen_cookie_consent_settings[enabled]" value="1"
                                <?php checked(!empty($settings['enabled']), true); ?>>
                            <p class="description">Enable or disable the cookie consent banner</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="banner_position">Banner Position</label>
                        </th>
                        <td>
                            <select id="banner_position" name="gliffen_cookie_consent_settings[banner_position]">
                                <option value="bottom" <?php selected($settings['banner_position'] ?? 'bottom', 'bottom'); ?>>Bottom</option>
                                <option value="top" <?php selected($settings['banner_position'] ?? 'bottom', 'top'); ?>>Top</option>
                            </select>
                            <p class="description">Where to display the consent banner</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="consent_expiry_days">Consent Expiry (Days)</label>
                        </th>
                        <td>
                            <input type="number" id="consent_expiry_days" name="gliffen_cookie_consent_settings[consent_expiry_days]"
                                value="<?php echo esc_attr($settings['consent_expiry_days'] ?? 30); ?>" min="1">
                            <p class="description">How many days before users see the banner again</p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Appearance Tab -->
            <div id="appearance" class="gliffen-tab-content">
                <h2>Appearance Settings</h2>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="banner_bg_color">Banner Background Color</label>
                        </th>
                        <td>
                            <input type="text" id="banner_bg_color" name="gliffen_cookie_consent_settings[banner_bg_color]"
                                class="color-picker" value="<?php echo esc_attr($settings['banner_bg_color'] ?? '#222222'); ?>">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="banner_text_color">Banner Text Color</label>
                        </th>
                        <td>
                            <input type="text" id="banner_text_color" name="gliffen_cookie_consent_settings[banner_text_color]"
                                class="color-picker" value="<?php echo esc_attr($settings['banner_text_color'] ?? '#ffffff'); ?>">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="button_bg_color">Button Background Color</label>
                        </th>
                        <td>
                            <input type="text" id="button_bg_color" name="gliffen_cookie_consent_settings[button_bg_color]"
                                class="color-picker" value="<?php echo esc_attr($settings['button_bg_color'] ?? '#007cba'); ?>">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="button_text_color">Button Text Color</label>
                        </th>
                        <td>
                            <input type="text" id="button_text_color" name="gliffen_cookie_consent_settings[button_text_color]"
                                class="color-picker" value="<?php echo esc_attr($settings['button_text_color'] ?? '#ffffff'); ?>">
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Cookies & Categories Tab -->
            <div id="cookies" class="gliffen-tab-content">
                <h2>Cookie Management by Category</h2>

                <p class="description">Manage which cookies are controlled by the consent system. Pre-configured cookies are built-in and cannot be removed, but you can add custom cookies to any category.</p>

                <?php
                $categories = $settings['categories'] ?? array();
                $preconfigured = $settings['preconfigured_cookies'] ?? array();
                $custom = $settings['custom_cookies'] ?? array();

                foreach ($categories as $cat_id => $category) :
                ?>
                    <div class="gliffen-cookie-category-section">
                        <h3><?php echo esc_html($category['name']); ?></h3>
                        <p class="description"><?php echo esc_html($category['description']); ?></p>

                        <!-- Pre-configured Cookies -->
                        <div class="gliffen-cookies-group">
                            <h4>Pre-configured Cookies</h4>
                            <div class="gliffen-cookies-list">
                                <?php
                                $preconfigured_cookies = $preconfigured[$cat_id] ?? array();
                                if (empty($preconfigured_cookies)) {
                                    echo '<p class="description"><em>No pre-configured cookies for this category.</em></p>';
                                } else {
                                    foreach ($preconfigured_cookies as $cookie) {
                                        echo '<span class="gliffen-cookie-badge gliffen-cookie-badge-readonly">' . esc_html($cookie) . '</span>';
                                    }
                                }
                                ?>
                            </div>
                        </div>

                        <!-- Custom Cookies -->
                        <div class="gliffen-cookies-group">
                            <h4>Custom Cookies</h4>
                            <p class="description">Add custom cookie names to control additional tracking cookies. Enter one cookie name per line.</p>
                            
                            <textarea name="gliffen_cookie_consent_settings[custom_cookies][<?php echo esc_attr($cat_id); ?>]"
                                class="gliffen-custom-cookies"
                                rows="4"
                                placeholder="e.g., _hjHasCachedUserAttributes&#10;cookieName&#10;anotherCookie"
                            ><?php
                                $custom_cookies = $custom[$cat_id] ?? array();
                                echo esc_textarea(implode("\n", $custom_cookies));
                            ?></textarea>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Legal Tab -->
            <div id="legal" class="gliffen-tab-content">
                <h2>Legal & Policy Links</h2>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="privacy_policy_url">Privacy Policy URL</label>
                        </th>
                        <td>
                            <input type="url" id="privacy_policy_url"
                                name="gliffen_cookie_consent_settings[privacy_policy_url]"
                                value="<?php echo esc_url($settings['privacy_policy_url'] ?? ''); ?>"
                                style="width: 100%;">
                            <p class="description">Link to your privacy policy</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="cookie_policy_url">Cookie Policy URL</label>
                        </th>
                        <td>
                            <input type="url" id="cookie_policy_url"
                                name="gliffen_cookie_consent_settings[cookie_policy_url]"
                                value="<?php echo esc_url($settings['cookie_policy_url'] ?? ''); ?>"
                                style="width: 100%;">
                            <p class="description">Link to your cookie policy</p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <?php submit_button(); ?>
    </form>
</div>

<style>
    .gliffen-cookie-consent-settings {
        background: white;
        padding: 20px;
        border-radius: 8px;
    }

    .gliffen-tab-nav {
        display: flex;
        border-bottom: 1px solid #ccc;
        gap: 0;
        margin-bottom: 20px;
    }

    .gliffen-tab-btn {
        padding: 10px 15px;
        border: none;
        background: none;
        cursor: pointer;
        border-bottom: 3px solid transparent;
        font-weight: 500;
        color: #666;
        transition: all 0.3s;
    }

    .gliffen-tab-btn.active {
        border-bottom-color: #0073aa;
        color: #0073aa;
    }

    .gliffen-tab-btn:hover {
        color: #0073aa;
    }

    .gliffen-tab-content {
        display: none;
    }

    .gliffen-tab-content.active {
        display: block;
    }

    .gliffen-service-card {
        border: 1px solid #e0e0e0;
        border-radius: 4px;
        padding: 15px;
        margin-bottom: 15px;
        background-color: #fafafa;
    }

    .gliffen-service-field {
        margin-bottom: 15px;
    }

    .gliffen-service-field input[type="text"],
    .gliffen-service-field input[type="url"] {
        width: 100%;
        padding: 8px;
        border: 1px solid #ddd;
        border-radius: 4px;
    }

    .gliffen-service-category {
        background-color: #e8f5e9;
        padding: 8px;
        border-radius: 4px;
        margin-bottom: 15px;
        font-size: 14px;
    }

    /* Cookie Management Styles */
    .gliffen-cookie-category-section {
        background-color: #fafafa;
        border: 1px solid #e0e0e0;
        border-radius: 4px;
        padding: 20px;
        margin-bottom: 20px;
    }

    .gliffen-cookie-category-section h3 {
        margin-top: 0;
        color: #0073aa;
    }

    .gliffen-cookie-category-section h4 {
        margin-top: 15px;
        margin-bottom: 10px;
        font-size: 14px;
        text-transform: uppercase;
        color: #555;
    }

    .gliffen-cookies-group {
        margin-bottom: 20px;
    }

    .gliffen-cookies-list {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-bottom: 15px;
    }

    .gliffen-cookie-badge {
        display: inline-block;
        background-color: #e8f5e9;
        color: #2e7d32;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 500;
        border: 1px solid #c8e6c9;
    }

    .gliffen-cookie-badge-readonly {
        background-color: #e3f2fd;
        color: #1565c0;
        border-color: #bbdefb;
    }

    .gliffen-custom-cookies {
        width: 100%;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-family: monospace;
        font-size: 13px;
    }

    .gliffen-cookies-info {
        background-color: #f0f5ff;
        border-left: 4px solid #0073aa;
        padding: 15px;
        margin-top: 20px;
        border-radius: 4px;
    }

    .gliffen-cookies-info ul {
        margin: 10px 0 0 20px;
    }

    .gliffen-cookies-info li {
        margin-bottom: 8px;
        font-size: 14px;
    }
</style>

<script>
    jQuery(function($) {
        // Color picker
        $('.color-picker').wpColorPicker();

        // Tab switching
        $('.gliffen-tab-btn').on('click', function(e) {
            e.preventDefault();
            const tab = $(this).data('tab');

            $('.gliffen-tab-btn').removeClass('active');
            $('.gliffen-tab-content').removeClass('active');

            $(this).addClass('active');
            $('#' + tab).addClass('active');
        });
    });
</script>
