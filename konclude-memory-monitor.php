<?php
/**
 * Plugin Name: Konclude Memory Monitor
 * Description: Logs high-memory WordPress requests to help identify problematic plugins or request patterns.
 * Version: 1.0.0
 * Author: Konclu.de (Archie Makuwa)
 * Author URI: https://konclu.de
 * 
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Konclude_Memory_Monitor {

	const OPTION_LOG = 'amm_memory_log';
	const OPTION_SETTINGS = 'amm_memory_settings';
	const MAX_LOG_ROWS = 100;

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'shutdown', array( $this, 'capture_request_profile' ), PHP_INT_MAX );
	}

	public function register_settings() {
		register_setting(
			'amm_memory_monitor_group',
			self::OPTION_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(
					'threshold_mb' => 128,
					'enabled'      => 1,
				),
			)
		);
	}

	public function sanitize_settings( $input ) {
		$threshold = isset( $input['threshold_mb'] ) ? (int) $input['threshold_mb'] : 128;
		$enabled   = isset( $input['enabled'] ) ? 1 : 0;

		if ( $threshold < 16 ) {
			$threshold = 16;
		}

		return array(
			'threshold_mb' => $threshold,
			'enabled'      => $enabled,
		);
	}

	public function get_settings() {
		$settings = get_option(
			self::OPTION_SETTINGS,
			array(
				'threshold_mb' => 128,
				'enabled'      => 1,
			)
		);

		$settings['threshold_mb'] = isset( $settings['threshold_mb'] ) ? (int) $settings['threshold_mb'] : 128;
		$settings['enabled']      = isset( $settings['enabled'] ) ? (int) $settings['enabled'] : 1;

		return $settings;
	}

	public function register_admin_page() {
		add_management_page(
			'Memory Monitor',
			'Memory Monitor',
			'manage_options',
			'archie-memory-monitor',
			array( $this, 'render_admin_page' )
		);
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['amm_clear_log'] ) && check_admin_referer( 'amm_clear_log_action', 'amm_clear_log_nonce' ) ) {
			delete_option( self::OPTION_LOG );
			echo '<div class="notice notice-success"><p>Memory log cleared.</p></div>';
		}

		$settings = $this->get_settings();
		$log      = get_option( self::OPTION_LOG, array() );

		?>
		<div class="wrap">
			<h1>Memory Monitor</h1>

			<p>
				This tool records high-memory requests at shutdown. It helps identify patterns and likely plugin combinations.
				It does <strong>not</strong> provide exact per-plugin PHP memory attribution.
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'amm_memory_monitor_group' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="amm_enabled">Enable monitoring</label></th>
						<td>
							<input type="checkbox" id="amm_enabled" name="<?php echo esc_attr( self::OPTION_SETTINGS ); ?>[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="amm_threshold_mb">Threshold (MB)</label></th>
						<td>
							<input type="number" min="16" step="1" id="amm_threshold_mb" name="<?php echo esc_attr( self::OPTION_SETTINGS ); ?>[threshold_mb]" value="<?php echo esc_attr( $settings['threshold_mb'] ); ?>" />
							<p class="description">Requests at or above this peak memory usage will be logged.</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Save Settings' ); ?>
			</form>

			<hr>

			<form method="post">
				<?php wp_nonce_field( 'amm_clear_log_action', 'amm_clear_log_nonce' ); ?>
				<?php submit_button( 'Clear Log', 'delete', 'amm_clear_log', false ); ?>
			</form>

			<hr>

			<h2>Recent high-memory requests</h2>

			<?php if ( empty( $log ) ) : ?>
				<p>No entries logged yet.</p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th>Date</th>
							<th>Type</th>
							<th>URI</th>
							<th>Peak MB</th>
							<th>Memory Limit</th>
							<th>Plugin Count</th>
							<th>Plugins</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( array_reverse( $log ) as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( $entry['time'] ); ?></td>
								<td><?php echo esc_html( $entry['request_type'] ); ?></td>
								<td><code><?php echo esc_html( $entry['request_uri'] ); ?></code></td>
								<td><?php echo esc_html( $entry['peak_mb'] ); ?></td>
								<td><?php echo esc_html( $entry['memory_limit'] ); ?></td>
								<td><?php echo esc_html( count( $entry['plugins'] ) ); ?></td>
								<td style="max-width: 520px;">
									<details>
										<summary>View plugins</summary>
										<pre style="white-space: pre-wrap;"><?php echo esc_html( implode( "\n", $entry['plugins'] ) ); ?></pre>
									</details>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	public function capture_request_profile() {
		$settings = $this->get_settings();

		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		$peak_bytes = memory_get_peak_usage( true );
		$peak_mb    = round( $peak_bytes / 1048576, 2 );
		$threshold  = (float) $settings['threshold_mb'];

		if ( $peak_mb < $threshold ) {
			return;
		}

		$plugins = $this->get_active_plugins();
		$entry   = array(
			'time'         => current_time( 'mysql' ),
			'request_uri'  => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
			'request_type' => $this->detect_request_type(),
			'peak_mb'      => $peak_mb,
			'current_mb'   => round( memory_get_usage( true ) / 1048576, 2 ),
			'memory_limit' => $this->get_memory_limit(),
			'plugins'      => $plugins,
		);

		$log   = get_option( self::OPTION_LOG, array() );
		$log[] = $entry;

		if ( count( $log ) > self::MAX_LOG_ROWS ) {
			$log = array_slice( $log, -1 * self::MAX_LOG_ROWS );
		}

		update_option( self::OPTION_LOG, $log, false );
	}

	private function detect_request_type() {
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return 'cron';
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}

		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return 'ajax';
		}

		if ( is_admin() ) {
			return 'admin';
		}

		return 'frontend';
	}

	private function get_memory_limit() {
		$limit = ini_get( 'memory_limit' );

		return $limit ? $limit : 'unknown';
	}

	private function get_active_plugins() {
		$plugins = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$network_plugins = array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) );
			$plugins         = array_unique( array_merge( $plugins, $network_plugins ) );
		}

		sort( $plugins );

		return $plugins;
	}
}

new Konclude_Memory_Monitor();