/**
 * PillPalNow WebView Utilities
 * Handles WebView detection, compatibility, and app communication
 * 
 * @package PillPalNow
 */
(function () {
  'use strict';

  const PillPalNowWebView = {

    /**
     * Detect if running inside WebView
     * Uses multiple detection methods for reliability
     */
    isWebView: function () {
      const ua = navigator.userAgent || '';

      // Primary detection methods
      if (
        ua.includes('AppMySite') ||
        ua.includes('wv') ||
        ua.includes('WebView') ||
        (ua.includes('Android') && ua.includes('Version/')) ||
        document.cookie.includes('pillpalnow_webview=1') ||
        new URLSearchParams(window.location.search).has('webview')
      ) {
        return true;
      }

      // Check for AppMySite specific characteristics
      // AppMySite apps typically load in a wrapper with specific behaviors
      if (
        // AppMySite referrer pattern
        (document.referrer && document.referrer.includes('appmysite'))
      ) {
        return true;
      }

      return false;
    },

    /**
     * Check for AppMySite banner element (DOM-based detection)
     * Runs after DOM is available
     */
    hasAppMySiteBanner: function () {
      // Look for the AppMySite banner text
      const bannerText = 'This app was built on AppMySite';
      return document.body && document.body.innerHTML.includes(bannerText);
    },

    /**
     * Initialize WebView mode
     */
    init: function () {
      const isWebViewDetected = this.isWebView();
      const hasBanner = this.hasAppMySiteBanner();

      if (!isWebViewDetected && !hasBanner) return;

      // Add WebView class to body
      document.body.classList.add('webview-mode');

      // Set cookie for server-side detection on subsequent loads
      document.cookie = 'pillpalnow_webview=1; path=/; max-age=31536000; SameSite=Lax';

      // Apply WebView fixes
      this.applyFixes();
      this.setupNetworkMonitor();
      this.preventUnsupportedActions();

      console.log('[PillPalNow] WebView mode initialized');
    },


    /**
     * Apply WebView-specific fixes
     */
    applyFixes: function () {
      // Prevent double-tap zoom
      let lastTap = 0;
      document.addEventListener('touchend', function (e) {
        const now = Date.now();
        if (now - lastTap < 300) {
          e.preventDefault();
        }
        lastTap = now;
      }, { passive: false });

      // Fix 300ms tap delay
      document.addEventListener('touchstart', function () { }, { passive: true });

      // Prevent pull-to-refresh on overscroll (handled by app)
      // document.body.style.overscrollBehavior = 'none';

      // Fix viewport height for mobile browsers
      this.fixViewportHeight();
      // Fix viewport height for mobile browsers
      this.fixViewportHeight();
      window.addEventListener('resize', this.fixViewportHeight.bind(this));

      // Enable drag-to-scroll for horizontal lists (desktop mouse compatibility)
      this.enableDragScroll();
    },

    /**
     * Enable drag-to-scroll for horizontal containers (desktop mouse support)
     */
    enableDragScroll: function () {
      const sliders = document.querySelectorAll('.overflow-x-auto');
      sliders.forEach(slider => {
        let isDown = false;
        let startX;
        let scrollLeft;

        // Set initial cursor
        slider.style.cursor = 'grab';

        slider.addEventListener('mousedown', (e) => {
          isDown = true;
          startX = e.pageX - slider.offsetLeft;
          scrollLeft = slider.scrollLeft;
          slider.style.cursor = 'grabbing';
        });

        slider.addEventListener('mouseleave', () => {
          isDown = false;
          slider.style.cursor = 'grab';
        });

        slider.addEventListener('mouseup', () => {
          isDown = false;
          slider.style.cursor = 'grab';
        });

        slider.addEventListener('mousemove', (e) => {
          if (!isDown) return;
          e.preventDefault();
          const x = e.pageX - slider.offsetLeft;
          const walk = (x - startX) * 2; // Scroll-fast multiplier
          slider.scrollLeft = scrollLeft - walk;
        });
      });
    },

    /**
     * Fix viewport height (100vh issue on mobile)
     */
    fixViewportHeight: function () {
      const vh = window.innerHeight * 0.01;
      document.documentElement.style.setProperty('--vh', vh + 'px');
    },

    /**
     * Monitor network status
     */
    setupNetworkMonitor: function () {
      const updateOnlineStatus = function () {
        document.body.classList.toggle('offline', !navigator.onLine);
      };

      window.addEventListener('online', updateOnlineStatus);
      window.addEventListener('offline', updateOnlineStatus);
      updateOnlineStatus();
    },

    /**
     * Prevent actions that don't work in WebView
     */
    preventUnsupportedActions: function () {
      const self = this;

      // Override window.open to prevent new tabs
      const originalOpen = window.open;
      window.open = function (url, target, features) {
        // Allow Stripe URLs to open normally (needed for 3D Secure / Payment Auth)
        if (url && (url.includes('stripe.com') || url.includes('hooks.stripe.com'))) {
          return originalOpen.apply(this, arguments);
        }

        if (url && !url.startsWith('javascript:')) {
          // Navigate in same window instead
          window.location.href = url;
          return null;
        }
        return originalOpen.apply(this, arguments);
      };

      // Override alert with toast
      const originalAlert = window.alert;
      window.alert = function (message) {
        if (typeof self.showToast === 'function') {
          self.showToast(message, 'info');
        } else {
          originalAlert.call(window, message);
        }
      };

      // Prevent confirm dialogs (return true by default)
      window.confirm = function (message) {
        console.warn('[WebView] confirm() called:', message);
        return true;
      };

      // Prevent prompt dialogs
      window.prompt = function (message, defaultValue) {
        console.warn('[WebView] prompt() called:', message);
        return defaultValue || '';
      };
    },

    /**
     * Show toast notification (WebView-friendly alternative to alert)
     */
    showToast: function (message, type) {
      type = type || 'info';

      var container = document.querySelector('.toast-container');
      if (!container) {
        container = document.createElement('div');
        container.className = 'toast-container';
        document.body.appendChild(container);
      }

      var toast = document.createElement('div');
      toast.className = 'toast toast-' + type;
      toast.textContent = message;
      container.appendChild(toast);

      requestAnimationFrame(function () {
        toast.classList.add('show');
      });

      setTimeout(function () {
        toast.classList.remove('show');
        setTimeout(function () {
          toast.remove();
        }, 300);
      }, 3000);
    },

    /**
     * Send message to native app (if supported)
     */
    sendToApp: function (action, data) {
      data = data || {};
      var message = { action: action, data: data, timestamp: Date.now() };

      // iOS (WKWebView)
      if (window.webkit && window.webkit.messageHandlers && window.webkit.messageHandlers.pillPalNow) {
        window.webkit.messageHandlers.pillPalNow.postMessage(message);
        return true;
      }

      // Android
      if (window.PillPalNowAndroid) {
        window.PillPalNowAndroid.postMessage(JSON.stringify(message));
        return true;
      }

      // AppMySite bridge
      if (window.AppMySite) {
        window.AppMySite.postMessage(JSON.stringify(message));
        return true;
      }

      return false;
    },

    /**
     * API fetch wrapper with timeout and error handling
     */
    fetch: function (endpoint, options) {
      var self = this;
      options = options || {};

      var controller = new AbortController();
      var timeoutId = setTimeout(function () {
        controller.abort();
      }, 15000);

      var fetchOptions = Object.assign({}, options, {
        signal: controller.signal,
        headers: Object.assign({
          'Content-Type': 'application/json'
        }, options.headers || {})
      });

      // Add nonce if available
      if (window.pillpalnow_vars && window.pillpalnow_vars.nonce) {
        fetchOptions.headers['X-WP-Nonce'] = window.pillpalnow_vars.nonce;
      }

      return fetch(endpoint, fetchOptions)
        .then(function (response) {
          clearTimeout(timeoutId);

          if (!response.ok) {
            throw new Error('HTTP ' + response.status);
          }

          return response.json();
        })
        .catch(function (error) {
          clearTimeout(timeoutId);

          if (error.name === 'AbortError') {
            self.showToast('Request timed out. Please try again.', 'warning');
            throw new Error('Request timed out');
          }

          if (!navigator.onLine) {
            self.showToast("You're offline. Please check your connection.", 'warning');
            throw new Error('No internet connection');
          }

          throw error;
        });
    }
  };

  // Initialize on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      PillPalNowWebView.init();
    });
  } else {
    PillPalNowWebView.init();
  }

  // Expose globally
  window.PillPalNowWebView = PillPalNowWebView;

})();
