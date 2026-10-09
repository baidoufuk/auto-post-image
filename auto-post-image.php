<?php
/**
 * Plugin Name: Auto Post Image
 * Description: Automatic article images and bilingual text covers prepared during publishing, never during visitor browsing.
 * Version: 1.0.0
 * Author: baidoufuk
 * Author URI: https://github.com/baidoufuk
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: auto-post-image
 */
defined('ABSPATH') || exit;

define('AUTO_POST_IMAGE_VERSION', '1.0.0');
define('AUTO_POST_IMAGE_DIR', __DIR__ . '/');
define('AUTO_POST_IMAGE_URL', plugin_dir_url(__FILE__));

// Only write callbacks load the image renderer; ordinary reads only register write hooks.
require_once AUTO_POST_IMAGE_DIR . 'includes/lifecycle.php';
add_action('wp_after_insert_post', array('AutoPostImage\Lifecycle', 'after_save'), 100, 4);
add_action('rest_after_insert_post', array('AutoPostImage\Lifecycle', 'rest_intent'), 100, 3);
add_filter('wp_insert_post_data', array('AutoPostImage\Lifecycle', 'before_save'), 100, 2);
if (is_admin()) {
    require_once AUTO_POST_IMAGE_DIR . 'includes/admin.php';
    AutoPostImage\Admin::boot();
}
register_activation_hook(__FILE__, function () {
    if (version_compare(get_bloginfo('version'), '5.8', '<')) {
        wp_die('Auto Post Image requires WordPress 5.8 or newer.');
    }
    if (!function_exists('imagettftext') || !function_exists('imagettfbbox') || !function_exists('imagecreatetruecolor')) {
        wp_die('Auto Post Image requires PHP GD with FreeType. Enable GD in your hosting panel before activation.');
    }
    foreach (array('NotoSansSC-Regular.otf', 'NotoSerifSC-Regular.otf') as $font) {
        $path = AUTO_POST_IMAGE_DIR . 'assets/fonts/' . $font;
        if (!is_readable($path) || !@imagettfbbox(12, 0, $path, '中文 ABC')) {
            wp_die('Auto Post Image could not render its bundled fonts. Check PHP GD FreeType support and the plugin font files.');
        }
    }
});
