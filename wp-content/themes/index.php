<?php
/**
 * PillPalNow functions and definitions
 *
 * @package PillPalNow
 */

if (!defined('_S_VERSION')) {
	// Replace the version number of the theme on each release.
	define('_S_VERSION', '1.1.4');
}

/**
 * Sets up theme defaults and registers support for various WordPress features.
 */
function dosecast_setup()
{
	// Add default posts and comments RSS feed links to head.
	add_theme_support('automatic-feed-links');

	// Let WordPress manage the document title.
	add_theme_support('title-tag');

	// Enable support for Post Thumbnails on posts and pages.
	add_theme_support('post-thumbnails');

	// Register Navigation Menus
	register_nav_menus(
		array(
			'primary' => esc_html__('Primary Menu', 'pillpalnow'),
		)
	);

	/**
	 * Customizer additions.
	 */
	// Check if file exists to avoid error if I missed copying it or something
	if (file_exists(get_template_directory() . '/inc/customizer.php')) {
		require get_template_directory() . '/inc/customizer.php';
	}

	// Switch default core markup for search form, comment form, and comments to output valid HTML5.
	add_theme_support(
		'html5',
		array(
			'search-form',
			'comment-form',
			'comment-list',
			'gallery',
			'caption',
			'style',
			'script',
		)
	);

	// Custom logo support
	add_theme_support(
		'custom-logo',
		array(
			'height' => 250,
			'width' => 250,
			'flex-width' => true,
			'flex-height' => true,
		)
	);
}
add_action('after_setup_theme', 'dosecast_setup');

/**
 * Enqueue scripts and styles.
 */
function dosecast_scripts()
{
	wp_enqueue_style('pillpalnow-style', get_stylesheet_uri(), array(), _S_VERSION);

	// WebView compatibility CSS
	wp_enqueue_style('pillpalnow-webview', get_template_directory_uri() . '/assets/css/webview-compat.css', array('pillpalnow-style'), _S_VERSION);

	// Enqueue Google Fonts (Inter + Outfit)
	wp_enqueue_style('pillpalnow-google-fonts', 'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;700;800&display=swap', array(), null);

	// WebView utilities (load early in head for detection before DOM loads)
	wp_enqueue_script('pillpalnow-webview', get_template_directory_uri() . '/assets/js/webview-utils.js', array(), _S_VERSION, false);

	// API helper with caching and error handling
	wp_enqueue_script('pillpalnow-api', get_template_directory_uri() . '/assets/js/api-helper.js', array('pillpalnow-webview'), _S_VERSION, true);

	// Enqueue main JS
	wp_enqueue_script('pillpalnow-logger', get_template_directory_uri() . '/assets/js/dose-logger.js', array('pillpalnow-webview', 'pillpalnow-api'), _S_VERSION, true);
	wp_enqueue_script('pillpalnow-add-medication', get_template_directory_uri() . '/assets/js/add-medication.js', array(), _S_VERSION, true);

	// Only load RxNorm autocomplete on add-medication page
	if (is_page('add-medication') || is_page('add-new-medication') || (isset($_GET['edit_medication']))) {
		wp_enqueue_script('pillpalnow-rxnorm', get_template_directory_uri() . '/assets/js/rxnorm-autocomplete.js', array('jquery'), _S_VERSION, true);
	}

	wp_enqueue_script('pillpalnow-notifications', get_template_directory_uri() . '/assets/js/notification-bell.js', array(), _S_VERSION, true);
	// wp_enqueue_script('pillpalnow-dropdown', get_template_directory_uri() . '/assets/js/dropdown.js', array(), _S_VERSION, true);

	// Localize script for dynamic URLs
	$pillpalnow_vars = array(
		'dashboard_url' => home_url('/'),
		'ajax_url' => admin_url('admin-ajax.php'),
		'rest_url' => get_rest_url(null, 'pillpalnow/v1/'),
		'nonce' => wp_create_nonce('pillpalnow_nonce')
	);

	wp_localize_script('pillpalnow-logger', 'pillpalnow_vars', $pillpalnow_vars);

	// Only localize RxNorm script if it was enqueued
	if (is_page('add-medication') || is_page('add-new-medication') || (isset($_GET['edit_medication']))) {
		wp_localize_script('pillpalnow-rxnorm', 'pillpalnow_vars', $pillpalnow_vars);
	}

	// Localize notification script with API settings
	wp_localize_script('pillpalnow-notifications', 'pillpalnowNotifications', array(
		'apiUrl' => rest_url('pillpalnow/v1/'),
		'nonce' => wp_create_nonce('wp_rest')
	));

	// Localize for profile page notifications
	if (is_page('profile')) {
		wp_localize_script('pillpalnow-logger', 'pillpalnowProfile', array(
			'restUrl' => rest_url('pillpalnow/v1/'),
			'nonce' => wp_create_nonce('wp_rest')
		));
	}
}
add_action('wp_enqueue_scripts', 'dosecast_scripts');

