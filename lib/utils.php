<?php

require_once 'scanner_base.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	class MSS_CLI {
		function dump() {
			// $scans = malCure_Utils::get_setting( 'scan' );
			$opt = get_option( 'MSS_scans' );
			krsort( $opt );
			WP_CLI::log( print_r( $opt, 1 ) );
		}
	}
	WP_CLI::add_command( 'mss', 'MSS_CLI' );
}

/**
 * Common utility functions
 */
final class malCure_Utils {

	static $opt_name = 'MSS';
	static $cap      = 'activate_plugins';

	function __construct() {
		// malCure_Utils::opt_name = 'MSS';
		//return self::get_instance();
		add_filter( 'mss_checksums', array( $this, 'generated_checksums' ) );
	}

	static function get_instance() {
		static $instance = null;
		if ( is_null( $instance ) ) {
			$instance = new self();
			$instance->init();
		}
		return $instance;
	}

	function init() {
		add_filter( 'mss_checksums', array( $this, 'generated_checksums' ) );
	}

	/**
	 * Debug function used for testing
	 *
	 * @param [type] $str
	 * @return void
	 */
	static function llog( $str, $log = false, $return = false ) {
		if ( $log ) {
			return self::elog( $str, '', $return );
		}
		if ( $return ) {
			return '<pre>' . print_r( $str, 1 ) . '</pre>';
		} else {
			echo '<pre>' . print_r( $str, 1 ) . '</pre>';
		}
	}

	/**
	 * Log error
	 *
	 * @param [type]  $err | Whatever error message
	 * @param [type]  $description: Where did this occur, how, when
	 * @param boolean $return
	 * @return void
	 */
	static function elog( $how_when_where, $msg, $return = false ) {
		self::append_err( $how_when_where, $msg );
		if ( $return ) {
			return '<pre>' . print_r( $str, 1 ) . '</pre>';
		} else {
			echo '<pre>' . print_r( $str, 1 ) . '</pre>';
		}
	}

	/**
	 * Log message to file
	 */
	static function flog( $str ) {
		$date = date( 'Ymd-G:i:s' ); // 20171231-23:59:59
		$date = $date . '-' . microtime( true );
		$file = MSS_DIR . 'log.log';
		file_put_contents( $file, PHP_EOL . $date, FILE_APPEND | LOCK_EX );
		// usleep( 1000 );
		$str = print_r( $str, true );
		file_put_contents( $file, PHP_EOL . $str, FILE_APPEND | LOCK_EX );
		// usleep( 1000 );
	}

	static function is_registered() {
		return self::get_setting( 'api-credentials' );
	}

	static function encode( $str ) {
		return strtr( base64_encode( json_encode( $str ) ), '+/=', '-_,' );
	}

	static function decode( $str ) {
		return json_decode( base64_decode( strtr( $str, '-_,', '+/=' ) ), true );
	}

	static function get_plugin_data() {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return get_plugin_data( MSS_FILE, false, false );
	}

