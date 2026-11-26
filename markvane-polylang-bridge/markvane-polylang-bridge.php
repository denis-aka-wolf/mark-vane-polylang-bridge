<?php
/**
 * Plugin Name: Mark Vane Polylang Bridge
 * Description: Preserves legacy RU/EN page-pair metadata and registers translations with Polylang.
 * Version: 1.3.0
 * Author: Mark Vane
 * License: GPL-2.0-or-later
 * Text Domain: mark-vane-polylang-bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mark_Vane_Polylang_Bridge {
	const VERSION = '1.3.0';
	const REST_NAMESPACE = 'mark-vane-polylang-bridge/v1';

	public static function boot() {
		add_action( 'init', array( __CLASS__, 'maybe_auto_sync' ), 99 );
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
		add_action( 'admin_post_mvpb_sync', array( __CLASS__, 'handle_manual_sync' ) );
		add_action( 'admin_post_mvpb_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	/**
	 * Read legacy pair data without changing or deleting any metadata.
	 * The old plugin stored JSON objects with "ru" and "en" post IDs.
	 * We deliberately inspect all page meta keys because older releases did
	 * not expose a stable public meta-key contract.
	 */
	public static function discover_pairs() {
		$page_ids = get_posts(
			array(
				'post_type'              => 'page',
				'post_status'            => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'numberposts'            => -1,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
			)
		);

		$pairs = array();
		foreach ( $page_ids as $page_id ) {
			$all_meta = get_post_meta( $page_id );
			foreach ( $all_meta as $values ) {
				foreach ( (array) $values as $raw_value ) {
					$candidates = array();
					if ( is_string( $raw_value ) ) {
						$decoded = json_decode( $raw_value, true );
						if ( is_array( $decoded ) ) {
							$candidates[] = $decoded;
						}
						$unserialized = maybe_unserialize( $raw_value );
						if ( is_array( $unserialized ) ) {
							$candidates[] = $unserialized;
						}
					} elseif ( is_array( $raw_value ) ) {
						$candidates[] = $raw_value;
					}

					foreach ( $candidates as $candidate ) {
						if ( ! isset( $candidate['ru'], $candidate['en'] ) ) {
							continue;
						}
						$ru_id = absint( $candidate['ru'] );
						$en_id = absint( $candidate['en'] );
						if ( ! $ru_id || ! $en_id || $ru_id === $en_id ) {
							continue;
						}
						$ru_post = get_post( $ru_id );
						$en_post = get_post( $en_id );
						if ( ! $ru_post || ! $en_post || 'page' !== $ru_post->post_type || 'page' !== $en_post->post_type ) {
							continue;
						}
						$key = $ru_id . ':' . $en_id;
						$pairs[ $key ] = array(
							'ru' => $ru_id,
							'en' => $en_id,
						);
					}
				}
			}
		}

		return array_values( $pairs );
	}

	public static function maybe_auto_sync() {
		$auto_sync = get_option( 'mvpb_auto_sync', 1 );
		if ( 1 === (int) $auto_sync ) {
			self::sync_legacy_pairs();
		}
	}

	public static function sync_legacy_pairs() {
		if ( ! function_exists( 'pll_save_post_translations' ) || ! function_exists( 'pll_get_post_language' ) || ! function_exists( 'pll_set_post_language' ) || ! function_exists( 'pll_get_post_translations' ) || ! function_exists( 'pll_languages_list' ) ) {
			return;
		}

		$languages = pll_languages_list( array( 'fields' => 'slug' ) );
		if ( ! in_array( 'ru', (array) $languages, true ) || ! in_array( 'en', (array) $languages, true ) ) {
			return;
		}

		foreach ( self::discover_pairs() as $pair ) {
			$ru_id = $pair['ru'];
			$en_id = $pair['en'];
			$ru_lang = pll_get_post_language( $ru_id, 'slug' );
			$en_lang = pll_get_post_language( $en_id, 'slug' );

			// Fill only missing language assignments. Never silently overwrite
			// an existing language choice made in the WordPress editor.
			if ( ! $ru_lang ) {
				pll_set_post_language( $ru_id, 'ru' );
				$ru_lang = pll_get_post_language( $ru_id, 'slug' );
			}
			if ( ! $en_lang ) {
				pll_set_post_language( $en_id, 'en' );
				$en_lang = pll_get_post_language( $en_id, 'slug' );
			}

			// A conflicting assignment needs a human decision, not an override.
			if ( 'ru' !== $ru_lang || 'en' !== $en_lang ) {
				continue;
			}

			$current = pll_get_post_translations( $ru_id );
			if ( is_array( $current ) && isset( $current['ru'], $current['en'] )
				&& (int) $current['ru'] === $ru_id && (int) $current['en'] === $en_id ) {
				continue;
			}

			pll_save_post_translations(
				array(
					'ru' => $ru_id,
					'en' => $en_id,
				)
			);
		}
	}


	public static function maybe_schedule_initial_sync() {
		if ( get_option( 'mvpb_initial_sync_version' ) === self::VERSION ) { return; }
		if ( 1 === (int) get_option( 'mvpb_auto_sync', 1 ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 10, self::CRON_HOOK );
		}
		update_option( 'mvpb_initial_sync_version', self::VERSION, false );
	}

	public static function invalidate_pair_cache() {
		delete_transient( self::PAIR_CACHE_KEY );
	}

	public static function watch_pair_metadata( $meta_id, $post_id, $meta_key, $meta_value ) {
		self::invalidate_pair_cache();
		if ( ! is_string( $meta_value ) || 1 !== (int) get_option( 'mvpb_auto_sync', 1 ) ) { return; }
		$pair = json_decode( $meta_value, true );
		if ( is_array( $pair ) && ! empty( $pair['ru'] ) && ! empty( $pair['en'] ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 15, self::CRON_HOOK );
		}
	}

	public static function run_scheduled_sync() {
		if ( 1 === (int) get_option( 'mvpb_auto_sync', 1 ) ) { self::sync_legacy_pairs(); }
	}

	private static function log_activity( $action, $ru_id, $en_id, $message ) {
		$log = get_option( self::LOG_OPTION, array() );
		if ( ! is_array( $log ) ) { $log = array(); }
		array_unshift( $log, array(
			'time' => current_time( 'mysql' ),
			'action' => sanitize_key( $action ),
			'ru' => absint( $ru_id ),
			'en' => absint( $en_id ),
			'message' => sanitize_text_field( $message ),
		) );
		update_option( self::LOG_OPTION, array_slice( $log, 0, 100 ), false );
	}

	public static function register_admin_page() {
		add_menu_page(
			'Polylang Bridge',
			'Polylang Bridge',
			'manage_options',
			'mark-vane-polylang-bridge',
			array( __CLASS__, 'render_admin_page' ),
			'dashicons-translation',
			58
		);
	}

	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$pairs = self::discover_pairs();
		$polylang_ready = function_exists( 'pll_save_post_translations' ) && function_exists( 'pll_get_post_language' ) && function_exists( 'pll_get_post_translations' ) && function_exists( 'pll_languages_list' );
		$auto_sync = (int) get_option( 'mvpb_auto_sync', 1 );
		$languages = $polylang_ready ? pll_languages_list( array( 'fields' => 'slug' ) ) : array();
		$missing_languages = $polylang_ready && ( ! in_array( 'ru', (array) $languages, true ) || ! in_array( 'en', (array) $languages, true ) );
		?>
		<div class="wrap">
			<h1>Mark Vane Polylang Bridge</h1>
			<?php if ( isset( $_GET['mvpb_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Настройки сохранены.</p></div><?php endif; ?>
			<?php if ( isset( $_GET['mvpb_synced'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Синхронизация завершена.</p></div><?php endif; ?>
			<p>Плагин обнаруживает существующие пары страниц из старых метаданных и связывает их через штатный API Polylang. Содержимое страниц, URL, меню и исходные метаданные не изменяются.</p>
			<div style="display:flex;gap:12px;flex-wrap:wrap;margin:18px 0">
				<div class="card" style="max-width:240px;margin:0;padding:14px 18px"><h2 style="margin:0 0 6px">Polylang API</h2><strong><?php echo $polylang_ready ? '<span style="color:#16803c">Доступен</span>' : '<span style="color:#b32d2e">Недоступен</span>'; ?></strong></div>
				<div class="card" style="max-width:240px;margin:0;padding:14px 18px"><h2 style="margin:0 0 6px">Найдено пар</h2><strong><?php echo esc_html( count( $pairs ) ); ?></strong></div>
				<div class="card" style="max-width:240px;margin:0;padding:14px 18px"><h2 style="margin:0 0 6px">Автосинхронизация</h2><strong><?php echo $auto_sync ? 'Включена' : 'Выключена'; ?></strong></div>
			</div>
			<?php if ( $missing_languages ) : ?><div class="notice notice-warning"><p>В Polylang должны быть настроены языки с кодами <code>ru</code> и <code>en</code>.</p></div><?php endif; ?>
			
			<table class="widefat striped">
				<thead><tr><th>RU ID</th><th>Русская страница</th><th>Язык</th><th>EN ID</th><th>English page</th><th>Язык</th><th>Связь Polylang</th></tr></thead>
				<tbody>
				<?php foreach ( $pairs as $pair ) :
					$ru = get_post( $pair['ru'] );
					$en = get_post( $pair['en'] );
					$ru_lang = $polylang_ready ? pll_get_post_language( $pair['ru'], 'slug' ) : '';
					$en_lang = $polylang_ready ? pll_get_post_language( $pair['en'], 'slug' ) : '';
					$translations = $polylang_ready ? pll_get_post_translations( $pair['ru'] ) : array();
					$linked = is_array( $translations ) && isset( $translations['en'] ) && (int) $translations['en'] === (int) $pair['en'];
					?>
					<tr>
						<td><?php echo esc_html( $pair['ru'] ); ?></td>
						<td><?php echo esc_html( $ru ? $ru->post_title : '' ); ?></td>
						<td><?php echo esc_html( $ru_lang ? $ru_lang : 'не назначен' ); ?></td>
						<td><?php echo esc_html( $pair['en'] ); ?></td>
						<td><?php echo esc_html( $en ? $en->post_title : '' ); ?></td>
						<td><?php echo esc_html( $en_lang ? $en_lang : 'не назначен' ); ?></td>
						<td><?php echo $linked ? 'связаны' : 'не связаны / конфликт языков'; ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( empty( $pairs ) ) : ?><tr><td colspan="7">Пары в метаданных не найдены.</td></tr><?php endif; ?>
				</tbody>
			</table>
			<h2 style="margin-top:28px">Настройки</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:12px 0 22px">
				<input type="hidden" name="action" value="mvpb_save_settings">
				<?php wp_nonce_field( 'mvpb_save_settings' ); ?>
				<label><input type="checkbox" name="auto_sync" value="1" <?php checked( 1, $auto_sync ); ?>> Автоматически синхронизировать найденные пары при загрузке сайта</label>
				<p class="description">Если выключить, связи будут обновляться только после ручного запуска синхронизации.</p>
				<?php submit_button( 'Сохранить настройки', 'secondary', 'submit', false ); ?>
			</form>
			<h2>Синхронизация</h2>
			<?php if ( $polylang_ready && ! $missing_languages ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
					<input type="hidden" name="action" value="mvpb_sync">
					<?php wp_nonce_field( 'mvpb_sync' ); ?>
					<?php submit_button( 'Синхронизировать пары с Polylang', 'primary', 'submit', false ); ?>
				</form>
			<?php else : ?>
				<p>Для синхронизации необходимо активировать Polylang и настроить языки с кодами <code>ru</code> и <code>en</code>.</p>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'mark-vane-polylang-bridge' ), 403 );
		}
		check_admin_referer( 'mvpb_save_settings' );
		update_option( 'mvpb_auto_sync', isset( $_POST['auto_sync'] ) ? 1 : 0, false );
		wp_safe_redirect( add_query_arg( 'mvpb_saved', '1', admin_url( 'admin.php?page=mark-vane-polylang-bridge' ) ) );
		exit;
	}

	public static function handle_manual_sync() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'mark-vane-polylang-bridge' ), 403 );
		}
		check_admin_referer( 'mvpb_sync' );
		self::sync_legacy_pairs();
		wp_safe_redirect( add_query_arg( 'mvpb_synced', '1', admin_url( 'admin.php?page=mark-vane-polylang-bridge' ) ) );
		exit;
	}

	public static function register_rest_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/pairs',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_get_pairs' ),
				'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/sync',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_sync_pairs' ),
				'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			)
		);
	}

	public static function rest_get_pairs() {
		$result = array();
		foreach ( self::discover_pairs() as $pair ) {
			$result[] = array(
				'ru' => $pair['ru'],
				'en' => $pair['en'],
				'ru_title' => get_the_title( $pair['ru'] ),
				'en_title' => get_the_title( $pair['en'] ),
				'ru_language' => function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $pair['ru'], 'slug' ) : null,
				'en_language' => function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $pair['en'], 'slug' ) : null,
				'translations' => function_exists( 'pll_get_post_translations' ) ? pll_get_post_translations( $pair['ru'] ) : array(),
			);
		}
		return rest_ensure_response( $result );
	}

	public static function rest_sync_pairs() {
		if ( ! function_exists( 'pll_save_post_translations' ) ) {
			return new WP_Error( 'mvpb_polylang_unavailable', 'Polylang API недоступен.', array( 'status' => 503 ) );
		}
		self::sync_legacy_pairs();
		return rest_ensure_response( array( 'success' => true, 'pairs' => self::discover_pairs() ) );
	}
}

Mark_Vane_Polylang_Bridge::boot();
