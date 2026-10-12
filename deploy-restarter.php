<?php
/**
 * Plugin Name:       Deploy Restarter
 * Description:       Secure REST endpoint that restarts (deactivate + reactivate) a plugin after its files were replaced over FTP/SFTP.
 * Version:           1.0.0
 * Author:            Shahab Fatemi
 * Requires at least: 5.6
 * Requires PHP:      7.2
 * License:           GPL-2.0-or-later
 * Text Domain:       deploy-restarter
 *
 * Install this ONCE, before you start deploying other plugins.
 * It must NOT be part of the folder you overwrite.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DR_VERSION', '1.0.0' );
define( 'DR_FILE', __FILE__ );
define( 'DR_OPTION', 'dr_settings' );
define( 'DR_LOG_OPTION', 'dr_log' );
define( 'DR_NS', 'deploy-restarter/v1' );
define( 'DR_MAX_LOG', 200 );

final class Deploy_Restarter {

	/* -----------------------------------------------------------------
	 *  BOOTSTRAP
	 * ---------------------------------------------------------------- */

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_regenerate_key' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DR_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function on_activate() {
		$settings = self::get_settings();
		if ( empty( $settings['api_key'] ) ) {
			$settings['api_key'] = self::generate_key();
		}
		update_option( DR_OPTION, $settings );
	}

	public static function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=deploy-restarter' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">Settings</a>' );
		return $links;
	}

	/* -----------------------------------------------------------------
	 *  SETTINGS STORAGE
	 * ---------------------------------------------------------------- */

	public static function defaults() {
		return array(
			'enabled'         => 1,           // REST endpoint on/off
			'api_key'         => '',          // auto-generated on activation
			'allowed_plugins' => '',          // empty = all installed plugins
			'ip_allowlist'    => '',          // empty = any IP
			'trust_proxy'     => 0,           // read X-Forwarded-For / CF-Connecting-IP
			'rate_limit'      => 10,          // requests per minute per IP (0 = off)
			'purge_cache'     => 1,           // wp_cache_flush() after restart
			'reset_opcache'   => 1,           // opcache_reset() after restart
			'logging'         => 1,           // write to the activity log
			'log_retention'   => 30,          // days
		);
	}

	public static function get_settings() {
		$stored = get_option( DR_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return wp_parse_args( $stored, self::defaults() );
	}

	public static function generate_key() {
		if ( function_exists( 'wp_generate_password' ) ) {
			return wp_generate_password( 48, false, false );
		}
		return bin2hex( random_bytes( 24 ) );
	}

	protected static function name( $key ) {
		return DR_OPTION . '[' . $key . ']';
	}

	/* -----------------------------------------------------------------
	 *  REST ROUTES
	 * ---------------------------------------------------------------- */

	public static function register_routes() {

		register_rest_route(
			DR_NS,
			'/restart',
			array(
				'methods'             => WP_REST_Server::CREATABLE, // POST
				'callback'            => array( __CLASS__, 'route_restart' ),
				'permission_callback' => array( __CLASS__, 'authorize' ),
				'args'                => array(
					'plugin' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => 'Plugin folder slug ("my-plugin"), main file ("my-plugin.php") or "folder/main-file.php".',
					),
				),
			)
		);

		register_rest_route(
			DR_NS,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE, // GET
				'callback'            => array( __CLASS__, 'route_status' ),
				'permission_callback' => array( __CLASS__, 'authorize' ),
			)
		);
	}

	/* -----------------------------------------------------------------
	 *  AUTHENTICATION / AUTHORISATION
	 * ---------------------------------------------------------------- */

	public static function authorize( WP_REST_Request $request ) {
		$settings = self::get_settings();

		if ( empty( $settings['enabled'] ) ) {
			return new WP_Error( 'dr_disabled', 'The Deploy Restarter endpoint is disabled.', array( 'status' => 503 ) );
		}

		if ( empty( $settings['api_key'] ) ) {
			return new WP_Error( 'dr_no_key', 'No API key configured yet. Open Settings → Deploy Restarter.', array( 'status' => 503 ) );
		}

		/* --- IP allow-list ------------------------------------------- */
		$allowlist = self::parse_list( $settings['ip_allowlist'] );
		if ( $allowlist ) {
			$ip = self::client_ip();
			if ( ! self::ip_in_list( $ip, $allowlist ) ) {
				self::log( 'denied', 'Blocked IP: ' . $ip );
				return new WP_Error( 'dr_ip_denied', 'Your IP address is not allowed.', array( 'status' => 403 ) );
			}
		}

		/* --- API key -------------------------------------------------- */
		$provided = self::provided_key( $request );
		if ( '' === $provided || ! hash_equals( (string) $settings['api_key'], $provided ) ) {
			self::log( 'denied', 'Invalid API key from ' . self::client_ip() );
			return new WP_Error( 'dr_invalid_key', 'Invalid API key.', array( 'status' => 401 ) );
		}

		/* --- Rate limit ----------------------------------------------- */
		$limit = (int) $settings['rate_limit'];
		if ( $limit > 0 ) {
			$bucket = 'dr_rl_' . md5( self::client_ip() );
			$hits   = (int) get_transient( $bucket );
			if ( $hits >= $limit ) {
				return new WP_Error( 'dr_rate_limited', 'Rate limit exceeded. Try again in a minute.', array( 'status' => 429 ) );
			}
			set_transient( $bucket, $hits + 1, MINUTE_IN_SECONDS );
		}

		return true;
	}

	protected static function provided_key( WP_REST_Request $request ) {
		$key = $request->get_header( 'x_api_key' );          // X-API-Key
		if ( ! $key ) {
			$auth = $request->get_header( 'authorization' ); // Authorization: Bearer xxx
			if ( $auth && 0 === stripos( $auth, 'bearer ' ) ) {
				$key = substr( $auth, 7 );
			}
		}
		if ( ! $key ) {
			$key = $request->get_param( 'api_key' );         // last resort: ?api_key=
		}
		return is_string( $key ) ? trim( $key ) : '';
	}

	protected static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		$settings = self::get_settings();
		if ( ! empty( $settings['trust_proxy'] ) ) {
			$headers = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' );
			foreach ( $headers as $header ) {
				if ( empty( $_SERVER[ $header ] ) ) {
					continue;
				}
				$parts     = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
				$candidate = trim( $parts[0] );
				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					$ip = $candidate;
					break;
				}
			}
		}

		return apply_filters( 'dr_client_ip', $ip );
	}

	/* -----------------------------------------------------------------
	 *  ENDPOINT: /restart
	 * ---------------------------------------------------------------- */

	public static function route_restart( WP_REST_Request $request ) {
		$requested = (string) $request->get_param( 'plugin' );
		$settings  = self::get_settings();

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		wp_clean_plugins_cache( true ); // the folder was just replaced over FTP

		/* --- Resolve the plugin file --------------------------------- */
		$plugin_file = self::resolve_plugin( $requested );
		if ( is_wp_error( $plugin_file ) ) {
			self::log( 'error', $plugin_file->get_error_message() );
			return $plugin_file;
		}

		/* --- Allowed-plugins guard ----------------------------------- */
		$allowed = self::parse_list( $settings['allowed_plugins'] );
		if ( $allowed ) {
			$ok = false;
			foreach ( $allowed as $entry ) {
				$resolved = self::resolve_plugin( $entry );
				if ( ! is_wp_error( $resolved ) && $resolved === $plugin_file ) {
					$ok = true;
					break;
				}
			}
			if ( ! $ok ) {
				self::log( 'denied', 'Plugin not in allow-list: ' . $plugin_file );
				return new WP_Error( 'dr_not_allowed', 'That plugin is not in the allowed list.', array( 'status' => 403 ) );
			}
		}

		$is_network = is_multisite() && is_plugin_active_for_network( $plugin_file );
		$was_active = $is_network || is_plugin_active( $plugin_file );

		$result = array(
			'plugin'        => $plugin_file,
			'was_active'    => (bool) $was_active,
			'deactivated'   => false,
			'activated'     => false,
			'cache_purged'  => false,
			'opcache_reset' => false,
			'timestamp'     => gmdate( 'c' ),
			'errors'        => array(),
		);

		/* --- 1. Deactivate ------------------------------------------- */
		if ( $was_active ) {
			deactivate_plugins( $plugin_file, true, $is_network ); // silent = true (no deactivation hook noise)
			$result['deactivated'] = ! is_plugin_active( $plugin_file );
		}

		/* --- 2. Re-activate (activation hooks fire) ------------------ */
		$activated = activate_plugin( $plugin_file, '', $is_network, false );
		if ( is_wp_error( $activated ) ) {
			$result['errors'][] = 'activate: ' . $activated->get_error_message();
		}
		$result['activated'] = is_plugin_active( $plugin_file ) || ( $is_network && is_plugin_active_for_network( $plugin_file ) );

		/* --- 3. Caches ----------------------------------------------- */
		if ( ! empty( $settings['purge_cache'] ) ) {
			wp_cache_flush();
			$result['cache_purged'] = true;
		}

		if ( ! empty( $settings['reset_opcache'] ) && function_exists( 'opcache_reset' ) ) {
			$result['opcache_reset'] = (bool) @opcache_reset();
		}

		/* --- 4. Log --------------------------------------------------- */
		$ok = empty( $result['errors'] ) && $result['activated'];
		self::log(
			$ok ? 'success' : 'error',
			sprintf(
				'%s — was_active=%s, deactivated=%s, activated=%s%s',
				$plugin_file,
				$was_active ? 'yes' : 'no',
				$result['deactivated'] ? 'yes' : 'no',
				$result['activated'] ? 'yes' : 'no',
				$result['errors'] ? ' | ' . implode( ' ; ', $result['errors'] ) : ''
			)
		);

		return new WP_REST_Response(
			array(
				'ok'     => $ok,
				'result' => $result,
			),
			$ok ? 200 : 500
		);
	}

	/* -----------------------------------------------------------------
	 *  ENDPOINT: /status
	 * ---------------------------------------------------------------- */

	public static function route_status( WP_REST_Request $request ) {
		return new WP_REST_Response(
			array(
				'ok'      => true,
				'plugin'  => 'deploy-restarter',
				'version' => DR_VERSION,
				'site'    => home_url(),
				'wp'      => get_bloginfo( 'version' ),
				'php'     => PHP_VERSION,
				'time'    => gmdate( 'c' ),
			),
			200
		);
	}

	/* -----------------------------------------------------------------
	 *  HELPERS
	 * ---------------------------------------------------------------- */

	protected static function resolve_plugin( $requested ) {
		$requested = ltrim( trim( str_replace( '\\', '/', (string) $requested ) ), '/' );

		if ( '' === $requested ) {
			return new WP_Error( 'dr_bad_request', 'No plugin specified.', array( 'status' => 400 ) );
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();

		// 1. Exact plugin-file match: "akismet/akismet.php"
		if ( isset( $plugins[ $requested ] ) ) {
			return $requested;
		}

		// 2. Single-file plugin: "hello" -> "hello.php"
		if ( isset( $plugins[ $requested . '.php' ] ) ) {
			return $requested . '.php';
		}

		$folder_matches = array();
		$file_matches   = array();

		foreach ( array_keys( $plugins ) as $file ) {
			$folder = ( strpos( $file, '/' ) !== false ) ? dirname( $file ) : '';
			$base   = basename( $file, '.php' );

			if ( '' !== $folder && $folder === $requested ) {
				$folder_matches[] = $file;
			}
			if ( $base === $requested ) {
				$file_matches[] = $file;
			}
		}

		if ( 1 === count( $folder_matches ) ) {
			return $folder_matches[0];
		}
		if ( count( $folder_matches ) > 1 ) {
			return new WP_Error(
				'dr_ambiguous',
				'Several plugins match that folder name. Send "folder/main-file.php" instead.',
				array( 'status' => 400 )
			);
		}
		if ( 1 === count( $file_matches ) ) {
			return $file_matches[0];
		}

		return new WP_Error(
			'dr_not_found',
			sprintf( 'Plugin "%s" is not installed, or its main file could not be found.', $requested ),
			array( 'status' => 404 )
		);
	}

	protected static function parse_list( $value ) {
		if ( is_array( $value ) ) {
			$value = implode( "\n", $value );
		}
		$parts = preg_split( '/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY );
		return $parts ? array_map( 'trim', $parts ) : array();
	}

	protected static function ip_in_list( $ip, array $list ) {
		$ip_bin = @inet_pton( (string) $ip );

		foreach ( $list as $entry ) {
			if ( $entry === $ip ) {
				return true;
			}

			if ( strpos( $entry, '/' ) === false ) {
				continue;
			}

			list( $subnet, $bits ) = array_pad( explode( '/', $entry, 2 ), 2, null );
			$subnet_bin            = @inet_pton( $subnet );

			if ( ! $subnet_bin || ! $ip_bin || strlen( $subnet_bin ) !== strlen( $ip_bin ) ) {
				continue;
			}

			$bits = (int) $bits;
			$max  = strlen( $ip_bin ) * 8;
			if ( $bits < 0 || $bits > $max ) {
				continue;
			}

			$full = intdiv( $bits, 8 );
			$rest = $bits % 8;

			if ( $full > 0 && substr( $ip_bin, 0, $full ) !== substr( $subnet_bin, 0, $full ) ) {
				continue;
			}
			if ( $rest ) {
				$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
				if ( ( ord( $ip_bin[ $full ] ) & $mask ) !== ( ord( $subnet_bin[ $full ] ) & $mask ) ) {
					continue;
				}
			}

			return true;
		}

		return false;
	}

	protected static function log( $level, $message ) {
		$settings = self::get_settings();
		if ( empty( $settings['logging'] ) ) {
			return;
		}

		$entries = get_option( DR_LOG_OPTION, array() );
		if ( ! is_array( $entries ) ) {
			$entries = array();
		}

		array_unshift(
			$entries,
			array(
				'time'    => time(),
				'level'   => (string) $level,
				'message' => (string) $message,
				'ip'      => self::client_ip(),
			)
		);

		// Retention by age.
		$cutoff = time() - ( max( 1, (int) $settings['log_retention'] ) * DAY_IN_SECONDS );
		$entries = array_values(
			array_filter(
				$entries,
				static function ( $entry ) use ( $cutoff ) {
					return isset( $entry['time'] ) && $entry['time'] >= $cutoff;
				}
			)
		);

		// Hard cap.
		if ( count( $entries ) > DR_MAX_LOG ) {
			$entries = array_slice( $entries, 0, DR_MAX_LOG );
		}

		update_option( DR_LOG_OPTION, $entries, false );
	}

	/* -----------------------------------------------------------------
	 *  ADMIN — SETTINGS PAGE
	 * ---------------------------------------------------------------- */

	public static function add_settings_page() {
		add_options_page(
			'Deploy Restarter',
			'Deploy Restarter',
			'manage_options',
			'deploy-restarter',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function register_settings() {

		register_setting(
			'dr_settings_group',
			DR_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => self::defaults(),
			)
		);

		add_settings_section(
			'dr_main',
			'API endpoint',
			static function () {
				echo '<p>POST JSON to <code>' . esc_html( rest_url( DR_NS . '/restart' ) ) . '</code> with the header <code>X-API-Key</code> and the body <code>{"plugin":"my-plugin"}</code>.</p>';
			},
			'deploy-restarter'
		);

		$fields = array(
			'enabled'         => array(
				'label' => 'Enable REST endpoint',
				'type'  => 'checkbox',
				'help'  => 'Turn the whole API on or off. Default: <strong>on</strong>.',
			),
			'api_key'         => array(
				'label' => 'API key',
				'type'  => 'text',
				'class' => 'regular-text code',
				'help'  => sprintf(
					'Send this in the <code>X-API-Key</code> header. <a href="%s">Regenerate key</a>',
					esc_url( wp_nonce_url( admin_url( 'options-general.php?page=deploy-restarter&dr_regenerate=1' ), 'dr_regenerate' ) )
				),
			),
			'allowed_plugins' => array(
				'label' => 'Allowed plugins',
				'type'  => 'textarea',
				'rows'  => 4,
				'help'  => 'One plugin per line (<code>my-plugin</code> or <code>my-plugin/my-plugin.php</code>). Leave empty to allow <strong>every</strong> installed plugin. Default: <strong>empty</strong>.',
			),
			'ip_allowlist'    => array(
				'label' => 'IP allow-list',
				'type'  => 'textarea',
				'rows'  => 4,
				'help'  => 'One IP or CIDR range per line, e.g. <code>203.0.113.7</code> or <code>203.0.113.0/24</code>. Leave empty to allow any IP. Default: <strong>empty</strong>.',
			),
			'trust_proxy'     => array(
				'label' => 'Trust proxy headers',
				'type'  => 'checkbox',
				'label' => 'Read the client IP from <code>CF-Connecting-IP</code> / <code>X-Forwarded-For</code>',
				'help'  => 'Enable only if your site is behind Cloudflare or a reverse proxy. Default: <strong>off</strong>.',
			),
			'rate_limit'      => array(
				'label' => 'Rate limit (requests / minute / IP)',
				'type'  => 'number',
				'min'   => 0,
				'help'  => 'Set to <code>0</code> to disable. Default: <strong>10</strong>.',
			),
			'purge_cache'     => array(
				'label' => 'Purge object cache',
				'type'  => 'checkbox',
				'label' => 'Run <code>wp_cache_flush()</code> after the restart',
				'help'  => 'Default: <strong>on</strong>.',
			),
			'reset_opcache'   => array(
				'label' => 'Reset OPcache',
				'type'  => 'checkbox',
				'label' => 'Run <code>opcache_reset()</code> after the restart',
				'help'  => 'Ignored when OPcache is not available. Default: <strong>on</strong>.',
			),
			'logging'         => array(
				'label' => 'Activity log',
				'type'  => 'checkbox',
				'label' => 'Keep a log of the last 200 requests',
				'help'  => 'Default: <strong>on</strong>.',
			),
			'log_retention'   => array(
				'label' => 'Log retention (days)',
				'type'  => 'number',
				'min'   => 1,
				'help'  => 'Default: <strong>30</strong>.',
			),
		);

		foreach ( $fields as $key => $args ) {
			$args['key'] = $key;
			add_settings_field(
				'dr_' . $key,
				$args['label'],
				array( __CLASS__, 'render_field' ),
				'deploy-restarter',
				'dr_main',
				$args
			);
		}
	}

	public static function sanitize_settings( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$existing = self::get_settings();
		$out      = array();

		foreach ( array( 'enabled', 'purge_cache', 'reset_opcache', 'logging', 'trust_proxy' ) as $flag ) {
			$out[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
		}

		$key = isset( $input['api_key'] ) ? trim( (string) $input['api_key'] ) : '';
		$key = preg_replace( '/[^A-Za-z0-9_\-]/', '', $key );
		if ( '' === $key ) {
			$key = ! empty( $existing['api_key'] ) ? $existing['api_key'] : self::generate_key();
		}
		$out['api_key'] = $key;

		$out['allowed_plugins'] = isset( $input['allowed_plugins'] ) ? sanitize_textarea_field( $input['allowed_plugins'] ) : '';
		$out['ip_allowlist']    = isset( $input['ip_allowlist'] ) ? sanitize_textarea_field( $input['ip_allowlist'] ) : '';

		$out['rate_limit']    = isset( $input['rate_limit'] ) ? max( 0, (int) $input['rate_limit'] ) : self::defaults()['rate_limit'];
		$out['log_retention'] = isset( $input['log_retention'] ) ? max( 1, (int) $input['log_retention'] ) : self::defaults()['log_retention'];

		return $out;
	}

	public static function maybe_regenerate_key() {
		if ( empty( $_GET['dr_regenerate'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'dr_regenerate' );

		$settings            = self::get_settings();
		$settings['api_key'] = self::generate_key();
		update_option( DR_OPTION, $settings );

		wp_safe_redirect( admin_url( 'options-general.php?page=deploy-restarter&dr_regenerated=1' ) );
		exit;
	}

	public static function render_field( $args ) {
		$key   = $args['key'];
		$type  = isset( $args['type'] ) ? $args['type'] : 'text';
		$all   = self::get_settings();
		$value = isset( $all[ $key ] ) ? $all[ $key ] : '';
		$name  = self::name( $key );
		$id    = 'dr-' . str_replace( '_', '-', $key );

		switch ( $type ) {
			case 'checkbox':
				printf(
					'<label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s /> %4$s</label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( ! empty( $value ), true, false ),
					wp_kses_post( isset( $args['label'] ) ? $args['label'] : '' )
				);
				break;

			case 'textarea':
				printf(
					'<textarea id="%1$s" name="%2$s" rows="%3$d" class="large-text code">%4$s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					isset( $args['rows'] ) ? (int) $args['rows'] : 5,
					esc_textarea( $value )
				);
				break;

			case 'number':
				printf(
					'<input type="number" id="%1$s" name="%2$s" value="%3$s" min="%4$s" step="1" class="small-text" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value ),
					esc_attr( isset( $args['min'] ) ? $args['min'] : 0 )
				);
				break;

			default:
				printf(
					'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="%4$s" autocomplete="off" spellcheck="false" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value ),
					esc_attr( isset( $args['class'] ) ? $args['class'] : 'regular-text' )
				);
		}

		if ( ! empty( $args['help'] ) ) {
			echo '<p class="description">' . wp_kses_post( $args['help'] ) . '</p>';
		}
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$endpoint = rest_url( DR_NS . '/restart' );
		?>
		<div class="wrap">
			<h1>Deploy Restarter</h1>

			<?php if ( isset( $_GET['dr_regenerated'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>A new API key has been generated. Update your deployment script.</p>
				</div>
			<?php endif; ?>

			<p>Restart a plugin after its files were replaced over FTP/SFTP:</p>
			<p>
				<code>POST <?php echo esc_html( $endpoint ); ?></code><br />
				<code>X-API-Key: &lt;your key&gt;</code><br />
				<code>{"plugin": "my-plugin"}</code>
			</p>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'dr_settings_group' );
				do_settings_sections( 'deploy-restarter' );
				submit_button();
				?>
			</form>

			<?php self::render_log_table(); ?>
		</div>
		<?php
	}

	protected static function render_log_table() {
		$entries = get_option( DR_LOG_OPTION, array() );
		if ( ! is_array( $entries ) || ! $entries ) {
			return;
		}
		?>
		<h2>Recent activity</h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th style="width:160px">Time (UTC)</th>
					<th style="width:90px">Level</th>
					<th style="width:140px">IP</th>
					<th>Message</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( array_slice( $entries, 0, 50 ) as $entry ) : ?>
				<tr>
					<td><?php echo esc_html( gmdate( 'Y-m-d H:i:s', (int) $entry['time'] ) ); ?></td>
					<td><?php echo esc_html( $entry['level'] ); ?></td>
					<td><?php echo esc_html( isset( $entry['ip'] ) ? $entry['ip'] : '' ); ?></td>
					<td><?php echo esc_html( $entry['message'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}

register_activation_hook( __FILE__, array( 'Deploy_Restarter', 'on_activate' ) );
Deploy_Restarter::init();