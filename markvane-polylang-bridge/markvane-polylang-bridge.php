<?php
/**
 * Plugin Name: Mark Vane Polylang Bridge
 * Description: Preserves legacy RU/EN page-pair metadata and registers translations with Polylang.
 * Version: 1.4.0
 * Author: Mark Vane
 * License: GPL-2.0-or-later
 * Text Domain: mark-vane-polylang-bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mark_Vane_Polylang_Bridge {
	const VERSION = '1.4.0';
	const CRON_HOOK = 'mvpb_run_scheduled_sync';
	const PAIR_CACHE_KEY = 'mvpb_pairs_cache';
	const MANAGED_OPTION = 'mvpb_managed_pairs';
	const IGNORED_OPTION = 'mvpb_ignored_pairs';
	const LOG_OPTION = 'mvpb_activity_log';
	const REST_NAMESPACE = 'mark-vane-polylang-bridge/v1';

	public static function boot() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_schedule_initial_sync' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_scheduled_sync' ) );
		add_action( 'added_post_meta', array( __CLASS__, 'watch_pair_metadata' ), 10, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'watch_pair_metadata' ), 10, 4 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'invalidate_pair_cache' ), 10, 4 );
		add_action( 'save_post_page', array( __CLASS__, 'invalidate_pair_cache' ), 10, 1 );
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
		add_action( 'admin_post_mvpb_sync', array( __CLASS__, 'handle_manual_sync' ) );
		add_action( 'admin_post_mvpb_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_mvpb_save_pair', array( __CLASS__, 'handle_save_pair' ) );
		add_action( 'admin_post_mvpb_unlink_pair', array( __CLASS__, 'handle_unlink_pair' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	/**
	 * Read legacy pair data without changing or deleting any metadata.
	 * The old plugin stored JSON objects with "ru" and "en" post IDs.
	 * We deliberately inspect all page meta keys because older releases did
	 * not expose a stable public meta-key contract.
	 */
	public static function discover_pairs( $force_refresh = false ) {
		if ( ! $force_refresh ) {
			$cached = get_transient( self::PAIR_CACHE_KEY );
			if ( is_array( $cached ) ) { return $cached; }
		}
		$ignored = get_option( self::IGNORED_OPTION, array() );
		$managed = get_option( self::MANAGED_OPTION, array() );
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
						if ( isset( $ignored[ $key ] ) ) { continue; }
						$pairs[ $key ] = array(
							'ru' => $ru_id,
							'en' => $en_id,
						);
					}
				}
			}
		}

		foreach ( (array) $managed as $managed_pair ) {
			if ( isset( $managed_pair['ru'], $managed_pair['en'] ) ) {
				$ru_id = absint( $managed_pair['ru'] );
				$en_id = absint( $managed_pair['en'] );
				if ( $ru_id && $en_id && $ru_id !== $en_id && get_post_type( $ru_id ) === 'page' && get_post_type( $en_id ) === 'page' ) {
					$pairs[ $ru_id . ':' . $en_id ] = array( 'ru' => $ru_id, 'en' => $en_id );
				}
			}
		}
		$result = array_values( $pairs );
		set_transient( self::PAIR_CACHE_KEY, $result, 300 );
		return $result;
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


			$ru_translations = pll_get_post_translations( $ru_id );
			$en_translations = pll_get_post_translations( $en_id );
			foreach ( array( (array) $ru_translations, (array) $en_translations ) as $translation_map ) {
				foreach ( $translation_map as $translation_id ) {
					if ( (int) $translation_id !== $ru_id && (int) $translation_id !== $en_id ) {
						continue 2;
					}
				}
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


	public static function activate() {
		if ( 1 === (int) get_option( 'mvpb_auto_sync', 1 ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 10, self::CRON_HOOK );
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


	public static function handle_save_pair() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Недостаточно прав.', 403 ); }
		check_admin_referer( 'mvpb_save_pair' );
		$ru_id = isset( $_POST['ru_id'] ) ? absint( $_POST['ru_id'] ) : 0;
		$en_id = isset( $_POST['en_id'] ) ? absint( $_POST['en_id'] ) : 0;
		$old_ru = isset( $_POST['old_ru_id'] ) ? absint( $_POST['old_ru_id'] ) : 0;
		$old_en = isset( $_POST['old_en_id'] ) ? absint( $_POST['old_en_id'] ) : 0;
		$ru = get_post( $ru_id );
		$en = get_post( $en_id );
		if ( ! $ru || ! $en || 'page' !== $ru->post_type || 'page' !== $en->post_type || $ru_id === $en_id ) {
			wp_die( 'Выберите две разные существующие страницы.', 400 );
		}
		if ( ! function_exists( 'pll_get_post_language' ) || ! function_exists( 'pll_set_post_language' ) || ! function_exists( 'pll_save_post_translations' ) || ! function_exists( 'pll_get_post_translations' ) || ! function_exists( 'pll_languages_list' ) ) {
			wp_die( 'Polylang API недоступен.', 503 );
		}
		$languages = pll_languages_list( array( 'fields' => 'slug' ) );
		if ( ! in_array( 'ru', (array) $languages, true ) || ! in_array( 'en', (array) $languages, true ) ) {
			wp_die( 'В Polylang должны быть настроены языки ru и en.', 400 );
		}
		if ( $old_ru && $old_en && ( $old_ru !== $ru_id || $old_en !== $en_id ) ) {
			$known = false;
			foreach ( self::discover_pairs( true ) as $known_pair ) {
				if ( (int) $known_pair['ru'] === $old_ru && (int) $known_pair['en'] === $old_en ) { $known = true; break; }
			}
			if ( ! $known ) { wp_die( 'Исходная пара не найдена. Обновите страницу.', 404 ); }
			$old_ru_map = (array) pll_get_post_translations( $old_ru );
			$old_en_map = (array) pll_get_post_translations( $old_en );
			if ( count( $old_ru_map ) > 2 || count( $old_en_map ) > 2 ) {
				wp_die( 'Исходная пара входит в группу с дополнительными языками. Измените её через Polylang.', 409 );
			}
			if ( isset( $old_ru_map['en'] ) && (int) $old_ru_map['en'] === $old_en ) { pll_save_post_translations( array( 'ru' => $old_ru ) ); }
			if ( isset( $old_en_map['ru'] ) && (int) $old_en_map['ru'] === $old_ru ) { pll_save_post_translations( array( 'en' => $old_en ) ); }
		}
		$ru_lang = pll_get_post_language( $ru_id, 'slug' );
		$en_lang = pll_get_post_language( $en_id, 'slug' );
		if ( ( $ru_lang && 'ru' !== $ru_lang ) || ( $en_lang && 'en' !== $en_lang ) ) {
			if ( $old_ru && $old_en ) { pll_save_post_translations( array( 'ru' => $old_ru, 'en' => $old_en ) ); }
			wp_die( 'У выбранной страницы уже назначен другой язык. Сначала исправьте его в Polylang.', 409 );
		}
		$ru_map = (array) pll_get_post_translations( $ru_id );
		$en_map = (array) pll_get_post_translations( $en_id );
		foreach ( array( $ru_map, $en_map ) as $map ) {
			foreach ( $map as $translation_id ) {
				if ( (int) $translation_id !== $ru_id && (int) $translation_id !== $en_id ) {
					if ( $old_ru && $old_en ) { pll_save_post_translations( array( 'ru' => $old_ru, 'en' => $old_en ) ); }
					wp_die( 'Одна из страниц уже входит в другую группу переводов. Объединение групп запрещено.', 409 );
				}
			}
		}
		if ( ! $ru_lang ) { pll_set_post_language( $ru_id, 'ru' ); }
		if ( ! $en_lang ) { pll_set_post_language( $en_id, 'en' ); }
		pll_save_post_translations( array( 'ru' => $ru_id, 'en' => $en_id ) );
		$check = pll_get_post_translations( $ru_id );
		if ( ! is_array( $check ) || ! isset( $check['en'] ) || (int) $check['en'] !== $en_id ) {
			if ( $old_ru && $old_en ) { pll_save_post_translations( array( 'ru' => $old_ru, 'en' => $old_en ) ); }
			wp_die( 'Polylang не подтвердил создание связи. Проверьте пару вручную.', 500 );
		}
		if ( $old_ru && $old_en && ( $old_ru !== $ru_id || $old_en !== $en_id ) ) {
			$old_key = $old_ru . ':' . $old_en;
			$managed_old = get_option( self::MANAGED_OPTION, array() );
			unset( $managed_old[ $old_key ] );
			update_option( self::MANAGED_OPTION, $managed_old, false );
			$ignored_old = get_option( self::IGNORED_OPTION, array() );
			$ignored_old[ $old_key ] = time();
			update_option( self::IGNORED_OPTION, $ignored_old, false );
		}
		$key = $ru_id . ':' . $en_id;
		$managed = get_option( self::MANAGED_OPTION, array() );
		$managed[ $key ] = array( 'ru' => $ru_id, 'en' => $en_id );
		update_option( self::MANAGED_OPTION, $managed, false );
		$ignored = get_option( self::IGNORED_OPTION, array() );
		unset( $ignored[ $key ] );
		update_option( self::IGNORED_OPTION, $ignored, false );
		self::invalidate_pair_cache();
		self::log_activity( $old_ru ? 'pair_updated' : 'pair_created', $ru_id, $en_id, $old_ru ? 'Пара переводов обновлена.' : 'Пара создана через панель управления.' );
		wp_safe_redirect( add_query_arg( 'mvpb_saved_pair', '1', admin_url( 'admin.php?page=mark-vane-polylang-bridge' ) ) );
		exit;
	}

	public static function handle_unlink_pair() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Недостаточно прав.', 403 ); }
		$ru_id = isset( $_POST['ru_id'] ) ? absint( $_POST['ru_id'] ) : 0;
		$en_id = isset( $_POST['en_id'] ) ? absint( $_POST['en_id'] ) : 0;
		check_admin_referer( 'mvpb_unlink_pair_' . $ru_id . '_' . $en_id );
		if ( ! function_exists( 'pll_get_post_translations' ) || ! function_exists( 'pll_save_post_translations' ) ) {
			wp_die( 'Polylang API недоступен.', 503 );
		}
		$ru_map = (array) pll_get_post_translations( $ru_id );
		$en_map = (array) pll_get_post_translations( $en_id );
		if ( count( $ru_map ) > 2 || count( $en_map ) > 2 ) {
			wp_die( 'Группа содержит дополнительные языки. Для безопасности отвяжите её через Polylang.', 409 );
		}
		if ( isset( $ru_map['en'] ) && (int) $ru_map['en'] === $en_id ) {
			pll_save_post_translations( array( 'ru' => $ru_id ) );
		}
		if ( isset( $en_map['ru'] ) && (int) $en_map['ru'] === $ru_id ) {
			pll_save_post_translations( array( 'en' => $en_id ) );
		}
		$after_ru = (array) pll_get_post_translations( $ru_id );
		$after_en = (array) pll_get_post_translations( $en_id );
		if ( ( isset( $after_ru['en'] ) && (int) $after_ru['en'] === $en_id )
			|| ( isset( $after_en['ru'] ) && (int) $after_en['ru'] === $ru_id ) ) {
			wp_die( 'Polylang не подтвердил отвязку. Данные не удалены.', 500 );
		}
		$key = $ru_id . ':' . $en_id;
		$managed = get_option( self::MANAGED_OPTION, array() );
		unset( $managed[ $key ] );
		update_option( self::MANAGED_OPTION, $managed, false );
		$ignored = get_option( self::IGNORED_OPTION, array() );
		$ignored[ $key ] = time();
		update_option( self::IGNORED_OPTION, $ignored, false );
		self::invalidate_pair_cache();
		self::log_activity( 'pair_unlinked', $ru_id, $en_id, 'Связь удалена; страницы и legacy metadata сохранены.' );
		wp_safe_redirect( add_query_arg( 'mvpb_unlinked', '1', admin_url( 'admin.php?page=mark-vane-polylang-bridge' ) ) );
		exit;
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
			<?php if ( isset( $_GET['mvpb_saved_pair'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Пара переводов создана.</p></div><?php endif; ?>
			<?php if ( isset( $_GET['mvpb_unlinked'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Связь удалена. Страницы и метаданные сохранены.</p></div><?php endif; ?>
			<p>Плагин обнаруживает существующие пары страниц из старых метаданных и связывает их через штатный API Polylang. Содержимое страниц, URL, меню и исходные метаданные не изменяются.</p>
			<div style="display:flex;gap:12px;flex-wrap:wrap;margin:18px 0">
				<div class="card" style="max-width:240px;margin:0;padding:14px 18px"><h2 style="margin:0 0 6px">Polylang API</h2><strong><?php echo $polylang_ready ? '<span style="color:#16803c">Доступен</span>' : '<span style="color:#b32d2e">Недоступен</span>'; ?></strong></div>
				<div class="card" style="max-width:240px;margin:0;padding:14px 18px"><h2 style="margin:0 0 6px">Найдено пар</h2><strong><?php echo esc_html( count( $pairs ) ); ?></strong></div>
				<div class="card" style="max-width:240px;margin:0;padding:14px 18px"><h2 style="margin:0 0 6px">Автосинхронизация</h2><strong><?php echo $auto_sync ? 'Включена' : 'Выключена'; ?></strong></div>
			</div>
			<?php if ( $missing_languages ) : ?><div class="notice notice-warning"><p>В Polylang должны быть настроены языки с кодами <code>ru</code> и <code>en</code>.</p></div><?php endif; ?>
			
			<table class="widefat striped">
				<thead><tr><th>RU ID</th><th>Русская страница</th><th>Язык</th><th>EN ID</th><th>English page</th><th>Язык</th><th>Связь Polylang</th><th>Действия</th></tr></thead>
				<tbody>
				<?php foreach ( $pairs as $pair ) :
					$ru = get_post( $pair['ru'] );
					$en = get_post( $pair['en'] );
					$ru_lang = $polylang_ready ? pll_get_post_language( $pair['ru'], 'slug' ) : '';
					$en_lang = $polylang_ready ? pll_get_post_language( $pair['en'], 'slug' ) : '';
					$translations = $polylang_ready ? pll_get_post_translations( $pair['ru'] ) : array();
					$linked = is_array( $translations ) && isset( $translations['en'] ) && (int) $translations['en'] === (int) $pair['en'];
					$edit_url = add_query_arg( array( 'page' => 'mark-vane-polylang-bridge', 'mvpb_edit_ru' => $pair['ru'], 'mvpb_edit_en' => $pair['en'] ), admin_url( 'admin.php' ) );
					?>
					<tr>
						<td><?php echo esc_html( $pair['ru'] ); ?></td>
						<td><?php echo esc_html( $ru ? $ru->post_title : '' ); ?></td>
						<td><?php echo esc_html( $ru_lang ? $ru_lang : 'не назначен' ); ?></td>
						<td><?php echo esc_html( $pair['en'] ); ?></td>
						<td><?php echo esc_html( $en ? $en->post_title : '' ); ?></td>
						<td><?php echo esc_html( $en_lang ? $en_lang : 'не назначен' ); ?></td>
						<td><?php echo $linked ? 'связаны' : 'не связаны / конфликт языков'; ?></td>
						<td><a class="button button-small" href="<?php echo esc_url( $edit_url ); ?>">Изменить</a>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block" onsubmit="return confirm('Отвязать страницы? Сами страницы и исходные метаданные останутся.');"><input type="hidden" name="action" value="mvpb_unlink_pair"><input type="hidden" name="ru_id" value="<?php echo esc_attr( $pair['ru'] ); ?>"><input type="hidden" name="en_id" value="<?php echo esc_attr( $pair['en'] ); ?>"><?php wp_nonce_field( 'mvpb_unlink_pair_' . $pair['ru'] . '_' . $pair['en'] ); ?><button type="submit" class="button button-small">Отвязать</button></form></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( empty( $pairs ) ) : ?><tr><td colspan="8">Пары в метаданных не найдены.</td></tr><?php endif; ?>
				</tbody>
			</table>
			<h2 style="margin-top:28px"><?php echo ( isset( $_GET['mvpb_edit_ru'] ) && isset( $_GET['mvpb_edit_en'] ) ) ? 'Изменить пару переводов' : 'Добавить пару переводов'; ?></h2>
			<p>Выберите две существующие страницы. Языки назначаются только если они ещё не заданы. Конфликтующие языки и существующие группы переводов не перезаписываются.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="mvpb_save_pair">
				<input type="hidden" name="old_ru_id" value="<?php echo isset( $_GET['mvpb_edit_ru'] ) ? absint( $_GET['mvpb_edit_ru'] ) : 0; ?>">
				<input type="hidden" name="old_en_id" value="<?php echo isset( $_GET['mvpb_edit_en'] ) ? absint( $_GET['mvpb_edit_en'] ) : 0; ?>">
				<?php wp_nonce_field( 'mvpb_save_pair' ); ?>
				<table class="form-table"><tr><th>Русская страница</th><td><?php wp_dropdown_pages( array( 'name' => 'ru_id', 'selected' => isset( $_GET['mvpb_edit_ru'] ) ? absint( $_GET['mvpb_edit_ru'] ) : 0, 'show_option_none' => 'Выберите страницу RU', 'post_status' => array( 'publish', 'private', 'draft', 'pending', 'future' ) ) ); ?></td></tr>
				<tr><th>English page</th><td><?php wp_dropdown_pages( array( 'name' => 'en_id', 'selected' => isset( $_GET['mvpb_edit_en'] ) ? absint( $_GET['mvpb_edit_en'] ) : 0, 'show_option_none' => 'Select English page', 'post_status' => array( 'publish', 'private', 'draft', 'pending', 'future' ) ) ); ?></td></tr></table>
				<?php submit_button( ( isset( $_GET['mvpb_edit_ru'] ) && isset( $_GET['mvpb_edit_en'] ) ) ? 'Сохранить изменения' : 'Создать связь' ); ?>
				<?php if ( isset( $_GET['mvpb_edit_ru'] ) ) : ?><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=mark-vane-polylang-bridge' ) ); ?>">Отмена</a><?php endif; ?>
			</form>
			<h2 style="margin-top:28px">Настройки</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:12px 0 22px">
				<input type="hidden" name="action" value="mvpb_save_settings">
				<?php wp_nonce_field( 'mvpb_save_settings' ); ?>
				<label><input type="checkbox" name="auto_sync" value="1" <?php checked( 1, $auto_sync ); ?>> Автоматически синхронизировать пары при изменении метаданных</label>
				<p class="description">Синхронизация запускается отложенной задачей WP-Cron, а не при каждом открытии сайта.</p>
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
			<h2 style="margin-top:28px">Журнал операций</h2>
			<table class="widefat striped"><thead><tr><th>Время</th><th>Операция</th><th>Пара</th><th>Результат</th></tr></thead><tbody>
			<?php $mvpb_log = get_option( self::LOG_OPTION, array() ); foreach ( array_slice( is_array( $mvpb_log ) ? $mvpb_log : array(), 0, 30 ) as $entry ) : ?>
				<tr><td><?php echo esc_html( isset( $entry['time'] ) ? $entry['time'] : '' ); ?></td><td><?php echo esc_html( isset( $entry['action'] ) ? $entry['action'] : '' ); ?></td><td><?php echo esc_html( ( isset( $entry['ru'] ) ? $entry['ru'] : 0 ) . ' / ' . ( isset( $entry['en'] ) ? $entry['en'] : 0 ) ); ?></td><td><?php echo esc_html( isset( $entry['message'] ) ? $entry['message'] : '' ); ?></td></tr>
			<?php endforeach; ?>
			<?php if ( empty( $mvpb_log ) ) : ?><tr><td colspan="4">Операций пока нет.</td></tr><?php endif; ?>
			</tbody></table>
		</div>
		<?php
	}

	public static function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'mark-vane-polylang-bridge' ), 403 );
		}
		check_admin_referer( 'mvpb_save_settings' );
		$enabled = isset( $_POST['auto_sync'] ) ? 1 : 0;
		update_option( 'mvpb_auto_sync', $enabled, false );
		if ( $enabled && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 10, self::CRON_HOOK );
		}
		if ( ! $enabled && wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
		wp_safe_redirect( add_query_arg( 'mvpb_saved', '1', admin_url( 'admin.php?page=mark-vane-polylang-bridge' ) ) );
		exit;
	}

	public static function handle_manual_sync() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'mark-vane-polylang-bridge' ), 403 );
		}
		check_admin_referer( 'mvpb_sync' );
		self::sync_legacy_pairs();
		self::log_activity( 'manual_sync', 0, 0, 'Ручная синхронизация запущена.' );
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
register_activation_hook( __FILE__, array( 'Mark_Vane_Polylang_Bridge', 'activate' ) );
register_activation_hook( __FILE__, array( 'Mark_Vane_Polylang_Bridge', 'activate' ) );
