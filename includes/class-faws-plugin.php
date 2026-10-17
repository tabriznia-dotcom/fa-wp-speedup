<?php
/**
 * Site-local modules and administrator controls.
 *
 * @package FA_WP_Speedup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site-local modules and administrator controls.
 */
final class FAWS_Plugin {
	/**
	 * Request-local observed.
	 *
	 * @var array
	 */
	private static array $observed = array();
	/**
	 * Request-local fallbacks.
	 *
	 * @var array
	 */
	private static array $fallbacks = array();
	/**
	 * Request-local external count.
	 *
	 * @var int
	 */
	private static int $external_count = 0;
	/**
	 * Request-local reported.
	 *
	 * @var bool
	 */
	private static bool $reported = false;
	/**
	 * Request-local http started.
	 *
	 * @var array
	 */
	private static array $http_started = array();
	/**
	 * Request-local http samples.
	 *
	 * @var array
	 */
	private static array $http_samples = array();

	/**
	 * Register modules without changing site behavior by default.
	 *
	 * @return void
	 */
	public static function boot(): void {
		load_plugin_textdomain( 'fa-wp-speedup', false, dirname( plugin_basename( FAWS_FILE ) ) . '/languages' );
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_faws_action', array( self::class, 'action' ) );
		add_action( 'faws_download', array( 'FAWS_Local', 'download' ) );
		add_filter( 'script_loader_src', array( self::class, 'source' ), 20, 2 );
		add_filter( 'style_loader_src', array( self::class, 'source' ), 20, 2 );
		add_action( 'template_redirect', array( self::class, 'capture' ) );
		add_filter( 'pre_http_request', array( self::class, 'http_start' ), PHP_INT_MAX, 3 );
		add_action( 'http_api_debug', array( self::class, 'http_observation' ), 10, 5 );
		add_action( 'shutdown', array( self::class, 'save_report' ) );
	}

	/**
	 * Read site-local module switches.
	 *
	 * @return array
	 */
	private static function options(): array {
		return get_option( 'faws_options', array() );
	}

	/**
	 * Allow diagnostic sampling only for consenting administrators.
	 *
	 * @return bool
	 */
	private static function diagnosing(): bool {
		return ! empty( self::options()['diagnostics'] ) && current_user_can( 'manage_options' );
	}

