<?php

/**
 * WP Shieldon Controller.
 *
 * @author Terry Lin
 * @package Shieldon
 * @since 1.0.0
 * @version 1.4.0
 * @license GPLv3
 *
 */

class WPSO_Shieldon_Guardian {

	/**
	 * Shieldon instance.
	 *
	 * @var object
	 */
	public $shieldon;
	
	/**
	 * Visitor's current position.
	 *
	 * @var string
	 */
	private $current_url;

	/**
	 * Constructer.
	 */
	public function __construct() {

		/**
		 * Start a Shieldon instance.
		 */
		$this->shieldon = new \Shieldon\Shieldon();

		$_SESSION['shieldon_ui_lang'] = wpso_get_lang();

		$this->current_url = $_SERVER['REQUEST_URI'];

		$ip_source = wpso_get_option( 'ip_source', 'shieldon_daemon' );

		switch ( $ip_source ) {
			case 'HTTP_CF_CONNECTING_IP':
				if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
					$this->shieldon->setIp( $_SERVER['HTTP_CF_CONNECTING_IP'] );
				}
				break;

			case 'HTTP_X_FORWARDED_FOR':
				if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
					$this->shieldon->setIp( $_SERVER['HTTP_X_FORWARDED_FOR'] );
				}
				break;
			case 'HTTP_X_FORWARDED_HOST':
				if ( ! empty( $_SERVER['HTTP_X_FORWARDED_HOST'] ) ) {
					$this->shieldon->setIp( $_SERVER['HTTP_X_FORWARDED_HOST'] );
				}
				break;

