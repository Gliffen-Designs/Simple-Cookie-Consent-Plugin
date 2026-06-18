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
            // Check if user has already made a consent decision
            this.consent = JSON.parse(localStorage.getItem('gliffen_consent') || 'null');

            // Setup cookie consent link handler (to re-show banner)
            this.setupCookieConsentLink();

            // If consent already decided, trigger re-initialization
            if (this.consent !== null) {
                this.triggerTrackingReinit();
                return;
            }

            // Show banner if no consent decision made
            this.createBanner();
            this.attachEvents();
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
                            <button class="gliffen-consent-btn gliffen-consent-reject">Reject All</button>
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

            // Reject All (except necessary)
            this.banner.querySelector('.gliffen-consent-reject').addEventListener('click', function() {
                self.saveConsent({
                    necessary: true,
                    analytics: false,
                    marketing: false
                });
            });

            // Customize
            this.banner.querySelector('.gliffen-consent-customize').addEventListener('click', function() {
                self.showCustomizeModal();
            });
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
                var checked = cat.always_enabled ? 'checked' : '';

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

            // Update global
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

            // Google Analytics
            if (window.gtag && this.consent && this.consent.analytics) {
                gtag('consent', 'update', {
                    'analytics_storage': 'granted'
                });
                var analyticsId = this.settings.analyticsId || this.getAnalyticsId();
                if (analyticsId) {
                    gtag('config', analyticsId, {
                        'anonymize_ip': true
                    });
                }
            }

            // Meta Pixel
            if (window.fbq && this.consent && this.consent.marketing) {
                fbq('consent', 'grant');
                // Optionally re-track page view
                fbq('track', 'PageView');
            }

            // Google Ads / Conversion Tracking
            if (window.gtag && this.consent && this.consent.marketing) {
                gtag('consent', 'update', {
                    'ad_storage': 'granted',
                    'ad_user_data': 'granted',
                    'ad_personalization': 'granted'
                });
            }

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