	/**
	 * Exclude personalized, authenticated and sensitive requests.
	 *
	 * @return bool
	 */
	public static function sensitive(): bool {
		if ( is_admin() || is_user_logged_in() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return true;
		}
		foreach ( array( 'is_cart', 'is_checkout', 'is_account_page' ) as $fn ) {
			if ( function_exists( $fn ) && $fn() ) {
				return true;
			}
		}
		// Anonymous sessions and all query-driven pages are conservatively excluded.
		if ( ! empty( $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}
		foreach ( array_keys( $_COOKIE ) as $name ) {
			if ( preg_match( '/wordpress|woocommerce|wp_woocommerce_session|phpsessid/i', $name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Describe the current page without persisting its URL.
	 *
	 * @return string
	 */
	private static function context(): string {
		foreach ( array( 'is_checkout', 'is_cart', 'is_account_page', 'is_product', 'is_shop', 'is_product_category', 'is_search', 'is_front_page', 'is_single', 'is_page' ) as $fn ) {
			if ( function_exists( $fn ) && $fn() ) {
				return $fn;
			}
		}
		return is_admin() ? 'admin' : 'other';
	}

	/**
	 * Record bounded, privacy-preserving resource metadata.
	 *
	 * @param string $url Source URL, used transiently.
	 * @param string $handle Original WordPress resource handle.
	 * @param string $kind Resource category.
	 * @return void
	 */
	private static function observe( string $url, string $handle, string $kind ): void {
		if ( ! self::diagnosing() || count( self::$observed ) >= 100 ) {
			return;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return;
		}
		$id = FAWS_Catalog::match( $url );
		// Unknown URLs may contain keys, nonces or personal data. Persist only hostname/type.
		$key                    = hash( 'sha256', $host . $handle . $kind );
		self::$observed[ $key ] = array(
			'host'     => sanitize_text_field( $host ),
			'handle'   => sanitize_key( $handle ),
			'kind'     => $kind,
			'catalog'  => $id,
			'context'  => self::context(),
			'external' => wp_parse_url( home_url(), PHP_URL_HOST ) !== $host,
			'reason'   => $id ? 'reviewed_mapping' : ( wp_parse_url( home_url(), PHP_URL_HOST ) === $host ? 'local_no_replacement' : 'unknown_origin_license_or_version_no_replacement' ),
		);
	}

	/**
	 * Replace a source URL while preserving the original dependency.
	 *
	 * @param string $src Current source URL.
	 * @param string $handle Original WordPress resource handle.
	 * @return string
	 */
	public static function source( string $src, string $handle ): string {
		self::observe( $src, $handle, current_filter() === 'style_loader_src' ? 'css' : 'js' );
		$id = FAWS_Catalog::match( $src );
		if ( ! $id || empty( self::options()['local'] ) || self::sensitive() ) {
			return $src;
		}
		$resources = get_option( 'faws_resources', array() );
		if ( empty( $resources[ $id ]['active'] ) || empty( $resources[ $id ]['consent'] ) ) {
			return $src;
		}
		if ( preg_match( '/checkout|payment|captcha|security|login|cart|gateway|wc-|woocommerce|map|analytics/i', $handle ) ) {
			return $src;
		}
		// Only a source URL filter is used. The original handle, inline data, dependencies,
		// strategy, version and tag attributes remain owned by WordPress.
		if ( self::duplicate( $handle, $id ) ) {
			return $src;
		}
		if ( ! FAWS_Local::verified( $id ) ) {
			self::$fallbacks[ $id ] = time();
			return $src;
		}
		list( , $local ) = FAWS_Local::path( $id );
		return $local;
	}

	/**
	 * Fail closed when another queued copy is recognizable.
	 *
	 * @param string $handle Original WordPress resource handle.
	 * @param string $id Reviewed catalog identifier.
	 * @return bool
	 */
	private static function duplicate( string $handle, string $id ): bool {
		$item     = FAWS_Catalog::all()[ $id ];
		$registry = 'css' === $item['type'] ? wp_styles() : wp_scripts();
		foreach ( $registry->registered as $name => $dependency ) {
			if ( $name === $handle || ! in_array( $name, array_merge( $registry->queue, $registry->done, $registry->to_do ), true ) ) {
				continue;
			}
			if ( false !== stripos( $name . ' ' . (string) $dependency->src, $item['library'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Discover hardcoded HTML resources without rewriting them.
	 *
	 * @return void
	 */
	public static function capture(): void {
		if ( ! self::diagnosing() || is_feed() || is_robots() || is_trackback() ) {
			return;
		}
		// HTML discovery is read-only. Hardcoded tags are never rewritten.
		ob_start(
			static function ( $html ) {
				if ( strlen( $html ) <= 2097152 && preg_match_all( '/<(script|link)\b[^>]*\b(src|href)\s*=\s*([\'"])(.*?)\3/is', $html, $matches, PREG_SET_ORDER ) ) {
					foreach ( array_slice( $matches, 0, 200 ) as $match ) {
						self::observe( html_entity_decode( $match[4], ENT_QUOTES, 'UTF-8' ), 'html', 'script' === strtolower( $match[1] ) ? 'js' : 'css_or_font' );
					}
				}
				self::save_report();
				return $html;
			}
		);
	}

	/**
	 * Start a timer for eligible WordPress HTTP requests.
	 *
	 * @param mixed  $pre Existing HTTP short-circuit result.
	 * @param array  $args WordPress HTTP arguments.
	 * @param string $url Source URL, used transiently.
	 * @return mixed
	 */
	public static function http_start( $pre, $args, $url ) {
		if ( false === $pre && self::diagnosing() && count( self::$http_started ) < 100 && wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			self::$http_started[ hash( 'sha256', $url ) ] = microtime( true );
		}
		return $pre;
	}

	/**
	 * Complete a timer without retaining request URLs or bodies.
	 *
	 * @param array|WP_Error $response HTTP response or error.
	 * @param string         $context HTTP debug event context.
	 * @param string         $transport HTTP transport identifier.
	 * @param array          $args WordPress HTTP arguments.
	 * @param string         $url Source URL, used transiently.
	 * @return void
	 */
	public static function http_observation( $response, $context, $transport, $args, $url ): void {
		$key = hash( 'sha256', $url );
		if ( 'response' === $context && isset( self::$http_started[ $key ] ) ) {
			++self::$external_count;
			if ( count( self::$http_samples ) < 20 ) {
				self::$http_samples[] = array(
					'host'   => sanitize_text_field( (string) wp_parse_url( $url, PHP_URL_HOST ) ),
					'ms'     => round( ( microtime( true ) - self::$http_started[ $key ] ) * 1000 ),
					'status' => is_wp_error( $response ) ? 'network_error' : wp_remote_retrieve_response_code( $response ),
				);
			}
			unset( self::$http_started[ $key ] );
		}
	}

	/**
	 * Persist bounded diagnostic samples and fallback events.
	 *
	 * @return void
	 */
	public static function save_report(): void {
		if ( self::$fallbacks ) {
			$previous = get_option( 'faws_fallbacks', array() );
			update_option( 'faws_fallbacks', array_merge( $previous, self::$fallbacks ), false );
		}
		if ( ! self::diagnosing() ) {
			return;
		}
		if ( self::$observed ) {
			$old = get_option( 'faws_inventory', array() );
			update_option( 'faws_inventory', array_slice( array_merge( $old, self::$observed ), -200, null, true ), false );
		}
		if ( self::$reported ) {
			return;
		}
		self::$reported = true;
		global $wpdb;
		$report    = array(
			'time'              => time(),
			'context'           => self::context(),
			'php_ms'            => isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? round( ( microtime( true ) - (float) $_SERVER['REQUEST_TIME_FLOAT'] ) * 1000 ) : null,
			'queries'           => $wpdb->num_queries,
			'query_ms'          => defined( 'SAVEQUERIES' ) && SAVEQUERIES ? round( array_sum( array_column( $wpdb->queries, 1 ) ) * 1000 ) : null,
			'external_requests' => self::$external_count,
			'external_samples'  => self::$http_samples,
			'peak_bytes'        => memory_get_peak_usage( true ),
		);
		$reports   = get_option( 'faws_reports', array() );
		$reports[] = $report;
		update_option( 'faws_reports', array_slice( $reports, -20 ), false );
	}

	/**
	 * Register the site administrator settings page.
	 *
	 * @return void
	 */
	public static function menu(): void {
		add_options_page( __( 'شتاب وردپرس', 'fa-wp-speedup' ), __( 'شتاب وردپرس', 'fa-wp-speedup' ), 'manage_options', 'faws', array( self::class, 'page' ) );
	}

	/**
	 * Authorize and dispatch administrator POST actions.
	 *
	 * @return void
	 */
	public static function action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی مجاز نیست.', 'fa-wp-speedup' ), 403 );
		}
		check_admin_referer( 'faws_action' );
		$op      = sanitize_key( is_string( $_POST['op'] ?? null ) ? wp_unslash( $_POST['op'] ) : '' );
		$id      = sanitize_key( is_string( $_POST['resource'] ?? null ) ? wp_unslash( $_POST['resource'] ) : '' );
		$options = self::options();
		$state   = get_option( 'faws_resources', array() );
		if ( 'settings' === $op ) {
			update_option( 'faws_previous', $options, false );
			$options = array();
			foreach ( array( 'diagnostics', 'local', 'delete_data' ) as $key ) {
				$options[ $key ] = ! empty( $_POST[ $key ] );
			}
			update_option( 'faws_options', $options, false );
		} elseif ( 'rollback' === $op ) {
			update_option( 'faws_options', get_option( 'faws_previous', array() ), false );
		} elseif ( 'clear_reports' === $op ) {
			foreach ( array( 'faws_inventory', 'faws_reports', 'faws_fallbacks' ) as $key ) {
				delete_option( $key );
			}
		} elseif ( isset( FAWS_Catalog::all()[ $id ] ) ) {
			if ( 'download' === $op ) {
				// Approval grants one immutable source, not an arbitrary URL or version.
				$state[ $id ]['consent'] = true;
				update_option( 'faws_resources', $state, false );
				if ( ! wp_next_scheduled( 'faws_download', array( $id ) ) ) {
					wp_schedule_single_event( time() + 5, 'faws_download', array( $id ) );
				}
			} elseif ( 'activate' === $op && ! empty( $state[ $id ]['consent'] ) && FAWS_Local::verified( $id ) ) {
				$state[ $id ]['active'] = true;
				update_option( 'faws_resources', $state, false );
			} elseif ( 'disable' === $op || 'delete' === $op ) {
				$state[ $id ]['active']  = false;
				$state[ $id ]['consent'] = false;
				update_option( 'faws_resources', $state, false );
				wp_clear_scheduled_hook( 'faws_download', array( $id ) );
				if ( 'delete' === $op ) {
					FAWS_Local::remove( $id );
				}
			}
		}
		wp_safe_redirect( admin_url( 'options-general.php?page=faws' ) );
		exit;
	}

	/**
	 * Render a nonce-protected resource action.
	 *
	 * @param string $op Whitelisted action name.
	 * @param string $label Translated button label.
	 * @param string $id Reviewed catalog identifier.
	 * @return void
	 */
	private static function form( string $op, string $label, string $id = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:4px">';
		wp_nonce_field( 'faws_action' );
		echo '<input type="hidden" name="action" value="faws_action"><input type="hidden" name="op" value="' . esc_attr( $op ) . '"><input type="hidden" name="resource" value="' . esc_attr( $id ) . '">';
		submit_button( $label, 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Render Persian settings and the read-only diagnostic report.
	 *
	 * @return void
	 */
	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$options = self::options();
		echo '<div class="wrap" dir="rtl"><h1>' . esc_html__( 'شتاب وردپرس — نسخهٔ آزمایشی ۰٫۲', 'fa-wp-speedup' ) . '</h1>';
		echo '<p>' . esc_html__( 'تشخیص فقط برای مدیر فعال می‌شود. تصویر و قواعد منابع فقط با انتخاب شما تغییر می‌کنند؛ cron سایت، کش صفحه و دادهٔ فروشگاه تغییر نمی‌کنند و آمار به بیرون ارسال نمی‌شود.', 'fa-wp-speedup' ) . '</p>';
		echo '<nav>';
		foreach ( array(
			'report'         => __( 'گزارش', 'fa-wp-speedup' ),
			'external'       => __( 'منابع خارجی', 'fa-wp-speedup' ),
			'frontend'       => __( 'فرانت‌اند', 'fa-wp-speedup' ),
			'media'          => __( 'تصاویر', 'fa-wp-speedup' ),
			'advanced-rules' => __( 'قواعد پیشرفته', 'fa-wp-speedup' ),
			'woo'            => __( 'ووکامرس', 'fa-wp-speedup' ),
			'admin'          => __( 'پیشخوان', 'fa-wp-speedup' ),
			'advanced'       => __( 'پیشرفته', 'fa-wp-speedup' ),
		) as $anchor => $label ) {
			echo '<a style="margin:8px" href="#' . esc_attr( $anchor ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav><h2 id="advanced">' . esc_html__( 'تنظیمات و بازگشت', 'fa-wp-speedup' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'faws_action' );
		echo '<input type="hidden" name="action" value="faws_action"><input type="hidden" name="op" value="settings">';
		$labels = array(
			'diagnostics' => __( 'ثبت تشخیص هنگام بازدید مدیر (۲۰ گزارش آخر؛ خاموش‌کردن ثبت را متوقف می‌کند)', 'fa-wp-speedup' ),
			'local'       => __( 'اجازهٔ جایگزینی منابع تأییدشده برای مهمان در صفحات عمومی؛ برای بازگشت خاموش کنید', 'fa-wp-speedup' ),
			'delete_data' => __( 'پاک‌کردن داده‌ها و فایل‌های محلی هنگام حذف افزونه؛ غیرقابل‌بازگشت', 'fa-wp-speedup' ),
		);
		foreach ( $labels as $key => $label ) {
			echo '<p><label><input type="checkbox" name="' . esc_attr( $key ) . '" value="1" ' . checked( ! empty( $options[ $key ] ), true, false ) . '> ' . esc_html( $label ) . '</label></p>';
		}
		submit_button( __( 'ذخیره', 'fa-wp-speedup' ) );
		echo '</form>';
		self::form( 'rollback', __( 'بازگردانی تنظیمات قبلی', 'fa-wp-speedup' ) );
		self::form( 'clear_reports', __( 'پاک‌کردن گزارش‌ها', 'fa-wp-speedup' ) );
		echo '<h2 id="external">' . esc_html__( 'منابع خارجی و پیش‌نمایش', 'fa-wp-speedup' ) . '</h2><p>' . esc_html__( 'دریافت فقط پس از رضایت مدیر، در WP-Cron و حداکثر هر ساعت انجام می‌شود. سپس فعال‌سازی جداگانه لازم است. زمان سنجش از سرور است و نمایندهٔ شبکهٔ کاربران ایران نیست. آستانهٔ کندی این نمونه ۱۵۰۰ میلی‌ثانیه است؛ timeout به‌تنهایی مجوز جایگزینی نیست.', 'fa-wp-speedup' ) . '</p>';
		$state     = get_option( 'faws_resources', array() );
		$fallbacks = get_option( 'faws_fallbacks', array() );
		foreach ( FAWS_Catalog::all() as $id => $item ) {
			echo '<section style="background:#fff;padding:12px;margin:12px 0"><h3>' . esc_html( $id ) . '</h3><p dir="ltr">' . esc_html( $item['url'] ) . '</p>';
			try {
				list( , $preview ) = FAWS_Local::path( $id );
				echo '<p dir="ltr">→ ' . esc_html( $preview ) . '</p>';
			} catch ( Throwable $e ) {
				echo '<p>' . esc_html__( 'مسیر uploads در دسترس نیست.', 'fa-wp-speedup' ) . '</p>';
			}
			echo '<p><a href="' . esc_url( $item['license_url'] ) . '">' . esc_html( $item['license'] ) . '</a> | <a href="' . esc_url( $item['provenance'] ) . '">' . esc_html__( 'منشأ checksum', 'fa-wp-speedup' ) . '</a></p>';
			$details                        = array_merge( $state[ $id ] ?? array(), get_option( 'faws_download_' . $id, array( 'status' => 'not_requested' ) ) );
			$details['verified_now']        = FAWS_Local::verified( $id );
			$details['fallback_last']       = $fallbacks[ $id ] ?? null;
			$details['checksum_expected']   = $item['algorithm'] . '-' . $item['digest'];
			$details['attempt']             = get_option( 'faws_attempt_' . $id, 0 );
			$details['version']             = $item['version'];
			$details['eligible_public_url'] = ! empty( $options['local'] ) && ! empty( $details['active'] ) && ! empty( $details['consent'] ) && $details['verified_now'] ? ( $preview ?? $item['url'] ) : $item['url'];
			echo '<pre dir="ltr" style="white-space:pre-wrap;overflow-wrap:anywhere">' . esc_html( wp_json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</pre>';
			self::form( 'download', __( 'تأیید مجوز و زمان‌بندی دریافت', 'fa-wp-speedup' ), $id );
			self::form( 'activate', __( 'تأیید فعال‌سازی فایل بررسی‌شده', 'fa-wp-speedup' ), $id );
			self::form( 'disable', __( 'بازگشت به منبع اصلی', 'fa-wp-speedup' ), $id );
			self::form( 'delete', __( 'لغو رضایت و حذف فایل محلی', 'fa-wp-speedup' ), $id );
			echo '</section>';
		}
		echo '<h2 id="report">' . esc_html__( 'گزارش', 'fa-wp-speedup' ) . '</h2><p>' . esc_html__( 'زمان PHP با TTFB مرورگر متفاوت است. زمان query، شبکه و cache hit بدون ابزار اندازه‌گیری گزارش نمی‌شود. URL ناشناخته و اطلاعات نشست ذخیره نمی‌شوند.', 'fa-wp-speedup' ) . '</p>';
		self::diagnostics_panel();
		FAWS_Media::panel();
		FAWS_Advanced::panel();
		echo '<h2 id="frontend">' . esc_html__( 'فرانت‌اند', 'fa-wp-speedup' ) . '</h2><p>' . esc_html__( 'منابع HTML فقط شناسایی می‌شوند. فونت و CSS دارای وابستگی بیرونی پشتیبانی نمی‌شوند. تنظیمات تصویر و قواعد منابع در بخش‌های جداگانه و پیش‌فرض خاموش هستند. lazy-load استاندارد وردپرس حفظ می‌شود. برای جلوگیری از کتابخانهٔ تکراری، پیش از تأیید فهرست handleها را بررسی کنید.', 'fa-wp-speedup' ) . '</p>';
		echo '<h2 id="woo">' . esc_html__( 'ووکامرس', 'fa-wp-speedup' ) . '</h2><p>' . esc_html__( 'سبد، پرداخت، حساب، کاربران واردشده، درخواست‌های دارای query و نشست از جایگزینی مستثنا هستند. دادهٔ قیمت، موجودی و سفارش تغییر نمی‌کند. آزمون محلی HPOS و پرداخت COD انجام شده است؛ درگاه بانکی و سازگاری قالب واقعی نیازمند تست مستقل است.', 'fa-wp-speedup' ) . '</p>';
		echo '<h2 id="admin">' . esc_html__( 'پیشخوان و صف', 'fa-wp-speedup' ) . '</h2><p>' . esc_html__( 'درخواست‌های افزونه‌های دیگر مسدود نمی‌شوند. cron و Action Scheduler فقط مشاهده می‌شوند؛ هیچ کار یا داده‌ای پاک یا retry نمی‌شود.', 'fa-wp-speedup' ) . '</p></div>';
	}

	/**
	 * Collect on-demand aggregate database and scheduler status.
	 *
	 * @return void
	 */
	private static function diagnostics_panel(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- On-demand read-only aggregate; do not load option values into memory.
		$autoload = $wpdb->get_row( "SELECT COUNT(*) AS count, COALESCE(SUM(LENGTH(option_value)),0) AS bytes FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto','auto-on')", ARRAY_A );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- On-demand read-only aggregate; do not load option values into memory.
		$transients = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%'" );
		$cron       = _get_cron_array();
		$hooks      = array();
		$count      = 0;
		$oldest     = null;
		foreach ( $cron ? $cron : array() as $timestamp => $events ) {
			foreach ( $events as $hook => $instances ) {
				$n              = count( $instances );
				$count         += $n;
				$hooks[ $hook ] = ( $hooks[ $hook ] ?? 0 ) + $n;
				$oldest         = null === $oldest ? $timestamp : min( $oldest, $timestamp );
			}
		}
		arsort( $hooks );
		$queue = array( 'status' => 'unavailable' );
		if ( class_exists( 'ActionScheduler' ) && method_exists( 'ActionScheduler', 'store' ) ) {
			try {
				$store = ActionScheduler::store();
				$queue = array(
					'pending' => $store->query_actions( array( 'status' => 'pending' ), 'count' ),
					'failed'  => $store->query_actions( array( 'status' => 'failed' ), 'count' ),
				);
			} catch ( Throwable $e ) {
				$queue = array( 'status' => 'unavailable' );
			}
		}
		$data = array(
			'autoload'             => $autoload,
			'transient_rows'       => (int) $transients,
			'object_cache'         => wp_using_ext_object_cache(),
			'page_cache_constant'  => defined( 'WP_CACHE' ) && WP_CACHE,
			'cron_disabled'        => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'cron_events'          => $count,
			'earliest_cron'        => $oldest,
			'cron_hooks'           => array_slice( $hooks, 0, 10, true ),
			'action_scheduler'     => $queue,
			'inventory'            => array_values( get_option( 'faws_inventory', array() ) ),
			'recent_admin_samples' => get_option( 'faws_reports', array() ),
		);
		echo '<pre dir="ltr" style="white-space:pre-wrap;overflow-wrap:anywhere">' . esc_html( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</pre>';
	}

	/**
	 * Unschedule approved jobs without deleting stored assets.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		FAWS_Media::cleanup();
		foreach ( array_keys( FAWS_Catalog::all() ) as $id ) {
			wp_clear_scheduled_hook( 'faws_download', array( $id ) );
		}
	}
}