	/**
	 * Returns all files at the specified path
	 *
	 * @param boolean $path
	 * @return array, file-paths and file-count
	 */
	static function get_files( $path = false ) {
		if ( ! $path ) {
			$path = ABSPATH;
		}
		$allfiles = new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS );
		$files    = array();
		foreach ( new RecursiveIteratorIterator( $allfiles ) as $filename => $cur ) {
			$files[] = $filename;
		}
		sort( $files );
		return array(
			'total_files' => count( $files ),
			'files'       => $files,
		);
	}

	/**
	 * Register with the api endpoint and save credentials
	 *
	 * @return mixed data or wp_error
	 */
	static function do_mss_api_register( $user ) {
		$user['fn'] = preg_replace( '/[^A-Za-z ]/', '', $user['fn'] );
		$user['ln'] = preg_replace( '/[^A-Za-z ]/', '', $user['ln'] );
		if ( empty( $user['fn'] ) || empty( $user['ln'] ) || ! filter_var( $user['email'], FILTER_VALIDATE_EMAIL ) ) {
			return;
		}
		global $wp_version;
		$data     = self::encode(
			array(
				'user' => $user,
				'diag' => array(
					'site_url'       => trailingslashit( site_url() ),
					'php'            => phpversion(),
					'web_server'     => empty( $_SERVER['SERVER_SOFTWARE'] ) ? 'none' : $_SERVER['SERVER_SOFTWARE'],
					'wp'             => $wp_version,
					'plugin_version' => self::get_plugin_data(),
					'cachebust'      => microtime( 1 ),
				),
			)
		);
		$url      = add_query_arg(
			'wpmr_action',
			'wpmr_register',
			add_query_arg(
				'p',
				'495',
				add_query_arg( 'reg_details', $data, MSS_API_EP )
			)
		);
		$response = wp_safe_remote_request(
			$url,
			array(
				'blocking' => true,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 != $status_code ) {
			return new WP_Error( 'broke', 'Got HTTP error ' . $status_code . '.' );
		}
		$response = wp_remote_retrieve_body( $response );
		if ( empty( $response ) || is_null( $response ) ) {
			return new WP_Error( 'broke', 'Empty response.' );
		}
		$data = json_decode( $response, true );
		if ( ! isset( $data['error'] ) ) {
			self::update_setting( 'api-credentials', $data );
			return $data;
		} else {
			return new WP_Error( 'broke', 'API server didn\'t register. Please try again later.' );
		}
		return new WP_Error( 'broke', 'Uncaught error in ' . __FUNCTION__ . '.' );
	}

	/**
	 * Gets the definitions from the database including version
	 *
	 * @return void
	 */
	static function get_definitions() {
		return self::get_option_definitions();
	}

	static function get_definition_version() {
		$defs = self::get_definitions();
		if ( ! empty( $defs['v'] ) ) {
			return $defs['v'];
		}
	}

	/**
	 * Gets all definitions excluding version
	 *
	 * @return void
	 */
	static function get_malware_definitions() {
		$defs = self::get_definitions();
		if ( ! empty( $defs['definitions'] ) ) {
			return $defs['definitions'];
		}
	}

	/**
	 * Gets malware definitions for files only
	 */
	static function get_malware_file_definitions() {
		$defs = self::get_malware_definitions();
		if ( ! empty( $defs['files'] ) ) {
			return $defs['files'];
		}
		// return $definitions['files'];
	}

	/**
	 * Gets malware definitions for database only
	 *
	 * @return void
	 */
	static function get_malware_db_definitions() {
		$defs = self::get_malware_definitions();
		if ( ! empty( $defs['db'] ) ) {
			return $defs['db'];
		}
	}

	/**
	 * For future, match malware in user content like post content, urls etc.?
	 *
	 * @return array
	 */
	static function get_malware_content_definitions() {

	}

	/**
	 * Get firewall rules
	 *
	 * @return array
	 */
	static function get_firewall_definitions() {

	}

	/**
	 * Returns full URL to API Endpoint for the requested action
	 */
	static function get_api_url( $action ) {
		return self::build_api_url( $action );
	}

	/**
	 * Builds full URL to API Endpoint for the requested action
	 */
	static function build_api_url( $action ) {
		$args          = array(
			'cachebust'   => time(),
			'wpmr_action' => $action,
		);
		$compatibility = self::get_plugin_data();
		$state         = self::get_setting( 'api-credentials' );
		$lic           = self::get_setting( 'license_key' );
		if ( $state ) {
			$state = array_merge( $state, $compatibility );
		} else {
			$state = $compatibility;
		}
		if ( $lic ) {
			$state['lic'] = $lic;
		}
		$args['state'] = self::encode( $state );
		// return trailingslashit( MSS_API_EP ) . '?' . urldecode( http_build_query( $args ) );
		return trailingslashit( MSS_API_EP ) . '?' . urldecode( http_build_query( $args ) );
	}

	/**
	 * Check for definition updates
	 *
	 * @return array of new and current defition versions | WP_Error
	 */
	static function definition_updates_available() {
		$current = self::get_definition_version();
		$new     = self::get_setting( 'update-version' );
		return $new;
		if ( empty( $new ) ) {
			$new = self::check_definition_updates();
			if ( is_wp_error( $new ) ) {
				return $new;
			}
		}
		if ( $current != $new ) {
			return array(
				'new'     => $new,
				'current' => $current,
			);
		}
		return false;
	}

	/**
	 * Meant to be run daily or on-demand. Checks for definition update from API server.
	 *
	 * @return void
	 */
	static function check_definition_updates() {
		$response    = wp_safe_remote_request( self::get_api_url( 'check-definitions' ) );
		$headers     = wp_remote_retrieve_headers( $response );
		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 != $status_code ) {
			return new WP_Error( 'broke', 'Got HTTP error ' . $status_code . ' while checking definition updates.' );
		}
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body    = wp_remote_retrieve_body( $response );
		$version = json_decode( $body, true );
		if ( is_null( $version ) ) {
			return new WP_Error( 'broke', 'Unparsable response during definition update-check.' );
		}
		if ( $version['success'] != true ) {
			return new WP_Error( 'broke', sanitize_text_field( $version['data'] ) );
		}
		if ( ! empty( $version['success'] ) && $version['success'] == true ) {
			$version = $version['data'];
			$time    = date( 'U' );
			self::update_setting( 'update-version', $version );
			return $version;
		}
	}

	/**
	 * Gets WordPress Core and plugin checksums
	 *
	 * @return array
	 */
	static function fetch_checksums() {
		// $checksums = $cached ? get_transient( 'WPMR_checksums' ) : false;
		$checksums = self::get_option_checksums_core();
		if ( ! $checksums ) {
			global $wp_version;
			$checksums = get_core_checksums( $wp_version, get_locale() );
			if ( ! $checksums ) { // get_core_checksums failed
				$checksums = get_core_checksums( $wp_version, 'en_US' ); // try en_US locale
				if ( ! $checksums ) {
					$checksums = array(); // fallback to empty array
				}
			}
			$plugin_checksums = self::fetch_plugin_checksums();
			if ( $plugin_checksums ) {
				$checksums = array_merge( $checksums, $plugin_checksums );
			}
			if ( $checksums ) {
				self::update_option_checksums_core( $checksums );
				return apply_filters( 'mss_checksums', $checksums );
			}
			return apply_filters( 'mss_checksums', array() );
		} else {
			return apply_filters( 'mss_checksums', $checksums );
		}
	}

	static function fetch_plugin_checksums() {
		$missing          = array();
		$all_plugins      = get_plugins();
		$install_path     = get_home_path();
		$plugin_checksums = array();
		foreach ( $all_plugins as $key => $value ) {
			if ( false !== strpos( $key, '/' ) ) { // plugin has to be inside a directory. currently drop in plugins are not supported
				$plugin_file  = trailingslashit( dirname( MSS_DIR ) ) . $key;
				$plugin_file  = str_replace( $install_path, '', $plugin_file );
				$checksum_url = 'https://downloads.wordpress.org/plugin-checksums/' . dirname( $key ) . '/' . $value['Version'] . '.json';
				$checksum     = wp_safe_remote_get( $checksum_url );
				if ( is_wp_error( $checksum ) ) {
					continue;
				}

				if ( '200' != wp_remote_retrieve_response_code( $checksum ) ) {
					if ( '404' == wp_remote_retrieve_response_code( $checksum ) ) {
						$missing[ $key ] = array( 'Version' => $value['Version'] );
					}
					continue;
				}
				$checksum = wp_remote_retrieve_body( $checksum );
				$checksum = json_decode( $checksum, true );
				if ( ! is_null( $checksum ) && ! empty( $checksum['files'] ) ) {
					$checksum = $checksum['files'];
					foreach ( $checksum as $file => $checksums ) {
						$plugin_checksums[ trailingslashit( dirname( $plugin_file ) ) . $file ] = $checksums['md5'];
					}
				}
			} else {
			}
		}
		$extras = self::get_pro_checksums( $missing );
		if ( $extras ) {
			$plugin_checksums = array_merge( $plugin_checksums, $extras );
		}
		return $plugin_checksums;
	}
	
	static function generated_checksums( $checksums ) {
		//malCure_Utils::flog( 'hooked generated checksums' );
		$generated = self::get_option_checksums_generated();
		if ( $generated && is_array( $generated ) && ! empty( $checksums ) && is_array( $checksums ) ) {
			$checksums = array_merge( $generated, $checksums );
		}
		else {
			//malCure_Utils::flog( 'not sending generated checksums' );
		}
		return $checksums;
	}

	static function normalize_path( $file_path ) {
		return str_replace( get_home_path(), '', $file_path );
	}

	/**
	 * Gets checksums of premium versions from API server
	 */
	static function get_pro_checksums( $missing ) {
		if ( empty( $missing ) ) {
			return;
		}
		if ( ! self::is_registered() ) {
			return;
		}
		$state            = self::get_setting( 'user' );
		$state            = self::encode( $state );
		$all_plugins      = $missing;
		$install_path     = get_home_path();
		$plugin_checksums = array();
		foreach ( $all_plugins as $key => $value ) {
			if ( false !== strpos( $key, '/' ) ) { // plugin has to be inside a directory. currently drop in plugins are not supported
				$plugin_file  = trailingslashit( dirname( MSS_DIR ) ) . $key;
				$plugin_file  = str_replace( $install_path, '', $plugin_file );
				$checksum_url = self::get_api_url( 'wpmr_checksum' );
				$checksum_url = add_query_arg(
					array(
						'slug'    => dirname( $key ),
						'version' => $value['Version'],
						'type'    => 'plugin',
					),
					'http://example.com'
				);

				$checksum = wp_safe_remote_get( $checksum_url );
				if ( is_wp_error( $checksum ) ) {
					continue;
				}
				if ( '200' != wp_remote_retrieve_response_code( $checksum ) ) {
					continue;
				}
				$checksum = wp_remote_retrieve_body( $checksum );
				$checksum = json_decode( $checksum, true );
				if ( ! is_null( $checksum ) && ! empty( $checksum['files'] ) ) {
					$checksum = $checksum['files'];
					foreach ( $checksum as $file => $checksums ) {
						$plugin_checksums[ trailingslashit( dirname( $plugin_file ) ) . $file ] = $checksums['md5'];
					}
				}
			} else {
			}
		}
		return $plugin_checksums;
	}

	/**
	 * Update definitions from API server
	 */
	static function update_definitions() {
		$definitions = self::fetch_definitions();

		if ( is_wp_error( $definitions ) ) {
			return $definitions;
		} else {
			self::update_option_definitions( $definitions );
			$time = date( 'U' );
			self::update_setting( 'definitions_update_time', $time );
			return true;
		}
	}

	/**
	 * Fetch definitions from the api endpoint
	 *
	 * @return array definitions or wp error
	 */
	static function fetch_definitions() {
		// $creds = self::$creds;
		$response    = wp_safe_remote_request( self::get_api_url( 'update-definitions' ) );
		$headers     = wp_remote_retrieve_headers( $response );
		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 != $status_code ) {
			return new WP_Error( 'broke', 'Got HTTP error ' . $status_code . ' while fetching Update.' );
		}
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body        = wp_remote_retrieve_body( $response );
		$definitions = json_decode( $body, true );
		if ( is_null( $definitions ) ) {
			return new WP_Error( 'broke', 'Unparsable response in definitions.' );
		}
		if ( $definitions['success'] != true ) {
			return new WP_Error( 'broke', sanitize_text_field( $definitions['data'] ) );
		}
		if ( ! empty( $definitions['success'] ) && $definitions['success'] == true ) {
			$definitions = $definitions['data'];
			return $definitions;
		}
	}

	// Update options
	static function update_option_checksums_core( $checksums ) {
		return update_option( self::$opt_name . '_checksums_core', $checksums );
	}

	static function update_option_checksums_generated( $checksums ) {
		return update_option( self::$opt_name . '_checksums_generated', $checksums );
	}

	static function update_option_definitions( $definitions ) {
		return update_option( self::$opt_name . '_definitions', $definitions );
	}

	// Get options
	static function get_option_checksums_core() {
		return get_option( self::$opt_name . '_checksums_core' );
	}

	static function get_option_checksums_generated() {
		$checksums = get_option( self::$opt_name . '_checksums_generated' );
		if ( ! $checksums ) {
			return array();
		}
		return $checksums;
	}

	static function get_option_definitions() {
		return get_option( self::$opt_name . '_definitions' );
	}

	// Delete options
	static function delete_option_checksums_core() {
		return delete_option( self::$opt_name . '_checksums_core' );
	}

	static function delete_option_checksums_generated() {
		return delete_option( self::$opt_name . '_checksums_generated' );
	}

	static function delete_option_definitions() {
		return delete_option( self::$opt_name . '_definitions' );
	}

	static function await_unlock() {
		// self::flog( __FUNCTION__ . ' called by: ' );
		// self::flog( debug_backtrace()[2] );
		while ( get_option( 'MSS_lock' ) == 'true' ) {
			// usleep( 1 );
			usleep( rand( 2500, 7500 ) );
		}
		// self::flog( 'lock acquired' );
		update_option( 'MSS_lock', 'true' );
	}

	static function do_unlock() {
		// self::flog( __FUNCTION__ . ' called by: ' );
		// self::flog( debug_backtrace()[2] );
		update_option( 'MSS_lock', 'false' );
		// self::flog( 'lock released' );
	}

	static function get_setting( $setting ) {
		self::await_unlock();
		$settings = get_option( self::$opt_name );
		self::do_unlock();
		return isset( $settings[ $setting ] ) ? $settings[ $setting ] : false;
	}

	static function update_setting( $setting, $value ) {
		self::await_unlock();
		$settings = get_option( self::$opt_name );
		if ( ! $settings ) {
			$settings = array();
		}
		$settings[ $setting ] = $value;
		update_option( self::$opt_name, $settings );
		self::do_unlock();
	}

	static function delete_setting( $setting ) {
		self::await_unlock();
		$settings = get_option( self::$opt_name );
		if ( ! $settings ) {
			$settings = array();
		}
		unset( $settings[ $setting ] );
		update_option( self::$opt_name, $settings );
		self::do_unlock();
	}

	static function append_err( $how_when_where, $msg = '' ) {
		$errors = self::get_setting( 'errors' );
		if ( ! $errors ) {
			$errors = array();
		}
		$errors[ time() ] = array(
			'how' => $how_when_where,
			'msg' => $msg,
		);

		asort( $errors );

		$errors = array_slice( $errors, 0, 100 ); // limit errors to recent 100

		return update_setting( 'errors', $errors );
	}

}

//malCure_Utils::get_instance();
$malCure_Utils = new malCure_Utils();
