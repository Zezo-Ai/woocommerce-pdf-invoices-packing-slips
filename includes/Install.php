<?php
namespace WPO\IPS;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( '\\WPO\\IPS\\Install' ) ) :

class Install {

	protected static $_instance = null;

	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function __construct() {
		// run lifecycle methods
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			add_action( 'admin_init', array( $this, 'do_install' ) );
		}
	}

	/** Lifecycle methods *******************************************************
	 * Because register_activation_hook only runs when the plugin is manually
	 * activated by the user, we're checking the current version against the
	 * version stored in the database
	****************************************************************************/

	/**
	 * Handles version checking
	 */
	public function do_install() {
		// only install when woocommerce is active
		if ( ! WPO_WCPDF()->is_woocommerce_activated() ) {
			return;
		}

		$version_setting   = 'wpo_wcpdf_version';
		$installed_version = get_option( $version_setting );

		// installed version lower than plugin version?
		if ( version_compare( $installed_version, WPO_WCPDF_VERSION, '<' ) ) {

			if ( ! $installed_version ) {
				try {
					$this->install();
				} catch ( \Throwable $th ) {
					wcpdf_log_error( sprintf( "Plugin install procedure failed (version %s): %s", WPO_WCPDF_VERSION, $th->getMessage() ), 'critical', $th );
				}
			} else {
				try {
					$this->upgrade( $installed_version );
				} catch ( \Throwable $th ) {
					wcpdf_log_error( sprintf( "Plugin upgrade procedure failed (updating from version %s to %s): %s", $installed_version, WPO_WCPDF_VERSION, $th->getMessage() ), 'critical', $th );
				}
			}

			// new version number
			update_option( $version_setting, WPO_WCPDF_VERSION );
		} elseif ( $installed_version && version_compare( $installed_version, WPO_WCPDF_VERSION, '>' ) ) {
			try {
				$this->downgrade( $installed_version );
			} catch ( \Throwable $th ) {
				wcpdf_log_error( sprintf( "Plugin downgrade procedure failed (downgrading from version %s to %s): %s", $installed_version, WPO_WCPDF_VERSION, $th->getMessage() ), 'critical', $th );
			}
			// downgrade version number
			update_option( $version_setting, WPO_WCPDF_VERSION );
		}

		// deactivate legacy addons
		add_action( 'admin_init', array( WPO_WCPDF(), 'deactivate_legacy_addons') );
	}


	/**
	 * Plugin install method. Perform any installation tasks here
	 */
	protected function install() {
		// only install when php version or higher
		if ( ! WPO_WCPDF()->is_dependency_version_supported( 'php' ) ) {
			return;
		}

		// check if upgrading from versionless (1.4.14 and older)
		if ( get_option( 'wpo_wcpdf_general_settings' ) ) {
			$this->upgrade( 'versionless' );
			return;
		}

		// Get tmp folders
		$tmp_base = WPO_WCPDF()->main->get_tmp_base();

		// check if tmp folder exists => if not, initialize
		if ( ! WPO_WCPDF()->file_system->is_dir( $tmp_base ) || ! WPO_WCPDF()->file_system->is_writable( $tmp_base ) ) {
			WPO_WCPDF()->main->init_tmp();
		}

		// Unsupported currency symbols
		$unsupported_symbols = array (
			'AED',
			'AFN',
			'BDT',
			'BHD',
			'BTC',
			'CRC',
			'DZD',
			'GEL',
			'GHS',
			'ILS',
			'INR',
			'IQD',
			'IRR',
			'IRT',
			'JOD',
			'KHR',
			'KPW',
			'KRW',
			'KWD',
			'LAK',
			'LBP',
			'LKR',
			'LYD',
			'MAD',
			'MNT',
			'MUR',
			'MVR',
			'NPR',
			'OMR',
			'PHP',
			'PKR',
			'PYG',
			'QAR',
			'RUB',
			'SAR',
			'SCR',
			'SDG',
			'SYP',
			'THB',
			'TND',
			'TRY',
			'UAH',
			'YER',
		);

		// set default settings
		$settings_defaults = array(
			'wpo_wcpdf_settings_general' => array(
				'download_display'			=> 'display',
				'template_path'				=> WPO_WCPDF()->plugin_path() . '/templates/Simple',
				'currency_font'				=> ( in_array( get_woocommerce_currency(), $unsupported_symbols ) ) ? 1 : '',
				'paper_size'				=> 'a4',
				// 'header_logo'				=> '',
				// 'shop_name'					=> array(),
				// 'shop_address'				=> array(),
				// 'footer'					=> array(),
				// 'extra_1'					=> array(),
				// 'extra_2'					=> array(),
				// 'extra_3'					=> array(),
			),
			'wpo_wcpdf_documents_settings_invoice' => array(
				'enabled'					=> 1,
				// 'attach_to_email_ids'		=> array(),
				// 'display_shipping_address'	=> '',
				// 'display_email'				=> '',
				// 'display_phone'				=> '',
				// 'display_date'				=> '',
				// 'display_number'			=> '',
				// 'number_format'				=> array(),
				// 'reset_number_yearly'		=> '',
				// 'my_account_buttons'		=> '',
				// 'invoice_number_column'		=> '',
				// 'invoice_date_column'		=> '',
				// 'disable_free'				=> '',
			),
			'wpo_wcpdf_documents_settings_packing-slip' => array(
				'enabled'					=> 1,
				// 'display_billing_address'	=> '',
				// 'display_email'				=> '',
				// 'display_phone'				=> '',
			),
			'wpo_wcpdf_settings_debug' => array(
				// 'legacy_mode'				=> '',
				// 'enable_debug'				=> '',
				// 'html_output'				=> '',
				// 'html_output'				=> '',
				'enable_cleanup'				=> 1,
				'cleanup_days'					=> 7,
			),
		);
		foreach ($settings_defaults as $option => $defaults) {
			add_option( $option, $defaults );
		}

		// set transient for wizard notification
		set_transient( 'wpo_wcpdf_new_install', 'yes', DAY_IN_SECONDS * 2 );

		// schedule the yearly reset number action
		if ( ! empty( WPO_WCPDF()->settings ) && is_callable( array( WPO_WCPDF()->settings, 'schedule_yearly_reset_numbers' ) ) ) {
			WPO_WCPDF()->settings->schedule_yearly_reset_numbers();
		}
	}

	/**
	 * Plugin upgrade method.  Perform any required upgrades here
	 *
	 * @param string $installed_version the currently installed ('old') version
	 */
	protected function upgrade( $installed_version ) {
		// Only upgrade when php version or higher
		if ( ! WPO_WCPDF()->is_dependency_version_supported( 'php' ) ) {
			return;
		}

		// Sync fonts on every upgrade!
		$tmp_base = WPO_WCPDF()->main->get_tmp_base();

		// Get fonts folder path
		$font_path = WPO_WCPDF()->main->get_tmp_path( 'fonts' );

		// Check if tmp folder exists => if not, initialize
		if (
			! WPO_WCPDF()->file_system->is_dir( $tmp_base ) ||
			! WPO_WCPDF()->file_system->is_writable( $tmp_base ) ||
			! WPO_WCPDF()->file_system->is_dir( $font_path ) ||
			! WPO_WCPDF()->file_system->is_writable( $font_path )
		) {
			WPO_WCPDF()->main->init_tmp();
		}

		// To ensure fonts will be copied to the upload directory
		delete_transient( 'wpo_wcpdf_subfolder_fonts_has_files' );

		// 1.5.28 update: copy next invoice number to separate setting
		if ( $installed_version == 'versionless' || version_compare( $installed_version, '1.5.28', '<' ) ) {
			$template_settings   = get_option( 'wpo_wcpdf_template_settings' );
			$next_invoice_number = isset( $template_settings['next_invoice_number'] ) ? $template_settings['next_invoice_number'] : '';
			update_option( 'wpo_wcpdf_next_invoice_number', $next_invoice_number );
		}

		// 2.0-dev update: reorganize settings
		if ( $installed_version == 'versionless' || version_compare( $installed_version, '2.0-dev', '<' ) ) {
			$old_settings = array(
				'wpo_wcpdf_general_settings'  => get_option( 'wpo_wcpdf_general_settings' ),
				'wpo_wcpdf_template_settings' => get_option( 'wpo_wcpdf_template_settings' ),
				'wpo_wcpdf_debug_settings'    => get_option( 'wpo_wcpdf_debug_settings' ),
			);

			// combine invoice number formatting in array
			$old_settings['wpo_wcpdf_template_settings']['invoice_number_formatting'] = array();
			$format_option_keys = array( 'padding', 'suffix', 'prefix' );
			foreach ( $format_option_keys as $format_option_key ) {
				if ( isset( $old_settings['wpo_wcpdf_template_settings']["invoice_number_formatting_{$format_option_key}"] ) ) {
					$old_settings['wpo_wcpdf_template_settings']['invoice_number_formatting'][ $format_option_key ] = $old_settings['wpo_wcpdf_template_settings']["invoice_number_formatting_{$format_option_key}"];
				}
			}

			// convert abbreviated email_ids
			if ( isset( $old_settings['wpo_wcpdf_general_settings']['email_pdf'] ) ) {
				foreach ( $old_settings['wpo_wcpdf_general_settings']['email_pdf'] as $email_id => $value ) {
					if ( $email_id == 'completed' || $email_id == 'processing' ) {
						$old_settings['wpo_wcpdf_general_settings']['email_pdf'][ "customer_{$email_id}_order" ] = $value;
						unset( $old_settings['wpo_wcpdf_general_settings']['email_pdf'][ $email_id ] );
					}
				}
			}

			// Migrate template path
			// forward slash for consistency/compatibility
			if ( ! empty( $old_settings['wpo_wcpdf_template_settings']['template_path'] ) ) {
				$template_path = str_replace( '\\', '/', $old_settings['wpo_wcpdf_template_settings']['template_path'] );
				// strip abspath (forward slashed) if included
				$template_path = str_replace( str_replace('\\','/', ABSPATH), '', $template_path );
				// strip pdf subfolder from templates path
				$template_path = str_replace( '/templates/pdf/', '/templates/', $template_path );
				$old_settings['wpo_wcpdf_template_settings']['template_path'] = $template_path;
			}

			// map new settings to old
			$settings_map = array(
				'wpo_wcpdf_settings_general' => array(
					'download_display' => array( 'wpo_wcpdf_general_settings' => 'download_display' ),
					'template_path'    => array( 'wpo_wcpdf_template_settings' => 'template_path' ),
					'currency_font'    => array( 'wpo_wcpdf_template_settings' => 'currency_font' ),
					'paper_size'       => array( 'wpo_wcpdf_template_settings' => 'paper_size' ),
					'header_logo'      => array( 'wpo_wcpdf_template_settings' => 'header_logo' ),
					'shop_name'        => array( 'wpo_wcpdf_template_settings' => 'shop_name' ),
					'shop_address'     => array( 'wpo_wcpdf_template_settings' => 'shop_address' ),
					'footer'           => array( 'wpo_wcpdf_template_settings' => 'footer' ),
					'extra_1'          => array( 'wpo_wcpdf_template_settings' => 'extra_1' ),
					'extra_2'          => array( 'wpo_wcpdf_template_settings' => 'extra_2' ),
					'extra_3'          => array( 'wpo_wcpdf_template_settings' => 'extra_3' ),
				),
				'wpo_wcpdf_documents_settings_invoice' => array(
					'attach_to_email_ids'      => array( 'wpo_wcpdf_general_settings' => 'email_pdf' ),
					'display_shipping_address' => array( 'wpo_wcpdf_template_settings' => 'invoice_shipping_address' ),
					'display_email'            => array( 'wpo_wcpdf_template_settings' => 'invoice_email' ),
					'display_phone'            => array( 'wpo_wcpdf_template_settings' => 'invoice_phone' ),
					'display_date'             => array( 'wpo_wcpdf_template_settings' => 'display_date' ),
					'display_number'           => array( 'wpo_wcpdf_template_settings' => 'display_number' ),
					'number_format'            => array( 'wpo_wcpdf_template_settings' => 'invoice_number_formatting' ),
					'reset_number_yearly'      => array( 'wpo_wcpdf_template_settings' => 'yearly_reset_invoice_number' ),
					'my_account_buttons'       => array( 'wpo_wcpdf_general_settings' => 'my_account_buttons' ),
					'invoice_number_column'    => array( 'wpo_wcpdf_general_settings' => 'invoice_number_column' ),
					'invoice_date_column'      => array( 'wpo_wcpdf_general_settings' => 'invoice_date_column' ),
					'disable_free'             => array( 'wpo_wcpdf_general_settings' => 'disable_free' ),
				),
				'wpo_wcpdf_documents_settings_packing-slip' => array(
					'display_billing_address' => array( 'wpo_wcpdf_template_settings' => 'packing_slip_billing_address' ),
					'display_email'           => array( 'wpo_wcpdf_template_settings' => 'packing_slip_email' ),
					'display_phone'           => array( 'wpo_wcpdf_template_settings' => 'packing_slip_phone' ),
				),
				'wpo_wcpdf_settings_debug' => array(
					'enable_debug' => array( 'wpo_wcpdf_debug_settings' => 'enable_debug' ),
					'html_output'  => array( 'wpo_wcpdf_debug_settings' => 'html_output' ),
				),
			);

			// walk through map
			foreach ( $settings_map as $new_option => $new_settings_keys ) {
				${$new_option} = array();
				foreach ( $new_settings_keys as $new_key => $old_setting ) {
					$old_key    = reset( $old_setting );
					$old_option = key( $old_setting );
					if ( ! empty( $old_settings[ $old_option ][ $old_key ] ) ) {
						// turn translatable fields into array
						$translatable_fields = array( 'shop_name','shop_address','footer','extra_1','extra_2','extra_3' );
						if ( in_array( $new_key, $translatable_fields ) ) {
							${$new_option}[ $new_key ] = array( 'default' => $old_settings[ $old_option ][ $old_key ] );
						} else {
							${$new_option}[ $new_key ] = $old_settings[ $old_option ][ $old_key ];
						}
					}
				}

				// auto enable invoice & packing slip
				$enabled = array( 'wpo_wcpdf_documents_settings_invoice', 'wpo_wcpdf_documents_settings_packing-slip' );
				if ( in_array( $new_option, $enabled ) ) {
					${$new_option}['enabled'] = 1;
				}

				// merge with existing settings
				${$new_option."_old"} = get_option( $new_option, ${$new_option} ); // second argument loads new as default in case the settings did not exist yet
				${$new_option} = (array) ${$new_option} + (array) ${$new_option."_old"}; // duplicate options take new options as default

				// store new option values
				update_option( $new_option, ${$new_option} );
			}
		}

		// 2.0-beta-2 update: copy next number to separate db store
		if ( version_compare( $installed_version, '2.0-beta-2', '<' ) ) {
			$next_number = get_option( 'wpo_wcpdf_next_invoice_number' );
			if ( ! empty( $next_number ) ) {
				$number_store = new \WPO\IPS\Documents\SequentialNumberStore( 'invoice_number' );
				$number_store->set_next( (int) $next_number );
			}
			// we're not deleting this option yet to make downgrading possible
			// delete_option( 'wpo_wcpdf_next_invoice_number' ); // clean up after ourselves
		}

		// 2.1.9: set cleanup defaults
		if ( $installed_version == 'versionless' || version_compare( $installed_version, '2.1.9', '<' ) ) {
			$debug_settings = get_option( 'wpo_wcpdf_settings_debug', array() );
			$debug_settings['enable_cleanup'] = 1;
			$debug_settings['cleanup_days'] = 7;
			update_option( 'wpo_wcpdf_settings_debug', $debug_settings );
		}

		// 2.10.0-dev: migrate template path to template ID
		// 2.11.5: improvements to the migration procedure
		if ( version_compare( $installed_version, '2.11.5', '<' ) ) {
			if ( ! empty( WPO_WCPDF()->settings ) && is_callable( array( WPO_WCPDF()->settings, 'maybe_migrate_template_paths' ) ) ) {
				WPO_WCPDF()->settings->maybe_migrate_template_paths();
			}
		}

		// 2.11.2: remove the obsolete .dist font cache file and mustRead.html from local fonts folder
		if ( version_compare( $installed_version, '2.11.2', '<' ) ) {
			wp_delete_file( trailingslashit( $font_path ) . 'dompdf_font_family_cache.dist.php' );
			wp_delete_file( trailingslashit( $font_path ) . 'mustRead.html' );
		}

		// 2.12.2-dev-1: change 'date' database table default value to '1000-01-01 00:00:00'
		if ( version_compare( $installed_version, '2.12.2-dev-1', '<' ) ) {
			global $wpdb;
			$documents = WPO_WCPDF()->documents->get_documents( 'all' );
			foreach ( $documents as $document ) {
				$store_name        = "{$document->slug}_number";
				$method            = WPO_WCPDF()->settings->get_sequential_number_store_method();
				$table_name        = apply_filters( 'wpo_wcpdf_number_store_table_name', wpo_wcpdf_sanitize_identifier( "{$wpdb->prefix}wcpdf_{$store_name}" ), $store_name, $method );
				$table_name_exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
					$wpdb->prepare( "SHOW TABLES LIKE %s", $table_name )
				) === $table_name;

				if ( ! $table_name_exists ) {
					continue;
				}

				if ( is_callable( array( $document, 'get_sequential_number_store' ) ) ) {
					$number_store = $document->get_sequential_number_store();

					if ( ! empty( $number_store ) ) {
						$column_name = 'date';
						$table_name  = $number_store->table_name;

						$query = wpo_wcpdf_prepare_identifier_query(
							"ALTER TABLE %i ALTER %i SET DEFAULT %s",
							array( $table_name, $column_name ),
							array( '1000-01-01 00:00:00' )
						);

						$query_result = $wpdb->query( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

						if ( $query_result ) {
							wcpdf_log_error(
								"Default value changed for '{$column_name}' column to '1000-01-01 00:00:00' on database table: {$table_name}",
								'info'
							);
						} else {
							wcpdf_log_error(
								"An error occurred! The default value for '{$column_name}' column couldn't be changed to '1000-01-01 00:00:00' on database table: {$table_name}",
								'critical'
							);
						}
					}
				}
			}
		}

		// 3.0.0-dev-1: remove saved option 'use_html5_parser'
		if ( version_compare( $installed_version, '3.0.0-dev-1', '<' ) ) {
			// removes 'HTML5 parser' setting value
			$debug_settings = get_option( 'wpo_wcpdf_settings_debug', array() );
			if ( ! empty( $debug_settings['use_html5_parser'] ) ) {
				unset( $debug_settings['use_html5_parser'] );
				update_option( 'wpo_wcpdf_settings_debug', $debug_settings );
			}
		}

		// 3.3.0-dev-1: schedule the yearly reset number action
		if ( version_compare( $installed_version, '3.3.0-dev-1', '<' ) ) {
			if ( ! empty( WPO_WCPDF()->settings ) && is_callable( array( WPO_WCPDF()->settings, 'schedule_yearly_reset_numbers' ) ) ) {
				WPO_WCPDF()->settings->schedule_yearly_reset_numbers();
			}
		}

		// 3.5.7-dev-1: migrate 'guest_access' setting to 'document_link_access_type'
		if ( version_compare( $installed_version, '3.5.7-dev-1', '<' ) ) {
			$debug_settings = get_option( 'wpo_wcpdf_settings_debug', array() );
			if ( ! empty( $debug_settings['guest_access'] ) ) {
				unset( $debug_settings['guest_access'] );
				$debug_settings['document_link_access_type'] = 'guest';
				update_option( 'wpo_wcpdf_settings_debug', $debug_settings );
			}
		}

		// 3.6.3-dev-1: check if 'legacy_mode' is enabled, and disable it
		if ( version_compare( $installed_version, '3.6.3-dev-1', '<' ) ) {
			$debug_settings = get_option( 'wpo_wcpdf_settings_debug', array() );
			$update         = false;

			if ( ! empty( $debug_settings['legacy_mode'] ) ) {
				unset( $debug_settings['legacy_mode'] );
				$update = true;
			}

			if ( ! empty( $debug_settings['legacy_textdomain'] ) ) {
				unset( $debug_settings['legacy_textdomain'] );
				$update = true;
			}

			if ( $update ) {
				update_option( 'wpo_wcpdf_settings_debug', $debug_settings );
			}
		}


		// 3.7.0-beta-4: migrate UBL legacy settings
		if ( version_compare( $installed_version, '3.7.0-beta-4', '<' ) ) {
			// legacy ubl general/invoice settings
			$legacy_ubl_general_settings = get_option( 'ubl_wc_general', [] );
			$general_settings            = get_option( 'wpo_wcpdf_settings_general', [] );
			$invoice_ubl_settings        = get_option( 'wpo_wcpdf_documents_settings_invoice_ubl', [] );

			$settings_to_migrate = [
				'vat_number'            => 'general',
				'coc_number'            => 'general',
				'company_name'          => 'general', // corresponds to 'shop_name' in the General Settings
				'attach_to_email_ids'   => 'invoice_ubl',
				'include_encrypted_pdf' => 'invoice_ubl',
			];

			foreach ( $settings_to_migrate as $setting => $type ) {
				$update = array();

				switch ( $type ) {
					case 'general':
						if ( isset( $legacy_ubl_general_settings[$setting] ) ) {
							$legacy_ubl_setting_value = $legacy_ubl_general_settings[$setting];
							$setting                  = ( 'company_name' === $setting && ! isset( $general_settings['shop_name'] ) ) ? 'shop_name' : $setting;

							if ( 'company_name' !== $setting ) {
								$general_settings[ $setting ] = $legacy_ubl_setting_value;
								$update[]                     = $type;
							}
						}
						break;
					case 'invoice_ubl':
						if ( isset( $legacy_ubl_general_settings[ $setting ] ) ) {
							$invoice_ubl_settings[ $setting ] = $legacy_ubl_general_settings[ $setting ];
							$update[]                         = $type;
						}
						break;
				}

				if ( ! empty( $update ) ) {
					$update = array_unique( $update );
					foreach ( $update as $type ) {
						switch ( $type ) {
							case 'general':
								update_option( 'wpo_wcpdf_settings_general', $general_settings );
								break;
							case 'invoice_ubl':
								$invoice_ubl_settings['enabled'] = '1';
								update_option( 'wpo_wcpdf_documents_settings_invoice_ubl', $invoice_ubl_settings );
								break;
						}
					}
				}
			}


			// legacy ubl tax settings
			$legacy_ubl_tax_settings = get_option( 'ubl_wc_taxes', [] );
			if ( ! empty( $legacy_ubl_tax_settings ) ) {
				update_option( 'wpo_wcpdf_settings_ubl_taxes', $legacy_ubl_tax_settings );
			}

			// set transient to flush rewrite rules if pretty links are enabled
			if ( WPO_WCPDF()->endpoint->pretty_links_enabled() ) {
				set_transient( 'wpo_wcpdf_flush_rewrite_rules', 'yes', HOUR_IN_SECONDS );
			}
		}

		// 3.9.5-beta-4: migrate UBL tax schemes/categories
		if ( version_compare( $installed_version, '3.9.5-beta-4', '<' ) ) {
			$ubl_tax_settings = get_option( 'wpo_wcpdf_settings_ubl_taxes', array() );

			if ( ! empty( $ubl_tax_settings ) ) {
				array_walk_recursive( $ubl_tax_settings, function ( &$value, $key ) {
					if ( in_array( $key, array( 'scheme', 'category' ) ) && ! empty( $value ) ) {
						$value = strtoupper( $value );
					}
				} );

				update_option( 'wpo_wcpdf_settings_ubl_taxes', $ubl_tax_settings );
			}
		}

		// 4.0.0-beta-3: Remove translatability from VAT and COC fields
		if ( version_compare( $installed_version, '4.0.0-beta-3', '<' ) ) {
			$general_settings = get_option( 'wpo_wcpdf_settings_general', array() );

			if ( isset( $general_settings['vat_number']['default'] ) ) {
				$general_settings['vat_number'] = $general_settings['vat_number']['default'];
			}

			if ( isset( $general_settings['coc_number']['default'] ) ) {
				$general_settings['coc_number'] = $general_settings['coc_number']['default'];
			}

			update_option( 'wpo_wcpdf_settings_general', $general_settings );
		}

		// 4.2.0-beta.3: migrate 'guest' access type to 'full'
		if ( version_compare( $installed_version, '4.2.0-beta.3', '<' ) ) {
			$debug_settings = get_option( 'wpo_wcpdf_settings_debug', array() );

			if ( ! empty( $debug_settings['document_link_access_type'] ) && 'guest' === $debug_settings['document_link_access_type'] ) {
				$debug_settings['document_link_access_type'] = 'full';
				update_option( 'wpo_wcpdf_settings_debug', $debug_settings );
			}
		}

		// 4.3.0-rc.2: reload attachment translations
		if ( version_compare( $installed_version, '4.3.0-rc.2', '<' ) ) {
			$debug_settings                                   = get_option( 'wpo_wcpdf_settings_debug', array() );
			$debug_settings['reload_attachment_translations'] = '1';
			update_option( 'wpo_wcpdf_settings_debug', $debug_settings );
		}

		// 4.5.0-beta.2: set default filesystem method to php
		if ( version_compare( $installed_version, '4.5.0-beta.2', '<' ) ) {
			$debug_settings = get_option( 'wpo_wcpdf_settings_debug', array() );

			if ( ! empty( $debug_settings['file_system_method'] ) && 'wp' === $debug_settings['file_system_method'] ) {
				$debug_settings['file_system_method'] = 'php';
				update_option( 'wpo_wcpdf_settings_debug', $debug_settings );
			}
		}

		// 4.5.0-beta.3: set shop address value for the new shop additional info field.
		if ( version_compare( $installed_version, '4.5.0-beta.3', '<' ) ) {
			$general_settings = get_option( 'wpo_wcpdf_settings_general', array() );

			if ( ! empty( $general_settings['shop_address'] ) ) {
				$general_settings['shop_address_additional'] = $general_settings['shop_address'];
				unset( $general_settings['shop_address'] );
				update_option( 'wpo_wcpdf_settings_general', $general_settings );
			}
		}

		// 4.5.3-pr1195.1: migrate shop address state value.
		if ( version_compare( $installed_version, '4.5.3-pr1195.1', '<' ) ) {
			$general_settings  = get_option( 'wpo_wcpdf_settings_general', array() );
			$states_setting    = $general_settings['shop_address_state'] ?? null;
			$countries_setting = $general_settings['shop_address_country'] ?? null;

			if ( ! empty( $states_setting ) && ! empty( $countries_setting ) ) {
				// Normalize both settings into arrays with locale keys
				$states_by_locale    = is_array( $states_setting )    ? $states_setting    : array( 'default' => $states_setting );
				$countries_by_locale = is_array( $countries_setting ) ? $countries_setting : array( 'default' => $countries_setting );

				// Loop through states and try to match them with the country codes
				$new_states_by_locale = array();
				foreach ( $states_by_locale as $locale => $state_name ) {
					$country_code = $countries_by_locale[ $locale ] ?? $countries_by_locale['default'] ?? '';
					$country_code = strtoupper( sanitize_text_field( trim( $country_code ) ) );
					$state_name   = sanitize_text_field( trim( $state_name ) );

					if ( empty( $country_code ) || empty( $state_name ) ) {
						continue;
					}

					$states = \WC()->countries->get_states( $country_code );

					if ( is_array( $states ) ) {
						$state_code = array_search( $state_name, $states, true );

						// If no match found, keep original value
						$new_states_by_locale[ $locale ] = strtoupper(
							$state_code !== false ? $state_code : ''
						);
					} else {
						$new_states_by_locale[ $locale ] = '';
					}
				}

				// Save only if we updated something
				if ( ! empty( $new_states_by_locale ) ) {
					$general_settings['shop_address_state'] = $new_states_by_locale;
					update_option( 'wpo_wcpdf_settings_general', $general_settings );
				}
				
				// reset shop address notice option
				delete_option( 'wpo_wcpdf_dismiss_shop_address_notice' );
			}
		}

		// Maybe reinstall fonts
		WPO_WCPDF()->main->maybe_reinstall_fonts( true );
	}

	/**
	 * Plugin downgrade method.  Perform any required downgrades here
	 *
	 *
	 * @param string $installed_version the currently installed ('old') version (actually higher since this is a downgrade)
	 */
	protected function downgrade( $installed_version ) {
		// Make sure fonts match with version: copy from plugin folder
		$tmp_base = WPO_WCPDF()->main->get_tmp_base();

		// Make sure we have the fonts directory
		$font_path = WPO_WCPDF()->main->get_tmp_path( 'fonts' );

		// Don't continue if we don't have an upload dir
		if ( false === $tmp_base ) {
			return $tmp_base;
		}

		// Check if tmp folder exists => if not, initialize
		if (
			! WPO_WCPDF()->file_system->is_dir( $tmp_base ) ||
			! WPO_WCPDF()->file_system->is_writable( $tmp_base ) ||
			! WPO_WCPDF()->file_system->is_dir( $font_path ) ||
			! WPO_WCPDF()->file_system->is_writable( $font_path )
		) {
			WPO_WCPDF()->main->init_tmp();
		}

		// To ensure fonts will be copied to the upload directory
		delete_transient( 'wpo_wcpdf_subfolder_fonts_has_files' );

		// Maybe reinstall fonts
		WPO_WCPDF()->main->maybe_reinstall_fonts();
	}

}

endif; // class_exists

