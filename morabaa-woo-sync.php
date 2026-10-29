<?php
/**
 * Plugin Name: Morabaa WooCommerce Inventory Sync
 * Plugin URI: https://github.com/yahyakhalid/morabaa-woo-sync
 * Description: مزامنة مخزون WooCommerce مع نظام مربّع POS عبر API مع لوحة تحكم عربية.
 * Version: 1.1.35
 * Author: Yahya Khalid
 * Author URI: https://akkadx.com
 * Text Domain: morabaa-woo-sync
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Morabaa_Woo_Sync_Plugin' ) ) {

	class Morabaa_Woo_Sync_Plugin {

		private static $instance = null;
		private $page_hook       = '';
		private $cached_pos_items = null;

		private function __construct() {
			$this->maybe_migrate_legacy_timestamps();
			add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
			add_action( 'wp_ajax_morabaa_barcode_diagnose', array( $this, 'ajax_barcode_diagnose' ) );
			add_action( 'wp_ajax_morabaa_import_search', array( $this, 'ajax_import_search' ) );
			add_action( 'wp_ajax_morabaa_manual_sync_batch', array( $this, 'ajax_manual_sync_batch' ) );
			add_filter( 'cron_schedules', array( $this, 'register_cron_schedule' ) );
			add_action( 'morabaa_woo_sync_cron_event', array( $this, 'cron_sync' ) );
			add_action( 'init', array( $this, 'ensure_sync_cron_scheduled' ) );
			add_action( 'woocommerce_product_options_sku', array( $this, 'render_manual_item_id_field' ) );
			add_action( 'woocommerce_process_product_meta', array( $this, 'save_manual_item_id_field' ) );
		}

		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		public static function activate() {
			if ( '' === get_option( 'morabaa_woo_auto_sync_enabled', '' ) ) {
				update_option( 'morabaa_woo_auto_sync_enabled', 'yes' );
			}

			if ( ! wp_next_scheduled( 'morabaa_woo_sync_cron_event' ) ) {
				wp_schedule_event( time(), 'morabaa_20_minutes', 'morabaa_woo_sync_cron_event' );
			}
		}

		public static function deactivate() {
			$timestamp = wp_next_scheduled( 'morabaa_woo_sync_cron_event' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'morabaa_woo_sync_cron_event' );
			}
		}

		public function register_cron_schedule( $schedules ) {
			$schedules['morabaa_20_minutes'] = array(
				'interval' => 20 * 60,
				'display'  => __( 'كل 20 دقيقة (مربّع)', 'morabaa-woo-sync' ),
			);
			return $schedules;
		}

		private function is_auto_sync_enabled() {
			$value = get_option( 'morabaa_woo_auto_sync_enabled', 'yes' );
			return 'no' !== (string) $value;
		}

		private function set_auto_sync_enabled( $enabled ) {
			$enabled = (bool) $enabled;
			update_option( 'morabaa_woo_auto_sync_enabled', $enabled ? 'yes' : 'no' );

			$timestamp = wp_next_scheduled( 'morabaa_woo_sync_cron_event' );
			if ( $enabled ) {
				if ( ! $timestamp ) {
					wp_schedule_event( time(), 'morabaa_20_minutes', 'morabaa_woo_sync_cron_event' );
				}
			} elseif ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'morabaa_woo_sync_cron_event' );
			}
		}

		public function ensure_sync_cron_scheduled() {
			$timestamp = wp_next_scheduled( 'morabaa_woo_sync_cron_event' );

			if ( ! $this->is_auto_sync_enabled() ) {
				if ( $timestamp ) {
					wp_unschedule_event( $timestamp, 'morabaa_woo_sync_cron_event' );
				}
				return;
			}

			if ( ! $timestamp ) {
				wp_schedule_event( time() + 30, 'morabaa_20_minutes', 'morabaa_woo_sync_cron_event' );
			}
		}

		private function get_now_timestamp() {
			return time();
		}

		private function get_site_utc_offset_seconds() {
			$offset = get_option( 'gmt_offset', 0 );
			return (int) round( (float) $offset * HOUR_IN_SECONDS );
		}

		private function maybe_migrate_legacy_timestamps() {
			if ( 'yes' === get_option( 'morabaa_woo_timestamps_migrated_utc', 'no' ) ) {
				return;
			}

			$offset = $this->get_site_utc_offset_seconds();
			if ( 0 === $offset ) {
				update_option( 'morabaa_woo_timestamps_migrated_utc', 'yes' );
				return;
			}

			$option_keys = array(
				'morabaa_woo_last_sync_time',
				'morabaa_woo_last_test_time',
				'morabaa_woo_last_cron_run',
			);

			foreach ( $option_keys as $option_key ) {
				$stored = (int) get_option( $option_key, 0 );
				if ( $stored > $offset ) {
					update_option( $option_key, max( 0, $stored - $offset ) );
				}
			}

			$sync_rows = get_option( 'morabaa_woo_last_sync_rows', array() );
			if ( is_array( $sync_rows ) ) {
				foreach ( $sync_rows as $idx => $sync_row ) {
					if ( isset( $sync_row['time'] ) && is_numeric( $sync_row['time'] ) ) {
						$sync_rows[ $idx ]['time'] = max( 0, (int) $sync_row['time'] - $offset );
					}
				}
				update_option( 'morabaa_woo_last_sync_rows', $sync_rows );
			}

			global $wpdb;
			if ( isset( $wpdb ) && ! empty( $wpdb->postmeta ) ) {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS SIGNED) - %d WHERE meta_key = %s AND meta_value REGEXP '^[0-9]+$'",
						$offset,
						'_morabaa_last_sync_at'
					)
				);
			}

			update_option( 'morabaa_woo_timestamps_migrated_utc', 'yes' );
		}

		private function maybe_run_overdue_sync_on_plugin_page() {
			if ( ! $this->is_auto_sync_enabled() ) {
				return;
			}

			if ( get_transient( 'morabaa_woo_sync_lock' ) ) {
				return;
			}

			if ( get_transient( 'morabaa_woo_overdue_sync_cooldown' ) ) {
				return;
			}

			$last_sync = (int) get_option( 'morabaa_woo_last_sync_time', 0 );
			$now       = $this->get_now_timestamp();
			$interval  = 20 * 60;

			if ( $last_sync > 0 && ( $now - $last_sync ) < ( $interval + 120 ) ) {
				return;
			}

			set_transient( 'morabaa_woo_overdue_sync_cooldown', 1, 10 * MINUTE_IN_SECONDS );
			$this->cron_sync();
		}

		public function add_admin_menu() {
			$this->page_hook = add_menu_page(
				'مربّع - مزامنة المخزون',
				'مزامنة مع المربّع',
				'manage_woocommerce',
				'morabaa-woo-sync',
				array( $this, 'render_admin_page' ),
				'dashicons-update',
				56
			);
		}

		public function enqueue_admin_assets( $hook ) {
			if ( empty( $this->page_hook ) || $hook !== $this->page_hook ) {
				return;
			}

			$plugin_url = plugin_dir_url( __FILE__ );
			$css_path   = plugin_dir_path( __FILE__ ) . 'assets/css/morabaa-woo-admin.css';
			$js_path    = plugin_dir_path( __FILE__ ) . 'assets/js/morabaa-woo-admin.js';
			$css_ver    = file_exists( $css_path ) ? (string) filemtime( $css_path ) : '1.0.0';
			$js_ver     = file_exists( $js_path ) ? (string) filemtime( $js_path ) : '1.0.0';

			wp_enqueue_style(
				'morabaa-woo-admin',
				$plugin_url . 'assets/css/morabaa-woo-admin.css',
				array(),
				$css_ver
			);

			wp_enqueue_script(
				'morabaa-woo-admin',
				$plugin_url . 'assets/js/morabaa-woo-admin.js',
				array( 'jquery' ),
				$js_ver,
				true
			);

			wp_localize_script(
				'morabaa-woo-admin',
				'morabaaWooAdmin',
				array(
					'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
					'barcodeNonce'   => wp_create_nonce( 'morabaa_barcode_diagnose_ajax' ),
					'searchNonce'    => wp_create_nonce( 'morabaa_import_search_ajax' ),
					'syncNonce'      => wp_create_nonce( 'morabaa_manual_sync_ajax' ),
					'diagLoadError'  => 'تعذر إكمال فحص الباركود حالياً. حاول مرة أخرى.',
					'searchLoadError'=> 'تعذر إكمال البحث حالياً. حاول مرة أخرى.',
					'searchingText'  => 'جاري البحث في كل المنتجات غير المرتبطة...',
					'syncLoadError'  => 'تعذر إكمال المزامنة حالياً. حاول مرة أخرى.',
				)
			);
		}

		private function diagnose_barcodes( $mappings ) {
			$items_diag      = $this->fetch_pos_items( true );
			$total_diag      = is_array( $items_diag ) ? count( $items_diag ) : 0;
			$with_barcode    = 0;
			$with_sku_field  = 0;
			$extracted       = 0;
			$samples         = array();

			foreach ( $items_diag as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$raw_barcode = $this->get_item_barcode_raw_value( $item );
				$raw_sku     = $this->normalize_sku_value( $this->get_mapped_value( $item, isset( $mappings['sku'] ) ? $mappings['sku'] : 'sku' ) );
				$final_sku   = $this->get_item_sku_value( $item, $mappings );

				if ( '' !== $raw_barcode || $this->has_non_empty_barcodes_value( $item ) ) {
					$with_barcode++;
				}
				if ( '' !== $raw_sku ) {
					$with_sku_field++;
				}
				if ( '' !== $final_sku ) {
					$extracted++;
				}

				// Keep diagnose lightweight: summary only, no per-item debug payload.
			}

			if ( $total_diag <= 0 ) {
				return array(
					'type'    => 'error',
					'message' => 'فحص الباركود: لا توجد بيانات راجعة من السيرفر حالياً.',
					'samples' => $samples,
				);
			}

			if ( $extracted > 0 ) {
				return array(
					'type'    => 'success',
					'message' => sprintf( 'فحص الباركود مكتمل: إجمالي العناصر %1$d، فيها Barcode %2$d، فيها SKU مباشر %3$d، تم استخراج SKU/Barcode بنجاح %4$d.', $total_diag, $with_barcode, $with_sku_field, $extracted ),
					'samples' => $samples,
				);
			}

			if ( 0 === $with_barcode && 0 === $with_sku_field ) {
				return array(
					'type'    => 'error',
					'message' => 'فحص الباركود: لا توجد قيم SKU/Barcode في الاستجابة الحالية.',
					'samples' => $samples,
				);
			}

			if ( $extracted <= 0 ) {
				return array(
					'type'    => 'error',
					'message' => 'نتيجة الفحص: البيانات فيها SKU/Barcode لكن الاستخراج فشل من الكود.',
					'samples' => $samples,
				);
			}

			return array(
				'type'    => 'error',
				'message' => 'نتيجة الفحص: لا توجد قيم صالحة للربط في حقول SKU/Barcode/Code ضمن العناصر الراجعة.',
				'samples' => $samples,
			);
		}

		public function ajax_barcode_diagnose() {
			if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => 'غير مسموح بالوصول.' ), 403 );
			}

			$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'morabaa_barcode_diagnose_ajax' ) ) {
				wp_send_json_error( array( 'message' => 'فشل التحقق من الطلب.' ), 403 );
			}

			$result = $this->diagnose_barcodes( $this->get_mappings() );
			if ( 'success' === $result['type'] ) {
				wp_send_json_success( array( 'message' => $result['message'], 'samples' => isset( $result['samples'] ) ? $result['samples'] : array() ) );
			}

			wp_send_json_error( array( 'message' => $result['message'], 'samples' => isset( $result['samples'] ) ? $result['samples'] : array() ) );
		}

		private function get_manual_sync_rows_cache_key() {
			$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
			return 'morabaa_woo_manual_sync_rows_' . $user_id;
		}

		private function get_import_search_results( $query, $limit = 120 ) {
			$query = $this->normalize_search_text( $query );
			$limit = max( 20, min( 300, (int) $limit ) );

			$mappings = $this->get_mappings();
			$items    = $this->fetch_pos_items();
			$unlinked = $this->get_unlinked_items_cached( $items, $mappings );

			$rows          = array();
			$total_matches = 0;

			foreach ( $unlinked as $row_key => $row ) {
				$haystack = $this->normalize_search_text(
					implode(
						' ',
						array(
							isset( $row['item_id'] ) ? $row['item_id'] : '',
							isset( $row['name'] ) ? $row['name'] : '',
							isset( $row['sku'] ) ? $row['sku'] : '',
							isset( $row['price'] ) ? $row['price'] : '',
							isset( $row['stock'] ) ? $row['stock'] : '',
						)
					)
				);

				if ( '' !== $query && false === strpos( $haystack, $query ) ) {
					continue;
				}

				$total_matches++;
				if ( count( $rows ) < $limit ) {
					$row['row_key'] = (string) $row_key;
					$rows[] = $row;
				}
			}

			return array(
				'rows'     => $rows,
				'total'    => $total_matches,
				'has_more' => $total_matches > count( $rows ),
			);
		}

		public function ajax_import_search() {
			if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => 'غير مسموح بالوصول.' ), 403 );
			}

			$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'morabaa_import_search_ajax' ) ) {
				wp_send_json_error( array( 'message' => 'فشل التحقق من الطلب.' ), 403 );
			}

			$query   = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
			$results = $this->get_import_search_results( $query, 150 );
			$total   = isset( $results['total'] ) ? (int) $results['total'] : 0;
			$rows    = isset( $results['rows'] ) && is_array( $results['rows'] ) ? $results['rows'] : array();

			if ( '' === trim( $query ) ) {
				$message = sprintf( 'تم عرض %1$d منتج من المنتجات غير المرتبطة الجاهزة للاستيراد.', count( $rows ) );
			} elseif ( $total > 0 ) {
				$message = sprintf( 'تم العثور على %1$d نتيجة مطابقة لعبارة "%2$s".', $total, $query );
			} else {
				$message = sprintf( 'لا توجد نتائج مطابقة لعبارة "%s" ضمن المنتجات غير المرتبطة.', $query );
			}

			if ( ! empty( $results['has_more'] ) ) {
				$message .= sprintf( ' يتم عرض أول %d نتيجة فقط لسرعة العرض.', count( $rows ) );
			}

			wp_send_json_success(
				array(
					'rows'    => $rows,
					'total'   => $total,
					'hasMore' => ! empty( $results['has_more'] ),
					'message' => $message,
				)
			);
		}

		public function ajax_manual_sync_batch() {
			if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => 'غير مسموح بالوصول.' ), 403 );
			}

			$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'morabaa_manual_sync_ajax' ) ) {
				wp_send_json_error( array( 'message' => 'فشل التحقق من الطلب.' ), 403 );
			}

			$offset     = isset( $_POST['offset'] ) ? max( 0, absint( wp_unslash( $_POST['offset'] ) ) ) : 0;
			$updated    = isset( $_POST['updated'] ) ? max( 0, absint( wp_unslash( $_POST['updated'] ) ) ) : 0;
			$matched    = isset( $_POST['matched'] ) ? max( 0, absint( wp_unslash( $_POST['matched'] ) ) ) : 0;
			$batch_size = isset( $_POST['batchSize'] ) ? max( 20, min( 100, absint( wp_unslash( $_POST['batchSize'] ) ) ) ) : 40;
			$mappings   = $this->get_mappings();
			$items      = $this->fetch_pos_items();
			$total_items = is_array( $items ) ? count( $items ) : 0;
			$rows_cache_key = $this->get_manual_sync_rows_cache_key();

			if ( 0 === $offset ) {
				delete_transient( $rows_cache_key );
			}

			$last_synced_rows = get_transient( $rows_cache_key );
			if ( ! is_array( $last_synced_rows ) ) {
				$last_synced_rows = array();
			}

			if ( $total_items <= 0 ) {
				delete_transient( $rows_cache_key );
				update_option( 'morabaa_woo_last_sync_rows', array() );
				update_option( 'morabaa_woo_last_sync_time', $this->get_now_timestamp() );
				wp_send_json_success(
					array(
						'complete'   => true,
						'progress'   => 100,
						'nextOffset' => 0,
						'totalItems' => 0,
						'updated'    => 0,
						'matched'    => 0,
						'step'       => 'لا توجد عناصر متاحة للمزامنة حالياً.',
						'hint'       => 'تحقق من الاتصال أو من وجود بيانات في Morabaa.',
						'message'    => 'لم يتم العثور على عناصر من Morabaa للمزامنة حالياً.',
					)
				);
			}

			$batch_items = array_slice( $items, $offset, $batch_size );
			$processed_in_batch = 0;

			foreach ( $batch_items as $item ) {
				$processed_in_batch++;
				if ( ! is_array( $item ) ) {
					continue;
				}

				$product_id = $this->find_wc_product_for_item( $item, $mappings, false );
				if ( ! $product_id ) {
					continue;
				}

				$matched++;
				$item_id  = $this->get_item_id_value( $item, $mappings );
				$item_sku = $this->get_item_sku_value( $item, $mappings );
				if ( ! empty( $item_id ) ) {
					update_post_meta( $product_id, '_morabaa_item_id', $item_id );
				}

				$catalog_data = $this->sync_product_catalog_data( $product_id, $item, $mappings );
				$stock        = $this->get_item_stock_value( $item, $mappings );
				$this->update_product_stock( $product_id, $stock );
				$updated++;

				if ( count( $last_synced_rows ) < 300 ) {
					$last_synced_rows[] = array(
						'product_id' => (int) $product_id,
						'name'       => isset( $catalog_data['name'] ) ? (string) $catalog_data['name'] : (string) get_the_title( $product_id ),
						'morabaa_id' => (string) $item_id,
						'sku'        => (string) $item_sku,
						'price'      => isset( $catalog_data['price'] ) ? (string) $catalog_data['price'] : '',
						'stock'      => is_numeric( $stock ) ? (string) $stock : '0',
						'time'       => $this->get_now_timestamp(),
					);
				}
			}

			$next_offset = min( $total_items, $offset + $processed_in_batch );
			$progress    = (int) round( ( $next_offset / max( 1, $total_items ) ) * 100 );
			$is_complete = $next_offset >= $total_items;

			if ( $is_complete ) {
				update_option( 'morabaa_woo_last_sync_rows', $last_synced_rows );
				update_option( 'morabaa_woo_last_sync_time', $this->get_now_timestamp() );
				update_option( 'morabaa_woo_last_linked_count', (int) $this->get_linked_products_count() );
				delete_transient( $rows_cache_key );
			} else {
				set_transient( $rows_cache_key, $last_synced_rows, HOUR_IN_SECONDS );
			}

			wp_send_json_success(
				array(
					'complete'   => $is_complete,
					'progress'   => $is_complete ? 100 : $progress,
					'nextOffset' => $next_offset,
					'totalItems' => $total_items,
					'updated'    => $updated,
					'matched'    => $matched,
					'step'       => $is_complete ? 'اكتملت كل دفعات المزامنة.' : sprintf( 'جاري معالجة الدفعات: %1$d / %2$d', $next_offset, $total_items ),
					'hint'       => 'تتم المزامنة على دفعات حقيقية لتجنب تجمّد الصفحة حتى مع آلاف المنتجات.',
					'message'    => $is_complete ? sprintf( 'اكتملت المزامنة اليدوية. تم تحديث %1$d منتج مرتبط من أصل %2$d تمت مطابقتهم.', $updated, $matched ) : sprintf( 'تمت معالجة %1$d من أصل %2$d عنصر حتى الآن.', $next_offset, $total_items ),
				)
			);
		}

		public function render_manual_item_id_field() {
			woocommerce_wp_text_input(
				array(
					'id'          => '_morabaa_item_id',
					'label'       => __( 'Morabaa Item ID', 'morabaa-woo-sync' ),
					'placeholder' => 'e.g. f6a7916e-57cd-4a5a-979d-d4c6f3342986',
					'desc_tip'    => true,
					'description' => __( 'للربط اليدوي: أدخل item id من مربّع لهذا المنتج.', 'morabaa-woo-sync' ),
				)
			);
		}

		public function save_manual_item_id_field( $post_id ) {
			$post_id = absint( $post_id );
			if ( $post_id <= 0 ) {
				return;
			}

			if ( isset( $_POST['_morabaa_item_id'] ) ) {
				$item_id = sanitize_text_field( wp_unslash( $_POST['_morabaa_item_id'] ) );
				if ( '' === $item_id ) {
					delete_post_meta( $post_id, '_morabaa_item_id' );
				} else {
					update_post_meta( $post_id, '_morabaa_item_id', $item_id );
				}
			}
		}

		private function get_default_endpoint() {
			return 'https://api.mymorabaa.com/v1/items';
		}

		private function get_mappings() {
			$mappings = get_option( 'morabaa_woo_json_mapping', array() );
			if ( ! is_array( $mappings ) ) {
				$mappings = array();
			}
			$defaults = array(
				'item_id' => 'id',
				'name'    => 'name',
				'sku'     => 'sku',
				'stock'   => 'stock',
				'price'   => 'sellPrice',
			);
			return array_merge( $defaults, $mappings );
		}

		private function encrypt_secret( $plaintext ) {
			if ( empty( $plaintext ) ) {
				return '';
			}
			if ( ! function_exists( 'wp_salt' ) ) {
				return '';
			}
			$key    = hash( 'sha256', wp_salt( 'auth' ) );
			$cipher = 'AES-256-CBC';
			$ivlen  = openssl_cipher_iv_length( $cipher );
			$iv     = openssl_random_pseudo_bytes( $ivlen );
			$ciphertext_raw = openssl_encrypt( $plaintext, $cipher, $key, 0, $iv );
			if ( false === $ciphertext_raw ) {
				return '';
			}
			return base64_encode( $iv . '::' . $ciphertext_raw );
		}

		private function decrypt_secret( $encrypted ) {
			if ( empty( $encrypted ) ) {
				return '';
			}
			if ( ! function_exists( 'wp_salt' ) ) {
				return '';
			}
			$key    = hash( 'sha256', wp_salt( 'auth' ) );
			$cipher = 'AES-256-CBC';
			$decoded = base64_decode( $encrypted, true );
			if ( false === $decoded ) {
				return '';
			}
			$parts = explode( '::', $decoded, 2 );
			if ( count( $parts ) !== 2 ) {
				return '';
			}
			list( $iv, $ciphertext_raw ) = $parts;
			$plaintext = openssl_decrypt( $ciphertext_raw, $cipher, $key, 0, $iv );
			if ( false === $plaintext ) {
				return '';
			}
			return $plaintext;
		}

		private function get_mapped_value( $item, $key ) {
			if ( ! is_array( $item ) || empty( $key ) ) {
				return '';
			}
			if ( strpos( $key, '.' ) !== false ) {
				$segments = explode( '.', $key );
				$value    = $item;
				foreach ( $segments as $segment ) {
					if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
						return '';
					}
					$value = $value[ $segment ];
				}
				return $value;
			}
			return isset( $item[ $key ] ) ? $item[ $key ] : '';
		}

		private function normalize_sku_value( $raw_value ) {
			if ( is_array( $raw_value ) ) {
				if ( isset( $raw_value[0] ) ) {
					$first = $this->normalize_sku_value( $raw_value[0] );
					if ( '' !== $first ) {
						return $first;
					}
				}

				foreach ( $raw_value as $val ) {
					$found = $this->normalize_sku_value( $val );
					if ( '' !== $found ) {
						return $found;
					}
				}
				return '';
			}

			if ( is_scalar( $raw_value ) ) {
				return trim( (string) $raw_value );
			}

			return '';
		}

		private function normalize_text_value( $raw_value ) {
			if ( is_scalar( $raw_value ) ) {
				return trim( (string) $raw_value );
			}

			return '';
		}

		private function normalize_search_text( $value ) {
			$value = is_scalar( $value ) ? trim( wp_strip_all_tags( (string) $value ) ) : '';
			if ( '' === $value ) {
				return '';
			}

			if ( function_exists( 'mb_strtolower' ) ) {
				return mb_strtolower( $value, 'UTF-8' );
			}

			return strtolower( $value );
		}

		private function looks_like_identifier_value( $value ) {
			$value = is_scalar( $value ) ? trim( (string) $value ) : '';
			if ( '' === $value ) {
				return false;
			}

			if ( preg_match( '/\s/', $value ) ) {
				return false;
			}

			if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value ) ) {
				return false;
			}

			if ( preg_match( '/^[0-9]+$/', $value ) ) {
				return strlen( $value ) >= 6;
			}

			return strlen( $value ) >= 4 && preg_match( '/[A-Za-z0-9]/', $value );
		}

		private function find_identifier_by_key_fragments( $data ) {
			if ( ! is_array( $data ) ) {
				return '';
			}

			$include = array( 'barcode', 'bar_code', 'barcodes', 'sku', 'itemcode', 'productcode', 'code', 'upc', 'ean', 'gtin' );
			$exclude = array( 'id', 'business', 'currency', 'packageid', 'store', 'group', 'morabaaid', 'createdat', 'updatedat' );

			foreach ( $data as $key => $value ) {
				$key_l = is_string( $key ) ? strtolower( $key ) : '';
				$has_include = false;
				foreach ( $include as $fragment ) {
					if ( '' !== $key_l && false !== strpos( $key_l, $fragment ) ) {
						$has_include = true;
						break;
					}
				}

				$has_exclude = false;
				foreach ( $exclude as $fragment ) {
					if ( '' !== $key_l && false !== strpos( $key_l, $fragment ) ) {
						$has_exclude = true;
						break;
					}
				}

				if ( $has_include && ! $has_exclude ) {
					$candidate = $this->normalize_sku_value( $value );
					if ( $this->looks_like_identifier_value( $candidate ) ) {
						return $candidate;
					}
				}

				if ( is_array( $value ) ) {
					$nested = $this->find_identifier_by_key_fragments( $value );
					if ( '' !== $nested ) {
						return $nested;
					}
				}
			}

			return '';
		}

		private function collect_debug_key_matches( $data, $fragments, $path = '', &$out = array(), $max = 20 ) {
			if ( ! is_array( $data ) || count( $out ) >= $max ) {
				return;
			}

			foreach ( $data as $key => $value ) {
				if ( count( $out ) >= $max ) {
					return;
				}

				$key_str  = is_string( $key ) ? $key : (string) $key;
				$key_low  = strtolower( $key_str );
				$full_key = '' === $path ? $key_str : ( $path . '.' . $key_str );

				$matched = false;
				foreach ( $fragments as $fragment ) {
					$fragment = strtolower( (string) $fragment );
					if ( '' !== $fragment && false !== strpos( $key_low, $fragment ) ) {
						$matched = true;
						break;
					}
				}

				if ( $matched ) {
					$val = is_array( $value ) ? wp_json_encode( $value ) : $this->normalize_text_value( $value );
					$out[] = array(
						'key'   => $full_key,
						'value' => (string) $val,
					);
				}

				if ( is_array( $value ) ) {
					$this->collect_debug_key_matches( $value, $fragments, $full_key, $out, $max );
				}
			}
		}

		private function get_item_name_value( $item, $mappings ) {
			$name_key = isset( $mappings['name'] ) ? $mappings['name'] : '';
			$name     = $this->normalize_text_value( $this->get_mapped_value( $item, $name_key ) );

			if ( '' !== $name ) {
				return $name;
			}

			$name_candidates = array( 'name', 'itemName', 'title', 'displayName', 'tradeName', 'scientificName', 'arabicName', 'englishName' );
			foreach ( $name_candidates as $candidate ) {
				$candidate_name = $this->normalize_text_value( $this->get_mapped_value( $item, $candidate ) );
				if ( '' !== $candidate_name ) {
					return $candidate_name;
				}
			}

			return $this->find_first_value_by_key_fragment( $item, array( 'name', 'title' ) );
		}

		private function find_first_value_by_key_fragment( $data, $fragments ) {
			if ( ! is_array( $data ) ) {
				return '';
			}

			foreach ( $data as $key => $value ) {
				$key_l = is_string( $key ) ? strtolower( $key ) : '';
				foreach ( $fragments as $fragment ) {
					$fragment = strtolower( (string) $fragment );
					if ( '' !== $key_l && '' !== $fragment && false !== strpos( $key_l, $fragment ) ) {
						$found = $this->normalize_sku_value( $value );
						if ( '' !== $found ) {
							return $found;
						}
					}
				}

				if ( is_array( $value ) ) {
					$nested = $this->find_first_value_by_key_fragment( $value, $fragments );
					if ( '' !== $nested ) {
						return $nested;
					}
				}
			}

			return '';
		}

		private function get_item_sku_value( $item, $mappings ) {
			$sku_key = isset( $mappings['sku'] ) ? $mappings['sku'] : '';
			$raw_sku = $this->get_mapped_value( $item, $sku_key );
			$sku     = $this->normalize_sku_value( $raw_sku );

			if ( '' !== $sku ) {
				return $sku;
			}

			// Morabaa may return barcode as `barcodes: []` instead of `sku`.
			$barcode_candidates = array(
				'barcodes.0',
				'barcodes.0.code',
				'barcodes.0.barcode',
				'barcodes.0.value',
				'barCodes.0',
				'barcode',
				'barCode',
				'bar_code',
				'code',
				'packages.0.barcode',
				'packages.0.code',
				'barcodes',
			);
			foreach ( $barcode_candidates as $barcode_key ) {
				$raw_barcode = $this->get_mapped_value( $item, $barcode_key );
				$barcode     = $this->normalize_sku_value( $raw_barcode );
				if ( '' !== $barcode ) {
					return $barcode;
				}
			}

			$barcode_guess = $this->find_first_value_by_key_fragment( $item, array( 'barcode', 'bar_code', 'barcodes' ) );
			if ( '' !== $barcode_guess ) {
				return $barcode_guess;
			}

			return '';
		}

		private function has_non_empty_barcodes_value( $item ) {
			if ( ! is_array( $item ) ) {
				return false;
			}

			foreach ( $item as $key => $value ) {
				$key_l = is_string( $key ) ? strtolower( $key ) : '';
				if ( in_array( $key_l, array( 'barcodes', 'barcodeslist', 'bar_codes', 'barcodes_array', 'barcodesarray', 'barcodesitems' ), true ) ) {
					$first = $this->normalize_sku_value( $value );
					if ( '' !== $first ) {
						return true;
					}
				}

				if ( is_array( $value ) && $this->has_non_empty_barcodes_value( $value ) ) {
					return true;
				}
			}

			return false;
		}

		private function get_item_barcode_raw_value( $item ) {
			$barcode_candidates = array(
				'barcodes.0',
				'barcodes.0.code',
				'barcodes.0.barcode',
				'barcodes.0.value',
				'barcodes',
				'barCodes.0',
				'barCodes',
				'barcode',
				'barCode',
				'bar_code',
				'packages.0.barcode',
				'packages.0.code',
			);

			foreach ( $barcode_candidates as $barcode_key ) {
				$raw_barcode = $this->get_mapped_value( $item, $barcode_key );
				$barcode     = $this->normalize_sku_value( $raw_barcode );
				if ( '' !== $barcode ) {
					return $barcode;
				}
			}

			$guessed = $this->find_first_value_by_key_fragment( $item, array( 'barcode', 'bar_code', 'barcodes' ) );
			if ( '' !== $guessed ) {
				return $guessed;
			}

			$fallback = $this->find_identifier_by_key_fragments( $item );
			if ( '' !== $fallback ) {
				return $fallback;
			}

			// In some responses, barcode exists as a non-empty list with nested structure.
			if ( $this->has_non_empty_barcodes_value( $item ) ) {
				return 'HAS_BARCODE_LIST';
			}

			return '';
		}

		private function get_item_stock_value( $item, $mappings ) {
			$stock_key = isset( $mappings['stock'] ) ? $mappings['stock'] : '';
			$stock     = $this->get_mapped_value( $item, $stock_key );
			if ( is_numeric( $stock ) ) {
				return (float) $stock;
			}

			$stock_candidates = array( 'quantity', 'qty', 'availableQuantity', 'stock', 'inventory.qty', 'packages.0.packageQuantity' );
			foreach ( $stock_candidates as $candidate ) {
				$value = $this->get_mapped_value( $item, $candidate );
				if ( is_numeric( $value ) ) {
					return (float) $value;
				}
			}

			$quantity_with_fill = $this->get_mapped_value( $item, 'quantityWithFill' );
			$quantity_with_fill = is_scalar( $quantity_with_fill ) ? (string) $quantity_with_fill : '';
			if ( '' !== $quantity_with_fill && preg_match( '/-?\d+(?:\.\d+)?/', $quantity_with_fill, $matches ) ) {
				return (float) $matches[0];
			}

			return 0;
		}

		private function normalize_price_value( $raw_value ) {
			if ( is_array( $raw_value ) ) {
				foreach ( $raw_value as $nested_value ) {
					$price = $this->normalize_price_value( $nested_value );
					if ( null !== $price ) {
						return $price;
					}
				}
				return null;
			}

			if ( is_numeric( $raw_value ) ) {
				return (float) $raw_value;
			}

			if ( is_scalar( $raw_value ) ) {
				$value = trim( (string) $raw_value );
				if ( '' === $value ) {
					return null;
				}

				$value = preg_replace( '/[^\d,\.\-]/', '', $value );
				if ( '' === $value ) {
					return null;
				}

				if ( false !== strpos( $value, ',' ) && false === strpos( $value, '.' ) ) {
					$value = str_replace( ',', '.', $value );
				} else {
					$value = str_replace( ',', '', $value );
				}

				if ( is_numeric( $value ) ) {
					return (float) $value;
				}
			}

			return null;
		}

		private function find_first_numeric_value_by_key_fragments( $data, $include_fragments, $exclude_fragments = array(), $path = '' ) {
			if ( ! is_array( $data ) ) {
				return null;
			}

			foreach ( $data as $key => $value ) {
				$key_l      = is_string( $key ) ? strtolower( $key ) : '';
				$path_l     = is_string( $path ) ? strtolower( $path ) : '';
				$full_key_l = '' === $path_l ? $key_l : ( $path_l . '.' . $key_l );
				$matched = false;

				foreach ( $include_fragments as $fragment ) {
					$fragment = strtolower( (string) $fragment );
					if ( '' !== $fragment && ( false !== strpos( $key_l, $fragment ) || false !== strpos( $full_key_l, $fragment ) ) ) {
						$matched = true;
						break;
					}
				}

				if ( $matched ) {
					$excluded = false;
					foreach ( $exclude_fragments as $fragment ) {
						$fragment = strtolower( (string) $fragment );
						if ( '' !== $fragment && ( false !== strpos( $key_l, $fragment ) || false !== strpos( $full_key_l, $fragment ) ) ) {
							$excluded = true;
							break;
						}
					}

					if ( ! $excluded ) {
						$numeric = $this->normalize_price_value( $value );
						if ( null !== $numeric ) {
							return $numeric;
						}
					}
				}

				if ( is_array( $value ) ) {
					$nested = $this->find_first_numeric_value_by_key_fragments( $value, $include_fragments, $exclude_fragments, $full_key_l );
					if ( null !== $nested ) {
						return $nested;
					}
				}
			}

			return null;
		}

		private function get_default_package_sell_price( $item ) {
			$packages = $this->get_mapped_value( $item, 'packages' );

			if ( ! is_array( $packages ) || empty( $packages ) ) {
				return null;
			}

			// Prefer explicitly default package.
			foreach ( $packages as $package ) {
				if ( ! is_array( $package ) ) {
					continue;
				}

				$is_default = isset( $package['isDefault'] ) ? (bool) $package['isDefault'] : false;
				if ( ! $is_default ) {
					continue;
				}

				$price = $this->normalize_price_value( isset( $package['price'] ) ? $package['price'] : null );
				if ( null !== $price ) {
					return max( 0, (float) $price );
				}
			}

			// Otherwise prefer package index 0 when available.
			foreach ( $packages as $package ) {
				if ( ! is_array( $package ) ) {
					continue;
				}

				$package_index = isset( $package['packageIndex'] ) ? (int) $package['packageIndex'] : -1;
				if ( 0 !== $package_index ) {
					continue;
				}

				$price = $this->normalize_price_value( isset( $package['price'] ) ? $package['price'] : null );
				if ( null !== $price ) {
					return max( 0, (float) $price );
				}
			}

			// Fallback to the first package sell price.
			foreach ( $packages as $package ) {
				if ( ! is_array( $package ) ) {
					continue;
				}

				$price = $this->normalize_price_value( isset( $package['price'] ) ? $package['price'] : null );
				if ( null !== $price ) {
					return max( 0, (float) $price );
				}
			}

			return null;
		}

		private function get_item_price_value( $item, $mappings ) {
			$price_key = isset( $mappings['price'] ) ? $mappings['price'] : '';
			$price     = $this->normalize_price_value( $this->get_mapped_value( $item, $price_key ) );
			if ( null !== $price ) {
				return max( 0, (float) $price );
			}

			$package_sell_price = $this->get_default_package_sell_price( $item );
			if ( null !== $package_sell_price ) {
				return $package_sell_price;
			}

			$price_candidates = array(
				'sellPriceObject.price',
				'sellPrice',
				'sellingPrice',
				'salePrice',
				'retailPrice',
				'privateSalePrice',
				'wholeSalePrice',
				'defaultSellPrice',
				'prices.sellPrice',
				'pricing.sellPrice',
				'pricing.price',
				'packages.0.sellPrice',
				'packages.0.price',
				'price',
			);
			foreach ( $price_candidates as $candidate ) {
				$found_price = $this->normalize_price_value( $this->get_mapped_value( $item, $candidate ) );
				if ( null !== $found_price ) {
					return max( 0, (float) $found_price );
				}
			}

			$guessed_price = $this->find_first_numeric_value_by_key_fragments(
				$item,
				array( 'sellprice', 'sellingprice', 'saleprice', 'retailprice', 'price' ),
				array( 'buy', 'purchase', 'cost', 'wholesale' )
			);

			return null !== $guessed_price ? max( 0, (float) $guessed_price ) : null;
		}

		private function get_item_id_value( $item, $mappings ) {
			$item_id_key = isset( $mappings['item_id'] ) ? (string) $mappings['item_id'] : '';
			$candidates  = array_filter(
				array_unique(
					array(
						$item_id_key,
						'id',
						'itemId',
						'item_id',
					)
				)
			);

			foreach ( $candidates as $candidate ) {
				$value = $this->get_mapped_value( $item, $candidate );
				if ( is_scalar( $value ) ) {
					$value = trim( (string) $value );
					if ( '' !== $value ) {
						return $value;
					}
				}
			}

			return '';
		}

		private function get_items_cache_key( $endpoint, $app_id ) {
			return 'morabaa_woo_items_' . md5( (string) $endpoint . '|' . (string) $app_id );
		}

		private function get_stored_business_id() {
			$business_id = get_option( 'morabaa_woo_business_id', '' );
			return is_scalar( $business_id ) ? trim( (string) $business_id ) : '';
		}

		private function maybe_extract_business_id_from_data( $data ) {
			if ( ! is_array( $data ) ) {
				return '';
			}

			$direct_keys = array( 'businessId', 'business_id', 'business-id' );
			foreach ( $direct_keys as $k ) {
				if ( isset( $data[ $k ] ) && is_scalar( $data[ $k ] ) ) {
					$v = trim( (string) $data[ $k ] );
					if ( '' !== $v ) {
						return $v;
					}
				}
			}

			if ( isset( $data['business'] ) && is_array( $data['business'] ) ) {
				foreach ( array( 'id', 'businessId', 'business_id' ) as $bk ) {
					if ( isset( $data['business'][ $bk ] ) && is_scalar( $data['business'][ $bk ] ) ) {
						$v = trim( (string) $data['business'][ $bk ] );
						if ( '' !== $v ) {
							return $v;
						}
					}
				}
			}

			foreach ( $data as $value ) {
				if ( is_array( $value ) ) {
					$nested = $this->maybe_extract_business_id_from_data( $value );
					if ( '' !== $nested ) {
						return $nested;
					}
				}
			}

			return '';
		}

		private function maybe_store_business_id_from_response( $body ) {
			$body = is_string( $body ) ? trim( $body ) : '';
			if ( '' === $body ) {
				return;
			}

			$data = json_decode( $body, true );
			if ( ! is_array( $data ) ) {
				return;
			}

			$business_id = $this->maybe_extract_business_id_from_data( $data );
			if ( '' !== $business_id ) {
				update_option( 'morabaa_woo_business_id', $business_id );
			}
		}

		private function get_unlinked_items_cache_key( $endpoint, $app_id, $mappings, $exclude_existing_skus = true ) {
			$mode = $exclude_existing_skus ? 'exclude_sku' : 'all_unlinked';
			return 'morabaa_woo_unlinked_' . md5( (string) $endpoint . '|' . (string) $app_id . '|' . wp_json_encode( $mappings ) . '|' . $mode );
		}

		private function clear_items_cache() {
			$endpoint = get_option( 'morabaa_woo_api_endpoint', $this->get_default_endpoint() );
			$app_id   = get_option( 'morabaa_woo_app_id', '' );
			$mappings = $this->get_mappings();
			delete_transient( $this->get_items_cache_key( $endpoint, $app_id ) );
			delete_transient( $this->get_unlinked_items_cache_key( $endpoint, $app_id, $mappings, true ) );
			delete_transient( $this->get_unlinked_items_cache_key( $endpoint, $app_id, $mappings, false ) );
			$this->cached_pos_items = null;
		}

		private function get_request_variants( $app_id, $secret ) {
			$base_headers = array(
				'Accept' => 'application/json',
			);

			return array(
				array(
					'label'   => 'X-App headers',
					'headers' => array_merge(
						$base_headers,
						array(
							'X-App-Id'     => $app_id,
							'X-App-Secret' => $secret,
						)
					),
				),
			);
		}

		private function request_pos_items( $endpoint, $app_id, $secret ) {
			$variants    = $this->get_request_variants( $app_id, $secret );
			$last_result = array(
				'success' => false,
				'code'    => 0,
				'body'    => '',
				'error'   => '',
				'variant' => '',
			);

			foreach ( $variants as $variant ) {
				$args = array(
					'timeout' => 15,
					'headers' => $variant['headers'],
				);

				$response = wp_remote_get( esc_url_raw( $endpoint ), $args );

				if ( is_wp_error( $response ) ) {
					$last_result = array(
						'success' => false,
						'code'    => 0,
						'body'    => '',
						'error'   => $response->get_error_message(),
						'variant' => $variant['label'],
					);
					continue;
				}

				$code = (int) wp_remote_retrieve_response_code( $response );
				$body = (string) wp_remote_retrieve_body( $response );

				if ( $code >= 200 && $code < 300 ) {
					$this->maybe_store_business_id_from_response( $body );
					return array(
						'success' => true,
						'code'    => $code,
						'body'    => $body,
						'error'   => '',
						'variant' => $variant['label'],
					);
				}

				$last_result = array(
					'success' => false,
					'code'    => $code,
					'body'    => $body,
					'error'   => '',
					'variant' => $variant['label'],
				);
			}

			return $last_result;
		}

		private function decode_items_response( $body ) {
			$data = json_decode( $body, true );

			if ( ! is_array( $data ) ) {
				return array();
			}

			if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
				return $data['data'];
			}

			if ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
				return $data['items'];
			}

			if ( isset( $data[0] ) && is_array( $data[0] ) ) {
				return $data;
			}

			return array();
		}

		private function get_error_message_from_body( $body ) {
			$body = is_string( $body ) ? trim( $body ) : '';
			if ( '' === $body ) {
				return '';
			}

			$data = json_decode( $body, true );
			if ( is_array( $data ) ) {
				foreach ( array( 'message', 'error', 'detail', 'description' ) as $key ) {
					if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ) {
						return (string) $data[ $key ];
					}
				}
			}

			$clean_body = wp_strip_all_tags( $body );
			if ( function_exists( 'mb_substr' ) ) {
				return mb_substr( $clean_body, 0, 180 );
			}

			return substr( $clean_body, 0, 180 );
		}

		private function build_paged_endpoint( $endpoint, $page, $per_page ) {
			$page     = max( 1, (int) $page );
			$per_page = max( 1, (int) $per_page );

			// Some APIs use page/per_page while others use limit/offset.
			return add_query_arg(
				array(
					'page'     => $page,
					'per_page' => $per_page,
					'limit'    => $per_page,
					'offset'   => ( $page - 1 ) * $per_page,
				),
				$endpoint
			);
		}

		private function get_pagination_hints( $body ) {
			$hints = array(
				'has_more'  => null,
				'total'     => null,
				'per_page'  => null,
				'page'      => null,
				'last_page' => null,
			);

			$data = json_decode( $body, true );
			if ( ! is_array( $data ) ) {
				return $hints;
			}

			if ( isset( $data['has_more'] ) ) {
				$hints['has_more'] = (bool) $data['has_more'];
			}

			if ( isset( $data['pagination'] ) && is_array( $data['pagination'] ) ) {
				$pagination = $data['pagination'];
				if ( isset( $pagination['has_more'] ) ) {
					$hints['has_more'] = (bool) $pagination['has_more'];
				}
				if ( isset( $pagination['total'] ) && is_numeric( $pagination['total'] ) ) {
					$hints['total'] = (int) $pagination['total'];
				}
				if ( isset( $pagination['per_page'] ) && is_numeric( $pagination['per_page'] ) ) {
					$hints['per_page'] = (int) $pagination['per_page'];
				}
				if ( isset( $pagination['page'] ) && is_numeric( $pagination['page'] ) ) {
					$hints['page'] = (int) $pagination['page'];
				}
				if ( isset( $pagination['last_page'] ) && is_numeric( $pagination['last_page'] ) ) {
					$hints['last_page'] = (int) $pagination['last_page'];
				}
			}

			if ( isset( $data['meta'] ) && is_array( $data['meta'] ) ) {
				$meta = $data['meta'];
				if ( isset( $meta['total'] ) && is_numeric( $meta['total'] ) ) {
					$hints['total'] = (int) $meta['total'];
				}
				if ( isset( $meta['per_page'] ) && is_numeric( $meta['per_page'] ) ) {
					$hints['per_page'] = (int) $meta['per_page'];
				}
				if ( isset( $meta['page'] ) && is_numeric( $meta['page'] ) ) {
					$hints['page'] = (int) $meta['page'];
				}
				if ( isset( $meta['last_page'] ) && is_numeric( $meta['last_page'] ) ) {
					$hints['last_page'] = (int) $meta['last_page'];
				}
			}

			return $hints;
		}

		private function fetch_pos_items( $force_refresh = false ) {
			if ( null !== $this->cached_pos_items && ! $force_refresh ) {
				return $this->cached_pos_items;
			}

			$endpoint = get_option( 'morabaa_woo_api_endpoint', $this->get_default_endpoint() );
			$app_id   = get_option( 'morabaa_woo_app_id', '' );
			$secret_e = get_option( 'morabaa_woo_app_secret', '' );
			$secret   = $this->decrypt_secret( $secret_e );

			if ( empty( $endpoint ) || empty( $app_id ) || empty( $secret ) ) {
				$this->cached_pos_items = array();
				return array();
			}

			$cache_key = $this->get_items_cache_key( $endpoint, $app_id );
			if ( ! $force_refresh ) {
				$cached = get_transient( $cache_key );
				if ( is_array( $cached ) ) {
					$this->cached_pos_items = $cached;
					return $cached;
				}
			}

			$all_items = array();
			$seen_hash = array();
			// Tested عملياً على Morabaa items endpoint: أكثر من 100 لا يرجع عناصر إضافية حالياً.
			$per_page  = (int) apply_filters( 'morabaa_woo_api_page_size', 100 );
			$per_page  = max( 25, min( 100, $per_page ) );
			$max_pages = (int) apply_filters( 'morabaa_woo_api_max_pages', 200 );
			$max_pages = max( 20, $max_pages );

			for ( $page = 1; $page <= $max_pages; $page++ ) {
				$paged_endpoint = $this->build_paged_endpoint( $endpoint, $page, $per_page );
				$result         = $this->request_pos_items( $paged_endpoint, $app_id, $secret );

				if ( empty( $result['success'] ) ) {
					if ( 1 === $page ) {
						$this->cached_pos_items = array();
						return array();
					}
					break;
				}

				$page_items = $this->decode_items_response( $result['body'] );
				if ( empty( $page_items ) ) {
					break;
				}

				$new_items_count = 0;
				foreach ( $page_items as $item ) {
					$item_key = md5( wp_json_encode( $item ) );
					if ( isset( $seen_hash[ $item_key ] ) ) {
						continue;
					}
					$seen_hash[ $item_key ] = true;
					$all_items[]            = $item;
					$new_items_count++;
				}

				// Keep scanning pages even if this page repeated old records; API ordering can shift.

				$hints = $this->get_pagination_hints( $result['body'] );
				if ( null !== $hints['has_more'] && false === $hints['has_more'] ) {
					break;
				}

				if ( null !== $hints['last_page'] && $page >= (int) $hints['last_page'] ) {
					break;
				}

				if ( count( $page_items ) < $per_page && null === $hints['has_more'] && null === $hints['last_page'] ) {
					break;
				}
			}

			$this->cached_pos_items = $all_items;
			set_transient( $cache_key, $all_items, 15 * MINUTE_IN_SECONDS );

			return $all_items;
		}

		private function test_connection() {
			$endpoint = get_option( 'morabaa_woo_api_endpoint', $this->get_default_endpoint() );
			$app_id   = get_option( 'morabaa_woo_app_id', '' );
			$secret_e = get_option( 'morabaa_woo_app_secret', '' );
			$secret   = $this->decrypt_secret( $secret_e );

			if ( empty( $endpoint ) || empty( $app_id ) || empty( $secret ) ) {
				$message = 'الرجاء إدخال رابط الـ API ومعرّف التطبيق وسرّ التطبيق أولاً، ثم حفظ الإعدادات والمحاولة مرة أخرى.';
				update_option( 'morabaa_woo_last_test_status', 'error' );
				update_option( 'morabaa_woo_last_test_time', $this->get_now_timestamp() );
				return array(
					'success' => false,
					'message' => $message,
				);
			}

			$result = $this->request_pos_items( $endpoint, $app_id, $secret );

			if ( empty( $result['success'] ) ) {
				if ( ! empty( $result['error'] ) ) {
					$message = 'فشل الاتصال بخادم مربّع. الرسالة التقنية: ' . $result['error'];
				} else {
					$api_error = $this->get_error_message_from_body( isset( $result['body'] ) ? $result['body'] : '' );
					$message   = sprintf(
						'فشل الاتصال بمربّع. كود الاستجابة: %1$d. طريقة التوثيق المستخدمة: %2$s.',
						isset( $result['code'] ) ? (int) $result['code'] : 0,
						isset( $result['variant'] ) ? (string) $result['variant'] : '-'
					);
					if ( '' !== $api_error ) {
						$message .= ' رسالة الخادم: ' . $api_error;
						if ( false !== stripos( $api_error, 'x-business-id' ) ) {
							$message .= ' ملاحظة: السيرفر يطلب X-Business-Id في الهيدر. إذا رجع هذا الحقل في أي استجابة ناجحة سيتم حفظه تلقائياً داخل الإضافة.';
						}
					}
				}

				update_option( 'morabaa_woo_last_test_status', 'error' );
				update_option( 'morabaa_woo_last_test_time', $this->get_now_timestamp() );
				return array(
					'success' => false,
					'message' => $message,
				);
			}

			$this->clear_items_cache();
			$items = $this->fetch_pos_items( true );
			$count = 0;
			if ( is_array( $items ) ) {
				$count = count( $items );
			}

			if ( $count > 0 ) {
				$message = sprintf( 'تم الاتصال بمربّع بنجاح. تم استرجاع %d عنصراً من واجهة الـ API.', (int) $count );
			} else {
				$message = 'تم الاتصال بمربّع بنجاح، لكن لم يتم العثور على عناصر في الاستجابة. تأكد من وجود بيانات منتجات في مربّع أو إعداد الفلاتر.';
			}

			update_option( 'morabaa_woo_last_test_status', 'success' );
			update_option( 'morabaa_woo_last_test_time', $this->get_now_timestamp() );

			return array(
				'success' => true,
				'message' => $message,
			);
		}

		private function find_wc_product_for_item( $item, $mappings, $allow_name_match = true ) {
			$item_id = $this->get_item_id_value( $item, $mappings );
			$sku     = $this->get_item_sku_value( $item, $mappings );
			$name    = $this->get_item_name_value( $item, $mappings );

			$name    = is_scalar( $name ) ? (string) $name : '';

			if ( ! empty( $item_id ) ) {
				$query = new WP_Query(
					array(
						'post_type'      => 'product',
						'posts_per_page' => 1,
						'post_status'    => 'any',
						'fields'         => 'ids',
						'meta_query'     => array(
							array(
								'key'   => '_morabaa_item_id',
								'value' => $item_id,
							),
						),
					)
				);
				if ( $query->have_posts() ) {
					return (int) $query->posts[0];
				}
			}

			if ( function_exists( 'wc_get_product_id_by_sku' ) && ! empty( $sku ) ) {
				$product_id = wc_get_product_id_by_sku( $sku );
				if ( $product_id ) {
					return (int) $product_id;
				}
			}

			// Fallback: if item id not found, barcode/SKU can still identify a linked product.

			if ( $allow_name_match && ! empty( $name ) ) {
				$post = get_page_by_title( $name, OBJECT, 'product' );
				if ( $post && isset( $post->ID ) ) {
					return (int) $post->ID;
				}
			}

			return 0;
		}

		private function update_product_stock( $product_id, $stock ) {
			if ( ! $product_id ) {
				return;
			}

			$stock_qty = is_numeric( $stock ) ? floatval( $stock ) : 0;
			$stock_qty = max( 0, $stock_qty );
			$status    = $stock_qty > 0 ? 'instock' : 'outofstock';

			if ( function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $product_id );
				if ( $product ) {
					if ( method_exists( $product, 'set_manage_stock' ) ) {
						$product->set_manage_stock( true );
					}
					if ( method_exists( $product, 'set_stock_quantity' ) ) {
						$product->set_stock_quantity( $stock_qty );
					} else {
						update_post_meta( $product_id, '_stock', $stock_qty );
					}
					if ( method_exists( $product, 'set_stock_status' ) ) {
						$product->set_stock_status( $status );
					} else {
						update_post_meta( $product_id, '_stock_status', $status );
					}
					$product->save();
					update_post_meta( $product_id, '_morabaa_last_sync_at', $this->get_now_timestamp() );
					update_post_meta( $product_id, '_morabaa_last_sync_status', 'success' );
					return;
				}
			}

			update_post_meta( $product_id, '_manage_stock', 'yes' );
			update_post_meta( $product_id, '_stock', $stock_qty );
			update_post_meta( $product_id, '_stock_status', $status );
			update_post_meta( $product_id, '_morabaa_last_sync_at', $this->get_now_timestamp() );
			update_post_meta( $product_id, '_morabaa_last_sync_status', 'success' );
		}

		private function sync_product_catalog_data( $product_id, $item, $mappings ) {
			$product_id = (int) $product_id;
			if ( $product_id <= 0 ) {
				return array(
					'name'  => '',
					'price' => '',
				);
			}

			$name         = $this->get_item_name_value( $item, $mappings );
			$price        = $this->get_item_price_value( $item, $mappings );
			$name         = is_scalar( $name ) ? trim( (string) $name ) : '';
			$price_string = '';
			$product      = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

			if ( null !== $price ) {
				$price = max( 0, (float) $price );
				if ( function_exists( 'wc_format_decimal' ) ) {
					$decimals     = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
					$price_string = (string) wc_format_decimal( $price, $decimals );
				} else {
					$price_string = (string) $price;
				}
			}

			if ( $product ) {
				$has_changes = false;

				if ( '' !== $name && method_exists( $product, 'get_name' ) && method_exists( $product, 'set_name' ) && $name !== (string) $product->get_name() ) {
					$product->set_name( $name );
					$has_changes = true;
				}

				if ( '' !== $price_string ) {
					if ( method_exists( $product, 'set_regular_price' ) ) {
						$product->set_regular_price( $price_string );
						$has_changes = true;
					}
					if ( method_exists( $product, 'set_price' ) ) {
						$product->set_price( $price_string );
						$has_changes = true;
					}
					if ( method_exists( $product, 'get_sale_price' ) && method_exists( $product, 'set_sale_price' ) ) {
						$sale_price = $product->get_sale_price();
						if ( '' !== $sale_price && (float) $sale_price > (float) $price_string ) {
							$product->set_sale_price( '' );
							$has_changes = true;
						}
					}
				}

				if ( $has_changes ) {
					$product->save();
				}
			} else {
				if ( '' !== $name && $name !== (string) get_the_title( $product_id ) ) {
					wp_update_post(
						array(
							'ID'         => $product_id,
							'post_title' => $name,
						)
					);
				}

				if ( '' !== $price_string ) {
					update_post_meta( $product_id, '_regular_price', $price_string );
					update_post_meta( $product_id, '_price', $price_string );
				}
			}

			if ( '' !== $name ) {
				update_post_meta( $product_id, '_morabaa_last_synced_name', $name );
			}
			if ( '' !== $price_string ) {
				update_post_meta( $product_id, '_morabaa_last_synced_price', $price_string );
			}

			return array(
				'name'  => '' !== $name ? $name : (string) get_the_title( $product_id ),
				'price' => $price_string,
			);
		}

		private function get_recently_synced_rows( $limit = 80 ) {
			$rows  = array();
			$limit = max( 1, (int) $limit );

			$query = new WP_Query(
				array(
					'post_type'      => 'product',
					'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
					'posts_per_page' => $limit,
					'fields'         => 'ids',
					'meta_key'       => '_morabaa_last_sync_at',
					'orderby'        => 'meta_value_num',
					'order'          => 'DESC',
					'meta_query'     => array(
						array(
							'key'     => '_morabaa_last_sync_at',
							'value'   => 0,
							'compare' => '>',
							'type'    => 'NUMERIC',
						),
					),
				)
			);

			if ( ! $query->have_posts() ) {
				return $rows;
			}

			foreach ( $query->posts as $product_id ) {
				$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
				if ( ! $product ) {
					continue;
				}

				$rows[] = array(
					'product_id' => (int) $product_id,
					'name'       => (string) get_the_title( $product_id ),
					'morabaa_id' => (string) get_post_meta( $product_id, '_morabaa_item_id', true ),
					'sku'        => (string) $product->get_sku(),
					'price'      => (string) $product->get_price(),
					'stock'      => (string) $product->get_stock_quantity(),
					'time'       => (int) get_post_meta( $product_id, '_morabaa_last_sync_at', true ),
				);
			}

			return $rows;
		}

		private function link_all_by_sku( $mappings ) {
			$items = $this->fetch_pos_items();
			if ( empty( $items ) ) {
				$items = $this->fetch_pos_items( true );
			}

			$map_by_sku = array();
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$sku = $this->get_item_sku_value( $item, $mappings );
				$iid = $this->get_item_id_value( $item, $mappings );
				if ( '' === $sku || '' === $iid ) {
					continue;
				}

				if ( ! isset( $map_by_sku[ $sku ] ) ) {
					$map_by_sku[ $sku ] = $item;
				}
			}

			$query = new WP_Query(
				array(
					'post_type'      => 'product',
					'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);

			$linked = 0;
			$skipped = 0;
			foreach ( $query->posts as $product_id ) {
				$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
				if ( ! $product ) {
					$skipped++;
					continue;
				}

				$sku = (string) $product->get_sku();
				if ( '' === $sku || ! isset( $map_by_sku[ $sku ] ) ) {
					$skipped++;
					continue;
				}

				$item    = $map_by_sku[ $sku ];
				$item_id = $this->get_item_id_value( $item, $mappings );
				if ( '' === $item_id ) {
					$skipped++;
					continue;
				}

				if ( $this->link_wc_product_to_item( (int) $product_id, $item_id, $item, $mappings ) ) {
					$linked++;
				} else {
					$skipped++;
				}
			}

			return array(
				'linked'  => $linked,
				'skipped' => $skipped,
				'failed'  => $skipped,
				'total'   => $linked + $skipped,
			);
		}

		private function get_linked_products_count() {
			$query = new WP_Query(
				array(
					'post_type'      => 'product',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_query'     => array(
						array(
							'key'     => '_morabaa_item_id',
							'compare' => 'EXISTS',
						),
					),
				)
			);
			return (int) $query->found_posts;
		}

		private function get_connection_state() {
			$endpoint = get_option( 'morabaa_woo_api_endpoint', $this->get_default_endpoint() );
			$app_id   = get_option( 'morabaa_woo_app_id', '' );
			$secret_e = get_option( 'morabaa_woo_app_secret', '' );

			$has_settings = ! empty( $endpoint ) && ! empty( $app_id ) && ! empty( $secret_e );
			$last_status  = get_option( 'morabaa_woo_last_test_status', '' );

			if ( ! $has_settings ) {
				return array(
					'code'  => 'missing',
					'icon'  => 'dashicons-warning',
					'label' => 'الإعدادات غير مكتملة',
					'class' => 'morabaa-badge-danger',
					'icon_class' => 'is-disconnected',
				);
			}

			if ( 'success' === $last_status ) {
				return array(
					'code'  => 'connected',
					'icon'  => 'dashicons-yes-alt',
					'label' => 'متصل مع مربّع',
					'class' => 'morabaa-badge-success',
					'icon_class' => 'is-connected',
				);
			}

			if ( 'error' === $last_status ) {
				return array(
					'code'  => 'failed',
					'icon'  => 'dashicons-dismiss',
					'label' => 'آخر فحص اتصال فشل',
					'class' => 'morabaa-badge-danger',
					'icon_class' => 'is-disconnected',
				);
			}

			return array(
				'code'  => 'unknown',
				'icon'  => 'dashicons-marker',
				'label' => 'لم يتم اختبار الاتصال بعد',
				'class' => 'morabaa-badge-neutral',
				'icon_class' => 'is-disconnected',
			);
		}

		private function get_wc_products_stock_rows( $limit = -1 ) {
			$rows = array();

			$posts_per_page = -1;
			if ( is_numeric( $limit ) ) {
				$limit = (int) $limit;
				if ( $limit > 0 ) {
					$posts_per_page = $limit;
				}
			}

			$query = new WP_Query(
				array(
					'post_type'      => 'product',
					'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
					'posts_per_page' => $posts_per_page,
					'orderby'        => 'title',
					'order'          => 'ASC',
					'fields'         => 'ids',
				)
			);

			if ( ! $query->have_posts() ) {
				return $rows;
			}

			foreach ( $query->posts as $product_id ) {
				$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
				if ( ! $product ) {
					continue;
				}

				$qty = $product->get_stock_quantity();
				if ( null === $qty ) {
					$qty = get_post_meta( $product_id, '_stock', true );
				}
				$qty = ( '' === $qty || null === $qty ) ? 0 : $qty;

				$rows[] = array(
					'id'         => (int) $product_id,
					'name'       => get_the_title( $product_id ),
					'sku'        => (string) $product->get_sku(),
					'price'      => (string) $product->get_price(),
					'stock'      => is_numeric( $qty ) ? $qty : 0,
					'status'     => (string) $product->get_stock_status(),
					'morabaa_id' => (string) get_post_meta( $product_id, '_morabaa_item_id', true ),
				);
			}

			return $rows;
		}

		private function get_product_sync_flags( $product_id ) {
			$product_id = (int) $product_id;
			if ( $product_id <= 0 ) {
				return array(
					'is_linked' => false,
					'is_synced' => false,
				);
			}

			$morabaa_id = (string) get_post_meta( $product_id, '_morabaa_item_id', true );
			$sync_at    = (int) get_post_meta( $product_id, '_morabaa_last_sync_at', true );
			$sync_state = (string) get_post_meta( $product_id, '_morabaa_last_sync_status', true );

			return array(
				'is_linked' => '' !== trim( $morabaa_id ),
				'is_synced' => ( $sync_at > 0 ) || ( 'success' === $sync_state ),
			);
		}

		private function perform_sync( $silent = false ) {
			$mappings = $this->get_mappings();
			$items    = $this->fetch_pos_items();

			if ( empty( $items ) ) {
				return array(
					'updated' => 0,
					'total'   => 0,
				);
			}

			$updated_count = 0;
			$total_matched = 0;
			$last_synced_rows = array();

			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$product_id = $this->find_wc_product_for_item( $item, $mappings, false );
				if ( $product_id ) {
					$total_matched++;
					$item_id  = $this->get_item_id_value( $item, $mappings );
					$item_sku = $this->get_item_sku_value( $item, $mappings );
					if ( ! empty( $item_id ) ) {
						update_post_meta( $product_id, '_morabaa_item_id', $item_id );
					}
					$catalog_data = $this->sync_product_catalog_data( $product_id, $item, $mappings );
					$stock        = $this->get_item_stock_value( $item, $mappings );
					$this->update_product_stock( $product_id, $stock );
					$updated_count++;

					if ( count( $last_synced_rows ) < 300 ) {
						$last_synced_rows[] = array(
							'product_id' => (int) $product_id,
							'name'       => isset( $catalog_data['name'] ) ? (string) $catalog_data['name'] : (string) get_the_title( $product_id ),
							'morabaa_id' => (string) $item_id,
							'sku'        => (string) $item_sku,
							'price'      => isset( $catalog_data['price'] ) ? (string) $catalog_data['price'] : '',
							'stock'      => is_numeric( $stock ) ? (string) $stock : '0',
							'time'       => $this->get_now_timestamp(),
						);
					}
				}
			}

			update_option( 'morabaa_woo_last_sync_rows', $last_synced_rows );
			update_option( 'morabaa_woo_last_sync_time', $this->get_now_timestamp() );
			update_option( 'morabaa_woo_last_linked_count', (int) $this->get_linked_products_count() );

			if ( ! empty( $items ) ) {
				$unlinked_items = $this->get_unlinked_items_for_import( $items, $mappings, false );
				update_option( 'morabaa_woo_last_unlinked_count', (int) count( $unlinked_items ) );
			}

			return array(
				'updated' => $updated_count,
				'total'   => $total_matched,
			);
		}

		public function cron_sync() {
			update_option( 'morabaa_woo_last_cron_run', $this->get_now_timestamp() );

			if ( ! $this->is_auto_sync_enabled() ) {
				update_option( 'morabaa_woo_last_cron_status', 'disabled' );
				update_option( 'morabaa_woo_last_cron_details', 'المزامنة التلقائية متوقفة.' );
				return;
			}

			if ( get_transient( 'morabaa_woo_sync_lock' ) ) {
				update_option( 'morabaa_woo_last_cron_status', 'skipped_locked' );
				update_option( 'morabaa_woo_last_cron_details', 'تم تخطي التنفيذ لأن مزامنة أخرى كانت قيد التشغيل.' );
				return;
			}

			set_transient( 'morabaa_woo_sync_lock', 1, 4 * MINUTE_IN_SECONDS );
			$result  = $this->perform_sync( true );
			$updated = isset( $result['updated'] ) ? (int) $result['updated'] : 0;
			$total   = isset( $result['total'] ) ? (int) $result['total'] : 0;
			update_option( 'morabaa_woo_last_cron_status', 'success' );
			update_option( 'morabaa_woo_last_cron_details', sprintf( 'نجح التنفيذ. تم تحديث %1$d من أصل %2$d منتج مرتبط.', $updated, $total ) );
			delete_transient( 'morabaa_woo_sync_lock' );
		}

		private function get_unlinked_items_for_import( $items, $mappings, $exclude_existing_skus = true ) {
			$unlinked = array();

			if ( empty( $items ) || ! is_array( $items ) ) {
				return $unlinked;
			}

			$existing_item_ids = array();
			$existing_skus     = array();

			global $wpdb;
			$item_id_rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''",
					'_morabaa_item_id'
				)
			);
			if ( is_array( $item_id_rows ) ) {
				foreach ( $item_id_rows as $iid ) {
					$iid = is_scalar( $iid ) ? trim( (string) $iid ) : '';
					if ( '' !== $iid ) {
						$existing_item_ids[ $iid ] = true;
					}
				}
			}

			$sku_rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''",
					'_sku'
				)
			);
			if ( is_array( $sku_rows ) ) {
				foreach ( $sku_rows as $sku_db ) {
					$sku_db = is_scalar( $sku_db ) ? trim( (string) $sku_db ) : '';
					if ( '' !== $sku_db ) {
						$existing_skus[ $sku_db ] = true;
					}
				}
			}

			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$item_id = $this->get_item_id_value( $item, $mappings );
				$name    = $this->get_item_name_value( $item, $mappings );
				$sku     = $this->get_item_sku_value( $item, $mappings );
				$price   = $this->get_item_price_value( $item, $mappings );
				$stock   = $this->get_item_stock_value( $item, $mappings );

				$name    = is_scalar( $name ) ? (string) $name : '';
				$sku     = is_scalar( $sku ) ? (string) $sku : '';
				$price   = null !== $price ? (string) $price : '';
				$stock   = is_numeric( $stock ) ? (string) $stock : '0';

				if ( '' !== $item_id && isset( $existing_item_ids[ $item_id ] ) ) {
					continue;
				}

				if ( $exclude_existing_skus && '' !== $sku && isset( $existing_skus[ $sku ] ) ) {
					continue;
				}

				if ( empty( $item_id ) && empty( $name ) && empty( $sku ) ) {
					continue;
				}

				$unlinked[ $item_id ?: md5( $name . '|' . $sku ) ] = array(
					'item_id' => $item_id,
					'name'    => $name,
					'sku'     => $sku,
					'price'   => $price,
					'stock'   => $stock,
				);
			}

			return $unlinked;
		}

		private function get_unlinked_items_cached( $items, $mappings, $force_refresh = false, $exclude_existing_skus = true ) {
			if ( empty( $items ) || ! is_array( $items ) ) {
				return array();
			}

			$endpoint  = get_option( 'morabaa_woo_api_endpoint', $this->get_default_endpoint() );
			$app_id    = get_option( 'morabaa_woo_app_id', '' );
			$cache_key = $this->get_unlinked_items_cache_key( $endpoint, $app_id, $mappings, $exclude_existing_skus );

			if ( ! $force_refresh ) {
				$cached = get_transient( $cache_key );
				if ( is_array( $cached ) ) {
					return $cached;
				}
			}

			$unlinked = $this->get_unlinked_items_for_import( $items, $mappings, $exclude_existing_skus );
			set_transient( $cache_key, $unlinked, 15 * MINUTE_IN_SECONDS );

			return $unlinked;
		}

		private function get_unlinked_wc_products_for_linking( $limit = -1 ) {
			$rows = array();
			$limit = (int) $limit;
			if ( $limit <= 0 ) {
				$limit = -1;
			}

			$query = new WP_Query(
				array(
					'post_type'      => 'product',
					'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
					'posts_per_page' => $limit,
					'orderby'        => 'title',
					'order'          => 'ASC',
					'fields'         => 'ids',
					'meta_query'     => array(
						'relation' => 'OR',
						array(
							'key'     => '_morabaa_item_id',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => '_morabaa_item_id',
							'value'   => '',
							'compare' => '=',
						),
					),
				)
			);

			if ( ! $query->have_posts() ) {
				return $rows;
			}

			foreach ( $query->posts as $product_id ) {
				$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
				if ( ! $product ) {
					continue;
				}

				$rows[] = array(
					'id'   => (int) $product_id,
					'name' => (string) get_the_title( $product_id ),
					'sku'  => (string) $product->get_sku(),
				);
			}

			return $rows;
		}

		private function normalize_name_for_match( $name ) {
			$name = is_scalar( $name ) ? (string) $name : '';
			$name = wp_strip_all_tags( $name );
			if ( function_exists( 'mb_strtolower' ) ) {
				$name = mb_strtolower( $name, 'UTF-8' );
			} else {
				$name = strtolower( $name );
			}

			$name = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $name );
			$name = preg_replace( '/\s+/u', ' ', $name );
			return trim( $name );
		}

		private function tokenize_name( $name ) {
			$normalized = $this->normalize_name_for_match( $name );
			if ( '' === $normalized ) {
				return array();
			}

			$parts = explode( ' ', $normalized );
			$tokens = array();
			foreach ( $parts as $part ) {
				$part = trim( $part );
				if ( '' === $part ) {
					continue;
				}
				$tokens[] = $part;
			}

			return array_values( array_unique( $tokens ) );
		}

		private function calculate_name_similarity( $left, $right ) {
			$left_normalized  = $this->normalize_name_for_match( $left );
			$right_normalized = $this->normalize_name_for_match( $right );

			if ( '' === $left_normalized || '' === $right_normalized ) {
				return 0;
			}

			$char_percent = 0.0;
			similar_text( $left_normalized, $right_normalized, $char_percent );

			$left_tokens  = $this->tokenize_name( $left_normalized );
			$right_tokens = $this->tokenize_name( $right_normalized );

			$token_percent = 0.0;
			if ( ! empty( $left_tokens ) && ! empty( $right_tokens ) ) {
				$intersect = array_intersect( $left_tokens, $right_tokens );
				$token_percent = ( 2 * count( $intersect ) * 100 ) / ( count( $left_tokens ) + count( $right_tokens ) );
			}

			$score = ( 0.55 * $token_percent ) + ( 0.45 * $char_percent );
			return (int) round( $score );
		}

		private function get_name_link_suggestions( $unlinked_items, $unlinked_wc_products, $max_suggestions = 80 ) {
			$suggestions = array();
			$used_wc_ids = array();
			$max_suggestions = max( 1, (int) $max_suggestions );
			$wc_by_normalized_name = array();

			foreach ( $unlinked_wc_products as $wc_row ) {
				$wc_id = isset( $wc_row['id'] ) ? (int) $wc_row['id'] : 0;
				if ( $wc_id <= 0 ) {
					continue;
				}

				$wc_name = isset( $wc_row['name'] ) ? (string) $wc_row['name'] : '';
				$wc_name_normalized = $this->normalize_name_for_match( $wc_name );
				if ( '' === $wc_name_normalized || isset( $wc_by_normalized_name[ $wc_name_normalized ] ) ) {
					continue;
				}

				$wc_by_normalized_name[ $wc_name_normalized ] = $wc_row;
			}

			foreach ( $unlinked_items as $item ) {
				if ( count( $suggestions ) >= $max_suggestions ) {
					break;
				}

				$item_name = isset( $item['name'] ) ? (string) $item['name'] : '';
				$item_id   = isset( $item['item_id'] ) ? (string) $item['item_id'] : '';
				if ( '' === $item_name || '' === $item_id ) {
					continue;
				}

				$item_name_normalized = $this->normalize_name_for_match( $item_name );
				if ( '' === $item_name_normalized || ! isset( $wc_by_normalized_name[ $item_name_normalized ] ) ) {
					continue;
				}

				$best_match = $wc_by_normalized_name[ $item_name_normalized ];
				$wc_id = isset( $best_match['id'] ) ? (int) $best_match['id'] : 0;
				if ( $wc_id <= 0 || isset( $used_wc_ids[ $wc_id ] ) ) {
					continue;
				}

				$used_wc_ids[ $wc_id ] = true;
				$suggestions[] = array(
					'item_id'      => $item_id,
					'item_name'    => $item_name,
					'item_sku'     => isset( $item['sku'] ) ? (string) $item['sku'] : '',
					'wc_id'        => $wc_id,
					'wc_name'      => isset( $best_match['name'] ) ? (string) $best_match['name'] : '',
					'wc_sku'       => isset( $best_match['sku'] ) ? (string) $best_match['sku'] : '',
					'similarity'   => 100,
				);
			}

			usort(
				$suggestions,
				function ( $a, $b ) {
					$as = isset( $a['similarity'] ) ? (int) $a['similarity'] : 0;
					$bs = isset( $b['similarity'] ) ? (int) $b['similarity'] : 0;
					if ( $as === $bs ) {
						return 0;
					}
					return ( $as > $bs ) ? -1 : 1;
				}
			);

			return $suggestions;
		}

		private function link_wc_product_to_item( $product_id, $item_id, $item, $mappings ) {
			$product_id = (int) $product_id;
			$item_id    = is_scalar( $item_id ) ? (string) $item_id : '';

			if ( $product_id <= 0 || '' === $item_id ) {
				return false;
			}

			if ( 'product' !== get_post_type( $product_id ) ) {
				return false;
			}

			update_post_meta( $product_id, '_morabaa_item_id', $item_id );

			$item_sku = $this->get_item_sku_value( $item, $mappings );
			if ( '' !== $item_sku ) {
				$existing_product_with_sku = function_exists( 'wc_get_product_id_by_sku' ) ? (int) wc_get_product_id_by_sku( $item_sku ) : 0;
				if ( 0 === $existing_product_with_sku || $existing_product_with_sku === $product_id ) {
					update_post_meta( $product_id, '_sku', $item_sku );
				}
			}

			$this->sync_product_catalog_data( $product_id, $item, $mappings );
			$stock = $this->get_item_stock_value( $item, $mappings );
			$this->update_product_stock( $product_id, $stock );

			return true;
		}

		private function link_all_exact_matches( $mappings ) {
			$items = $this->fetch_pos_items();
			if ( empty( $items ) ) {
				$items = $this->fetch_pos_items( true );
			}

			$unlinked_items       = $this->get_unlinked_items_for_import( $items, $mappings, false );
			$unlinked_wc_products = $this->get_unlinked_wc_products_for_linking( 2000 );
			$suggestions          = $this->get_name_link_suggestions( $unlinked_items, $unlinked_wc_products, 2000 );

			$item_map = array();
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$iid = $this->get_item_id_value( $item, $mappings );
				if ( '' !== $iid ) {
					$item_map[ $iid ] = $item;
				}
			}

			$linked = 0;
			$failed = 0;
			foreach ( $suggestions as $s ) {
				$item_id = isset( $s['item_id'] ) ? (string) $s['item_id'] : '';
				$wc_id   = isset( $s['wc_id'] ) ? (int) $s['wc_id'] : 0;
				if ( '' === $item_id || $wc_id <= 0 || ! isset( $item_map[ $item_id ] ) ) {
					$failed++;
					continue;
				}
				if ( $this->link_wc_product_to_item( $wc_id, $item_id, $item_map[ $item_id ], $mappings ) ) {
					$linked++;
				} else {
					$failed++;
				}
			}

			return array(
				'linked' => $linked,
				'failed' => $failed,
				'total'  => count( $suggestions ),
			);
		}

		private function import_selected_items( $selected_ids ) {
			if ( empty( $selected_ids ) || ! is_array( $selected_ids ) ) {
				return array(
					'imported' => 0,
					'skipped'  => 0,
				);
			}

			$mappings = $this->get_mappings();
			$items    = $this->fetch_pos_items();
			if ( empty( $items ) ) {
				return array(
					'imported' => 0,
					'skipped'  => count( $selected_ids ),
				);
			}

			$map_by_item_id = array();
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$item_id = $this->get_item_id_value( $item, $mappings );
				if ( ! empty( $item_id ) ) {
					$map_by_item_id[ $item_id ] = $item;
				}
			}

			$imported = 0;
			$skipped  = 0;

			foreach ( $selected_ids as $sid ) {
				$sid = is_scalar( $sid ) ? (string) $sid : '';
				if ( '' === $sid ) {
					$skipped++;
					continue;
				}

				if ( ! isset( $map_by_item_id[ $sid ] ) ) {
					$skipped++;
					continue;
				}

				$item = $map_by_item_id[ $sid ];

				$product_id_existing = $this->find_wc_product_for_item( $item, $mappings, false );
				if ( $product_id_existing ) {
					$skipped++;
					continue;
				}

				$name  = $this->get_item_name_value( $item, $mappings );
				$sku   = $this->get_item_sku_value( $item, $mappings );
				$price = $this->get_item_price_value( $item, $mappings );
				$stock = $this->get_item_stock_value( $item, $mappings );

				$name  = is_scalar( $name ) ? (string) $name : '';
				$sku   = is_scalar( $sku ) ? (string) $sku : '';
				$price = null !== $price ? (float) $price : null;
				$stock = is_numeric( $stock ) ? $stock : 0;

				if ( '' === $name ) {
					$skipped++;
					continue;
				}

				$post_data = array(
					'post_title'  => $name,
					'post_status' => 'publish',
					'post_type'   => 'product',
				);

				$new_id = wp_insert_post( $post_data, true );
				if ( is_wp_error( $new_id ) || ! $new_id ) {
					$skipped++;
					continue;
				}

				if ( '' !== $sku ) {
					$sku_clean = function_exists( 'wc_clean' ) ? wc_clean( $sku ) : sanitize_text_field( $sku );
					update_post_meta( $new_id, '_sku', $sku_clean );
				}

				update_post_meta( $new_id, '_morabaa_item_id', $sid );
				if ( null !== $price ) {
					update_post_meta( $new_id, '_regular_price', $price );
					update_post_meta( $new_id, '_price', $price );
				}

				$this->sync_product_catalog_data( $new_id, $item, $mappings );
				$this->update_product_stock( $new_id, $stock );

				$imported++;
			}

			return array(
				'imported' => $imported,
				'skipped'  => $skipped,
			);
		}

		private function save_settings() {
			$endpoint = isset( $_POST['morabaa_api_endpoint'] ) ? esc_url_raw( wp_unslash( $_POST['morabaa_api_endpoint'] ) ) : '';
			$app_id   = isset( $_POST['morabaa_app_id'] ) ? sanitize_text_field( wp_unslash( $_POST['morabaa_app_id'] ) ) : '';
			$secret   = isset( $_POST['morabaa_app_secret'] ) ? trim( (string) wp_unslash( $_POST['morabaa_app_secret'] ) ) : '';

			$item_id_key = isset( $_POST['morabaa_json_item_id'] ) ? sanitize_text_field( wp_unslash( $_POST['morabaa_json_item_id'] ) ) : '';
			$name_key    = isset( $_POST['morabaa_json_name'] ) ? sanitize_text_field( wp_unslash( $_POST['morabaa_json_name'] ) ) : '';
			$sku_key     = isset( $_POST['morabaa_json_sku'] ) ? sanitize_text_field( wp_unslash( $_POST['morabaa_json_sku'] ) ) : '';
			$stock_key   = isset( $_POST['morabaa_json_stock'] ) ? sanitize_text_field( wp_unslash( $_POST['morabaa_json_stock'] ) ) : '';
			$price_key   = isset( $_POST['morabaa_json_price'] ) ? sanitize_text_field( wp_unslash( $_POST['morabaa_json_price'] ) ) : '';

			if ( empty( $endpoint ) ) {
				$endpoint = $this->get_default_endpoint();
			}

			update_option( 'morabaa_woo_api_endpoint', $endpoint );
			update_option( 'morabaa_woo_app_id', $app_id );

			$existing_encrypted = get_option( 'morabaa_woo_app_secret', '' );
			if ( '' !== $secret ) {
				$encrypted = $this->encrypt_secret( $secret );
				if ( '' !== $encrypted ) {
					update_option( 'morabaa_woo_app_secret', $encrypted );
				}
			} elseif ( '' === $secret && '' === $existing_encrypted ) {
				update_option( 'morabaa_woo_app_secret', '' );
			}

			$mappings = array(
				'item_id' => $item_id_key ?: 'id',
				'name'    => $name_key ?: 'name',
				'sku'     => $sku_key ?: 'sku',
				'stock'   => $stock_key ?: 'stock',
				'price'   => $price_key ?: 'sellPrice',
			);
			update_option( 'morabaa_woo_json_mapping', $mappings );
		}

		public function render_admin_page() {
			if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'غير مسموح بالوصول إلى هذه الصفحة.', 'morabaa-woo-sync' ) );
			}

			$this->ensure_sync_cron_scheduled();

			$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
			$link_view  = isset( $_GET['link_view'] ) ? sanitize_key( wp_unslash( $_GET['link_view'] ) ) : 'none';
			$import_view = isset( $_GET['import_view'] ) ? sanitize_key( wp_unslash( $_GET['import_view'] ) ) : 'none';
			$dashboard_view = isset( $_GET['dashboard_view'] ) ? sanitize_key( wp_unslash( $_GET['dashboard_view'] ) ) : 'none';
			if ( ! in_array( $link_view, array( 'none', 'all', 'suggestions', 'wc', 'morabaa' ), true ) ) {
				$link_view = 'none';
			}
			if ( ! in_array( $import_view, array( 'none', 'list' ), true ) ) {
				$import_view = 'none';
			}
			if ( ! in_array( $dashboard_view, array( 'none', 'stock' ), true ) ) {
				$dashboard_view = 'none';
			}
			$import_page_num = isset( $_GET['import_page_num'] ) ? max( 1, absint( wp_unslash( $_GET['import_page_num'] ) ) ) : 1;
			$import_per_page = 250;
			$message    = '';
			$message_t  = 'success';

			if ( isset( $_POST['morabaa_action'] ) ) {
				$action = sanitize_text_field( wp_unslash( $_POST['morabaa_action'] ) );

				if ( 'save_settings' === $action ) {
					if ( ! isset( $_POST['morabaa_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['morabaa_nonce'] ) ), 'morabaa_save_settings' ) ) {
						$message   = 'حدث خطأ في التحقق من الطلب. يرجى إعادة المحاولة.';
						$message_t = 'error';
					} else {
						$this->save_settings();
						$message   = 'تم حفظ الإعدادات بنجاح.';
						$message_t = 'success';
						$active_tab = 'settings';
					}
				} elseif ( 'test_connection' === $action ) {
					if ( ! isset( $_POST['morabaa_test_connection_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['morabaa_test_connection_nonce'] ) ), 'morabaa_test_connection' ) ) {
						$message   = 'حدث خطأ في التحقق من طلب اختبار الاتصال.';
						$message_t = 'error';
					} else {
						$result   = $this->test_connection();
						$message  = isset( $result['message'] ) ? $result['message'] : '';
						$message_t = ( ! empty( $result['success'] ) ) ? 'success' : 'error';
						$active_tab = 'settings';
					}
				} elseif ( 'manual_sync' === $action ) {
					if ( ! isset( $_POST['morabaa_manual_sync_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['morabaa_manual_sync_nonce'] ) ), 'morabaa_manual_sync' ) ) {
						$message   = 'حدث خطأ في التحقق من طلب المزامنة.';
						$message_t = 'error';
					} else {
						$result = $this->perform_sync( false );
						$updated = isset( $result['updated'] ) ? (int) $result['updated'] : 0;
						$total   = isset( $result['total'] ) ? (int) $result['total'] : 0;
						$message = sprintf(
							'تمت مزامنة الاسم والسعر والمخزون بنجاح. تم تحديث %1$d من إجمالي %2$d منتج مرتبط.',
							$updated,
							$total
						);
						$message_t  = 'success';
						$active_tab = 'dashboard';
					}
				} elseif ( 'pull_status' === $action ) {
					if ( ! isset( $_POST['morabaa_pull_status_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['morabaa_pull_status_nonce'] ) ), 'morabaa_pull_status' ) ) {
						$message   = 'حدث خطأ في التحقق من طلب السحب.';
						$message_t = 'error';
					} else {
						$linked_now   = $this->get_linked_products_count();
						update_option( 'morabaa_woo_last_linked_count', (int) $linked_now );
						$items_now    = $this->fetch_pos_items( true );
						$mappings_now = $this->get_mappings();
						$unlinked_now = $this->get_unlinked_items_for_import( $items_now, $mappings_now, false );
						$unlinked_cnt = is_array( $unlinked_now ) ? count( $unlinked_now ) : 0;
						update_option( 'morabaa_woo_last_unlinked_count', (int) $unlinked_cnt );

						$message = sprintf(
							'تم سحب حالة الربط من مربّع بنجاح بدون مزامنة. المنتجات المرتبطة حالياً: %1$d منتج. المنتجات غير المرتبطة (في مربّع فقط): %2$d منتج.',
							(int) $linked_now,
							(int) $unlinked_cnt
						);
						$message_t  = 'success';
						$active_tab = 'dashboard';
					}
				} elseif ( 'import_items' === $action ) {
					if ( ! isset( $_POST['morabaa_import_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['morabaa_import_nonce'] ) ), 'morabaa_import_items' ) ) {
						$message   = 'حدث خطأ في التحقق من طلب الاستيراد.';
						$message_t = 'error';
					} else {
						$single_item = isset( $_POST['morabaa_single_import'] ) ? sanitize_text_field( wp_unslash( $_POST['morabaa_single_import'] ) ) : '';
						if ( '' !== $single_item ) {
							$selected = array( $single_item );
						} else {
							$selected = isset( $_POST['morabaa_import_items'] ) && is_array( $_POST['morabaa_import_items'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['morabaa_import_items'] ) ) : array();
						}
						$result   = $this->import_selected_items( $selected );
						$imported = isset( $result['imported'] ) ? (int) $result['imported'] : 0;
						$skipped  = isset( $result['skipped'] ) ? (int) $result['skipped'] : 0;
						if ( '' !== $single_item ) {
							$message = $imported > 0 ? 'تمت إضافة المنتج إلى المتجر بنجاح.' : 'تعذر إضافة المنتج. قد يكون موجود مسبقاً أو البيانات غير مكتملة.';
						} else {
							$message  = sprintf(
								'تم استيراد %1$d منتج من مربّع. تم تخطي %2$d منتج (موجود مسبقاً أو بيانات غير مكتملة).',
								$imported,
								$skipped
							);
						}
						$message_t  = 'success';
						$active_tab = 'import';
						$import_view = 'list';
					}
				} elseif ( 'barcode_diagnose' === $action ) {
					if ( ! isset( $_POST['morabaa_barcode_diag_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['morabaa_barcode_diag_nonce'] ) ), 'morabaa_barcode_diagnose' ) ) {
						$message   = 'حدث خطأ في التحقق من طلب فحص الباركود.';
						$message_t = 'error';
					} else {
						$items_diag = $this->fetch_pos_items( true );
						$total_diag = is_array( $items_diag ) ? count( $items_diag ) : 0;
						$with_barcode = 0;
						$with_sku_field = 0;
						$extracted = 0;

						foreach ( $items_diag as $item ) {
							if ( ! is_array( $item ) ) {
								continue;
							}
							$raw_barcode = $this->get_item_barcode_raw_value( $item );
							$raw_sku     = $this->normalize_sku_value( $this->get_mapped_value( $item, isset( $mappings['sku'] ) ? $mappings['sku'] : 'sku' ) );
							$final_sku   = $this->get_item_sku_value( $item, $mappings );

							if ( '' !== $raw_barcode || $this->has_non_empty_barcodes_value( $item ) ) {
								$with_barcode++;
							}
							if ( '' !== $raw_sku ) {
								$with_sku_field++;
							}
							if ( '' !== $final_sku ) {
								$extracted++;
							}

						}

						if ( $total_diag <= 0 ) {
							$message   = 'فحص الباركود: لا توجد بيانات راجعة من السيرفر حالياً.';
							$message_t = 'error';
						} elseif ( 0 === $with_barcode && 0 === $with_sku_field ) {
							$message   = 'فحص الباركود: لا توجد قيم SKU/Barcode في الاستجابة الحالية.';
							$message_t = 'error';
						} elseif ( $extracted <= 0 ) {
							$message   = 'نتيجة الفحص: البيانات فيها SKU/Barcode لكن الاستخراج فشل من الكود.';
							$message_t = 'error';
						} else {
							$message = sprintf( 'فحص الباركود مكتمل: إجمالي العناصر %1$d، فيها Barcode %2$d، فيها SKU مباشر %3$d، تم استخراج SKU/Barcode بنجاح %4$d.', $total_diag, $with_barcode, $with_sku_field, $extracted );
							$message_t = 'success';
						}

						$active_tab = 'import';
						$import_view = 'none';
					}
				} elseif ( 'link_pair' === $action ) {
					if ( ! isset( $_POST['morabaa_link_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['morabaa_link_nonce'] ) ), 'morabaa_link_pair' ) ) {
						$message   = 'حدث خطأ في التحقق من طلب الربط.';
						$message_t = 'error';
					} else {
						$product_id = isset( $_POST['morabaa_wc_product_id'] ) ? absint( wp_unslash( $_POST['morabaa_wc_product_id'] ) ) : 0;
						$item_id    = isset( $_POST['morabaa_item_id'] ) ? sanitize_text_field( wp_unslash( $_POST['morabaa_item_id'] ) ) : '';
						$item_sku   = isset( $_POST['morabaa_item_sku'] ) ? sanitize_text_field( wp_unslash( $_POST['morabaa_item_sku'] ) ) : '';

						$items       = $this->fetch_pos_items();
						if ( empty( $items ) ) {
							$items = $this->fetch_pos_items( true );
						}
						$item_by_id  = array();
						$item_by_sku = array();

						foreach ( $items as $item ) {
							if ( ! is_array( $item ) ) {
								continue;
							}
							$iid = $this->get_item_id_value( $item, $mappings );
							$isk = $this->get_item_sku_value( $item, $mappings );
							if ( '' !== $iid ) {
								$item_by_id[ $iid ] = $item;
							}
							if ( '' !== $isk ) {
								$item_by_sku[ $isk ] = $item;
							}
						}

						$selected_item = null;
						if ( '' !== $item_id && isset( $item_by_id[ $item_id ] ) ) {
							$selected_item = $item_by_id[ $item_id ];
						} elseif ( '' !== $item_sku && isset( $item_by_sku[ $item_sku ] ) ) {
							$selected_item = $item_by_sku[ $item_sku ];
						}

						if ( $product_id <= 0 || ! is_array( $selected_item ) ) {
							$message   = 'تعذر الربط. تحقق من المنتج أو معرّف مربّع وحاول مرة أخرى.';
							$message_t = 'error';
						} else {
							$item_id_final = '' !== $item_id ? $item_id : $this->get_item_id_value( $selected_item, $mappings );
							$ok = $this->link_wc_product_to_item( $product_id, $item_id_final, $selected_item, $mappings );
							if ( $ok ) {
								$message   = 'تم ربط المنتج مع مربّع بنجاح (بالمعرّف أو SKU/Barcode)، مع تحديث الاسم والسعر والمخزون وحفظ SKU من باركود مربّع عند التوفر.';
								$message_t = 'success';
							} else {
								$message   = 'فشل الربط. تأكد أن المنتج المختار من نوع WooCommerce Product.';
								$message_t = 'error';
							}
						}

						$active_tab = 'linking';
					}
				} elseif ( 'refresh_items_cache' === $action ) {
					if ( ! isset( $_POST['morabaa_refresh_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['morabaa_refresh_nonce'] ) ), 'morabaa_refresh_items_cache' ) ) {
						$message   = 'حدث خطأ في التحقق من طلب إعادة التحديث.';
						$message_t = 'error';
					} else {
						$this->clear_items_cache();
						$fresh_items = $this->fetch_pos_items( true );
						$message = sprintf( 'تمت إعادة تحميل بيانات مربّع بنجاح. العناصر المسحوبة الآن: %d', count( $fresh_items ) );
						$message_t = 'success';
					}

					if ( in_array( $active_tab, array( 'import', 'linking' ), true ) ) {
						if ( 'import' === $active_tab ) {
							$import_view = 'list';
						}
						// stay on the same tab.
					} else {
						$active_tab = 'dashboard';
					}
				} elseif ( 'reset_query_data' === $action ) {
					if ( ! isset( $_POST['morabaa_reset_query_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['morabaa_reset_query_nonce'] ) ), 'morabaa_reset_query_data' ) ) {
						$message   = 'حدث خطأ في التحقق من طلب حذف بيانات الاستعلام.';
						$message_t = 'error';
					} else {
						$this->clear_items_cache();
						update_option( 'morabaa_woo_last_unlinked_count', 0 );
						$message   = 'تم حذف بيانات الاستعلام المخزنة داخل الإضافة فقط. لم يتم حذف أي منتج من WooCommerce.';
						$message_t = 'success';
					}
					$active_tab  = 'import';
					$import_view = 'none';
				} elseif ( 'toggle_auto_sync' === $action ) {
					if ( ! isset( $_POST['morabaa_toggle_sync_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['morabaa_toggle_sync_nonce'] ) ), 'morabaa_toggle_auto_sync' ) ) {
						$message   = 'حدث خطأ في التحقق من طلب تغيير حالة المزامنة التلقائية.';
						$message_t = 'error';
					} else {
						$target = isset( $_POST['morabaa_auto_sync_target'] ) ? sanitize_key( wp_unslash( $_POST['morabaa_auto_sync_target'] ) ) : 'on';
						$enable = ( 'off' !== $target );
						$this->set_auto_sync_enabled( $enable );
						$message = $enable ? 'تم تشغيل المزامنة التلقائية كل 20 دقيقة.' : 'تم إيقاف المزامنة التلقائية.';
						$message_t = 'success';
					}
					$active_tab = 'dashboard';
				} elseif ( 'link_all_exact' === $action ) {
					if ( ! isset( $_POST['morabaa_link_all_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['morabaa_link_all_nonce'] ) ), 'morabaa_link_all_exact' ) ) {
						$message   = 'حدث خطأ في التحقق من طلب ربط الكل.';
						$message_t = 'error';
					} else {
						$result = $this->link_all_exact_matches( $mappings );
						$linked = isset( $result['linked'] ) ? (int) $result['linked'] : 0;
						$failed = isset( $result['failed'] ) ? (int) $result['failed'] : 0;
						$total  = isset( $result['total'] ) ? (int) $result['total'] : 0;
						$message = sprintf( 'تمت محاولة الربط التلقائي المطابق 100%%. نجح %1$d من أصل %2$d، وفشل %3$d.', $linked, $total, $failed );
						$message_t = ( $linked > 0 ) ? 'success' : 'error';
					}
					$active_tab = 'linking';
					$link_view  = 'suggestions';
				} elseif ( 'link_all_by_sku' === $action ) {
					if ( ! isset( $_POST['morabaa_link_by_sku_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['morabaa_link_by_sku_nonce'] ) ), 'morabaa_link_all_by_sku' ) ) {
						$message   = 'حدث خطأ في التحقق من طلب الربط بالـ SKU/Barcode.';
						$message_t = 'error';
					} else {
						$result = $this->link_all_by_sku( $mappings );
						$linked = isset( $result['linked'] ) ? (int) $result['linked'] : 0;
						$failed = isset( $result['failed'] ) ? (int) $result['failed'] : 0;
						$total  = isset( $result['total'] ) ? (int) $result['total'] : 0;
						$message = sprintf( 'تمت محاولة الربط بالـ SKU/Barcode. نجح %1$d من أصل %2$d، وفشل %3$d.', $linked, $total, $failed );
						$message_t = ( $linked > 0 ) ? 'success' : 'error';
					}
					$active_tab = 'linking';
					$link_view  = 'all';
				}
			}

			$endpoint = get_option( 'morabaa_woo_api_endpoint', $this->get_default_endpoint() );
			$app_id   = get_option( 'morabaa_woo_app_id', '' );
			$mappings = $this->get_mappings();
			$link_suggestions  = array();
			$linked_count      = 0;
			$unlinked_count    = 0;
			$unlinked_items_view = array();
			$import_total_pages = 1;
			$import_from        = 0;
			$import_to          = 0;

			$last_test_status = get_option( 'morabaa_woo_last_test_status', '' );
			$last_test_time   = (int) get_option( 'morabaa_woo_last_test_time', 0 );
			$last_sync_rows   = $this->get_recently_synced_rows( 120 );
			$last_sync_time   = ! empty( $last_sync_rows ) && isset( $last_sync_rows[0]['time'] ) ? (int) $last_sync_rows[0]['time'] : 0;
			$auto_sync_enabled = $this->is_auto_sync_enabled();
			$next_cron_run    = wp_next_scheduled( 'morabaa_woo_sync_cron_event' );
			$last_cron_run    = (int) get_option( 'morabaa_woo_last_cron_run', 0 );
			$last_cron_status = (string) get_option( 'morabaa_woo_last_cron_status', '' );
			$last_cron_details = (string) get_option( 'morabaa_woo_last_cron_details', '' );
			$cron_status_class = 'morabaa-badge-neutral';
			$cron_status_icon  = 'dashicons-clock';
			$cron_status_label = 'لا يوجد سجل بعد';
			if ( 'success' === $last_cron_status ) {
				$cron_status_class = 'morabaa-badge-success';
				$cron_status_icon  = 'dashicons-yes-alt';
				$cron_status_label = 'ناجح';
			} elseif ( 'skipped_locked' === $last_cron_status ) {
				$cron_status_class = 'morabaa-badge-neutral';
				$cron_status_icon  = 'dashicons-controls-pause';
				$cron_status_label = 'تم التخطي';
			} elseif ( 'disabled' === $last_cron_status ) {
				$cron_status_class = 'morabaa-badge-danger';
				$cron_status_icon  = 'dashicons-no-alt';
				$cron_status_label = 'متوقف';
			} elseif ( '' !== $last_cron_status ) {
				$cron_status_class = 'morabaa-badge-danger';
				$cron_status_icon  = 'dashicons-warning';
				$cron_status_label = $last_cron_status;
			}
			$connection_state = $this->get_connection_state();
			$wc_products_rows = array();

			if ( 'dashboard' === $active_tab ) {
				$linked_count   = (int) get_option( 'morabaa_woo_last_linked_count', 0 );
				if ( $linked_count <= 0 ) {
					$linked_count = $this->get_linked_products_count();
				}
				$unlinked_count = (int) get_option( 'morabaa_woo_last_unlinked_count', 0 );
				if ( 'stock' === $dashboard_view ) {
					$wc_products_rows = $this->get_wc_products_stock_rows( -1 );
					foreach ( $wc_products_rows as $i => $row ) {
						$pid = isset( $row['id'] ) ? (int) $row['id'] : 0;
						$flags = $this->get_product_sync_flags( $pid );
						$sync_at = $pid > 0 ? (int) get_post_meta( $pid, '_morabaa_last_sync_at', true ) : 0;
						$wc_products_rows[ $i ]['is_linked'] = ! empty( $flags['is_linked'] );
						$wc_products_rows[ $i ]['is_synced'] = ! empty( $flags['is_synced'] );
						$wc_products_rows[ $i ]['sync_at']   = $sync_at;
					}
				}
			}

			if ( 'import' === $active_tab && 'list' === $import_view ) {
				$pos_items      = $this->fetch_pos_items();
				$unlinked_items = $this->get_unlinked_items_cached( $pos_items, $mappings );
				$unlinked_count = count( $unlinked_items );
				$import_total_pages = max( 1, (int) ceil( $unlinked_count / $import_per_page ) );
				$import_page_num    = min( $import_page_num, $import_total_pages );
				$import_offset      = max( 0, ( $import_page_num - 1 ) * $import_per_page );
				$unlinked_items_view = array_slice( $unlinked_items, $import_offset, $import_per_page, true );
				$import_from         = $unlinked_count > 0 ? ( $import_offset + 1 ) : 0;
				$import_to           = $unlinked_count > 0 ? min( $unlinked_count, $import_offset + count( $unlinked_items_view ) ) : 0;
				update_option( 'morabaa_woo_last_unlinked_count', (int) $unlinked_count );
			}

			if ( 'linking' === $active_tab ) {
				if ( 'none' !== $link_view ) {
					$pos_items            = $this->fetch_pos_items();
					$unlinked_items       = $this->get_unlinked_items_for_import( $pos_items, $mappings, false );
					update_option( 'morabaa_woo_last_unlinked_count', (int) count( $unlinked_items ) );
					$unlinked_wc_products = $this->get_unlinked_wc_products_for_linking( -1 );
					$link_suggestions     = $this->get_name_link_suggestions( $unlinked_items, $unlinked_wc_products, 120 );
				}
			}
			?>
			<div class="wrap morabaa-woo-wrap" dir="rtl">
				<h1 class="morabaa-page-title <?php echo esc_attr( isset( $connection_state['icon_class'] ) ? $connection_state['icon_class'] : '' ); ?>">
					<span class="dashicons dashicons-update"></span>
					<span>مربّع - مزامنة المخزون مع المتجر بواسطه يحيى خالد</span>
					<span class="morabaa-badge <?php echo esc_attr( $connection_state['class'] ); ?>" style="margin-right:8px;">
						<span class="dashicons <?php echo esc_attr( $connection_state['icon'] ); ?>"></span>
						<span><?php echo esc_html( $connection_state['label'] ); ?></span>
					</span>
				</h1>

				<div class="morabaa-tabs nav-tab-wrapper">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=morabaa-woo-sync&tab=settings' ) ); ?>" class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>">
						<span class="dashicons dashicons-admin-generic"></span>
						<span>الإعدادات والأمان</span>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=morabaa-woo-sync&tab=dashboard' ) ); ?>" class="nav-tab <?php echo 'dashboard' === $active_tab ? 'nav-tab-active' : ''; ?>">
						<span class="dashicons dashicons-analytics"></span>
						<span>لوحة التحكم والتقارير</span>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=morabaa-woo-sync&tab=import&import_view=none' ) ); ?>" class="nav-tab <?php echo 'import' === $active_tab ? 'nav-tab-active' : ''; ?>">
						<span class="dashicons dashicons-download"></span>
						<span>استيراد المنتجات</span>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=morabaa-woo-sync&tab=linking' ) ); ?>" class="nav-tab <?php echo 'linking' === $active_tab ? 'nav-tab-active' : ''; ?>">
						<span class="dashicons dashicons-admin-links"></span>
						<span>الربط اليدوي</span>
					</a>
					<div style="clear: both;"></div>
				</div>

				<?php if ( ! empty( $message ) ) : ?>
					<div class="morabaa-notice <?php echo 'error' === $message_t ? 'morabaa-notice-error' : 'morabaa-notice-success'; ?>">
						<span class="dashicons <?php echo 'error' === $message_t ? 'dashicons-dismiss' : 'dashicons-yes-alt'; ?>"></span>
						<span><?php echo esc_html( $message ); ?></span>
					</div>
				<?php endif; ?>

				<?php if ( 'settings' === $active_tab ) : ?>
					<div class="morabaa-card">
						<div class="morabaa-card-header">
							<div>
								<div class="morabaa-card-title">
									<span class="dashicons dashicons-admin-network"></span>
									<span>إعدادات الاتصال والأمان مع مربّع</span>
								</div>
								<p class="morabaa-card-description">
									قم بتكوين بيانات الاتصال بواجهة مربّع (API) وتعيين مفاتيح JSON حتى يتم ربط الحقول بشكل صحيح مع منتجات WooCommerce.
								</p>
							</div>
							<div class="morabaa-badge">
								<span class="dashicons dashicons-lock"></span>
								<span>تشفير سرّ التطبيق داخل قاعدة البيانات</span>
							</div>
						</div>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=morabaa-woo-sync&tab=settings' ) ); ?>">
							<?php wp_nonce_field( 'morabaa_save_settings', 'morabaa_nonce' ); ?>
							<input type="hidden" name="morabaa_action" value="save_settings" />

							<div class="morabaa-form-grid">
								<div class="morabaa-form-group">
									<label>
										<span>رابط واجهة البرمجة (API Endpoint)</span>
									</label>
									<input type="text" name="morabaa_api_endpoint" class="morabaa-input" value="<?php echo esc_attr( $endpoint ); ?>" placeholder="مثال: https://api.mymorabaa.com/v1/items" />
									<small>يمكنك ترك القيمة الافتراضية إذا كنت تستخدم الإعداد القياسي في مربّع.</small>
								</div>

								<div class="morabaa-form-group">
									<label>
										<span>معرّف التطبيق X-App-Id</span>
									</label>
									<input type="text" name="morabaa_app_id" class="morabaa-input" value="<?php echo esc_attr( $app_id ); ?>" placeholder="أدخل معرّف التطبيق من مربّع" />
								</div>

								<div class="morabaa-form-group">
									<label>
										<span>سرّ التطبيق X-App-Secret</span>
									</label>
									<input type="password" name="morabaa_app_secret" class="morabaa-input" value="" placeholder="لن يتم عرض القيمة المخزنة لأسباب أمنية" autocomplete="new-password" />
									<small>يتم تشفير السرّ باستخدام دالة <code>openssl_encrypt</code> و <code>wp_salt()</code> وتخزينه بأمان.</small>
								</div>
							</div>

							<hr style="border:0;border-top:1px solid #e5e7eb;margin:20px 0;">

							<div class="morabaa-card-header" style="margin-bottom: 12px;">
								<div>
									<div class="morabaa-card-title">
										<span class="dashicons dashicons-editor-code"></span>
										<span>تعيين مفاتيح JSON من استجابة مربّع</span>
									</div>
									<p class="morabaa-card-description">
										حدد أسماء المفاتيح كما تظهر في استجابة JSON الخاصة بـ مربّع حتى يمكن قراءة معرّف المنتج، الاسم، السعر، الـSKU، والمخزون بشكل صحيح.
									</p>
								</div>
							</div>

							<div class="morabaa-form-grid">
								<div class="morabaa-form-group">
									<label>
										<span>مفتاح معرّف المنتج في JSON</span>
										<small>مثال: <code>id</code> أو <code>item.id</code></small>
									</label>
									<input type="text" name="morabaa_json_item_id" class="morabaa-input" value="<?php echo esc_attr( $mappings['item_id'] ); ?>" />
								</div>

								<div class="morabaa-form-group">
									<label>
										<span>مفتاح اسم المنتج في JSON</span>
										<small>مثال: <code>name</code></small>
									</label>
									<input type="text" name="morabaa_json_name" class="morabaa-input" value="<?php echo esc_attr( $mappings['name'] ); ?>" />
								</div>

								<div class="morabaa-form-group">
									<label>
										<span>مفتاح SKU في JSON</span>
										<small>مثال: <code>sku</code></small>
									</label>
									<input type="text" name="morabaa_json_sku" class="morabaa-input" value="<?php echo esc_attr( $mappings['sku'] ); ?>" />
								</div>

								<div class="morabaa-form-group">
									<label>
										<span>مفتاح المخزون في JSON</span>
										<small>مثال: <code>stock</code> أو <code>inventory.qty</code></small>
									</label>
									<input type="text" name="morabaa_json_stock" class="morabaa-input" value="<?php echo esc_attr( $mappings['stock'] ); ?>" />
								</div>

								<div class="morabaa-form-group">
									<label>
										<span>مفتاح السعر في JSON</span>
										<small>مثال من التوثيق: <code>sellPrice</code></small>
									</label>
									<input type="text" name="morabaa_json_price" class="morabaa-input" value="<?php echo esc_attr( isset( $mappings['price'] ) ? $mappings['price'] : 'sellPrice' ); ?>" />
								</div>
							</div>

							<p style="margin-top:20px;">
								<button type="submit" class="morabaa-button morabaa-ajax-button">
									<span class="dashicons dashicons-yes"></span>
									<span>حفظ الإعدادات</span>
									<span class="morabaa-spinner"></span>
								</button>
								<span class="morabaa-muted">تأكد من صحة المفاتيح قبل تفعيل المزامنة التلقائية.</span>
							</p>
						</form>

						<hr style="border:0;border-top:1px solid #e5e7eb;margin:20px 0;">

						<div class="morabaa-card-header" style="margin-bottom: 8px;">
							<div>
								<div class="morabaa-card-title">
									<span class="dashicons dashicons-rss"></span>
									<span>اختبار الاتصال مع مربّع</span>
								</div>
								<p class="morabaa-card-description" style="margin-bottom:6px;">
									يقوم هذا الاختبار بإرسال طلب تجريبي إلى واجهة مربّع باستخدام الإعدادات الحالية للتأكد من صحة الاتصال.
								</p>
								<?php if ( $last_test_time ) : ?>
									<p class="morabaa-muted">
										<?php
										$label = ( 'success' === $last_test_status ) ? 'ناجح' : ( ( 'error' === $last_test_status ) ? 'فاشل' : 'غير معروف' );
										echo esc_html(
											sprintf(
												'آخر اختبار اتصال: %1$s في %2$s',
												$label,
												wp_date( 'Y-m-d H:i', $last_test_time )
											)
										);
										?>
									</p>
								<?php else : ?>
									<p class="morabaa-muted">
										لم يتم إجراء أي اختبار اتصال بعد. قم بالنقر على زر "اختبار الاتصال الآن" للتأكد من صحة الإعدادات.
									</p>
								<?php endif; ?>
							</div>
						</div>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=morabaa-woo-sync&tab=settings' ) ); ?>" style="margin-top:4px;">
							<?php wp_nonce_field( 'morabaa_test_connection', 'morabaa_test_connection_nonce' ); ?>
							<input type="hidden" name="morabaa_action" value="test_connection" />
							<button type="submit" class="morabaa-button morabaa-button-secondary morabaa-ajax-button">
								<span class="dashicons dashicons-rss"></span>
								<span>اختبار الاتصال الآن</span>
								<span class="morabaa-spinner"></span>
							</button>
						</form>
					</div>
				<?php elseif ( 'dashboard' === $active_tab ) : ?>
					<div class="morabaa-card">
						<div class="morabaa-card-header">
							<div>
								<div class="morabaa-card-title">
									<span class="dashicons dashicons-analytics"></span>
									<span>لوحة التحكم والتقارير</span>
								</div>
								<p class="morabaa-card-description">
									ملخص سريع لحالة الربط بين منتجات WooCommerce ومنتجات مربّع، مع إمكانية تنفيذ مزامنة فورية للاسم والسعر والمخزون.
								</p>
							</div>
							<div class="morabaa-badge">
								<span class="dashicons dashicons-clock"></span>
								<span><?php echo esc_html( $auto_sync_enabled ? 'المزامنة التلقائية مفعلة (كل 20 دقيقة)' : 'المزامنة التلقائية متوقفة' ); ?></span>
							</div>
						</div>

						<div class="morabaa-stats-grid">
							<div class="morabaa-stat-card">
								<div>
									<div class="morabaa-stat-label">حالة الاتصال الحالية</div>
									<div class="morabaa-stat-value" style="font-size:18px;"><?php echo esc_html( $connection_state['label'] ); ?></div>
								</div>
								<div class="morabaa-stat-icon" style="background: linear-gradient(135deg,#16a34a,#059669);">
									<span class="dashicons <?php echo esc_attr( $connection_state['icon'] ); ?>"></span>
								</div>
							</div>

							<div class="morabaa-stat-card">
								<div>
									<div class="morabaa-stat-label">المنتجات المرتبطة (تتم مزامنتها بنجاح)</div>
									<div class="morabaa-stat-value"><?php echo esc_html( number_format_i18n( $linked_count ) ); ?></div>
								</div>
								<div class="morabaa-stat-icon">
									<span class="dashicons dashicons-products"></span>
								</div>
							</div>

							<div class="morabaa-stat-card">
								<div>
									<div class="morabaa-stat-label">المنتجات غير المرتبطة (موجودة في مربّع فقط)</div>
									<div class="morabaa-stat-value"><?php echo esc_html( number_format_i18n( $unlinked_count ) ); ?></div>
								</div>
								<div class="morabaa-stat-icon" style="background: linear-gradient(135deg,#f97316,#ea580c);">
									<span class="dashicons dashicons-warning"></span>
								</div>
							</div>
						</div>

						<div class="morabaa-table-wrapper" style="margin-top:14px;">
							<table class="morabaa-table">
								<thead>
									<tr>
										<th>موعد المزامنة القادمة</th>
										<th>آخر مزامنة كانت في</th>
										<th>حالة المزامنة التلقائية</th>
										<th>نتيجة آخر مزامنه </th>
									</tr>
								</thead>
								<tbody>
									<tr>
										<td>
											<?php if ( $next_cron_run ) : ?>
												<div class="morabaa-cron-next" data-next-cron-ts="<?php echo esc_attr( (int) $next_cron_run ); ?>">
													<div class="morabaa-cron-datetime"><?php echo esc_html( wp_date( 'Y-m-d H:i:s', (int) $next_cron_run ) ); ?></div>
													<div class="morabaa-cron-countdown">
														<span class="dashicons dashicons-clock"></span>
														<span class="js-morabaa-cron-remaining">--:--:--</span>
													</div>
												</div>
											<?php else : ?>
												<span class="morabaa-badge morabaa-badge-danger">
													<span class="dashicons dashicons-warning"></span>
													<span>غير مجدول</span>
												</span>
											<?php endif; ?>
										</td>
										<td>
											<?php if ( $last_cron_run ) : ?>
												<div class="morabaa-cron-datetime"><?php echo esc_html( wp_date( 'Y-m-d H:i:s', (int) $last_cron_run ) ); ?></div>
											<?php else : ?>
												<span class="morabaa-muted">لم ينفذ بعد</span>
											<?php endif; ?>
										</td>
										<td>
											<span class="morabaa-badge <?php echo esc_attr( $auto_sync_enabled ? 'morabaa-badge-success' : 'morabaa-badge-danger' ); ?>">
												<span class="dashicons <?php echo esc_attr( $auto_sync_enabled ? 'dashicons-yes-alt' : 'dashicons-no-alt' ); ?>"></span>
												<span><?php echo esc_html( $auto_sync_enabled ? 'مفعل' : 'متوقف' ); ?></span>
											</span>
										</td>
										<td>
											<div class="morabaa-cron-result">
												<span class="morabaa-badge <?php echo esc_attr( $cron_status_class ); ?>">
													<span class="dashicons <?php echo esc_attr( $cron_status_icon ); ?>"></span>
													<span><?php echo esc_html( $cron_status_label ); ?></span>
												</span>
												<div class="morabaa-muted" style="margin-top:4px;"><?php echo esc_html( '' !== $last_cron_details ? $last_cron_details : 'لا يوجد سجل بعد' ); ?></div>
											</div>
										</td>
									</tr>
								</tbody>
							</table>
						</div>

						<hr style="border:0;border-top:1px solid #e5e7eb;margin:20px 0;">

						<div class="morabaa-flex-between">
							<div>
								<div class="morabaa-card-title" style="margin-bottom:4px;">
									<span class="dashicons dashicons-update-alt"></span>
									<span>مزامنة يدوية فورية للمنتجات</span>
								</div>
								<p class="morabaa-card-description" style="margin-bottom:0;">
									تقوم المزامنة اليدوية بجلب بيانات المنتجات من مربّع وتحديث الاسم والسعر وكميات المخزون وحالة التوفر في المنتجات المرتبطة فقط.
								</p>
								<p class="morabaa-muted" style="margin-top:4px;">
									لا يتم إنشاء أو حذف أي منتجات تلقائياً. يتم فقط تحديث الاسم والسعر والمخزون للمنتجات المطابقة (حسب معرّف مربّع، ثم SKU/Barcode).
								</p>
							</div>
							<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=morabaa-woo-sync&tab=dashboard' ) ); ?>">
									<?php wp_nonce_field( 'morabaa_link_all_by_sku', 'morabaa_link_by_sku_nonce' ); ?>
									<input type="hidden" name="morabaa_action" value="link_all_by_sku" />
									<button type="submit" class="morabaa-button morabaa-button-secondary morabaa-ajax-button">
										<span class="dashicons dashicons-admin-links"></span>
										<span>ربط الكل حسب SKU/Barcode</span>
										<span class="morabaa-spinner"></span>
									</button>
								</form>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=morabaa-woo-sync&tab=dashboard' ) ); ?>">
									<?php wp_nonce_field( 'morabaa_refresh_items_cache', 'morabaa_refresh_nonce' ); ?>
									<input type="hidden" name="morabaa_action" value="refresh_items_cache" />
									<button type="submit" class="morabaa-button morabaa-ajax-button">
										<span class="dashicons dashicons-image-rotate"></span>
										<span>إعادة تحديث البيانات</span>
										<span class="morabaa-spinner"></span>
									</button>
								</form>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=morabaa-woo-sync&tab=dashboard' ) ); ?>">
									<?php wp_nonce_field( 'morabaa_toggle_auto_sync', 'morabaa_toggle_sync_nonce' ); ?>
									<input type="hidden" name="morabaa_action" value="toggle_auto_sync" />
									<input type="hidden" name="morabaa_auto_sync_target" value="<?php echo esc_attr( $auto_sync_enabled ? 'off' : 'on' ); ?>" />
									<button type="submit" class="morabaa-button morabaa-button-secondary morabaa-ajax-button">
										<span class="dashicons <?php echo esc_attr( $auto_sync_enabled ? 'dashicons-controls-pause' : 'dashicons-controls-play' ); ?>"></span>
										<span><?php echo esc_html( $auto_sync_enabled ? 'إيقاف المزامنة التلقائية' : 'تشغيل المزامنة التلقائية' ); ?></span>
										<span class="morabaa-spinner"></span>
									</button>
								</form>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=morabaa-woo-sync&tab=dashboard' ) ); ?>">
									<?php wp_nonce_field( 'morabaa_pull_status', 'morabaa_pull_status_nonce' ); ?>
									<input type="hidden" name="morabaa_action" value="pull_status" />
									<button type="submit" class="morabaa-button morabaa-ajax-button">
										<span class="dashicons dashicons-visibility"></span>
										<span>سحب الحالة الآن (بدون مزامنة)</span>
										<span class="morabaa-spinner"></span>
									</button>
								</form>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=morabaa-woo-sync&tab=dashboard' ) ); ?>" class="morabaa-manual-sync-form">
									<?php wp_nonce_field( 'morabaa_manual_sync', 'morabaa_manual_sync_nonce' ); ?>
									<input type="hidden" name="morabaa_action" value="manual_sync" />
									<button type="submit" class="morabaa-button morabaa-button-secondary morabaa-ajax-button" data-loading-label="جاري تنفيذ المزامنة اليدوية على دفعات">
										<span class="dashicons dashicons-update"></span>
										<span>مزامنة يدوية الآن</span>
										<span class="morabaa-spinner"></span>
									</button>
								</form>
							</div>
						</div>

						<hr style="border:0;border-top:1px solid #e5e7eb;margin:20px 0;">

						<div class="morabaa-card-title" style="margin-bottom:8px;">
							<span class="dashicons dashicons-list-view"></span>
							<span>كل منتجات النظام مع السعر والمخزون</span>
						</div>
						<p class="morabaa-card-description" style="margin-bottom:8px;">
							لتسريع فتح الداشبورد، جدول جميع المنتجات لا يتم تحميله إلا عند الطلب.
						</p>
						<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px;">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=morabaa-woo-sync&tab=dashboard&dashboard_view=stock' ) ); ?>" class="morabaa-button morabaa-button-secondary">
								<span class="dashicons dashicons-visibility"></span>
								<span>عرض كل المنتجات مع المخزون</span>
							</a>
							<?php if ( 'stock' === $dashboard_view ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=morabaa-woo-sync&tab=dashboard&dashboard_view=none' ) ); ?>" class="morabaa-button">
									<span class="dashicons dashicons-hidden"></span>
									<span>إخفاء الجدول الثقيل</span>
								</a>
							<?php endif; ?>
						</div>

						<?php if ( 'stock' === $dashboard_view ) : ?>
							<input type="text" class="morabaa-input morabaa-live-search" data-target-table="closest" placeholder="بحث مباشر في المنتجات..." style="max-width:320px;margin-bottom:8px;" />
							<div class="morabaa-table-wrapper">
								<table class="morabaa-table js-load-more-table" data-initial-rows="40" data-step-rows="40">
									<thead>
										<tr>
											<th style="width:80px;">#</th>
											<th>اسم المنتج</th>
											<th>SKU / Barcode</th>
											<th>حالة الربط</th>
											<th>حالة المزامنة</th>
											<th>السعر</th>
											<th>المخزون</th>
											<th>حالة التوفر</th>
											<th>معرّف مربّع</th>
										</tr>
									</thead>
									<tbody>
									<?php if ( empty( $wc_products_rows ) ) : ?>
										<tr>
											<td colspan="9" class="morabaa-table-empty">لا توجد منتجات حالياً في WooCommerce.</td>
										</tr>
									<?php else : ?>
										<?php foreach ( $wc_products_rows as $row ) : ?>
											<tr>
												<td><?php echo esc_html( (string) $row['id'] ); ?></td>
												<td><?php echo esc_html( $row['name'] ); ?></td>
												<td><?php echo esc_html( '' !== $row['sku'] ? $row['sku'] : '—' ); ?></td>
												<td>
													<?php if ( ! empty( $row['is_linked'] ) ) : ?>
														<span class="morabaa-status-chip is-ok"><span class="dashicons dashicons-yes-alt"></span><span>مربوط</span></span>
													<?php else : ?>
														<span class="morabaa-status-chip is-bad"><span class="dashicons dashicons-dismiss"></span><span>غير مربوط</span></span>
													<?php endif; ?>
												</td>
												<td>
													<?php if ( ! empty( $row['is_synced'] ) ) : ?>
														<span class="morabaa-status-chip is-ok"><span class="dashicons dashicons-yes-alt"></span><span>تمت المزامنة</span></span>
													<?php else : ?>
														<span class="morabaa-status-chip is-bad"><span class="dashicons dashicons-dismiss"></span><span>غير مزامن</span></span>
													<?php endif; ?>
												</td>
												<td><?php echo esc_html( isset( $row['price'] ) && '' !== (string) $row['price'] ? (string) $row['price'] : '—' ); ?></td>
												<td><?php echo esc_html( (string) $row['stock'] ); ?></td>
												<td><?php echo esc_html( 'instock' === $row['status'] ? 'متوفر' : 'غير متوفر' ); ?></td>
												<td><?php echo esc_html( '' !== $row['morabaa_id'] ? $row['morabaa_id'] : 'غير مرتبط' ); ?></td>
											</tr>
										<?php endforeach; ?>
									<?php endif; ?>
									</tbody>
								</table>
							</div>
						<?php else : ?>
							<div class="morabaa-notice morabaa-notice-success" style="margin-top:10px;">
								<span class="dashicons dashicons-info-outline"></span>
								<span>الجدول مخفي حالياً لتحسين السرعة. اضغط "عرض كل المنتجات مع المخزون" عند الحاجة.</span>
							</div>
						<?php endif; ?>

						<?php if ( ! empty( $last_sync_rows ) ) : ?>
						<hr style="border:0;border-top:1px solid #e5e7eb;margin:20px 0;">
						<div class="morabaa-flex-between" style="margin-bottom:8px;">
							<div class="morabaa-card-title">
								<span class="dashicons dashicons-yes-alt"></span>
								<span>المنتجات التي تمت مزامنتها</span>
							</div>
							<button type="button" class="morabaa-button morabaa-button-secondary" data-toggle-target="#morabaa-last-sync-box" style="padding:7px 16px;">
								<span class="dashicons dashicons-visibility"></span>
								<span>عرض/إخفاء</span>
							</button>
						</div>
						<p class="morabaa-muted" style="margin-bottom:8px;">
							<?php echo esc_html( $last_sync_time ? 'آخر مزامنة: ' . wp_date( 'Y-m-d H:i', $last_sync_time ) : 'لا يوجد وقت مزامنة محفوظ.' ); ?>
						</p>
						<div id="morabaa-last-sync-box" style="display:none;">
							<div class="morabaa-table-wrapper">
								<table class="morabaa-table js-load-more-table" data-initial-rows="30" data-step-rows="30">
									<thead>
										<tr>
											<th># المنتج</th>
											<th>اسم المنتج</th>
											<th>Morabaa Item ID</th>
											<th>SKU / Barcode</th>
											<th>السعر بعد المزامنة</th>
											<th>المخزون بعد المزامنة</th>
										</tr>
									</thead>
									<tbody>
									<?php foreach ( $last_sync_rows as $sync_row ) : ?>
										<tr>
											<td><?php echo esc_html( isset( $sync_row['product_id'] ) ? (string) $sync_row['product_id'] : '—' ); ?></td>
											<td><?php echo esc_html( isset( $sync_row['name'] ) ? (string) $sync_row['name'] : '—' ); ?></td>
											<td><?php echo esc_html( isset( $sync_row['morabaa_id'] ) ? (string) $sync_row['morabaa_id'] : '—' ); ?></td>
											<td><?php echo esc_html( isset( $sync_row['sku'] ) && '' !== (string) $sync_row['sku'] ? (string) $sync_row['sku'] : '—' ); ?></td>
											<td><?php echo esc_html( isset( $sync_row['price'] ) && '' !== (string) $sync_row['price'] ? (string) $sync_row['price'] : '—' ); ?></td>
											<td><?php echo esc_html( isset( $sync_row['stock'] ) ? (string) $sync_row['stock'] : '0' ); ?></td>
										</tr>
									<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						</div>
						<?php endif; ?>
					</div>
				<?php elseif ( 'import' === $active_tab ) : ?>
					<div class="morabaa-card">
						<div class="morabaa-card-header">
							<div>
								<div class="morabaa-card-title">
									<span class="dashicons dashicons-download"></span>
									<span>استيراد المنتجات من مربّع إلى WooCommerce</span>
								</div>
								<p class="morabaa-card-description">
									يعرض هذا الجدول المنتجات الموجودة في مربّع وغير الموجودة في WooCommerce. يمكنك اختيار المنتجات المراد استيرادها كمنتجات بسيطة جديدة.
								</p>
							</div>
							<div class="morabaa-badge morabaa-badge-danger">
								<span class="dashicons dashicons-info"></span>
								<span>لن يتم استيراد المنتجات الموجودة مسبقاً أو ذات بيانات غير مكتملة.</span>
							</div>
						</div>

						<div class="morabaa-flex-between" style="margin-bottom:10px;">
							<div class="morabaa-muted">رتبنا صفحة الاستيراد لتكون أسرع: تحميل على دفعات + كاش + أدوات فحص. إذا كان عندك منتج  عدد كبير فالتأخير الأولي طبيعي، لكن بعده يصير أسرع.</div>
							<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=morabaa-woo-sync&tab=import&import_view=list' ) ); ?>" class="morabaa-button morabaa-button-secondary">
									<span class="dashicons dashicons-visibility"></span>
									<span>تحميل المنتجات على دفعات</span>
								</a>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=morabaa-woo-sync&tab=import&import_view=list' ) ); ?>" style="display:inline-block;" class="morabaa-barcode-diagnose-form">
									<?php wp_nonce_field( 'morabaa_barcode_diagnose', 'morabaa_barcode_diag_nonce' ); ?>
									<input type="hidden" name="morabaa_action" value="barcode_diagnose" />
									<button type="submit" class="morabaa-button morabaa-ajax-button morabaa-barcode-diagnose-btn">
										<span class="dashicons dashicons-search"></span>
										<span>فحص الباركود من السيرفر</span>
										<span class="morabaa-spinner"></span>
									</button>
								</form>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=morabaa-woo-sync&tab=import&import_view=none' ) ); ?>" style="display:inline-block;">
									<?php wp_nonce_field( 'morabaa_reset_query_data', 'morabaa_reset_query_nonce' ); ?>
									<input type="hidden" name="morabaa_action" value="reset_query_data" />
									<button type="submit" class="morabaa-button morabaa-button-secondary morabaa-ajax-button">
										<span class="dashicons dashicons-trash"></span>
										<span>حذف بيانات الاستعلام</span>
										<span class="morabaa-spinner"></span>
									</button>
								</form>
							</div>
						</div>

						<div id="morabaa-barcode-diagnose-result" class="morabaa-notice" style="display:none;margin-bottom:10px;"></div>



						<?php if ( 'none' === $import_view ) : ?>
							<div class="morabaa-notice morabaa-notice-success" style="margin-bottom:10px;">
								<span class="dashicons dashicons-info-outline"></span>
								<span>لتحسين السرعة، يتم تحميل قائمة المنتجات عند الطلب فقط.</span>
							</div>
						<?php else : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=morabaa-woo-sync&tab=import&import_view=list' ) ); ?>" style="margin-bottom:10px;">
							<?php wp_nonce_field( 'morabaa_refresh_items_cache', 'morabaa_refresh_nonce' ); ?>
							<input type="hidden" name="morabaa_action" value="refresh_items_cache" />
							<button type="submit" class="morabaa-button morabaa-ajax-button">
								<span class="dashicons dashicons-image-rotate"></span>
								<span>إعادة تحديث بيانات مربّع</span>
								<span class="morabaa-spinner"></span>
							</button>
						</form>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=morabaa-woo-sync&tab=import&import_view=list' ) ); ?>">
							<?php wp_nonce_field( 'morabaa_import_items', 'morabaa_import_nonce' ); ?>
							<input type="hidden" name="morabaa_action" value="import_items" />

							<div class="morabaa-flex-between" style="margin-bottom:10px;">
								<div class="morabaa-muted">
									يعتمد الكشف عن المنتجات غير المرتبطة على مطابقة معرّف مربّع، ثم SKU/Barcode.
									<?php if ( $unlinked_count > $import_per_page ) : ?>
										<br><strong>للسرعة:</strong> يتم عرض <?php echo esc_html( (string) $import_per_page ); ?> منتج بكل صفحة. المعروض الآن <?php echo esc_html( (string) $import_from ); ?> - <?php echo esc_html( (string) $import_to ); ?> من أصل <?php echo esc_html( (string) $unlinked_count ); ?>.
									<?php endif; ?>
								</div>
								<div>
									<label style="display:inline-flex;align-items:center;gap:6px;font-size:12px;color:#0f172a;">
										<input type="checkbox" id="morabaa-select-all" class="morabaa-checkbox" />
										<span>تحديد الكل</span>
									</label>
								</div>
							</div>
							<input type="text" id="morabaa-import-search" class="morabaa-input morabaa-live-search" data-target-table="closest" data-remote-search="import" placeholder="ابحث في كل المنتجات غير المرتبطة بالاسم أو SKU/Barcode..." style="max-width:420px;margin-bottom:8px;" />
							<div id="morabaa-import-search-status" class="morabaa-search-status morabaa-muted">اكتب للبحث في كل المنتجات غير المرتبطة بدون الحاجة للتنقل بين الصفحات.</div>

							<?php if ( ! empty( $unlinked_items_view ) ) : ?>
								<p style="margin-top:0;margin-bottom:12px;">
									<button type="submit" class="morabaa-button morabaa-ajax-button">
										<span class="dashicons dashicons-download"></span>
										<span>استيراد المنتجات المحددة</span>
										<span class="morabaa-spinner"></span>
									</button>
									<span class="morabaa-muted">سيتم إنشاء منتجات بسيطة جديدة وربطها تلقائياً بمعرّف مربّع.</span>
								</p>
							<?php endif; ?>

							<div class="morabaa-table-wrapper">
								<table id="morabaa-import-table" class="morabaa-table">
									<thead>
										<tr>
											<th style="width:40px;">
												<input type="checkbox" id="morabaa-select-all-header" class="morabaa-checkbox" />
											</th>
											<th>معرّف المنتج في مربّع</th>
											<th>اسم المنتج</th>
											<th>SKU / Barcode</th>
											<th>السعر</th>
											<th>المخزون</th>
										</tr>
									</thead>
									<tbody>
									<?php if ( empty( $unlinked_items_view ) ) : ?>
										<tr>
											<td colspan="6" class="morabaa-table-empty">
												لا توجد حالياً منتجات غير مرتبطة في مربّع. جميع المنتجات متطابقة أو تم استيرادها.
											</td>
										</tr>
									<?php else : ?>
										<?php foreach ( $unlinked_items_view as $uid => $row ) : ?>
											<tr>
												<td>
													<input type="checkbox" class="morabaa-checkbox morabaa-item-checkbox" name="morabaa_import_items[]" value="<?php echo esc_attr( $row['item_id'] ? $row['item_id'] : $uid ); ?>" />
												</td>
												<td><?php echo esc_html( $row['item_id'] ? $row['item_id'] : '—' ); ?></td>
												<td><?php echo esc_html( $row['name'] ? $row['name'] : '—' ); ?></td>
												<td><?php echo esc_html( $row['sku'] ? $row['sku'] : '—' ); ?></td>
												<td><?php echo esc_html( isset( $row['price'] ) && '' !== (string) $row['price'] ? (string) $row['price'] : '—' ); ?></td>
												<td><?php echo esc_html( $row['stock'] ); ?></td>
											</tr>
										<?php endforeach; ?>
									<?php endif; ?>
									</tbody>
								</table>
							</div>

							<div class="morabaa-flex-between" style="margin-top:10px;">
								<div class="morabaa-muted">
									<?php echo esc_html( sprintf( 'عدد المنتجات غير المرتبطة: %d', (int) $unlinked_count ) ); ?>
								</div>
								<?php if ( $import_total_pages > 1 ) : ?>
									<div class="morabaa-pagination">
										<?php if ( $import_page_num > 1 ) : ?>
											<a class="morabaa-button morabaa-button-secondary" href="<?php echo esc_url( add_query_arg( 'import_page_num', $import_page_num - 1, admin_url( 'admin.php?page=morabaa-woo-sync&tab=import&import_view=list' ) ) ); ?>">
												<span class="dashicons dashicons-arrow-right-alt2"></span>
												<span>الدفعة السابقة</span>
											</a>
										<?php endif; ?>
										<span class="morabaa-pagination-label"><?php echo esc_html( sprintf( 'الصفحة %1$d من %2$d', (int) $import_page_num, (int) $import_total_pages ) ); ?></span>
										<?php if ( $import_page_num < $import_total_pages ) : ?>
											<a class="morabaa-button" href="<?php echo esc_url( add_query_arg( 'import_page_num', $import_page_num + 1, admin_url( 'admin.php?page=morabaa-woo-sync&tab=import&import_view=list' ) ) ); ?>">
												<span>الدفعة التالية</span>
												<span class="dashicons dashicons-arrow-left-alt2"></span>
											</a>
										<?php endif; ?>
									</div>
								<?php endif; ?>
							</div>

							<p class="morabaa-muted" style="margin-top:10px;">بدلاً من تحميل كل النتائج مرة وحدة، صرنا نعرضها على دفعات لتقليل وقت الانتظار وتحسين السرعة مع الأعداد الكبيرة.</p>
						</form>
						<?php endif; ?>
					</div>
				<?php elseif ( 'linking' === $active_tab ) : ?>
					<div class="morabaa-card">
						<div class="morabaa-card-header">
							<div>
								<div class="morabaa-card-title">
									<span class="dashicons dashicons-admin-links"></span>
									<span>الربط اليدوي بين مربّع و WooCommerce</span>
								</div>
								<p class="morabaa-card-description">
									قسمان منفصلان لعرض منتجات مربّع ومنتجات WooCommerce غير المرتبطة، مع اقتراحات تشابه أسماء وزر ربط مباشر.
								</p>
							</div>
							<div class="morabaa-badge morabaa-badge-neutral">
								<span class="dashicons dashicons-shield"></span>
								<span>الربط يتم بمعرّف مربّع أو SKU/Barcode</span>
							</div>
						</div>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=morabaa-woo-sync&tab=linking&link_view=' . $link_view ) ); ?>" style="margin-bottom:10px;">
							<?php wp_nonce_field( 'morabaa_refresh_items_cache', 'morabaa_refresh_nonce' ); ?>
							<input type="hidden" name="morabaa_action" value="refresh_items_cache" />
							<button type="submit" class="morabaa-button morabaa-ajax-button">
								<span class="dashicons dashicons-image-rotate"></span>
								<span>إعادة تحديث بيانات مربّع</span>
								<span class="morabaa-spinner"></span>
							</button>
						</form>

						<div class="morabaa-flex-between" style="margin-bottom:12px;">
							<div class="morabaa-muted">اختَر القسم المطلوب عبر الأزرار التالية.</div>
							<div class="morabaa-link-buttons" style="display:flex;gap:8px;flex-wrap:wrap;">
								<a class="morabaa-button <?php echo ( 'all' === $link_view ) ? 'morabaa-button-secondary' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=morabaa-woo-sync&tab=linking&link_view=all' ) ); ?>">الكل</a>
								<a class="morabaa-button <?php echo ( 'suggestions' === $link_view ) ? 'morabaa-button-secondary' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=morabaa-woo-sync&tab=linking&link_view=suggestions' ) ); ?>">اقتراحات تشابه الأسماء مع زر الربط</a>
								<a class="morabaa-button <?php echo ( 'wc' === $link_view ) ? 'morabaa-button-secondary' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=morabaa-woo-sync&tab=linking&link_view=wc' ) ); ?>">منتجات WooCommerce غير المرتبطة</a>
								<a class="morabaa-button <?php echo ( 'morabaa' === $link_view ) ? 'morabaa-button-secondary' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=morabaa-woo-sync&tab=linking&link_view=morabaa' ) ); ?>">منتجات مربّع غير المرتبطة</a>
							</div>
						</div>

						<?php if ( 'none' === $link_view ) : ?>
							<div class="morabaa-notice morabaa-notice-success">
								<span class="dashicons dashicons-info-outline"></span>
								<span>اختر من الأزرار أعلاه القسم الذي تريد عرضه. لن يتم تحميل أي قائمة قبل الاختيار لتسريع الصفحة.</span>
							</div>
						<?php endif; ?>

						<?php if ( in_array( $link_view, array( 'all', 'morabaa', 'wc' ), true ) ) : ?>
						<div class="morabaa-link-layout">
							<?php if ( in_array( $link_view, array( 'all', 'morabaa' ), true ) ) : ?>
							<div class="morabaa-link-column">
								<div class="morabaa-card-title" style="margin-bottom:8px;">
									<span class="dashicons dashicons-store"></span>
									<span>منتجات مربّع غير المرتبطة</span>
								</div>
								<div class="morabaa-table-wrapper morabaa-table-compact">
									<table class="morabaa-table js-load-more-table" data-initial-rows="35" data-step-rows="35">
										<thead>
											<tr>
												<th>معرّف مربّع</th>
												<th>الاسم</th>
												<th>SKU / Barcode</th>
											</tr>
										</thead>
										<tbody>
										<?php if ( empty( $unlinked_items ) ) : ?>
											<tr><td colspan="3" class="morabaa-table-empty">لا توجد منتجات مربّع غير مرتبطة.</td></tr>
										<?php else : ?>
											<?php foreach ( array_values( $unlinked_items ) as $row ) : ?>
												<tr>
													<td><?php echo esc_html( ! empty( $row['item_id'] ) ? $row['item_id'] : '—' ); ?></td>
													<td><?php echo esc_html( ! empty( $row['name'] ) ? $row['name'] : '—' ); ?></td>
													<td><?php echo esc_html( ! empty( $row['sku'] ) ? $row['sku'] : '—' ); ?></td>
												</tr>
											<?php endforeach; ?>
										<?php endif; ?>
										</tbody>
									</table>
								</div>
							</div>
							<?php endif; ?>

							<?php if ( 'all' === $link_view ) : ?>
							<div class="morabaa-link-divider" aria-hidden="true"></div>
							<?php endif; ?>

							<?php if ( in_array( $link_view, array( 'all', 'wc' ), true ) ) : ?>
							<div class="morabaa-link-column">
								<div class="morabaa-card-title" style="margin-bottom:8px;">
									<span class="dashicons dashicons-products"></span>
									<span>منتجات WooCommerce غير المرتبطة</span>
								</div>
								<div class="morabaa-table-wrapper morabaa-table-compact">
									<table class="morabaa-table js-load-more-table" data-initial-rows="35" data-step-rows="35">
										<thead>
											<tr>
												<th>#</th>
												<th>الاسم</th>
												<th>SKU / Barcode</th>
												<th>حالة الربط</th>
												<th>حالة المزامنة</th>
											</tr>
										</thead>
										<tbody>
										<?php if ( empty( $unlinked_wc_products ) ) : ?>
											<tr><td colspan="5" class="morabaa-table-empty">لا توجد منتجات WooCommerce غير مرتبطة.</td></tr>
										<?php else : ?>
											<?php foreach ( $unlinked_wc_products as $row ) : ?>
												<?php $flags = $this->get_product_sync_flags( isset( $row['id'] ) ? (int) $row['id'] : 0 ); ?>
												<tr>
													<td><?php echo esc_html( (string) $row['id'] ); ?></td>
													<td><?php echo esc_html( ! empty( $row['name'] ) ? $row['name'] : '—' ); ?></td>
													<td><?php echo esc_html( ! empty( $row['sku'] ) ? $row['sku'] : '—' ); ?></td>
													<td>
														<?php if ( ! empty( $flags['is_linked'] ) ) : ?>
															<span class="morabaa-status-chip is-ok"><span class="dashicons dashicons-yes-alt"></span><span>مربوط</span></span>
														<?php else : ?>
															<span class="morabaa-status-chip is-bad"><span class="dashicons dashicons-dismiss"></span><span>غير مربوط</span></span>
														<?php endif; ?>
													</td>
													<td>
														<?php if ( ! empty( $flags['is_synced'] ) ) : ?>
															<span class="morabaa-status-chip is-ok"><span class="dashicons dashicons-yes-alt"></span><span>تمت المزامنة</span></span>
														<?php else : ?>
															<span class="morabaa-status-chip is-bad"><span class="dashicons dashicons-dismiss"></span><span>غير مزامن</span></span>
														<?php endif; ?>
													</td>
												</tr>
											<?php endforeach; ?>
										<?php endif; ?>
										</tbody>
									</table>
								</div>
							</div>
							<?php endif; ?>
						</div>
						<?php endif; ?>

						<?php if ( in_array( $link_view, array( 'all', 'suggestions' ), true ) ) : ?>
						<hr class="morabaa-divider-line" />

						<div class="morabaa-card-title" style="margin-bottom:8px;">
							<span class="dashicons dashicons-search"></span>
							<span>اقتراحات تشابه الأسماء مع زر الربط</span>
						</div>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=morabaa-woo-sync&tab=linking&link_view=suggestions' ) ); ?>" style="margin-bottom:10px;">
							<?php wp_nonce_field( 'morabaa_link_all_exact', 'morabaa_link_all_nonce' ); ?>
							<input type="hidden" name="morabaa_action" value="link_all_exact" />
							<button type="submit" class="morabaa-button morabaa-button-secondary morabaa-ajax-button">
								<span class="dashicons dashicons-admin-links"></span>
								<span>ربط الكل (مطابقة 100%)</span>
								<span class="morabaa-spinner"></span>
							</button>
						</form>
						<input type="text" class="morabaa-input morabaa-live-search" data-target-table="closest" placeholder="بحث مباشر في اقتراحات الربط..." style="max-width:320px;margin-bottom:8px;" />
						<p class="morabaa-muted" style="margin-bottom:10px;">
							راجع التشابه ثم اضغط "ربط" للمنتج الصحيح. الربط الفعلي يحفظ فقط معرّف مربّع داخل منتج WooCommerce المختار.
						</p>

						<div class="morabaa-table-wrapper">
							<table class="morabaa-table js-load-more-table" data-initial-rows="30" data-step-rows="30">
								<thead>
									<tr>
										<th>منتج مربّع</th>
										<th>منتج WooCommerce المقترح</th>
										<th>حالة المنتج في المتجر</th>
										<th>نسبة التشابه</th>
										<th style="width:140px;">إجراء</th>
									</tr>
								</thead>
								<tbody>
								<?php if ( empty( $link_suggestions ) ) : ?>
									<tr>
										<td colspan="5" class="morabaa-table-empty">لا توجد اقتراحات تشابه كافية حالياً. يمكنك الاستيراد أو تعديل أسماء المنتجات لتحسين الربط.</td>
									</tr>
								<?php else : ?>
									<?php foreach ( $link_suggestions as $s ) : ?>
										<?php $flags = $this->get_product_sync_flags( isset( $s['wc_id'] ) ? (int) $s['wc_id'] : 0 ); ?>
										<tr>
											<td>
												<div><strong><?php echo esc_html( $s['item_name'] ); ?></strong></div>
												<div class="morabaa-muted"><?php echo esc_html( $s['item_id'] ); ?><?php echo esc_html( '' !== $s['item_sku'] ? ' | SKU: ' . $s['item_sku'] : '' ); ?></div>
											</td>
											<td>
												<div><strong><?php echo esc_html( $s['wc_name'] ); ?></strong></div>
												<div class="morabaa-muted">#<?php echo esc_html( (string) $s['wc_id'] ); ?><?php echo esc_html( '' !== $s['wc_sku'] ? ' | SKU: ' . $s['wc_sku'] : '' ); ?></div>
											</td>
											<td>
												<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
													<?php if ( ! empty( $flags['is_linked'] ) ) : ?>
														<span class="morabaa-status-chip is-ok"><span class="dashicons dashicons-yes-alt"></span><span>مربوط</span></span>
													<?php else : ?>
														<span class="morabaa-status-chip is-bad"><span class="dashicons dashicons-dismiss"></span><span>غير مربوط</span></span>
													<?php endif; ?>
													<?php if ( ! empty( $flags['is_synced'] ) ) : ?>
														<span class="morabaa-status-chip is-ok"><span class="dashicons dashicons-yes-alt"></span><span>تمت المزامنة</span></span>
													<?php else : ?>
														<span class="morabaa-status-chip is-bad"><span class="dashicons dashicons-dismiss"></span><span>غير مزامن</span></span>
													<?php endif; ?>
												</div>
											</td>
											<td>
												<span class="morabaa-badge morabaa-badge-neutral"><?php echo esc_html( (string) $s['similarity'] ); ?>%</span>
											</td>
											<td>
												<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=morabaa-woo-sync&tab=linking&link_view=suggestions' ) ); ?>">
													<?php wp_nonce_field( 'morabaa_link_pair', 'morabaa_link_nonce' ); ?>
													<input type="hidden" name="morabaa_action" value="link_pair" />
													<input type="hidden" name="morabaa_item_id" value="<?php echo esc_attr( $s['item_id'] ); ?>" />
													<input type="hidden" name="morabaa_item_sku" value="<?php echo esc_attr( $s['item_sku'] ); ?>" />
													<input type="hidden" name="morabaa_wc_product_id" value="<?php echo esc_attr( (string) $s['wc_id'] ); ?>" />
													<button type="submit" class="morabaa-button morabaa-button-secondary morabaa-ajax-button" style="padding:7px 16px;">
														<span class="dashicons dashicons-admin-links"></span>
														<span>ربط</span>
														<span class="morabaa-spinner"></span>
													</button>
												</form>
											</td>
										</tr>
									<?php endforeach; ?>
								<?php endif; ?>
								</tbody>
							</table>
						</div>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<div class="morabaa-page-footer">
					&gt;مربّع - مزامنة المخزون مع المتجر بواسطه يحيى خالد
				</div>
			</div>
			<?php
		}
	}

	Morabaa_Woo_Sync_Plugin::instance();
	register_activation_hook( __FILE__, array( 'Morabaa_Woo_Sync_Plugin', 'activate' ) );
	register_deactivation_hook( __FILE__, array( 'Morabaa_Woo_Sync_Plugin', 'deactivate' ) );
}

