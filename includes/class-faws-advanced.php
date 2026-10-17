<?php
/**
 * Scoped asset rules with administrator preview and compatibility guards.
 *
 * @package FA_WP_Speedup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Optimizations use core APIs and never combine or rewrite script contents. */
final class FAWS_Advanced {
	/**
	 * Register opt-in module hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'admin_post_faws_advanced', array( self::class, 'action' ) );
		add_action( 'template_redirect', array( self::class, 'preview_headers' ), 0 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'apply' ), PHP_INT_MAX );
		add_action( 'wp_dashboard_setup', array( self::class, 'dashboard' ), PHP_INT_MAX );
		add_filter( 'heartbeat_settings', array( self::class, 'heartbeat' ) );
	}

	/**
	 * Classify the current frontend page without retaining its URL.
	 *
	 * @return string
	 */
	public static function context(): string {
		foreach ( array(
			'is_product_taxonomy' => 'shop',
			'is_shop'             => 'shop',
			'is_product'          => 'product',
			'is_front_page'       => 'home',
			'is_search'           => 'search',
			'is_single'           => 'single',
			'is_page'             => 'page',
		) as $fn => $context ) {
			if ( function_exists( $fn ) && $fn() ) {
				return $context;
			}
		}
		return 'other';
	}

	/**
	 * Authorize a signed preview request for a site administrator.
	 *
	 * @return bool
	 */
	public static function preview(): bool {
		$value = isset( $_GET['faws_preview'] ) && is_string( $_GET['faws_preview'] ) ? sanitize_text_field( wp_unslash( $_GET['faws_preview'] ) ) : '';
		return $value && current_user_can( 'manage_options' ) && (bool) wp_verify_nonce( $value, 'faws_preview' );
	}

	/**
	 * Prevent caching of authenticated preview responses.
	 *
	 * @return void
	 */
	public static function preview_headers(): void {
		if ( self::preview() ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			nocache_headers();
		}
	}

	/**
	 * Detect active asset optimizers that block public application.
	 *
	 * @return array
	 */
	public static function conflicts(): array {
		$active = array_merge( get_option( 'active_plugins', array() ), array_keys( get_site_option( 'active_sitewide_plugins', array() ) ) );
		return array_values(
			array_filter(
				$active,
				static function ( $plugin ) {
					return (bool) preg_match( '/autoptimize|wp-rocket|litespeed-cache|w3-total-cache|perfmatters|wp-optimize|asset-clean-up|hummingbird|jetpack-boost/i', $plugin );
				}
			)
		);
	}

	/**
	 * Require an approved mode and compatible environment.
	 *
	 * @return bool
	 */
	private static function enabled(): bool {
		$options = get_option( 'faws_advanced', array() );
		$mode    = $options['mode'] ?? 'off';
		if ( 'preview' === $mode ) {
			return self::preview();
		}
		return 'live' === $mode && ! empty( $options['baseline'] ) && ! empty( $options['confirmed'] ) && ! self::conflicts();
	}

