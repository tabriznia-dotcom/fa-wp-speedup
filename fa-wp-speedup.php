<?php
/**
 * Plugin Name: FA WP Speedup
 * Description: تشخیص کارایی و میزبانی محلی منابع تأییدشده، با کنترل مدیر و بازگشت امن.
 * Version: 0.2.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Text Domain: fa-wp-speedup
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 *
 * @package FA_WP_Speedup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FAWS_FILE', __FILE__ );
require_once __DIR__ . '/includes/class-faws-catalog.php';
require_once __DIR__ . '/includes/class-faws-local.php';
require_once __DIR__ . '/includes/class-faws-plugin.php';
require_once __DIR__ . '/includes/class-faws-media.php';
require_once __DIR__ . '/includes/class-faws-advanced.php';

add_action( 'plugins_loaded', array( 'FAWS_Plugin', 'boot' ) );
add_action( 'plugins_loaded', array( 'FAWS_Media', 'boot' ) );
add_action( 'plugins_loaded', array( 'FAWS_Advanced', 'boot' ) );
register_deactivation_hook( __FILE__, array( 'FAWS_Plugin', 'deactivate' ) );

register_activation_hook(
	__FILE__,
	static function ( $network_wide ) {
		if ( $network_wide ) {
			wp_die( esc_html__( 'فعال‌سازی شبکه‌ای در نسخهٔ اولیه پشتیبانی نمی‌شود؛ افزونه را برای هر سایت جداگانه فعال کنید.', 'fa-wp-speedup' ) );
		}
	}
);