			case 'REMOTE_ADDR':
			default:
		}
	}

	/**
	 * Initialize everything the Githuber plugin needs.
	 * 
	 * @document https://shield-on-php.github.io/en/
	 */
	public function init() {

		$this->set_driver();           // Set Shieldon data driver to store logs.
		$this->reset_logs();           // Clear all logs if new data cycle should be started.
		$this->set_logger();           // Set Action Logger.
		$this->set_frequency_check();  // Set Frequancy check. (settings)
		$this->set_filters();          // Set filters.
		$this->set_component();        // Set Shieldon components.
		$this->set_captcha();          // Set Shieldon CAPTCHA instances.
		$this->set_session_limit();    // Set online session limit settings.
		$this->set_authentication();   // Set the URL paths that are protected by WWW-Authenticate protocol.
		$this->set_xss_protection();   // Filter XSS strings.
	}

	/**
	 * Start protecting your website!
	 *
	 * @return void
	 */
	public function run() {

		// Check ecxluded list before checking process.
		if ( $this->is_excluded_list() ) {
			return null;
		}

		$is_driver_reset = get_option( 'wpso_driver_reset' );

		if ( 'no' === $is_driver_reset ) {
			$this->shieldon->createDatabase( false );
		}

		$result = $this->shieldon->run();

		if ($result !== $this->shieldon::RESPONSE_ALLOW) {

			// The first time initializing has created the database automatically, so we don't need to check it every time.
			if ( 'yes' === $is_driver_reset ) {
				update_option( 'wpso_driver_reset', 'no' );
			}

			if ($this->shieldon->captchaResponse()) {

				// Unban current session.
				$this->shieldon->unban();
			}

			// Output the result page with HTTP status code 200.
			$this->shieldon->output(200);
		}
	}

	/**
	 * Print Javascript plaintext in page footer.
	 * 
	 * @return void
	 */
	public function front_print_footer_scripts() {
		echo $this->shieldon->outputJsSnippet();
	}

	/**
	 * Frequency check.
	 *
	 * @return void
	 */
	private function set_frequency_check() {

		if ( 'yes' === wpso_get_option( 'enable_filter_frequency', 'shieldon_daemon' ) ) {

			$time_unit_quota_s = wpso_get_option( 'time_unit_quota_s', 'shieldon_daemon' );
			$time_unit_quota_m = wpso_get_option( 'time_unit_quota_m', 'shieldon_daemon' );
			$time_unit_quota_h = wpso_get_option( 'time_unit_quota_h', 'shieldon_daemon' );
			$time_unit_quota_d = wpso_get_option( 'time_unit_quota_d', 'shieldon_daemon' );
	
			$time_unit_quota['s'] = ( is_numeric( $time_unit_quota_s ) && ! empty( $time_unit_quota_s ) ) ? (int) $time_unit_quota_s : 2;
			$time_unit_quota['m'] = ( is_numeric( $time_unit_quota_m ) && ! empty( $time_unit_quota_m ) ) ? (int) $time_unit_quota_m : 10;
			$time_unit_quota['h'] = ( is_numeric( $time_unit_quota_h ) && ! empty( $time_unit_quota_h ) ) ? (int) $time_unit_quota_h : 30;
			$time_unit_quota['d'] = ( is_numeric( $time_unit_quota_d ) && ! empty( $time_unit_quota_d ) ) ? (int) $time_unit_quota_d : 60;
	
			$this->shieldon->setProperty( 'time_unit_quota', $time_unit_quota );
		}
	}

	/**
	 * Set filters.
	 *
	 * @return void
	 */
	private function set_filters() {

		$filter_config = array(
			'session'   => ( 'yes' === wpso_get_option( 'enable_filter_session',   'shieldon_filter' ) ) ? true : false,
			'cookie'    => ( 'yes' === wpso_get_option( 'enable_filter_cookie',    'shieldon_filter' ) ) ? true : false,
			'referer'   => ( 'yes' === wpso_get_option( 'enable_filter_referer',   'shieldon_filter' ) ) ? true : false,
			'frequency' => ( 'yes' === wpso_get_option( 'enable_filter_frequency', 'shieldon_daemon' ) ) ? true : false,
		);

		$this->shieldon->setFilters( $filter_config );

		// Check cookie generated by JavaScript.
		if ( $filter_config['cookie'] ) {
			add_action( 'wp_print_footer_scripts', array( $this, 'front_print_footer_scripts' ) );
		}
	}

	/**
	 * Set data driver for Shieldon.
	 * 
	 * @return void
	 */
	public function set_driver() {

		$driver_type = wpso_get_option( 'data_driver_type', 'shieldon_daemon' );

		switch ( $driver_type ) {

			case 'reids':

				try {

					// Create a Redis instance.
					$redis_instance = new \Redis();
					$redis_instance->connect( '127.0.0.1', 6379 );

					// Use Redis data driver.
					$this->shieldon->setDriver(
						new \Shieldon\Driver\RedisDriver( $redis_instance )
					);

				} catch( \RedisException $e ) {
					echo $e->getMessage();
					return false;
				}

				break;

			case 'file':

				$shieldon_file_dir = wpso_get_upload_dir();

				// Use File data driver.
				$this->shieldon->setDriver(
					new \Shieldon\Driver\FileDriver( $shieldon_file_dir )
				);

				break;

			case 'sqlite':

				try {
					
					// Specific the sqlite file location.
					$sqlite_location = wpso_get_upload_dir() . '/shieldon.sqlite3';

					// Create a PDO instance.
					$pdo_instance = new \PDO( 'sqlite:' . $sqlite_location );

					// Use Sqlite data driver.
					$this->shieldon->setDriver(
						new \Shieldon\Driver\SqliteDriver( $pdo_instance )
					);
	
				} catch( \PDOException $e ) {
					echo $e->getMessage();
					return false;
				}

				break;

			case 'mysql':
			default:

				// Read database settings from wp-config.php
				$db = array(
					'host'    => DB_HOST,
					'dbname'  => DB_NAME,
					'user'    => DB_USER,
					'pass'    => DB_PASSWORD,
					'charset' => DB_CHARSET,
				);
		
				try {

					// Create a PDO instance.
					$pdo_instance = new \PDO(
						'mysql:host=' . $db['host'] . ';dbname=' . $db['dbname'] . ';charset=' . $db['charset'],
						$db['user'],
						$db['pass']
					);

					// Use MySQL data driver.
					$this->shieldon->setDriver(
						new \Shieldon\Driver\MysqlDriver( $pdo_instance )
					);

				} catch( \PDOException $e ) {
					echo $e->getMessage();
					return false;
				}
		}

		// Set Channel, for WordPress multisite network.
		$this->shieldon->setChannel( wpso_get_channel_id() );
	}

	/**
	 * Components.
	 *
	 * @return void
	 */
	private function set_component() {
		/**
		 * Load "Ip" component.
		 */
		$component_ip = new \Shieldon\Component\Ip();

		$this->shieldon->setComponent( $component_ip );

		$this->ip_manager();

		/**
		 * Load "Trusted Bot" component.
		 */
		if ( 'yes' === wpso_get_option( 'enable_component_trustedbot', 'shieldon_component' ) ) {

			// This component will only allow popular search engline.
			// Other bots will go into the checking process.
			$component_trustedbot = new \Shieldon\Component\TrustedBot();

			$this->shieldon->setComponent( $component_trustedbot );
		}

		/**
		 * Load "Header" component.
		 */
		if ( 'yes' === wpso_get_option( 'enable_component_header', 'shieldon_component' ) ) {

			$component_header = new \Shieldon\Component\Header();

			// Deny all vistors without common header information.
			if ( 'yes' === wpso_get_option( 'header_strict_mode', 'shieldon_component' ) ) {
				$component_header->setStrict( true );
			}

			$this->shieldon->setComponent( $component_header );
		}

		/**
		 * Load "User-agent" component.
		 */
		if ( 'yes' === wpso_get_option( 'enable_component_agent', 'shieldon_component' ) ) {

			$component_agent = new \Shieldon\Component\UserAgent();

			// Visitors with empty user-agent information will be blocked.
			if ( 'yes' === wpso_get_option( 'agent_strict_mode', 'shieldon_component' ) ) {
				$component_agent->setStrict( true );
			}

			$this->shieldon->setComponent( $component_agent );
		}

		/**
		 * Load "Rdns" component.
		 */
		if ( 'yes' === wpso_get_option( 'enable_component_rdns', 'shieldon_component' ) ) {

			$component_rdns = new \Shieldon\Component\Rdns();

			// Visitors with empty Rdns record will be blocked.
			// IP resolved hostname (Rdns) and IP address must conform with each other.
			if ( 'yes' === wpso_get_option( 'rdns_strict_mode', 'shieldon_component' ) ) {
				$component_rdns->setStrict( true );
			}

			$this->shieldon->setComponent( $component_rdns );
		}
	}

	/**
	 * Set CAPTCHA.
	 *
	 * @return void
	 */
	private function set_captcha() {

		if ( 'yes' === wpso_get_option( 'enable_captcha_google', 'shieldon_captcha' ) ) {

			$google_captcha_config['key']    = wpso_get_option( 'google_recaptcha_key', 'shieldon_captcha' );
			$google_captcha_config['secret'] = wpso_get_option( 'google_recaptcha_secret', 'shieldon_captcha' );
			$google_captcha_config['version'] = wpso_get_option( 'google_recaptcha_version', 'shieldon_captcha' );
			$google_captcha_config['lang']   = wpso_get_option( 'google_recaptcha_version', 'shieldon_captcha' );

			$captcha_google = new \Shieldon\Captcha\Recaptcha( $google_captcha_config );

			$this->shieldon->setCaptcha( $captcha_google );
		}

		if ( 'yes' === wpso_get_option( 'enable_captcha_image', 'shieldon_captcha' ) ) {

			$image_captcha_config['word_length'] = wpso_get_option( 'image_captcha_length', 'shieldon_captcha' );

			$image_captcha_type = wpso_get_option( 'image_captcha_type', 'shieldon_captcha' );

			switch ($image_captcha_type) {
				case 'numeric':
					$image_captcha_config['pool'] = '0123456789';
					break;

				case 'alpha':
					$image_captcha_config['pool'] = '0123456789abcdefghijklmnopqrstuvwxyz';
					break;

				case 'alnum':
				default:
					$image_captcha_config['pool'] = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
			}
			
			$captcha_image = new \Shieldon\Captcha\ImageCaptcha( $image_captcha_config );
			$this->shieldon->setCaptcha( $captcha_image );
		}
	}

	/**
	 * Set online session limit.
	 *
	 * @return void
	 */
	private function set_session_limit() {

		if ( 'yes' === wpso_get_option( 'enable_online_session_limit', 'shieldon_daemon' ) ) {

			$online_users = wpso_get_option( 'session_limit_count', 'shieldon_daemon' );
			$alive_period = wpso_get_option( 'session_limit_period', 'shieldon_daemon' );
		
			$online_users = ( is_numeric( $online_users ) && ! empty( $online_users ) ) ? ( (int) $online_users ) : 100;
			$alive_period = ( is_numeric( $alive_period ) && ! empty( $alive_period ) ) ? ( (int) $alive_period * 60 )  : 300;

			$this->shieldon->limitSession( $online_users, $alive_period );
		}
	}

	/**
	 * Clear all logs from Data driver.
	 *
	 * @return void
	 */
	private function reset_logs() {

		if ( 'yes' === wpso_get_option( 'reset_data_circle', 'shieldon_daemon' ) ) {

			$now_time = time();

			$last_reset_time = get_option( 'wpso_last_reset_time' );

			if ( empty( $last_reset_time ) ) {
				$last_reset_time = strtotime( date('Y-m-d 00:00:00') );
			} else {
				$last_reset_time = (int) $last_reset_time;
			}

			if ( ( $now_time - $last_reset_time ) > 86400 ) {
				$last_reset_time = strtotime( date('Y-m-d 00:00:00') );

				// Record new reset time.
				update_option( 'wpso_last_reset_time', $last_reset_time );

				// Remove all data.
				$this->shieldon->driver->rebuild();
			}
		}
	}

	/**
	 * Check excluded list.
	 *
	 * @return bool
	 */
	private function is_excluded_list() {

		$list = wpso_get_option( 'excluded_urls', 'shieldon_exclusion' );
		$urls = array();

		if ( ! empty( $list ) ) {
			$urls = explode(PHP_EOL, $list);
		}

		$blog_install_dir = parse_url( get_site_url() , PHP_URL_PATH );

		if ( '/' === $blog_install_dir ) {
			$blog_install_dir = '';
		}

		// `Save draft` will use this path.
		if ( 'yes' === wpso_get_option( 'ignore_wp_json', 'shieldon_exclusion' ) ) {
			array_push( $urls, $blog_install_dir . '/wp-json/' );
		}
		
		// Customer preview
		if ( 'yes' === wpso_get_option( 'ignore_wp_theme_customizer', 'shieldon_exclusion' ) ) {
			array_push( $urls, $blog_install_dir . '/?customize_changeset_uuid=' );
		}

		foreach ($urls as $url) {
			if ( 0 === strpos( $this->current_url, $url ) ) {
				return true;
			}
		}

		// Login page.
		if ( 'yes' === wpso_get_option( 'ignore_page_login', 'shieldon_exclusion' ) ) {

			if ( 0 === strpos( $this->current_url, '/wp-login.php' ) ) {
				return true;
			}
		}

		// Signup page.
		if ( 'yes' === wpso_get_option( 'ignore_page_signup', 'shieldon_exclusion' ) ) {
			if ( 0 === strpos( $this->current_url, '/wp-signup.php' ) ) {
				return true;
			}
		}

		// XML RPC.
		if ( 'yes' === wpso_get_option( 'ignore_wp_xmlrpc', 'shieldon_exclusion' ) ) {
			if ( 0 === strpos( $this->current_url, '/xmlrpc.php' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * IP manager.
	 */
	private function ip_manager() {

		if ( 0 === strpos( $this->current_url, '/wp-login.php' ) ) {

			// Login page.
			$login_whitelist = wpso_get_option( 'ip_login_whitelist', 'shieldon_ip_login' );
			$login_blacklist = wpso_get_option( 'ip_login_blacklist', 'shieldon_ip_login' );
			$login_deny_all  = wpso_get_option( 'ip_login_deny_all', 'shieldon_ip_login' );

			if ( ! empty( $login_whitelist ) ) {
				$whitelist = explode(PHP_EOL, $login_whitelist );
				$this->shieldon->component['Ip']->setAllowedList( $whitelist );
			}

			if ( ! empty( $login_blacklist ) ) {
				$blacklist = explode(PHP_EOL, $login_blacklist );
				$this->shieldon->component['Ip']->setDeniedList( $blacklist );
			}

			$passcode         = wpso_get_option( 'deny_all_passcode', 'shieldon_ip_login' );
			$passcode_confirm = '';

			if ( ! empty( $_COOKIE['wp_shieldon_passcode'] ) ) {
				$passcode_confirm = $_COOKIE['wp_shieldon_passcode'];
			}

			if ( ! empty( $passcode ) && isset( $_GET[ $passcode ] ) ) {
				if ( empty( $_COOKIE['wp_shieldon_passcode'] ) ) {
					setcookie( 'wp_shieldon_passcode', $passcode, time() + 86400 );
				}
				$passcode_confirm = $passcode;
			}

			if ( 'yes' === $login_deny_all ) {
				if ( $passcode_confirm !== $passcode ) {
					$this->shieldon->component['Ip']->denyAll();
				}
			}

		} elseif ( 0 === strpos( $this->current_url, '/wp-signup.php' ) ) {

			// Signup page.
			$signup_whitelist = wpso_get_option( 'ip_signup_whitelist', 'shieldon_ip_signup' );
			$signup_blacklist = wpso_get_option( 'ip_signup_blacklist', 'shieldon_ip_signup' );
			$signup_deny_all  = wpso_get_option( 'ip_signup_deny_all', 'shieldon_ip_signup' );

			if ( ! empty( $signup_whitelist ) ) {
				$whitelist = explode( PHP_EOL, $signup_whitelist );
				$this->shieldon->component['Ip']->setAllowedList( $whitelist );
			}

			if ( ! empty( $signup_blacklist ) ) {
				$blacklist = explode( PHP_EOL, $signup_blacklist );
				$this->shieldon->component['Ip']->setDeniedList( $blacklist );
			}

			if ( 'yes' === $signup_deny_all ) {
				$this->shieldon->component['Ip']->denyAll();
			}

		} elseif ( 0 === strpos( $this->current_url, '/xmlrpc.php' ) ) {

			// XML RPC.
			$xmlrpc_whitelist = wpso_get_option( 'ip_xmlrpc_whitelist', 'shieldon_ip_xmlrpc' );
			$xmlrpc_blacklist = wpso_get_option( 'ip_xmlrpc_blacklist', 'shieldon_ip_xmlrpc' );
			$xmlrpc_deny_all  = wpso_get_option( 'ip_xmlrpc_deny_all', 'shieldon_ip_xmlrpc' );

			if ( ! empty( $xmlrpc_whitelist ) ) {
				$whitelist = explode( PHP_EOL, $xmlrpc_whitelist );
				$this->shieldon->component['Ip']->setAllowedList( $whitelist );
			}

			if ( ! empty( $xmlrpc_blacklist ) ) {
				$blacklist = explode( PHP_EOL, $xmlrpc_blacklist );
				$this->shieldon->component['Ip']->setDeniedList( $blacklist );
			}

			if ( 'yes' === $xmlrpc_deny_all ) {
				$this->shieldon->component['Ip']->denyAll();
			}

		} else {

			// Global.
			$global_whitelist = wpso_get_option( 'ip_global_whitelist', 'shieldon_ip_global' );
			$global_blacklist = wpso_get_option( 'ip_global_blacklist', 'shieldon_ip_global' );
			$global_deny_all  = wpso_get_option( 'ip_global_deny_all', 'shieldon_ip_global' );

			if ( ! empty( $global_whitelist ) ) {
				$whitelist = explode( PHP_EOL, $global_whitelist );
				$this->shieldon->component['Ip']->setAllowedList( $whitelist );
			}

			if ( ! empty( $global_blacklist ) ) {
				$blacklist = explode( PHP_EOL, $global_blacklist );
				$this->shieldon->component['Ip']->setDeniedList( $blacklist );
			}

			if ( 'yes' === $global_deny_all ) {
				$this->shieldon->component['Ip']->denyAll();
			}
		}
	}

	/**
	 * Set Action Logger.
	 *
	 * @return void
	 */
	private function set_logger() {

		$logger = new \Shieldon\Log\ActionLogger( wpso_get_logs_dir() );

		$this->shieldon->setLogger( $logger );
	}

	/**
	 * Set the URLs that are protected by WWW-Authenticate protocol.
	 *
	 * @return void
	 */
	private function set_authentication() {

		$authenticated_list = get_option( 'shieldon_authetication' );

		if ( ! empty( $authenticated_list) ) {

			$auth_handler = new \Shieldon\Security\httpAuthentication();

			$this->shieldon->setClosure( 'www_authenticate', function() use ( $auth_handler, $authenticated_list ) {
				$auth_handler->set( $authenticated_list );
				$auth_handler->check();
			});
		}
	}

	/**
	 * Undocumented function
	 *
	 * @return void
	 */
	private function set_xss_protection() {

		$xss_protection_options = get_option( 'shieldon_xss_protection' );

		$xss_filter = new \Shieldon\Security\Xss();

		if ( $xss_protection_options['post'] ) {

			$this->shieldon->setClosure( 'xss_post', function() use ( $xss_filter ) {
				if ( ! empty( $_POST ) ) {
					foreach ( array_keys( $_POST ) as $k ) {
						$_POST[ $k ] = $xss_filter->clean( $_POST[ $k ] );
					}
				}
			});
		}

		if ( $xss_protection_options['get'] ) {

			$this->shieldon->setClosure( 'xss_get', function() use ( $xss_filter ) {
				if ( ! empty( $_GET ) ) {
					foreach ( array_keys( $_GET ) as $k ) {
						$_GET[ $k ] = $xss_filter->clean( $_GET[ $k ] );
					}
				}
			});
		}

		if ( $xss_protection_options['cookie'] ) {
			$this->shieldon->setClosure( 'xss_cookie', function() use ( $xss_filter ) {
				if ( ! empty( $_COOKIE ) ) {
					foreach ( array_keys( $_COOKIE ) as $k ) {
						$_COOKIE[ $k ] = $xss_filter->clean( $_COOKIE[ $k ] );
					}
				}
			});
		}
		
		$xss_protected_list = get_option( 'shieldon_xss_protected_list' );

		if (! empty($xssProtectedList)) {
		
			$this->shieldon->setClosure( 'xss_protection', function() use ( $xss_filter, $xss_protected_list ) {

				foreach ( $xss_protected_list as $v ) {

					$k = $v['variable'] ?? 'undefined';
	
					switch ( $v['type'] ) {

						case 'get':

							if ( ! empty( $_GET[ $k ] ) ) {
								$_GET[ $k ] = $xss_filter->clean( $_GET[ $k ] );
							}
							break;
	
						case 'post':
	
							if ( ! empty( $_POST[ $k ]) ) {
								$_POST[ $k ] = $xss_filter->clean( $_POST[ $k ] );
							}
							break;
	
						case 'cookie':

							if ( ! empty( $_COOKIE[ $k ] ) ) {
								$_COOKIE[ $k ] = $xss_filter->clean( $_COOKIE[$k] );
							}
							break;
	
						default:
					}
				}
			});
		}
	}
}