/**
 * Register App Menu Widget Area
 */
function dosecast_widgets_init()
{
	register_sidebar(array(
		'name' => esc_html__('App Home Menu', 'pillpalnow'),
		'id' => 'app-home-menu',
		'description' => esc_html__('Add widgets here to appear on the App Home.', 'pillpalnow'),
		'before_widget' => '<section id="%1$s" class="widget %2$s">',
		'after_widget' => '</section>',
		'before_title' => '<h2 class="widget-title">',
		'after_title' => '</h2>',
	));
}

add_action('widgets_init', 'dosecast_widgets_init');


/**
 * Redirect to Custom Login Page after Logout
 */
add_filter('logout_redirect', 'dosecast_logout_redirect', 10, 3);
function dosecast_logout_redirect($redirect_to, $requested_redirect_to, $user)
{
	return home_url('/login');
}

/**
 * Global Redirect for Non-Logged-In Users
 */
function dosecast_global_auth_redirect()
{
	// Check if user is logged in
	if (is_user_logged_in()) {
		return;
	}

	// Define allowed pages (slugs)
	$allowed_pages = array('login', 'register', 'password-reset', 'forgot-password', 'magic-login', 'caregiver-signup');

	// Check if current page is in allowed pages
	if (is_page($allowed_pages)) {
		return;
	}

	// Safety Loop Prevention: Check REQUEST_URI directly
	// This allows access if we are already AT trying to access /login or /register, avoiding infinite redirect even if is_page() fails (e.g. 404).
	$request_uri = $_SERVER['REQUEST_URI'];
	if (strpos($request_uri, '/login') !== false || strpos($request_uri, '/register') !== false || strpos($request_uri, '/password-reset') !== false) {
		return;
	}

	// Allow standard wp-login.php (for admin access usually)
	global $pagenow;
	if ($pagenow === 'wp-login.php') {
		if (isset($_GET['action']) && $_GET['action'] === 'register') {
			wp_redirect(home_url('/login'));
			exit;
		}
		return;
	}

	// Redirect to login page
	wp_redirect(home_url('/login'));
	exit;
}
add_action('template_redirect', 'dosecast_global_auth_redirect');
add_action('login_init', function () {
	if (isset($_GET['action']) && $_GET['action'] === 'register') {
		wp_redirect(home_url('/login'));
		exit;
	}
});

// Add magic-login to allowed pages if not already covered
add_filter('dosecast_allowed_pages', function ($pages) {
	$pages[] = 'magic-login';
	return $pages;
});

/**
 * Detect AppMySite WebView and Add Body Class
 * 
 * Adds 'is-app' class to body when site is loaded inside AppMySite WebView.
 * This enables CSS-based hiding of header/navigation elements that would
 * duplicate the app's native navigation.
 * 
 * Detection methods:
 * - User-agent contains "AppMySite"
 * - User-agent contains "wv" (Android WebView)
 * - Android with specific WebView pattern
 * - Cookie fallback for subsequent page loads
 * - URL parameter ?webview=1 for testing
 */
function dosecast_detect_webview_body_class($classes)
{
	$is_webview = false;
	$user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';

	// Check user-agent for WebView indicators
	if (
		stripos($user_agent, 'AppMySite') !== false ||
		stripos($user_agent, 'wv') !== false ||
		stripos($user_agent, 'WebView') !== false ||
		(stripos($user_agent, 'Android') !== false && preg_match('/Version\/[\d.]+/', $user_agent))
	) {
		$is_webview = true;
	}

	// Check for WebView cookie (set by JavaScript for subsequent loads)
	// DISABLED: Cookie sticking causes issues on web. Rely on JS detection in header.php.
	/* if (isset($_COOKIE['dosecast_webview']) && $_COOKIE['dosecast_webview'] === '1') {
		$is_webview = true;
	} */

	// Check for URL parameter (useful for testing)
	if (isset($_GET['webview']) && $_GET['webview'] === '1') {
		$is_webview = true;
	}

	// Add 'is-app' class if WebView detected
	if ($is_webview) {
		$classes[] = 'is-app';
	}

	return $classes;
}
add_filter('body_class', 'dosecast_detect_webview_body_class');

/**
 * Temporary Flush Rewrite Rules
 * Fixes 404 on family member pages. Remove after verification.
 */
