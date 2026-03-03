/**
 * PillPalNow API Helper
 * Handles API requests with caching, loading states, and error handling
 * 
 * @package PillPalNow
 */
(function () {
    'use strict';

    const PillPalNowAPI = {

        // Cache configuration
        cache: {
            data: {},
            ttl: 5 * 60 * 1000, // 5 minutes default TTL

            set: function (key, value, customTtl) {
                const ttl = customTtl || this.ttl;
                this.data[key] = {
                    value: value,
                    expires: Date.now() + ttl
                };

                // Also store in localStorage for offline access
                try {
                    localStorage.setItem('pillpalnow_cache_' + key, JSON.stringify({
                        value: value,
                        expires: Date.now() + ttl
                    }));
                } catch (e) {
                    console.warn('[PillPalNowAPI] localStorage not available');
                }
            },

            get: function (key) {
                // Check memory cache first
                if (this.data[key] && this.data[key].expires > Date.now()) {
                    return this.data[key].value;
                }

                // Check localStorage
                try {
                    const stored = localStorage.getItem('pillpalnow_cache_' + key);
                    if (stored) {
                        const parsed = JSON.parse(stored);
                        if (parsed.expires > Date.now()) {
                            // Restore to memory cache
                            this.data[key] = parsed;
                            return parsed.value;
                        } else {
                            // Expired, remove
                            localStorage.removeItem('pillpalnow_cache_' + key);
                        }
                    }
                } catch (e) {
                    // Ignore localStorage errors
                }

                return null;
            },

            remove: function (key) {
                delete this.data[key];
                try {
                    localStorage.removeItem('pillpalnow_cache_' + key);
                } catch (e) { }
            },

            clear: function () {
                this.data = {};
                try {
                    Object.keys(localStorage).forEach(function (key) {
                        if (key.startsWith('pillpalnow_cache_')) {
                            localStorage.removeItem(key);
                        }
                    });
                } catch (e) { }
            }
        },

        // Request queue for offline mode
        offlineQueue: [],

        /**
         * Initialize API helper
         */
        init: function () {
            // Process offline queue when back online
            window.addEventListener('online', this.processOfflineQueue.bind(this));
        },

        /**
         * Get base URL and nonce
         */
        getConfig: function () {
            return {
                baseUrl: (window.pillpalnow_vars && window.pillpalnow_vars.rest_url) || '/wp-json/pillpalnow/v1/',
                nonce: (window.pillpalnow_vars && window.pillpalnow_vars.nonce) || '',
                ajaxUrl: (window.pillpalnow_vars && window.pillpalnow_vars.ajax_url) || '/wp-admin/admin-ajax.php'
            };
        },

        /**
         * Show loading state on element
         */
        showLoading: function (element, type) {
            type = type || 'spinner';

            if (!element) return;

            element.classList.add('is-loading');
            element.setAttribute('data-original-content', element.innerHTML);

            if (type === 'spinner') {
                element.innerHTML = '<span class="loading-spinner"></span>';
            } else if (type === 'skeleton') {
                element.innerHTML = '<div class="loading-skeleton" style="height: 1em; width: 100%;"></div>';
            } else if (type === 'overlay') {
                var overlay = document.createElement('div');
                overlay.className = 'loading-overlay';
                overlay.innerHTML = '<span class="loading-spinner"></span>';
                element.style.position = 'relative';
                element.appendChild(overlay);
            }
        },

        /**
         * Hide loading state on element
         */
        hideLoading: function (element) {
            if (!element) return;

            element.classList.remove('is-loading');

            var overlay = element.querySelector('.loading-overlay');
            if (overlay) {
                overlay.remove();
            }

            var originalContent = element.getAttribute('data-original-content');
            if (originalContent && element.querySelector('.loading-spinner, .loading-skeleton')) {
                element.innerHTML = originalContent;
            }

            element.removeAttribute('data-original-content');
        },

        /**
         * Show error state
         */
        showError: function (element, message) {
            if (!element) return;

            this.hideLoading(element);
            element.classList.add('has-error');

            var errorDiv = document.createElement('div');
            errorDiv.className = 'api-error-message';
            errorDiv.innerHTML = '<span class="error-icon">⚠️</span> ' + (message || 'Something went wrong');

            // Add retry button
            var retryBtn = document.createElement('button');
            retryBtn.className = 'btn btn-secondary btn-sm mt-2';
            retryBtn.textContent = 'Retry';
            retryBtn.onclick = function () {
                element.classList.remove('has-error');
                errorDiv.remove();
                // Will need to be bound to retry function
            };
            errorDiv.appendChild(retryBtn);

            element.appendChild(errorDiv);
        },

        /**
         * Main fetch wrapper with all features
         */
        fetch: function (endpoint, options) {
            var self = this;
            options = options || {};

            var config = this.getConfig();
            var url = endpoint.startsWith('http') ? endpoint : config.baseUrl + endpoint;
            var cacheKey = options.cacheKey || url;
            var useCache = options.cache !== false;
            var timeout = options.timeout || 15000;
            var loadingElement = options.loadingElement;
            var loadingType = options.loadingType || 'spinner';

            // Check cache first (for GET requests)
            if (useCache && (!options.method || options.method === 'GET')) {
                var cached = this.cache.get(cacheKey);
                if (cached) {
                    return Promise.resolve(cached);
                }
            }

            // Check if offline
            if (!navigator.onLine) {
                // Return cached data if available (even if expired)
                var offlineData = this.cache.get(cacheKey);
                if (offlineData) {
                    if (window.PillPalNowWebView) {
                        PillPalNowWebView.showToast('Showing cached data (offline)', 'warning');
                    }
                    return Promise.resolve(offlineData);
                }

                // Queue for later if it's a mutation
                if (options.method && options.method !== 'GET') {
                    this.offlineQueue.push({ endpoint: endpoint, options: options });
                    if (window.PillPalNowWebView) {
                        PillPalNowWebView.showToast('Action queued for when online', 'info');
                    }
                    return Promise.reject(new Error('Offline - action queued'));
                }

                return Promise.reject(new Error('No internet connection'));
            }

            // Show loading state
            if (loadingElement) {
                this.showLoading(loadingElement, loadingType);
            }

            // Create abort controller for timeout
            var controller = new AbortController();
            var timeoutId = setTimeout(function () {
                controller.abort();
            }, timeout);

            // Build fetch options
            var fetchOptions = {
                method: options.method || 'GET',
                headers: Object.assign({
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce
                }, options.headers || {}),
                signal: controller.signal
            };

            if (options.body) {
                fetchOptions.body = typeof options.body === 'string' ? options.body : JSON.stringify(options.body);
            }

            return fetch(url, fetchOptions)
                .then(function (response) {
                    clearTimeout(timeoutId);

                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status + ': ' + response.statusText);
                    }

                    return response.json();
                })
                .then(function (data) {
                    // Hide loading
                    if (loadingElement) {
                        self.hideLoading(loadingElement);
                    }

                    // Cache successful GET responses
                    if (useCache && (!options.method || options.method === 'GET')) {
                        self.cache.set(cacheKey, data, options.cacheTtl);
                    }

                    return data;
                })
                .catch(function (error) {
                    clearTimeout(timeoutId);

                    // Hide loading
                    if (loadingElement) {
                        self.hideLoading(loadingElement);
                    }

                    // Handle specific errors
                    if (error.name === 'AbortError') {
                        error = new Error('Request timed out');
                        if (window.PillPalNowWebView) {
                            PillPalNowWebView.showToast('Request timed out. Please try again.', 'warning');
                        }
                    } else if (!navigator.onLine) {
                        error = new Error('No internet connection');
                        if (window.PillPalNowWebView) {
                            PillPalNowWebView.showToast("You're offline", 'warning');
                        }
                    } else {
                        if (window.PillPalNowWebView) {
                            PillPalNowWebView.showToast('Something went wrong. Please try again.', 'error');
                        }
                    }

                    // Show error on element if provided
                    if (loadingElement && options.showError !== false) {
                        self.showError(loadingElement, error.message);
                    }

                    throw error;
                });
        },

        /**
         * GET request shorthand
         */
        get: function (endpoint, options) {
            options = options || {};
            options.method = 'GET';
            return this.fetch(endpoint, options);
        },

        /**
         * POST request shorthand
         */
        post: function (endpoint, data, options) {
            options = options || {};
            options.method = 'POST';
            options.body = data;
            options.cache = false; // Don't cache POST responses
            return this.fetch(endpoint, options);
        },

        /**
         * Process queued offline requests
         */
        processOfflineQueue: function () {
            var self = this;

            if (this.offlineQueue.length === 0) return;

            if (window.PillPalNowWebView) {
                PillPalNowWebView.showToast('Syncing ' + this.offlineQueue.length + ' queued action(s)...', 'info');
            }

            var queue = this.offlineQueue.slice();
            this.offlineQueue = [];

            queue.forEach(function (item) {
                self.fetch(item.endpoint, item.options).catch(function (err) {
                    console.error('[PillPalNowAPI] Failed to sync queued action:', err);
                });
            });
        },

        /**
         * Preload common data into cache
         */
        preload: function (endpoints) {
            var self = this;
            endpoints = endpoints || [];

            endpoints.forEach(function (endpoint) {
                self.get(endpoint, { cache: true }).catch(function () {
                    // Ignore preload errors
                });
            });
        }
    };

    // Initialize
    PillPalNowAPI.init();

    // Expose globally
    window.PillPalNowAPI = PillPalNowAPI;

})();
