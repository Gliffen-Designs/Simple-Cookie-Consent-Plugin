# Simple Cookie Consent Plugin - Complete Developer Guide

A lightweight cookie consent plugin for WordPress built around a **US-style opt-in-by-default** model, with a heuristic opt-out affordance for likely EU visitors. This document provides comprehensive technical context for understanding and extending the plugin.

---

## 🎯 Plugin Overview

**Purpose**: Control third-party tracking cookies (GA, Meta Pixel, Google Ads, etc.) by:
1. Allowing cookies/tracking by default (opt-in model) until the user explicitly opts out, unless a Global Privacy Control (GPC) signal is detected
2. Presenting a user-friendly consent banner so visitors can accept, customize, or (if likely in the EU) reject
3. Keeping Google Consent Mode (`dataLayer`/`gtag`) in sync so GTM-only installs still receive consent signals
4. Storing consent preferences for repeat visitors

**Key Design Decision**: Lightweight plugin with all settings stored in WordPress options table (no custom database tables) to minimize complexity and database footprint.

**Architecture Pattern**: Singleton classes for managers, hooked into WordPress lifecycle

---

## 🚀 Installation

Basic installation steps:

1. Download the latest release from the [GitHub Releases page](https://github.com/Gliffen-Designs/Simple-Cookie-Consent-Plugin/releases).
2. Upload the `.zip` file in WordPress under **Plugins > Add New > Upload Plugin**, or copy the plugin folder into `wp-content/plugins/`.
3. Activate **Simple Cookie Consent Plugin** from the WordPress Plugins screen.
4. Open **Cookie Consent** in the admin menu and configure the plugin settings:
    - **General**: enable the plugin, choose banner position, and set the consent expiry days.
    - **Appearance**: style the banner and buttons with your preferred colors.
    - **Cookies & Services**: add or confirm the cookie names that should be intercepted, then enable the tracking services you use.
    - **Legal**: set your Privacy Policy and Cookie Policy URLs.
5. Save your changes and clear any site cache if you use a caching plugin or CDN.

---

## 📁 Plugin Structure

```
gliffen-cookie-consent/
├── gliffen-cookie-consent.php           # Main plugin file - activation & initialization
├── README.md                             # This file
├── includes/
│   ├── class-cookie-consent-manager.php # Frontend manager - JS injection, banner, AJAX
│   └── class-admin-settings.php         # Admin settings manager - menu, pages, sanitization
├── admin/
│   ├── pages/
│   │   └── settings.php                 # Admin settings page template (tabbed UI)
│   └── assets/
│       ├── css/
│       │   └── admin.css                # Admin styling
│       └── js/
│           └── admin.js                 # Admin tab switching
├── public/
│   └── assets/
│       ├── css/
│       │   └── consent-banner.css       # Frontend banner styling
│       └── js/
│           └── consent-banner.js        # Frontend banner & tracking re-init logic
```

---

## 🔧 Core Components

### 1. Main Plugin File (`gliffen-cookie-consent.php`)

**Responsibilities**:
- Define plugin metadata (name, version, author)
- Set up constants for paths and URLs
- Include manager classes
- Register activation/deactivation hooks
- Initialize plugin on `plugins_loaded`

**Key Functions**:
- `gliffen_cookie_consent_init()`: Called on `plugins_loaded`, instantiates singleton managers
- `gliffen_cookie_consent_activate()`: Sets default options on activation
  - **Important**: All default settings created here in a single serialized array

**Default Settings Structure** (stored as single wp_options entry):
```php
'gliffen_cookie_consent_settings' => array(
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
        'necessary' => array('name' => 'Necessary', 'description' => '...', 'always_enabled' => true),
        'analytics' => array('name' => 'Analytics', 'description' => '...', 'always_enabled' => false),
        'marketing' => array('name' => 'Marketing', 'description' => '...', 'always_enabled' => false),
        'preferences' => array('name' => 'Preferences', 'description' => '...', 'always_enabled' => false),
    ),
    
    'cookies' => array(  // Cookie name → category mapping
        '_ga' => 'analytics',
        '_fbp' => 'marketing',
        'NID' => 'necessary',
        // ... etc
    ),
    
    'services' => array(
        'google_analytics' => array(
            'name' => 'Google Analytics',
            'category' => 'analytics',
            'enabled' => false,
            'tracking_id' => ''
        ),
        // ... more services
    )
)
```

---

### 2. Cookie Consent Manager (`includes/class-cookie-consent-manager.php`)

**Responsibilities**:
- Inject cookie interceptor in frontend `<head>` (CRITICAL - must be first)
- Display consent banner to first-time visitors
- Enqueue frontend scripts and styles
- Handle AJAX consent logging
- Provide client IP detection for audit purposes

**Key Methods**:

#### `__construct()`
- Loads settings from wp_options
- Checks if plugin is enabled
- Hooks all frontend actions only if enabled

#### `output_cookie_interceptor()`
**Location**: Hooked to `wp_head` with priority 1 (runs BEFORE everything else)
**Purpose**: Inject inline JavaScript that blocks cookies before third-party scripts run

**How it works**:
```javascript
1. Load user consent from localStorage
2. If no explicit decision exists yet, apply the default:
   - Opt-in (necessary+analytics+marketing = true) unless navigator.globalPrivacyControl is true
   - If GPC is set, default to opt-out (necessary only) instead
3. Build cookie → category mapping from PHP settings
4. Define window.dataLayer/gtag (if not already defined by gtag.js) and push
   gtag('consent', 'default', {...}) reflecting the consent above, so GTM containers
   receive Consent Mode signals even when GA is only installed via GTM
5. Override Document.prototype.cookie setter:
   - When script tries: document.cookie = "ga=xyz"
   - Check if "_ga" is in a consented category
   - If NOT consented: silently ignore the write
   - If consented: allow it via original setter
6. Expose globals: window.glifCookieConsent, window.glifCookieAllowed
```

**Critical Detail**: This runs INLINE in `<head>` before wp_enqueue_scripts fires. This is essential because:
- External scripts in `<head>` won't have loaded yet
- But our interceptor is already in place to catch them
- The mapping is passed as JSON: `var cookieRegistry = <?php echo json_encode(...) ?>`

#### `output_consent_banner()`
**Location**: Hooked to `wp_footer` with priority 999 (very late)
**Purpose**: Display banner only if user hasn't made a consent decision

**Logic**:
- Calls `$this->get_user_consent()` to check localStorage
- If null (no decision), includes `templates/consent-banner.php`
- If already decided, returns early (no banner shown)

#### `enqueue_scripts()`
**Location**: Hooked to `wp_enqueue_scripts`
**Purpose**: Load frontend JS, CSS, and localize settings

**What's localized**:
- `glifCookieSettings` object passed to JS with all admin settings
- Includes: banner colors, position, categories, URLs, expiry days
- JavaScript accesses via `window.glifCookieSettings`

#### `handle_save_consent()` (AJAX)
**Endpoint**: `wp_ajax_gliffen_save_consent` and `wp_ajax_nopriv_gliffen_save_consent`
**Purpose**: Log consent to server for audit purposes
**Current State**: Accepts consent data, could be extended to store in custom table or transient

**Note**: Consent is primarily stored in localStorage (client-side). This AJAX handler is for server-side logging (not currently implemented fully).

---

### 3. Admin Settings Manager (`includes/class-admin-settings.php`)

**Responsibilities**:
- Register admin menu (Cookie Consent)
- Register and sanitize settings
- Enqueue admin scripts/styles
- Render admin settings page

**Key Methods**:

#### `add_admin_menu()`
- Creates top-level menu: "Cookie Consent" with privacy icon
- Points to settings page slug: `gliffen-cookie-consent`

#### `register_settings()`
- Registers `gliffen_cookie_consent_group` settings group
- Sanitization callback: `sanitize_settings()`

#### `sanitize_settings($input)`
**Critical**: This is where input validation happens
- Validates colors: `sanitize_hex_color()`
- Validates URLs: `esc_url_raw()`
- Converts booleans: `(bool)`
- Converts integers: `intval()`
- Validates service IDs against whitelist
- **Note**: Preserves entire settings structure, updates only changed fields

**Key behavior**: Merges updated fields with existing settings:
```php
$sanitized = $this->settings;  // Start with current
if (isset($input['enabled'])) {
    $sanitized['enabled'] = (bool) $input['enabled'];
}
// ... update individual fields
return $sanitized;
```

#### `enqueue_admin_scripts($hook)`
- Loads WP color picker for admin
- Loads custom admin CSS/JS
- Localizes (none currently, could add nonces)

---

### 4. Admin Settings Page (`admin/pages/settings.php`)

**Structure**: Tabbed interface with 4 tabs

#### Tab 1: General
- Enable/disable plugin checkbox
- Banner position dropdown (top/bottom)
- Consent expiry days input

#### Tab 2: Appearance
- Banner background color (color picker)
- Banner text color (color picker)
- Button background color (color picker)
- Button text color (color picker)

#### Tab 3: Cookies & Services
- **Dynamic service cards** for each pre-configured service:
  - Google Analytics (tracking_id field)
  - Meta Pixel (pixel_id field)
  - Google Ads (conversion_id field)
  - reCAPTCHA (site_key field)
- Each has enable checkbox and category badge
- Information box listing pre-configured cookies

#### Tab 4: Legal
- Privacy Policy URL
- Cookie Policy URL

**Form Structure**:
- Uses WordPress `settings_fields()` and `do_settings_sections()`
- Actually: Uses `register_setting()` without `add_settings_section()`, so renders manual form
- Field names: `gliffen_cookie_consent_settings[field_name]` to nest under main option

**Important**: Color pickers initialized via inline script using `$('.color-picker').wpColorPicker()`

---

### 5. Frontend Consent Banner (`public/assets/js/consent-banner.js`)

**This is the MOST IMPORTANT file for understanding user flow and tracking re-init**

**Singleton Object**: `GlifCookieConsentBanner`

**Key Flow**:

#### Initialization (`init()`)
```
1. Check localStorage for existing consent
2. If consent exists:
   - Call triggerTrackingReinit()
   - Exit early (no banner)
3. If NO consent:
   - Create banner DOM
   - Attach event handlers
```

#### Banner Creation (`createBanner()`)
- Creates HTML: banner div with text, buttons, styling
- Applies colors from `glifCookieSettings`
- Appends to body
- Creates inline style for color application

#### Button Events
- **Accept All**: `{necessary: true, analytics: true, marketing: true}` (always shown)
- **Reject All**: `{necessary: true, analytics: false, marketing: false}` (only rendered when `isLikelyEU()` returns true - hidden for everyone else)
- **Customize**: Opens modal with checkboxes

#### Customize Modal
- Shows checkbox for each category
- "Necessary" is disabled and always checked
- "Analytics" is pre-checked by default (one click to reach a privacy-friendly middle ground); "Marketing" is pre-unchecked by default
- If GPC is detected, both Analytics and Marketing default to unchecked
- "Save Preferences" button collects checked categories
- Builds consent object and calls `saveConsent()`

#### Save Consent (`saveConsent()`)
```
1. Add timestamp: consent.timestamp = Date.now()
2. Save to localStorage: localStorage.setItem('gliffen_consent', JSON.stringify(consent))
3. Update global: window.glifCookieConsent = consent
4. Log to server: this.logConsentToServer(consent)  [AJAX call]
5. Trigger tracking re-init: this.triggerTrackingReinit()
6. Remove banner from DOM
```

#### **CRITICAL: Tracking Re-initialization** (`triggerTrackingReinit()`)

Runs both when a decision already exists AND on first load with the default (opt-in/GPC) consent, so tracking is active immediately rather than waiting for a click:

```javascript
// gtag/dataLayer are defined by the wp_head interceptor even when GA is only
// loaded via GTM (no direct gtag.js), so this always works
window.dataLayer = window.dataLayer || [];
var gtag = window.gtag || function() { dataLayer.push(arguments); };
var consent = this.consent || {};

// Analytics: explicit granted/denied is always pushed (not just when granted),
// so revoking consent mid-session is honored too
gtag('consent', 'update', {
    'analytics_storage': consent.analytics === true ? 'granted' : 'denied'
});
if (consent.analytics === true) {
    gtag('config', trackingId, { 'anonymize_ip': true });
}

// Meta Pixel
if (window.fbq) {
    if (consent.marketing === true) {
        fbq('consent', 'grant');
        fbq('track', 'PageView');
    } else {
        fbq('consent', 'revoke');
    }
}

// Google Ads / conversion tracking - same explicit granted/denied pattern
gtag('consent', 'update', {
    'ad_storage': consent.marketing === true ? 'granted' : 'denied',
    'ad_user_data': consent.marketing === true ? 'granted' : 'denied',
    'ad_personalization': consent.marketing === true ? 'granted' : 'denied'
});

// Custom event for other services
document.dispatchEvent(new CustomEvent('gliffen-consent-granted', { detail: this.consent }));
```

**Why this works**:
- A `gtag`/`dataLayer` shim is defined in `<head>` before GTM's container script loads, so Consent Mode signals reach GTM even when GA is only installed as a GTM tag (no direct `gtag.js`)
- Consent updates are pushed explicitly in both directions (granted AND denied), not just when granting, so switching from Accept to Reject mid-session actually revokes tracking
- Cookies are allowed/blocked live via `window.glifCookieConsent`, so no page reload is needed either way

#### Consent Logging (`logConsentToServer()`)
- Makes AJAX POST to `wp_ajax_gliffen_save_consent`
- Sends action + JSON consent object
- Currently just logs, doesn't fail if error occurs

---

### 6. Frontend Styling (`public/assets/css/consent-banner.css`)

**Key CSS classes**:

- `#gliffen-cookie-banner`: Main container (fixed position, z-index: 9999)
- `.gliffen-consent-banner-content`: Flex container for content + buttons
- `.gliffen-consent-btn`: Base button styling
- `.gliffen-primary`: Primary (Accept All) button with accent color
- `.gliffen-consent-modal`: Modal overlay (z-index: 10000)
- `.gliffen-consent-modal-content`: Modal box
- `.gliffen-consent-category`: Category checkbox group

**Responsive**: Flexes to column layout on mobile (< 768px)

**Color Application**: Inline `<style>` tag created in JavaScript applies admin colors

---

## 🍪 Cookie Interception: Deep Dive

### The Problem
Third-party scripts (GA, Meta) execute and try to set cookies immediately:
```javascript
// Inside Google Analytics script
document.cookie = "_ga=GA1.2.123456789";  // Wants to set cookie
```

Without our interceptor, this would always succeed, even with no consent.

### The Solution

We inject an interceptor in `<head>` BEFORE external scripts load:

```javascript
// 1. Save original cookie descriptor
var originalDescriptor = Object.getOwnPropertyDescriptor(Document.prototype, 'cookie');

// 2. Override document.cookie setter
Object.defineProperty(Document.prototype, 'cookie', {
    set: function(value) {
        // value = "_ga=GA1.2.123456789"
        var cookieName = value.split('=')[0].trim();  // "_ga"
        
        // Get this cookie's category
        var category = getCookieCategory(cookieName);  // "analytics"
        
        // Check user consent
        if (!isAllowedByConsent(cookieName, userConsent)) {
            console.debug('Blocked: ' + cookieName);
            return;  // Don't set the cookie
        }
        
        // User consented - allow it
        originalDescriptor.set.call(this, value);
    },
    get: originalDescriptor.get
});
```

### Cookie-to-Category Mapping

Pre-configured in PHP default settings:

```php
'cookies' => array(
    '_ga' => 'analytics',
    '_gat' => 'analytics',
    '_gid' => 'analytics',
    '_fbp' => 'marketing',
    'fr' => 'marketing',
    'IDE' => 'marketing',
    'ANID' => 'marketing',
    'NID' => 'necessary',
)
```

Passed to JavaScript as JSON: `var cookieRegistry = <?php echo json_encode(...) ?>`

**Lookup logic**:
1. Exact match: `cookieRegistry['_ga']` → 'analytics'
2. Partial match: Check if '_ga' is a substring (e.g., '_gat' matches '_ga' pattern)
3. Default: 'necessary' if not found

### User Consent Structure

localStorage key: `gliffen_consent`

```json
{
    "necessary": true,
    "analytics": false,
    "marketing": true,
    "timestamp": 1234567890000
}
```

**Check**: 
```javascript
var allowed = userConsent['analytics'] === true;  // false in example
```

If this key doesn't exist yet (no decision made), the effective consent used for cookies and Consent Mode is computed on the fly instead of read from storage - see "Consent Defaults & Regional Behavior" below.

---

## 🌎 Consent Defaults & Regional Behavior

This plugin defaults to a **US opt-in model**: tracking is allowed until the user says otherwise, with two overrides.

### 1. Global Privacy Control (GPC)
- Checked via `navigator.globalPrivacyControl === true` in both the `wp_head` interceptor (PHP) and `consent-banner.js` (JS), so they agree before either loads a tag.
- If GPC is present **and no explicit decision has been saved yet**, the default flips from opt-in to opt-out (`analytics`/`marketing` = `false`).
- GPC only affects the *default* used before a decision exists - it does not retroactively override a previously saved "Accept All".

### 2. EU heuristic (`isLikelyEU()`)
- Checks `Intl.DateTimeFormat().resolvedOptions().timeZone` against common EU time zone prefixes (`Europe/`, `Atlantic/Azores`, `Atlantic/Canary`) and `navigator.languages` against a list of EU language codes.
- Used **only** to decide whether the "Reject All" button is rendered on the banner. It does not change the default consent value itself.
- Visitors who don't match either signal only see "Accept All" and "Customize" - they can still opt out entirely via Customize.

### 3. Effective default consent (no decision yet)
| Signal | Necessary | Analytics | Marketing | Reject All button |
|---|---|---|---|---|
| No GPC, not likely EU | true | true | true | hidden |
| No GPC, likely EU | true | true | true | shown |
| GPC detected | true | false | false | per EU heuristic |

### 4. Customize modal defaults
- Necessary: always checked, disabled
- Analytics: checked by default (unless GPC detected)
- Marketing: unchecked by default

---

## 🔄 User Journey & Session Timeline

### Session 1: First-Time Visitor (default US visitor, no GPC)

**Timeline**:
```
1. User visits page
2. <head> loads
   └─ Cookie interceptor injected (priority: runs FIRST)
   └─ cookieRegistry loaded from PHP
   └─ localStorage checked - EMPTY (no consent yet)
   └─ No GPC signal detected → default consent = {necessary: true, analytics: true, marketing: true}
   └─ gtag('consent', 'default', {analytics_storage: 'granted', ...}) pushed to dataLayer
   
3. GA script (direct or via GTM) loads
   └─ document.cookie = "_ga=..." attempted
   └─ Interceptor checks the default consent above → ALLOWED
   └─ GA tracks the page view immediately, no waiting for a click
   
4. Meta Pixel script loads
   └─ Similar flow, _fbp cookie ALLOWED by default
   
5. <body> renders, <footer> loads
   └─ Banner still rendered (no explicit decision saved yet)
   └─ User sees banner with Accept All + Customize (no Reject All - not likely EU)
   
6. User clicks "Accept All" (or ignores the banner - tracking already active)
   └─ Consent saved to localStorage: {necessary: true, analytics: true, marketing: true, timestamp}
   └─ window.glifCookieConsent updated
   └─ AJAX sent to server (audit log)
   └─ triggerTrackingReinit() re-confirms granted state to gtag/fbq
   └─ Banner removed
```

**Result**: 
- Cookies/tracking active from initial page load (opt-in model) ✅
- Banner still available for the user to opt out via Customize ✅
- GPC visitors instead default to denied until they explicitly opt in ✅

### Session 2: Return Visitor

**Timeline**:
```
1. User visits page
2. <head> loads
   └─ Cookie interceptor injected
   └─ localStorage checked - HAS consent from session 1
   └─ window.glifCookieConsent populated immediately
   
3. GA script loads
   └─ gtag() called
   └─ document.cookie = "_ga=..." attempted
   └─ Interceptor checks: analytics: true ✅
   └─ Cookie ALLOWED (localStorage says yes)
   └─ GA tracks this page
   
4. Banner check
   └─ get_user_consent() returns non-null
   └─ Banner NOT shown
   
5. Page continues normally with tracking
```

**Result**: 
- Consent remembered ✅
- Cookies set immediately ✅
- No banner shown ✅
- GA tracks from page load ✅

---

## 🔌 Extending the Plugin

### Adding a New Tracking Service

**Example: Hotjar**

1. **Add to default services** (in `gliffen-cookie-consent.php`):
```php
'services' => array(
    // ... existing services ...
    'hotjar' => array(
        'name' => 'Hotjar',
        'category' => 'analytics',
        'enabled' => false,
        'site_id' => ''
    )
)
```

2. **Add cookies to registry** (in `gliffen-cookie-consent.php`):
```php
'cookies' => array(
    // ... existing ...
    '_hjid' => 'analytics',
    '_hjTLDTest' => 'analytics',
)
```

3. **Update admin page** (in `admin/pages/settings.php`):
```php
<?php elseif ($service_id === 'hotjar') : ?>
    <div class="gliffen-service-field">
        <label for="hotjar_site_id">Hotjar Site ID</label>
        <input type="text" id="hotjar_site_id"
            name="gliffen_cookie_consent_settings[services][hotjar][site_id]"
            value="<?php echo esc_attr($service['site_id'] ?? ''); ?>">
    </div>
<?php endif; ?>
```

4. **Add re-init trigger** (in `public/assets/js/consent-banner.js`):
```javascript
// Hotjar
if (window.hj && this.consent && this.consent.analytics) {
    hj('identify', { consent: true });
}
```

---

### Handling Local Storage Issues

Current implementation uses **client-side only** consent storage (localStorage). To add server-side persistence:

1. **Enable consent logging in AJAX handler** (in `class-cookie-consent-manager.php`):
```php
public function handle_save_consent() {
    $consent = sanitize_text_field($_POST['consent']);
    $log = array(
        'timestamp' => current_time('mysql'),
        'consent' => $consent,
        'ip_address' => $this->get_client_ip(),
        'user_id' => get_current_user_id()
    );
    // Store in WP options as transient or custom table
    set_transient('gliffen_consent_' . get_current_user_id(), $log, 30 * DAY_IN_SECONDS);
}
```

2. **Create custom table** (optional, for audit logs):
```sql
CREATE TABLE wp_gliffen_consent_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    timestamp DATETIME,
    consent JSON,
    ip_address VARCHAR(45),
    user_id BIGINT,
    KEY (user_id),
    KEY (timestamp)
);
```

---

### JavaScript Events & Hooks

Custom event dispatched after consent granted:

```javascript
document.addEventListener('gliffen-consent-granted', function(e) {
    console.log('User consented:', e.detail);
    
    // e.detail = {
    //     necessary: true,
    //     analytics: false,
    //     marketing: true,
    //     preferences: true,
    //     timestamp: 1234567890
    // }
});
```

Use this to initialize custom tracking services not in the pre-configured list.

---

## 🐛 Troubleshooting & Known Limitations

### Known Limitations

1. **HTTP-Only Cookies**: Can't be intercepted by JavaScript. Server must handle these.
   - Solution: Add server-side middleware to prevent HTTP-only cookies without consent

2. **Service Workers**: If scripts are cached offline, the interceptor might not run first.
   - Solution: Version the service worker when consent logic changes

3. **Third-Party iframes**: Facebook/external iframes can't be controlled.
   - Solution: Sandbox iframes with restrictions

4. **Page Reload for Initial Tracking**: Not applicable for the default opt-in path (tracking starts immediately on first load); only relevant if GPC forces a denied default and the user later opts in via the banner - tracking activates same-session via `triggerTrackingReinit()`, no reload needed

5. **`isLikelyEU()` is a heuristic, not a legal determination**: Time zone and browser language can be spoofed or misleading (e.g., travelers, VPNs, multilingual users). It only controls whether the "Reject All" button is shown - EU visitors can still fully opt out via Customize even if misclassified.

### Debugging Checklist

**Banner not showing**:
- Check `enabled` setting
- Clear localStorage: `localStorage.clear()`
- Check console for JS errors
- Verify `get_user_consent()` returns null

**Cookies still being set after Reject All**:
- Verify cookie name in registry
- Check category mapping is correct
- Verify localStorage consent object is valid JSON
- Inspect Network tab for Set-Cookie headers

**Tracking not active by default for a new visitor**:
- Confirm `navigator.globalPrivacyControl` isn't unexpectedly `true` in the browser/devtools
- Check that `gtag('consent', 'default', ...)` is present in the page source (output by `output_cookie_interceptor()`)
- Verify `window.glifCookieConsent` reflects `{analytics: true, marketing: true}` before any decision is made

**Tracking not working after consent**:
- Verify `window.gtag` and `window.fbq` are defined (or that the `dataLayer` shim is present)
- Check service IDs are correct
- Confirm `gtag('consent', 'update', ...)` is being called with the expected granted/denied values
- Look for JavaScript errors in console

---

## 📊 Performance Considerations

**Plugin is lightweight**:
- ✅ Single inline `<script>` in head (~2KB minified)
- ✅ Single localStorage key (max ~5KB JSON)
- ✅ No database queries on frontend (all in wp_options)
- ✅ CSS is minimal (~3KB)
- ✅ JS is vanilla (no jQuery dependency on frontend)

**Potential optimizations**:
- Minify interceptor JS
- Cache banner HTML in plugin
- Use IndexedDB for large consent logs

---

## 🔐 Security Notes

**CSRF**: AJAX handler currently has no nonce verification. Add:
```php
check_ajax_referer('gliffen_consent_nonce');
```

**XSS**: All color inputs validated with `sanitize_hex_color()`. URLs with `esc_url_raw()`.

**SQL Injection**: Only uses wp_options (prepared by WordPress).

---

## 📝 Future Enhancements

**Phase 2**:
- [ ] Ability to manually add/edit cookies in admin
- [ ] Consent audit log viewer
- [ ] Bulk consent import/export

**Phase 3**:
- [ ] Consent revocation UI
- [ ] Email notification of policy changes
- [ ] A/B testing consent banner layouts

**Phase 4**:
- [ ] CCPA-specific compliance features
- [ ] Custom category management
- [ ] ConsentBase/OneTrust integration

---

## 📚 File Reference Quick Index

| File | Lines | Purpose |
|------|-------|---------|
| `gliffen-cookie-consent.php` | ~150 | Main plugin bootstrap |
| `includes/class-cookie-consent-manager.php` | ~200 | Frontend manager |
| `includes/class-admin-settings.php` | ~150 | Admin manager |
| `admin/pages/settings.php` | ~300 | Admin page template |
| `public/assets/js/consent-banner.js` | ~350 | Banner + tracking re-init |
| `public/assets/css/consent-banner.css` | ~200 | Banner styling |

**Total size**: ~1,350 lines of code

---

## 🎓 Key Takeaways for Next Developer

1. **The interceptor is the heart** - It runs in `<head>` via `wp_head` hook at priority 1. This must happen before external scripts load. It now also computes the default consent (opt-in, or opt-out if GPC) and pushes `gtag('consent', 'default', ...)` before GTM's container script loads.

2. **Consent is localStorage-based** - Simple `localStorage.getItem('gliffen_consent')` returns JSON, or `null` if no decision has been made yet. When `null`, both PHP and JS independently compute the same default (opt-in unless GPC) rather than treating "undecided" as "denied."

3. **Tracking re-init always pushes both directions** - `triggerTrackingReinit()` calls `gtag()`/`fbq()` with explicit `granted`/`denied` values every time consent changes (not just when granting), so switching from Accept to Reject mid-session actually revokes tracking, and GTM-only installs (no direct `gtag.js`) still receive the signal via a `dataLayer` shim.

4. **The "Reject All" button is conditional** - Only rendered when `isLikelyEU()` returns true. All visitors can still fully opt out through "Customize" regardless.

5. **All settings in one wp_options key** - No custom tables. Makes plugin portable and WordPress-native. Structure is: `gliffen_cookie_consent_settings => array of everything`.

6. **Admin page is standard WordPress** - Uses `register_setting()`, tabbed interface in template. Color picker via WordPress native `wpColorPicker()`.

7. **Pre-configured services simplify setup** - Admin doesn't need to understand cookie names; just enable GA/Meta and add tracking ID. Plugin handles the rest.

---

## 📧 Support

For modifications or issues:
- Check the troubleshooting section above
- Review console logs for JavaScript errors  
- Verify settings saved correctly in wp_options
- Check WordPress debug.log for PHP errors

---

## License

GPL v2 or later