	/**
	 * Check whether this request may use public optimizations.
	 *
	 * @return bool
	 */
	public static function eligible(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! self::enabled() ) {
			return false;
		}
		foreach ( array( 'is_cart', 'is_checkout', 'is_account_page' ) as $fn ) {
			if ( function_exists( $fn ) && $fn() ) {
				return false;
			}
		}
		if ( self::preview() ) {
			return true;
		}
		return ! FAWS_Plugin::sensitive();
	}

	/**
	 * Validate a bounded list of exact resource rules.
	 *
	 * @param string $raw JSON list supplied in the administrator form.
	 * @return array
	 */
	public static function validate_rules( string $raw ): array {
		$rules = json_decode( $raw, true );
		if ( ! is_array( $rules ) || count( $rules ) > 30 || array_values( $rules ) !== $rules ) {
			return array();
		}
		$result = array();
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || ! is_string( $rule['handle'] ?? null ) || ! preg_match( '/^[a-z0-9_.-]{1,100}$/D', $rule['handle'] ) || ! in_array( $rule['type'] ?? '', array( 'script', 'style' ), true ) || ! in_array( $rule['context'] ?? '', array( 'home', 'page', 'single', 'shop', 'product', 'search' ), true ) || ! in_array( $rule['action'] ?? '', array( 'unload', 'defer' ), true ) || ( 'style' === $rule['type'] && 'defer' === $rule['action'] ) ) {
				return array();
			}
			$result[] = array_intersect_key( $rule, array_flip( array( 'handle', 'type', 'context', 'action' ) ) );
		}
		return $result;
	}

	/**
	 * Check the registered transitive dependency graph with cycle protection.
	 *
	 * @param WP_Dependencies $registry Registered WordPress dependencies.
	 * @param string          $handle Exact resource handle.
	 * @param string          $target Dependency handle to locate.
	 * @param array           $seen Handles already visited in the current graph traversal.
	 * @return bool
	 */
	private static function depends( $registry, string $handle, string $target, array &$seen = array() ): bool {
		if ( count( $seen ) >= 100 ) {
			return true;
		}
		if ( isset( $seen[ $handle ] ) || ! isset( $registry->registered[ $handle ] ) ) {
			return false;
		}
		$seen[ $handle ] = true;
		foreach ( $registry->registered[ $handle ]->deps as $dependency ) {
			if ( $dependency === $target || self::depends( $registry, $dependency, $target, $seen ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Reject protected, dynamic, inline or depended-on resources.
	 *
	 * @param WP_Dependencies $registry Registered WordPress dependencies.
	 * @param string          $handle Exact resource handle.
	 * @return string
	 */
	public static function guard( $registry, string $handle ): string {
		if ( ! isset( $registry->registered[ $handle ] ) || ! in_array( $handle, $registry->queue, true ) || in_array( $handle, $registry->done, true ) ) {
			return 'not_queued_or_already_printed';
		}
		$asset = $registry->registered[ $handle ];
		if ( ! is_string( $asset->src ) || '' === $asset->src ) {
			return 'protected_core_or_inline';
		}
		$scheme = wp_parse_url( $asset->src, PHP_URL_SCHEME );
		if ( $scheme && ! in_array( $scheme, array( 'https', 'http' ), true ) ) {
			return 'external_or_dynamic_resource';
		}
		if ( preg_match( '/jquery|woocommerce|wc-|checkout|cart|payment|gateway|captcha|security|nonce|login|form|menu|navigation|mobile|variation|shipping|tax|^wp-|react|elementor|block|polyfill|runtime|bootstrap|faws-/i', $handle ) || preg_match( '#woocommerce|checkout|payment|gateway|captcha|security|/wp-includes/|/wp-admin/#i', (string) $asset->src ) ) {
			return 'protected_critical_resource';
		}
		if ( ! is_string( $asset->src ) || '' === $asset->src || preg_match( '#/wp-includes/|/wp-admin/#', $asset->src ) ) {
			return 'protected_core_or_inline';
		}
		$host = wp_parse_url( $asset->src, PHP_URL_HOST );
		if ( $host && wp_parse_url( home_url(), PHP_URL_HOST ) !== $host ) {
			return 'external_or_dynamic_resource';
		}
		$query = wp_parse_url( $asset->src, PHP_URL_QUERY );
		if ( $query ) {
			parse_str( $query, $params );
			if ( array_diff( array_keys( $params ), array( 'ver' ) ) ) {
				return 'external_or_dynamic_resource';
			}
		}
		foreach ( array( 'before', 'after', 'data', 'textdomain', 'conditional', 'type' ) as $key ) {
			if ( ! empty( $asset->extra[ $key ] ) ) {
				return 'inline_or_localized_or_special';
			}
		}
		if ( ! empty( $asset->extra['strategy'] ) && 'defer' !== $asset->extra['strategy'] ) {
			return 'existing_async_strategy';
		}
		$seen = array();
		foreach ( array_unique( array_merge( $registry->queue, $registry->to_do ) ) as $other ) {
			if ( $other !== $handle && self::depends( $registry, $other, $handle, $seen ) ) {
				return 'required_by_another_resource';
			}
		}
		return '';
	}

	/**
	 * Apply eligible exact rules through WordPress enqueue APIs.
	 *
	 * @return void
	 */
	public static function apply(): void {
		if ( ! self::eligible() ) {
			return;
		}
		$options   = get_option( 'faws_advanced', array() );
		$decisions = array();
		$context   = self::context();
		foreach ( $options['rules'] ?? array() as $rule ) {
			if ( $rule['context'] !== $context ) {
				continue;
			}
			$registry = 'script' === $rule['type'] ? wp_scripts() : wp_styles();
			$reason   = self::guard( $registry, $rule['handle'] );
			if ( '' === $reason ) {
				if ( 'defer' === $rule['action'] ) {
					wp_script_add_data( $rule['handle'], 'strategy', 'defer' );
				} elseif ( 'script' === $rule['type'] ) {
					wp_dequeue_script( $rule['handle'] );
				} else {
					wp_dequeue_style( $rule['handle'] );
				}
			}
			$decisions[] = array_merge( $rule, array( 'result' => $reason ? $reason : 'applied' ) );
		}
		if ( ! empty( $options['emoji'] ) ) {
			remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
			remove_action( 'wp_print_styles', 'print_emoji_styles' );
			remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
			wp_dequeue_style( 'wp-emoji-styles' );
		}
		if ( self::preview() ) {
			update_option(
				'faws_advanced_preview',
				array(
					'time'      => time(),
					'context'   => $context,
					'decisions' => $decisions,
					'emoji'     => ! empty( $options['emoji'] ),
				),
				false
			);
		}
	}

	/**
	 * Hide only the optional WordPress news widget.
	 *
	 * @return void
	 */
	public static function dashboard(): void {
		$options = get_option( 'faws_advanced', array() );
		if ( self::enabled() && current_user_can( 'manage_options' ) && ! empty( $options['dashboard_news'] ) ) {
			remove_meta_box( 'dashboard_primary', 'dashboard', 'side' );
		}
	}

	/**
	 * Change the interval only on the dashboard, preserving editors.
	 *
	 * @param array $settings Native Heartbeat settings.
	 * @return array
	 */
	public static function heartbeat( array $settings ): array {
		$options = get_option( 'faws_advanced', array() );
		$screen  = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( self::enabled() && current_user_can( 'manage_options' ) && ( ( $screen && 'dashboard' === $screen->base ) || ( ! $screen && is_admin() && 'index.php' === ( $GLOBALS['pagenow'] ?? '' ) ) ) && ! empty( $options['heartbeat'] ) ) {
			$settings['interval'] = 60;
		}
		return $settings;
	}

	/**
	 * Validate and dispatch administrator-only settings actions.
	 *
	 * @return void
	 */
	public static function action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی مجاز نیست.', 'fa-wp-speedup' ), 403 );
		}
		check_admin_referer( 'faws_advanced' );
		$op = sanitize_key( is_string( $_POST['op'] ?? null ) ? wp_unslash( $_POST['op'] ) : '' );
		if ( 'rollback' === $op ) {
			update_option( 'faws_advanced', get_option( 'faws_advanced_previous', array() ), false );
		} elseif ( 'off' === $op ) {
			$options         = get_option( 'faws_advanced', array() );
			$options['mode'] = 'off';
			update_option( 'faws_advanced', $options, false );
		} elseif ( 'save' === $op ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Legacy JSON is strictly validated before persistence.
			$raw = is_string( $_POST['rules'] ?? null ) ? wp_unslash( $_POST['rules'] ) : '[]';
			if ( isset( $_POST['rule_rows'] ) && is_array( $_POST['rule_rows'] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each bounded field is sanitized and validated below.
				$rows  = wp_unslash( $_POST['rule_rows'] );
				$input = array();
				if ( count( $rows ) > 30 ) {
					$raw = 'invalid';
				} else {
					foreach ( $rows as $row ) {
						if ( ! is_array( $row ) ) {
							$input = false;
							break; }
						if ( '' === ( $row['handle'] ?? '' ) ) {
							continue; }
						$item = array();
						foreach ( array( 'handle', 'type', 'context', 'action' ) as $key ) {
							$item[ $key ] = is_string( $row[ $key ] ?? null ) ? sanitize_text_field( $row[ $key ] ) : '';
						}
						$input[] = $item;
					}
					$raw = wp_json_encode( $input );
				}
			}
			$mode  = is_string( $_POST['mode'] ?? null ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'off';
			$rules = strlen( $raw ) <= 15000 ? self::validate_rules( $raw ) : array();
			if ( strlen( $raw ) > 15000 || ( empty( $rules ) && array() !== json_decode( $raw, true ) ) ) {
				set_transient( 'faws_advanced_notice_' . get_current_user_id(), __( 'قواعد نامعتبر است؛ تنظیمات قبلی حفظ شد.', 'fa-wp-speedup' ), MINUTE_IN_SECONDS );
				wp_safe_redirect( admin_url( 'options-general.php?page=faws#advanced-rules' ) );
				exit;
			}
			$options = array(
				'rules' => $rules,
				'mode'  => in_array( $mode, array( 'off', 'preview', 'live' ), true ) ? $mode : 'off',
			);
			foreach ( array( 'baseline', 'confirmed', 'emoji', 'dashboard_news', 'heartbeat' ) as $key ) {
				$options[ $key ] = ! empty( $_POST[ $key ] );
			}
			if ( 'live' === $options['mode'] && ( ! $options['baseline'] || ! $options['confirmed'] || self::conflicts() ) ) {
				$options['mode'] = 'preview';
			}
			update_option( 'faws_advanced_previous', get_option( 'faws_advanced', array() ), false );
			update_option( 'faws_advanced', $options, false );
		}
		wp_safe_redirect( admin_url( 'options-general.php?page=faws#advanced-rules' ) );
		exit;
	}

	/**
	 * Render opt-in controls, status and rollback actions.
	 *
	 * @return void
	 */
	public static function panel(): void {
		$options = get_option( 'faws_advanced', array() );
		$notice  = get_transient( 'faws_advanced_notice_' . get_current_user_id() );
		if ( $notice ) {
			echo '<p role="alert">' . esc_html( $notice ) . '</p>';
		}
		echo '<h2 id="advanced-rules">' . esc_html__( 'قواعد پیشرفتهٔ صفحه و پیشخوان', 'fa-wp-speedup' ) . '</h2><p>' . esc_html__( 'حذف فایل یا defer ممکن است صفحه را مختل کند. ابتدا baseline و خرید آزمایشی را ثبت کنید و در حالت پیش‌نمایش نتیجه را ببینید. فقط handle محلی، مستقل و بدون inline/localization پذیرفته می‌شود. منابع حیاتی، وابستگی‌ها، مسیرهای حساس و درخواست نشست‌دار محافظت می‌شوند. ترکیب و تأخیر اجرایی اسکریپت وجود ندارد.', 'fa-wp-speedup' ) . '</p>';
		echo '<p>' . esc_html__( 'حالت پیش‌نمایش فقط برای مدیر با لینک nonceدار است و هدر عدم کش دارد. cache/proxy ناسازگار باید در staging بررسی شود. حالت عمومی با شناسایی افزونهٔ بهینه‌ساز دیگر متوقف می‌شود. خاموش‌کردن فوری یا بازگردانی تنظیمات، قواعد را برمی‌گرداند؛ HTML کش‌شده را از ابزار کش فعلی تخلیه کنید.', 'fa-wp-speedup' ) . '</p>';
		echo '<p dir="ltr">' . esc_html( implode( ', ', self::conflicts() ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'faws_advanced' );
		echo '<input type="hidden" name="action" value="faws_advanced"><input type="hidden" name="op" value="save"><select name="mode">';
		foreach ( array(
			'off'     => __( 'خاموش', 'fa-wp-speedup' ),
			'preview' => __( 'پیش‌نمایش مدیر', 'fa-wp-speedup' ),
			'live'    => __( 'اعمال عمومی پس از تأیید', 'fa-wp-speedup' ),
		) as $mode => $label ) {
			echo '<option value="' . esc_attr( $mode ) . '" ' . selected( $options['mode'] ?? 'off', $mode, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><p>' . esc_html__( 'نام دقیق منبع (handle) را از گزارش کپی کنید، نوع صفحه و تغییر را انتخاب کنید. برای حذف یک قاعده نام آن را خالی کنید. پس از ذخیره، ردیف‌های خالی تازه تا سقف ۳۰ قاعده نمایش داده می‌شوند.', 'fa-wp-speedup' ) . '</p><table class="widefat"><thead><tr>';
		foreach ( array( __( 'نام منبع', 'fa-wp-speedup' ), __( 'نوع', 'fa-wp-speedup' ), __( 'صفحه', 'fa-wp-speedup' ), __( 'تغییر', 'fa-wp-speedup' ) ) as $label ) {
			echo '<th>' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		$rules        = array_values( $options['rules'] ?? array() );
		$fields       = array(
			'type'    => array(
				'script' => 'JavaScript',
				'style'  => 'CSS',
			),
			'context' => array(
				'home'    => __( 'خانه', 'fa-wp-speedup' ),
				'page'    => __( 'برگه', 'fa-wp-speedup' ),
				'single'  => __( 'نوشته', 'fa-wp-speedup' ),
				'shop'    => __( 'فروشگاه', 'fa-wp-speedup' ),
				'product' => __( 'محصول', 'fa-wp-speedup' ),
				'search'  => __( 'جست‌وجو', 'fa-wp-speedup' ),
			),
			'action'  => array(
				'unload' => __( 'حذف در این صفحه', 'fa-wp-speedup' ),
				'defer'  => __( 'defer فقط JavaScript', 'fa-wp-speedup' ),
			),
		);
		$field_labels = array(
			'type'    => __( 'نوع', 'fa-wp-speedup' ),
			'context' => __( 'صفحه', 'fa-wp-speedup' ),
			'action'  => __( 'تغییر', 'fa-wp-speedup' ),
		);
		$row_count    = min( 30, count( $rules ) + 5 );
		for ( $row = 0; $row < $row_count; ++$row ) {
			echo '<tr><td><input dir="ltr" aria-label="' . esc_attr__( 'نام دقیق منبع', 'fa-wp-speedup' ) . '" name="rule_rows[' . esc_attr( $row ) . '][handle]" maxlength="100" value="' . esc_attr( $rules[ $row ]['handle'] ?? '' ) . '"></td>';
			foreach ( $fields as $key => $choices ) {
				echo '<td><select aria-label="' . esc_attr( $field_labels[ $key ] ) . '" name="rule_rows[' . esc_attr( $row ) . '][' . esc_attr( $key ) . ']">';
				foreach ( $choices as $value => $label ) {
					echo '<option value="' . esc_attr( $value ) . '" ' . selected( $rules[ $row ][ $key ] ?? array_key_first( $choices ), $value, false ) . '>' . esc_html( $label ) . '</option>';
				}
				echo '</select></td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		foreach ( array(
			'baseline'       => __( 'baseline و سناریوی خرید را در staging ثبت کرده‌ام', 'fa-wp-speedup' ),
			'confirmed'      => __( 'نتیجهٔ پیش‌نمایش را بررسی کرده‌ام و ریسک اعمال عمومی را تأیید می‌کنم', 'fa-wp-speedup' ),
			'emoji'          => __( 'حذف بارگذار emoji وردپرس فقط در صفحات عمومی مجاز؛ نمایش emoji به مرورگر سپرده می‌شود', 'fa-wp-speedup' ),
			'dashboard_news' => __( 'حذف فقط ابزارک اخبار وردپرس از پیشخوان مدیر؛ با خاموش‌کردن برمی‌گردد', 'fa-wp-speedup' ),
			'heartbeat'      => __( 'فاصلهٔ Heartbeat برابر ۶۰ ثانیه فقط در صفحهٔ اصلی پیشخوان؛ ویرایشگر و قفل نوشته تغییر نمی‌کنند', 'fa-wp-speedup' ),
		) as $key => $label ) {
			echo '<p><label><input type="checkbox" name="' . esc_attr( $key ) . '" value="1" ' . checked( ! empty( $options[ $key ] ), true, false ) . '> ' . esc_html( $label ) . '</label></p>';
		}
		submit_button( __( 'ذخیرهٔ قواعد', 'fa-wp-speedup' ) );
		echo '</form>';
		foreach ( array(
			'off'      => __( 'خاموش‌کردن فوری', 'fa-wp-speedup' ),
			'rollback' => __( 'بازگردانی قواعد قبلی', 'fa-wp-speedup' ),
		) as $op => $label ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'faws_advanced' );
			echo '<input type="hidden" name="action" value="faws_advanced"><input type="hidden" name="op" value="' . esc_attr( $op ) . '">';
			submit_button( $label, 'secondary' );
			echo '</form>';
		}
		foreach ( get_posts(
			array(
				'post_type'      => array( 'product', 'page', 'post' ),
				'post_status'    => 'publish',
				'posts_per_page' => 5,
			)
		) as $post ) {
			echo '<p><a href="' . esc_url( wp_nonce_url( get_permalink( $post ), 'faws_preview', 'faws_preview' ) ) . '">' . esc_html__( 'پیش‌نمایش: ', 'fa-wp-speedup' ) . esc_html( get_the_title( $post ) ) . '</a></p>';
		}
		echo '<p><a href="' . esc_url( wp_nonce_url( home_url( '/' ), 'faws_preview', 'faws_preview' ) ) . '">' . esc_html__( 'پیش‌نمایش صفحهٔ اصلی', 'fa-wp-speedup' ) . '</a> | <a href="' . esc_url( wp_nonce_url( admin_url( 'index.php' ), 'faws_preview', 'faws_preview' ) ) . '">' . esc_html__( 'پیش‌نمایش پیشخوان', 'fa-wp-speedup' ) . '</a></p><p>' . esc_html__( 'برای صفحهٔ دیگر سایت، پارامتر faws_preview همین لینک را هنگام ورود مدیر به URL همان صفحه اضافه کنید؛ پارامتر را منتشر نکنید.', 'fa-wp-speedup' ) . '</p><pre dir="ltr" style="white-space:pre-wrap">' . esc_html( wp_json_encode( get_option( 'faws_advanced_preview', array() ), JSON_PRETTY_PRINT ) ) . '</pre>';
	}
}
