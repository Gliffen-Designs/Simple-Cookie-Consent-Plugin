/**
 * Simple Cookie Consent Plugin Banner
 * Handles user consent UI and interaction
 */

(function() {
    'use strict';

    var GlifCookieConsentBanner = {
        settings: window.glifCookieSettings || {},
        consent: null,
        banner: null,

        init: function() {
            this.gpcEnabled = this.detectGPC();
            this.isEU = this.isLikelyEU();

            // Check if user has already made a consent decision
            this.consent = JSON.parse(localStorage.getItem('gliffen_consent') || 'null');

            // Setup cookie consent link handler (to re-show banner)
            this.setupCookieConsentLink();

            // If consent already decided, trigger re-initialization
            if (this.consent !== null) {
                this.triggerTrackingReinit();
                return;
            }

            // No explicit decision yet: opt-in by default (US model) unless GPC signals opt-out,
            // so tracking works immediately while the banner is still shown for the user's choice
            this.consent = this.getDefaultConsent();
            window.glifCookieConsent = this.consent;
            this.triggerTrackingReinit();

            this.createBanner();
            this.attachEvents();
        },

        // Global Privacy Control: a browser/extension signal requesting opt-out of tracking
        detectGPC: function() {
            return typeof navigator !== 'undefined' && navigator.globalPrivacyControl === true;
        },

        // Heuristic used only to decide whether to surface the "Reject All" button
        isLikelyEU: function() {
            try {
                var languages = navigator.languages || [navigator.language || ''];
                var timeZone = Intl.DateTimeFormat().resolvedOptions().timeZone || '';

                var euZones = ['Europe/', 'Atlantic/Azores', 'Atlantic/Canary'];
                var isEUTimeZone = euZones.some(function(zone) {
                    return timeZone.indexOf(zone) === 0;
                });

                var euLanguages = ['fr', 'de', 'it', 'es', 'nl', 'pl', 'pt', 'sv', 'da', 'fi', 'ro', 'cs', 'hu', 'sk', 'bg', 'lt', 'lv', 'et', 'sl', 'mt', 'cy', 'ga', 'hr'];
                var hasEULanguage = languages.some(function(lang) {
                    return euLanguages.some(function(euLang) {
                        return (lang || '').toLowerCase().indexOf(euLang) === 0;
                    });
                });

                return isEUTimeZone || hasEULanguage;
            } catch (e) {
                return false;
            }
        },

        // US-compliant default: opt-in for everyone, unless GPC requests opt-out
        getDefaultConsent: function() {
            if (this.gpcEnabled) {
                return { necessary: true, analytics: false, marketing: false };
            }
            return { necessary: true, analytics: true, marketing: true };
        },

        setupCookieConsentLink: function() {
            var self = this;
            // Find all anchor links pointing to #cookie-consent
            document.addEventListener('click', function(e) {
                if (e.target.tagName === 'A' && e.target.getAttribute('href') === '#cookie-consent') {
                    e.preventDefault();
                    self.resetConsentAndShowBanner();
                }
            });
        },

        resetConsentAndShowBanner: function() {
            // Clear existing banner if visible
            var existingBanner = document.getElementById('gliffen-cookie-banner');
            if (existingBanner) {
                existingBanner.remove();
            }

            // Clear existing modal if visible
            var existingModal = document.getElementById('gliffen-consent-modal');
            if (existingModal) {
                existingModal.remove();
            }

            // Show the banner again
            this.createBanner();
            this.attachEvents();
        },

        createBanner: function() {
            var html = `
                <div id="gliffen-cookie-banner" class="gliffen-consent-banner gliffen-${this.settings.bannerPosition || 'bottom'}">
                    <div class="gliffen-consent-banner-content">
                        <div class="gliffen-consent-banner-text">
                            <h3>Cookie Consent</h3>
                            <p>We use cookies to enhance your experience and analyze our traffic. By clicking "Accept", you consent to our use of cookies.</p>
                            ${this.settings.privacyUrl ? `<a href="${this.settings.privacyUrl}" target="_blank">Privacy Policy</a>` : ''}
                            ${this.settings.cookieUrl ? `<a href="${this.settings.cookieUrl}" target="_blank">Cookie Policy</a>` : ''}
                        </div>
                        <div class="gliffen-consent-banner-buttons">
                            ${this.isEU ? '<button class="gliffen-consent-btn gliffen-consent-reject">Reject All</button>' : ''}
                            <button class="gliffen-consent-btn gliffen-consent-customize">Customize</button>
                            <button class="gliffen-consent-btn gliffen-consent-accept gliffen-primary">Accept All</button>
                        </div>
                    </div>
                </div>
            `;

            var container = document.createElement('div');
            container.innerHTML = html;
            this.banner = container.firstElementChild;

            // Apply colors
            var style = document.createElement('style');
            style.textContent = `
                #gliffen-cookie-banner {
                    background-color: ${this.settings.bannerBgColor};
                    color: ${this.settings.bannerTextColor};
                }
                #gliffen-cookie-banner .gliffen-consent-accept {
                    background-color: ${this.settings.buttonBgColor};
                    color: ${this.settings.buttonTextColor};
                }
            `;
            document.head.appendChild(style);

            document.body.appendChild(this.banner);
        },

        attachEvents: function() {
            var self = this;

            // Accept All
            this.banner.querySelector('.gliffen-consent-accept').addEventListener('click', function() {
                self.saveConsent({
                    necessary: true,
                    analytics: true,
                    marketing: true
                });
            });

            // Reject All (except necessary) - only rendered for likely-EU visitors
            var rejectBtn = this.banner.querySelector('.gliffen-consent-reject');
            if (rejectBtn) {
                rejectBtn.addEventListener('click', function() {
                    self.saveConsent({
                        necessary: true,
                        analytics: false,
                        marketing: false
                    });
                });
            }

            // Customize
            this.banner.querySelector('.gliffen-consent-customize').addEventListener('click', function() {
                self.showCustomizeModal();
            });
        },

        // Pre-checked state for the customize modal: analytics on, marketing off by default,
        // so it takes one extra click to fully opt out while marketing stays opt-in-only
        getDefaultCategoryChecked: function(category, cat) {
            if (cat.always_enabled) {
                return true;
            }
            if (this.gpcEnabled) {
                return false;
            }
            return category === 'analytics';
        },

        showCustomizeModal: function() {
            var self = this;

            var modalHtml = `
                <div id="gliffen-consent-modal" class="gliffen-consent-modal">
                    <div class="gliffen-consent-modal-content">
                        <span class="gliffen-consent-modal-close">&times;</span>
                        <h2>Cookie Preferences</h2>
                        <p>Select which cookies you allow:</p>
                        <div class="gliffen-consent-preferences">
            `;

            // Add category toggles
            for (var category in this.settings.categories) {
                var cat = this.settings.categories[category];
                var disabled = cat.always_enabled ? 'disabled' : '';
                var checked = this.getDefaultCategoryChecked(category, cat) ? 'checked' : '';

                modalHtml += `
                    <div class="gliffen-consent-category">
                        <label>
                            <input type="checkbox" name="category_${category}" value="${category}" ${checked} ${disabled}>
                            <strong>${cat.name}</strong>
                        </label>
                        <p>${cat.description}</p>
                    </div>
                `;
            }

            modalHtml += `
                        </div>
                        <div class="gliffen-consent-modal-buttons">
                            <button class="gliffen-consent-btn gliffen-consent-modal-save">Save Preferences</button>
                        </div>
                    </div>
                </div>
            `;

            var container = document.createElement('div');
            container.innerHTML = modalHtml;
            var modal = container.firstElementChild;

            document.body.appendChild(modal);

            // Close modal
            modal.querySelector('.gliffen-consent-modal-close').addEventListener('click', function() {
                modal.remove();
            });

            // Save preferences
            modal.querySelector('.gliffen-consent-modal-save').addEventListener('click', function() {
                var consent = {
                    necessary: true // Always true
                };

                // Get checked categories
                var checkboxes = modal.querySelectorAll('input[type="checkbox"]');
                checkboxes.forEach(function(cb) {
                    var category = cb.name.replace('category_', '');
                    consent[category] = cb.checked;
                });

                self.saveConsent(consent);
                modal.remove();
            });
        },

        saveConsent: function(consentObject) {
            // Add timestamp
            consentObject.timestamp = Date.now();

            // Save to localStorage
            localStorage.setItem('gliffen_consent', JSON.stringify(consentObject));

            // Keep local state in sync so triggerTrackingReinit sees the new choices
            this.consent = consentObject;

            // Update global (read live by the cookie interceptor in wp_head)
            window.glifCookieConsent = consentObject;

            // Log to server (optional)
            this.logConsentToServer(consentObject);

            // Trigger tracking re-initialization
            this.triggerTrackingReinit();

            // Remove banner
            if (this.banner) {
                this.banner.remove();
            }
        },

        triggerTrackingReinit: function() {
            // Manually trigger tracking services to re-initialize with consent

            // gtag/dataLayer are defined by the wp_head interceptor even when GA is only
            // loaded via GTM (no direct gtag.js), so push consent updates unconditionally
            window.dataLayer = window.dataLayer || [];
            var gtag = window.gtag || function() { dataLayer.push(arguments); };
            var consent = this.consent || {};

            // Always push an explicit granted/denied update so revoking consent mid-session is honored, not just granting it
            var analyticsGranted = consent.analytics === true;
            gtag('consent', 'update', {
                'analytics_storage': analyticsGranted ? 'granted' : 'denied'
            });
            if (analyticsGranted) {
                var analyticsId = this.settings.analyticsId || this.getAnalyticsId();
                if (analyticsId) {
                    gtag('config', analyticsId, {
                        'anonymize_ip': true
                    });
                }
            }

            // Meta Pixel
            if (window.fbq) {
                if (consent.marketing === true) {
                    fbq('consent', 'grant');
                    // Optionally re-track page view
                    fbq('track', 'PageView');
                } else {
                    fbq('consent', 'revoke');
                }
            }

            // Google Ads / Conversion Tracking
            var marketingGranted = consent.marketing === true;
            gtag('consent', 'update', {
                'ad_storage': marketingGranted ? 'granted' : 'denied',
                'ad_user_data': marketingGranted ? 'granted' : 'denied',
                'ad_personalization': marketingGranted ? 'granted' : 'denied'
            });

            // Dispatch custom event for other services
            var event = new CustomEvent('gliffen-consent-granted', {
                detail: this.consent
            });
            document.dispatchEvent(event);
        },

        getAnalyticsId: function() {
            // First check if we have it from PHP settings
            if (this.settings.analyticsId) {
                return this.settings.analyticsId;
            }
            
            // Fallback: Extract GA ID from window.dataLayer if available
            if (window.dataLayer && window.dataLayer[0]) {
                for (var key in window.dataLayer[0]) {
                    if (key.indexOf('GA_') === 0 || key.indexOf('G-') === 0) {
                        return window.dataLayer[0][key];
                    }
                }
            }
            
            return ''; // Return empty string if not found
        },

        logConsentToServer: function(consentObject) {
            // Send consent to server via AJAX
            var data = new FormData();
            data.append('action', 'gliffen_save_consent');
            data.append('consent', JSON.stringify(consentObject));

            fetch(this.settings.ajaxUrl, {
                method: 'POST',
                body: data
            }).catch(function(error) {
                console.error('Error logging consent:', error);
            });
        }
    };

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            GlifCookieConsentBanner.init();
        });
    } else {
        GlifCookieConsentBanner.init();
    }

    // Expose to global
    window.GlifCookieConsentBanner = GlifCookieConsentBanner;
})();
