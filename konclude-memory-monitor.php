<?php
/**
* Plugin Name: Konclude Memory Monitor
* Plugin URI:  https://konclu.de
* Description: Tracks per-plugin memory usage, logs requests, identifies memory hogs, and sends email alerts.
* Version:     2.0.0
* Author:      Konclu.de (Archie Makuwa)
* Author URI:  https://konclu.de
* Requires at least: 6.0
* Requires PHP: 7.4
*/

if ( ! defined( 'ABSPATH' ) ) {
exit;
}

class Konclude_Memory_Monitor {

const OPTION_LOG      = 'kmm_log';
const OPTION_SETTINGS = 'kmm_settings';
const TRANSIENT_ALERT = 'kmm_alert_sent';
const MAX_LOG_ROWS    = 200;
const MENU_SLUG       = 'kmm-dashboard';

/** @var array<string, int> Memory in bytes at each lifecycle stage */
private array $lifecycle = [];

/** @var array<string, int> Memory delta in bytes per plugin file */
private array $plugin_deltas = [];

/** @var int Memory reading before the next plugin loads */
private int $mem_before_plugin = 0;

// =========================================================
// Boot
// =========================================================

public function __construct() {
	// Must hook as early as possible so we catch every plugin load.
	add_action( 'muplugins_loaded',   [ $this, 'on_muplugins_loaded'   ], 0       );
	add_action( 'plugin_loaded',      [ $this, 'on_plugin_loaded'       ], 0,  1  );
	add_action( 'plugins_loaded',     [ $this, 'on_plugins_loaded'      ], -9999   );
	add_action( 'after_setup_theme',  [ $this, 'on_after_setup_theme'   ], -9999   );
	add_action( 'init',               [ $this, 'on_init'                ], -9999   );
	add_action( 'wp_loaded',          [ $this, 'on_wp_loaded'           ], -9999   );
	add_action( 'template_redirect',  [ $this, 'on_template_redirect'   ], -9999   );
	add_action( 'shutdown',           [ $this, 'on_shutdown'            ], PHP_INT_MAX );

	add_action( 'admin_menu',             [ $this, 'register_menus'    ] );
	add_action( 'admin_init',             [ $this, 'register_settings' ] );
	add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue_assets'    ] );
}

// =========================================================
// Lifecycle hooks
// =========================================================

public function on_muplugins_loaded(): void {
	$mem = memory_get_usage( true );
	$this->lifecycle['muplugins_loaded'] = $mem;
	$this->mem_before_plugin             = $mem;
}

/**
 * Fires after every individual plugin file is included.
 * Delta = memory consumed by that single plugin file inclusion.
 * Note: plugins that defer work to later hooks will show a small
 * delta here — the lifecycle table shows the fuller picture.
 */
public function on_plugin_loaded( string $file ): void {
	$after = memory_get_usage( true );
	$this->plugin_deltas[ $file ] = max( 0, $after - $this->mem_before_plugin );
	$this->mem_before_plugin      = $after;
}

public function on_plugins_loaded(): void {
	$this->lifecycle['plugins_loaded'] = memory_get_usage( true );
}

public function on_after_setup_theme(): void {
	$this->lifecycle['after_setup_theme'] = memory_get_usage( true );
}

public function on_init(): void {
	$this->lifecycle['init'] = memory_get_usage( true );
}

public function on_wp_loaded(): void {
	$this->lifecycle['wp_loaded'] = memory_get_usage( true );
}

public function on_template_redirect(): void {
	$this->lifecycle['template_redirect'] = memory_get_usage( true );
}

// =========================================================
// Shutdown — build entry, store, maybe alert
// =========================================================

public function on_shutdown(): void {
	$s = $this->get_settings();

	if ( empty( $s['enabled'] ) ) {
		return;
	}

	$peak_mb   = round( memory_get_peak_usage( true ) / 1048576, 2 );
	$threshold = (float) $s['threshold_mb'];

	if ( empty( $s['log_all'] ) && $peak_mb < $threshold ) {
		return;
	}

	// Build plugin delta map in MB (drop zero-byte entries).
	$deltas_mb = [];
	foreach ( $this->plugin_deltas as $file => $bytes ) {
		$mb = round( $bytes / 1048576, 3 );
		if ( $mb > 0 ) {
			$deltas_mb[ $file ] = $mb;
		}
	}

	// Theme delta: memory gained between plugins_loaded → after_setup_theme.
	$theme_delta_mb = null;
	if ( isset( $this->lifecycle['after_setup_theme'], $this->lifecycle['plugins_loaded'] ) ) {
		$raw            = $this->lifecycle['after_setup_theme'] - $this->lifecycle['plugins_loaded'];
		$theme_delta_mb = round( max( 0, $raw ) / 1048576, 3 );
	}

	// Convert lifecycle snapshots to MB.
	$lifecycle_mb = [];
	foreach ( $this->lifecycle as $stage => $bytes ) {
		$lifecycle_mb[ $stage ] = round( $bytes / 1048576, 2 );
	}

	$entry = [
		'time'           => current_time( 'mysql' ),
		'request_uri'    => isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '',
		'request_type'   => $this->request_type(),
		'peak_mb'        => $peak_mb,
		'at_shutdown_mb' => round( memory_get_usage( true ) / 1048576, 2 ),
		'memory_limit'   => $this->memory_limit(),
		'plugin_deltas'  => $deltas_mb,
		'theme_delta_mb' => $theme_delta_mb,
		'lifecycle_mb'   => $lifecycle_mb,
	];

	$log   = (array) get_option( self::OPTION_LOG, [] );
	$log[] = $entry;

	if ( count( $log ) > self::MAX_LOG_ROWS ) {
		$log = array_slice( $log, - self::MAX_LOG_ROWS );
	}

	update_option( self::OPTION_LOG, $log, false );

	// Email alert.
	if ( ! empty( $s['email_enabled'] ) && $peak_mb >= (float) $s['alert_threshold_mb'] ) {
		$this->maybe_alert( $entry, $s );
	}
}

// =========================================================
// Email alert
// =========================================================

private function maybe_alert( array $entry, array $s ): void {
	if ( get_transient( self::TRANSIENT_ALERT ) ) {
		return; // Still in cooldown window.
	}

	$cooldown = max( 1, (int) $s['alert_cooldown_min'] );
	set_transient( self::TRANSIENT_ALERT, 1, $cooldown * MINUTE_IN_SECONDS );

	$recipients = $this->parse_emails( $s['alert_emails'] ?? '' );
	if ( empty( $recipients ) ) {
		return;
	}

	$site      = get_bloginfo( 'name' );
	$url       = home_url();
	$limit_mb  = $this->limit_to_mb( $entry['memory_limit'] );
	$pct       = $limit_mb > 0 ? round( ( $entry['peak_mb'] / $limit_mb ) * 100 ) : '?';

	$subject = sprintf(
		'[%s] Memory alert: %.2f MB (%s%% of limit)',
		$site,
		$entry['peak_mb'],
		$pct
	);

	// Top 10 plugins by load-time delta.
	$top = $this->top_plugins( $entry['plugin_deltas'], 10 );

	$lines = '';
	foreach ( $top as $file => $mb ) {
		$lines .= sprintf( "  %-60s  +%s MB\n", $file, $mb );
	}

	$body  = "Memory alert from: {$site}\n";
	$body .= str_repeat( '-', 60 ) . "\n\n";
	$body .= "URL          : {$url}\n";
	$body .= "Time         : {$entry['time']}\n";
	$body .= "Request      : {$entry['request_uri']}\n";
	$body .= "Request type : {$entry['request_type']}\n";
	$body .= "Peak memory  : {$entry['peak_mb']} MB  ({$pct}% of {$entry['memory_limit']})\n\n";
	$body .= "Top plugins by load-time memory delta:\n";
	$body .= $lines ?: "  (no delta data)\n";

	if ( null !== $entry['theme_delta_mb'] ) {
		$body .= "\nTheme setup delta : {$entry['theme_delta_mb']} MB\n";
	}

	$body .= "\nLifecycle snapshots:\n";
	foreach ( $entry['lifecycle_mb'] as $stage => $mb ) {
		$body .= sprintf( "  %-28s  %s MB\n", $stage, $mb );
	}

	$body .= "\nView full log: " . admin_url( 'admin.php?page=' . self::MENU_SLUG ) . "\n";

	foreach ( $recipients as $email ) {
		wp_mail( $email, $subject, $body );
	}
}

// =========================================================
// Settings
// =========================================================

public function register_settings(): void {
	register_setting( 'kmm_group', self::OPTION_SETTINGS, [
		'type'              => 'array',
		'sanitize_callback' => [ $this, 'sanitize_settings' ],
		'default'           => $this->default_settings(),
	] );
}

public function sanitize_settings( array $input ): array {
	return [
		'enabled'           => ! empty( $input['enabled'] )       ? 1 : 0,
		'log_all'           => ! empty( $input['log_all'] )        ? 1 : 0,
		'threshold_mb'      => max( 16, (int) ( $input['threshold_mb']      ?? 64  ) ),
		'email_enabled'     => ! empty( $input['email_enabled'] )  ? 1 : 0,
		'alert_threshold_mb'=> max( 16, (int) ( $input['alert_threshold_mb'] ?? 128 ) ),
		'alert_cooldown_min'=> max( 1,  (int) ( $input['alert_cooldown_min'] ?? 60  ) ),
		'alert_emails'      => implode( ', ', $this->parse_emails( $input['alert_emails'] ?? '' ) ),
	];
}

private function default_settings(): array {
	return [
		'enabled'            => 1,
		'log_all'            => 0,
		'threshold_mb'       => 64,
		'email_enabled'      => 0,
		'alert_threshold_mb' => 128,
		'alert_cooldown_min' => 60,
		'alert_emails'       => get_option( 'admin_email', '' ),
	];
}

public function get_settings(): array {
	return wp_parse_args(
		(array) get_option( self::OPTION_SETTINGS, [] ),
		$this->default_settings()
	);
}

// =========================================================
// Admin menus
// =========================================================

public function register_menus(): void {
	add_menu_page(
		'Memory Monitor',
		'Memory Monitor',
		'manage_options',
		self::MENU_SLUG,
		[ $this, 'page_log' ],
		'dashicons-performance',
		3
	);

	add_submenu_page(
		self::MENU_SLUG,
		'Request Log — Memory Monitor',
		'Request Log',
		'manage_options',
		self::MENU_SLUG,
		[ $this, 'page_log' ]
	);

	add_submenu_page(
		self::MENU_SLUG,
		'Plugin Stats — Memory Monitor',
		'Plugin Stats',
		'manage_options',
		self::MENU_SLUG . '-stats',
		[ $this, 'page_stats' ]
	);

	add_submenu_page(
		self::MENU_SLUG,
		'Settings — Memory Monitor',
		'Settings',
		'manage_options',
		self::MENU_SLUG . '-settings',
		[ $this, 'page_settings' ]
	);
}

// =========================================================
// Assets
// =========================================================

public function enqueue_assets( string $hook ): void {
	if ( false === strpos( $hook, self::MENU_SLUG ) ) {
		return;
	}
	wp_add_inline_style( 'wp-admin', $this->inline_css() );
}

private function inline_css(): string {
	return '
	.kmm-wrap { max-width:1400px; }
	.kmm-cards { display:flex; gap:14px; flex-wrap:wrap; margin:16px 0 24px; }
	.kmm-card {
		background:#fff; border:1px solid #ddd; border-radius:8px;
		padding:14px 20px; min-width:150px;
	}
	.kmm-card-val { font-size:1.9em; font-weight:700; line-height:1.2; }
	.kmm-card-lbl { color:#888; font-size:11px; text-transform:uppercase; letter-spacing:.05em; margin-top:3px; }
	.kmm-red    { color:#dc2626; }
	.kmm-amber  { color:#d97706; }
	.kmm-green  { color:#16a34a; }
	.kmm-blue   { color:#2563eb; }
	.kmm-bar-track { display:inline-block; background:#e5e7eb; border-radius:4px; height:8px; width:90px; vertical-align:middle; overflow:hidden; }
	.kmm-bar-fill  { height:8px; border-radius:4px; }
	.kmm-dlist { list-style:none; margin:0; padding:0; font-size:12px; line-height:1.9; }
	table.widefat td, table.widefat th { vertical-align:top; }
	.kmm-pill {
		display:inline-block; padding:1px 7px; border-radius:10px; font-size:11px;
		background:#e0e7ff; color:#3730a3; font-weight:600;
	}
	.kmm-pill.admin    { background:#fef3c7; color:#92400e; }
	.kmm-pill.cron     { background:#ede9fe; color:#5b21b6; }
	.kmm-pill.ajax     { background:#d1fae5; color:#065f46; }
	.kmm-pill.rest     { background:#fee2e2; color:#991b1b; }
	.kmm-pill.frontend { background:#e0f2fe; color:#075985; }
	';
}

// =========================================================
// Page: Request Log
// =========================================================

public function page_log(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if (
		isset( $_POST['kmm_clear'] ) &&
		check_admin_referer( 'kmm_clear_log', 'kmm_nonce' )
	) {
		delete_option( self::OPTION_LOG );
		echo '<div class="notice notice-success is-dismissible"><p>Log cleared.</p></div>';
	}

	$s       = $this->get_settings();
	$log     = array_reverse( (array) get_option( self::OPTION_LOG, [] ) );
	$total   = count( $log );
	$peaks   = array_column( $log, 'peak_mb' );
	$max_p   = $total ? (float) max( $peaks ) : 0;
	$avg_p   = $total ? round( array_sum( $peaks ) / $total, 1 ) : 0;
	$high    = 0;
	foreach ( $log as $e ) {
		if ( (float) $e['peak_mb'] >= (float) $s['alert_threshold_mb'] ) {
			$high++;
		}
	}
	?>
	<div class="wrap kmm-wrap">
		<h1>🧠 Memory Monitor — Request Log</h1>

		<div class="kmm-cards">
			<div class="kmm-card">
				<div class="kmm-card-val kmm-blue"><?php echo esc_html( $total ); ?></div>
				<div class="kmm-card-lbl">Entries logged</div>
			</div>
			<?php if ( $total ) : ?>
			<div class="kmm-card">
				<div class="kmm-card-val kmm-red"><?php echo esc_html( $max_p ); ?> MB</div>
				<div class="kmm-card-lbl">Highest peak</div>
			</div>
			<div class="kmm-card">
				<div class="kmm-card-val kmm-amber"><?php echo esc_html( $avg_p ); ?> MB</div>
				<div class="kmm-card-lbl">Average peak</div>
			</div>
			<div class="kmm-card">
				<div class="kmm-card-val <?php echo $high ? 'kmm-red' : 'kmm-green'; ?>">
					<?php echo esc_html( $high ); ?>
				</div>
				<div class="kmm-card-lbl">
					Above alert threshold<br>
					<small>(≥ <?php echo esc_html( $s['alert_threshold_mb'] ); ?> MB)</small>
				</div>
			</div>
			<?php endif; ?>
		</div>

		<p>
			<?php if ( ! empty( $s['log_all'] ) ) : ?>
				Logging <strong>every request</strong>.
			<?php else : ?>
				Logging requests &ge; <strong><?php echo esc_html( $s['threshold_mb'] ); ?> MB</strong>.
			<?php endif; ?>
			Keeps the last <?php echo self::MAX_LOG_ROWS; ?> entries.
			&nbsp;<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-settings' ) ); ?>">Change settings &rarr;</a>
		</p>

		<form method="post" style="margin-bottom:16px;">
			<?php wp_nonce_field( 'kmm_clear_log', 'kmm_nonce' ); ?>
			<button type="submit" name="kmm_clear" class="button button-secondary"
				onclick="return confirm('Clear the entire log?');">
				Clear Log
			</button>
		</form>

		<?php if ( empty( $log ) ) : ?>
			<p>Nothing logged yet.</p>
		<?php else : ?>
		<table class="widefat striped" style="font-size:13px;">
			<thead>
				<tr>
					<th>Date / Time</th>
					<th>Type</th>
					<th>Request URI</th>
					<th>Peak Memory</th>
					<th>PHP Limit</th>
					<th>Top Plugin Deltas <small>(at load time)</small></th>
					<th>Theme &Delta;</th>
					<th>Lifecycle</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $log as $entry ) :
				$peak     = (float) ( $entry['peak_mb'] ?? 0 );
				$lim_str  = $entry['memory_limit'] ?? '256M';
				$lim_mb   = $this->limit_to_mb( $lim_str );
				$pct      = $lim_mb > 0 ? min( 100, round( ( $peak / $lim_mb ) * 100 ) ) : 0;
				$bar_col  = $pct >= 80 ? '#dc2626' : ( $pct >= 50 ? '#f59e0b' : '#22c55e' );
				$txt_cls  = $pct >= 80 ? 'kmm-red' : ( $pct >= 50 ? 'kmm-amber' : 'kmm-green' );
				$top5     = $this->top_plugins( $entry['plugin_deltas'] ?? [], 5 );
				$all_d    = $entry['plugin_deltas'] ?? [];
				arsort( $all_d );
				$rtype    = esc_html( $entry['request_type'] ?? 'unknown' );
			?>
			<tr>
				<td style="white-space:nowrap;"><?php echo esc_html( $entry['time'] ); ?></td>
				<td><span class="kmm-pill <?php echo esc_attr( $entry['request_type'] ?? '' ); ?>"><?php echo $rtype; ?></span></td>
				<td>
					<code style="word-break:break-all;display:block;max-width:230px;">
						<?php echo esc_html( $entry['request_uri'] ); ?>
					</code>
				</td>
				<td>
					<strong class="<?php echo esc_attr( $txt_cls ); ?>">
						<?php echo esc_html( $peak ); ?> MB
					</strong><br>
					<small><?php echo esc_html( $pct ); ?>% of limit</small><br>
					<div class="kmm-bar-track" style="margin-top:4px;">
						<div class="kmm-bar-fill" style="width:<?php echo esc_attr( $pct ); ?>%;background:<?php echo esc_attr( $bar_col ); ?>;"></div>
					</div>
				</td>
				<td><?php echo esc_html( $lim_str ); ?></td>
				<td style="min-width:210px;">
					<?php if ( ! empty( $top5 ) ) : ?>
						<ul class="kmm-dlist">
							<?php foreach ( $top5 as $file => $mb ) : ?>
								<li>
									<strong class="kmm-blue">+<?php echo esc_html( $mb ); ?>MB</strong>
									&nbsp;<span title="<?php echo esc_attr( $file ); ?>">
										<?php echo esc_html( $this->plugin_label( $file ) ); ?>
									</span>
								</li>
							<?php endforeach; ?>
						</ul>
						<?php if ( count( $all_d ) > 5 ) : ?>
						<details style="font-size:11px;margin-top:4px;">
							<summary>All <?php echo esc_html( count( $all_d ) ); ?> plugins</summary>
							<ul class="kmm-dlist" style="margin-top:6px;">
								<?php foreach ( $all_d as $file => $mb ) : ?>
									<li>
										<strong>+<?php echo esc_html( $mb ); ?>MB</strong>
										<?php echo esc_html( $file ); ?>
									</li>
								<?php endforeach; ?>
							</ul>
						</details>
						<?php endif; ?>
					<?php else : ?>
						<small style="color:#aaa;">—</small>
					<?php endif; ?>
				</td>
				<td>
					<?php if ( isset( $entry['theme_delta_mb'] ) ) : ?>
						<strong><?php echo esc_html( $entry['theme_delta_mb'] ); ?> MB</strong>
					<?php else : ?>
						<span style="color:#ccc;">—</span>
					<?php endif; ?>
				</td>
				<td>
					<?php if ( ! empty( $entry['lifecycle_mb'] ) ) : ?>
						<details>
							<summary style="cursor:pointer;font-size:12px;">View stages</summary>
							<table style="font-size:11px;margin-top:6px;border-collapse:collapse;">
								<?php foreach ( $entry['lifecycle_mb'] as $stage => $mb ) : ?>
									<tr>
										<td style="padding:1px 10px 1px 0;color:#666;">
											<?php echo esc_html( $stage ); ?>
										</td>
										<td><strong><?php echo esc_html( $mb ); ?> MB</strong></td>
									</tr>
								<?php endforeach; ?>
							</table>
						</details>
					<?php endif; ?>
				</td>
			</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
	</div>
	<?php
}

// =========================================================
// Page: Plugin Stats
// =========================================================

public function page_stats(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$log    = (array) get_option( self::OPTION_LOG, [] );
	$totals = [];

	foreach ( $log as $entry ) {
		foreach ( ( $entry['plugin_deltas'] ?? [] ) as $file => $mb ) {
			if ( ! isset( $totals[ $file ] ) ) {
				$totals[ $file ] = [ 'count' => 0, 'sum' => 0.0, 'max' => 0.0 ];
			}
			$totals[ $file ]['count']++;
			$totals[ $file ]['sum'] += (float) $mb;
			if ( (float) $mb > $totals[ $file ]['max'] ) {
				$totals[ $file ]['max'] = (float) $mb;
			}
		}
	}

	// Sort by average delta, highest first.
	uasort( $totals, static function ( $a, $b ) {
		$avg_a = $a['count'] ? $a['sum'] / $a['count'] : 0;
		$avg_b = $b['count'] ? $b['sum'] / $b['count'] : 0;
		return $avg_b <=> $avg_a;
	} );

	$max_avg = 0.001;
	foreach ( $totals as $d ) {
		$avg = $d['count'] ? $d['sum'] / $d['count'] : 0;
		if ( $avg > $max_avg ) {
			$max_avg = $avg;
		}
	}
	?>
	<div class="wrap kmm-wrap">
		<h1>🔌 Plugin Memory Stats</h1>

		<p>
			Aggregated load-time memory deltas across
			<strong><?php echo esc_html( count( $log ) ); ?></strong> logged request(s).<br>
			<em>Measured when each plugin file is <code>include</code>d.
			Plugins that defer work to <code>init</code> or later will show smaller deltas here
			but may still inflate peak memory — cross-reference with the lifecycle column in the log.</em>
		</p>

		<?php if ( empty( $totals ) ) : ?>
			<p>No data yet. Capture some requests in the log first.</p>
		<?php else : ?>
		<table class="widefat striped" style="font-size:13px;">
			<thead>
				<tr>
					<th>Plugin</th>
					<th>Seen in</th>
					<th>Avg load &Delta;</th>
					<th>Max load &Delta;</th>
					<th>Total load &Delta;</th>
					<th style="width:220px;">Relative footprint</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $totals as $file => $d ) :
				$avg   = $d['count'] ? round( $d['sum'] / $d['count'], 3 ) : 0;
				$color = $avg > 5  ? '#dc2626'
						: ( $avg > 2  ? '#d97706'
						: ( $avg > 0.5 ? '#2563eb' : '#16a34a' ) );
				$bar_w = (int) round( ( $avg / $max_avg ) * 200 );
			?>
			<tr>
				<td>
					<strong><?php echo esc_html( $this->plugin_label( $file ) ); ?></strong><br>
					<small style="color:#999;"><?php echo esc_html( $file ); ?></small>
				</td>
				<td><?php echo esc_html( $d['count'] ); ?></td>
				<td>
					<strong style="color:<?php echo esc_attr( $color ); ?>;">
						<?php echo esc_html( $avg ); ?> MB
					</strong>
				</td>
				<td><?php echo esc_html( round( $d['max'], 3 ) ); ?> MB</td>
				<td><?php echo esc_html( round( $d['sum'], 3 ) ); ?> MB</td>
				<td>
					<div style="background:#e5e7eb;border-radius:4px;height:12px;width:200px;">
						<div style="
							width:<?php echo esc_attr( min( 200, $bar_w ) ); ?>px;
							background:<?php echo esc_attr( $color ); ?>;
							height:12px; border-radius:4px;">
						</div>
					</div>
				</td>
			</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
	</div>
	<?php
}

// =========================================================
// Page: Settings
// =========================================================

public function page_settings(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$s = $this->get_settings();
	$n = self::OPTION_SETTINGS; // name attribute shorthand
	?>
	<div class="wrap kmm-wrap">
		<h1>⚙️ Memory Monitor — Settings</h1>

		<form method="post" action="options.php">
			<?php settings_fields( 'kmm_group' ); ?>

			<h2 style="margin-top:24px;">General</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="kmm_enabled">Enable monitoring</label></th>
					<td>
						<input type="checkbox" id="kmm_enabled"
							name="<?php echo esc_attr( $n ); ?>[enabled]"
							value="1" <?php checked( ! empty( $s['enabled'] ) ); ?> />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="kmm_log_all">Log every request</label></th>
					<td>
						<input type="checkbox" id="kmm_log_all"
							name="<?php echo esc_attr( $n ); ?>[log_all]"
							value="1" <?php checked( ! empty( $s['log_all'] ) ); ?> />
						<p class="description">
							Captures all requests regardless of memory usage.
							Good for short diagnosis sessions — the log rotates at
							<?php echo self::MAX_LOG_ROWS; ?> entries.
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="kmm_threshold">Log threshold (MB)</label></th>
					<td>
						<input type="number" id="kmm_threshold" min="16" step="1" style="width:90px;"
							name="<?php echo esc_attr( $n ); ?>[threshold_mb]"
							value="<?php echo esc_attr( $s['threshold_mb'] ); ?>" />
						<p class="description">
							Used when "Log every request" is <strong>off</strong>.
							Only requests whose peak memory meets or exceeds this value are logged.
						</p>
					</td>
				</tr>
			</table>

			<hr>
			<h2>📧 Email Alerts</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="kmm_email_on">Enable email alerts</label></th>
					<td>
						<input type="checkbox" id="kmm_email_on"
							name="<?php echo esc_attr( $n ); ?>[email_enabled]"
							value="1" <?php checked( ! empty( $s['email_enabled'] ) ); ?> />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="kmm_emails">Alert recipients</label></th>
					<td>
						<input type="text" id="kmm_emails" class="large-text"
							name="<?php echo esc_attr( $n ); ?>[alert_emails]"
							value="<?php echo esc_attr( $s['alert_emails'] ); ?>"
							placeholder="you@example.com, dev@example.com" />
						<p class="description">Comma-separated list of email addresses.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="kmm_alert_mb">Alert threshold (MB)</label></th>
					<td>
						<input type="number" id="kmm_alert_mb" min="16" step="1" style="width:90px;"
							name="<?php echo esc_attr( $n ); ?>[alert_threshold_mb]"
							value="<?php echo esc_attr( $s['alert_threshold_mb'] ); ?>" />
						<p class="description">
							Send an alert when peak memory reaches or exceeds this value.
							Can be set higher than the log threshold.
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="kmm_cooldown">Cooldown between alerts</label></th>
					<td>
						<input type="number" id="kmm_cooldown" min="1" step="1" style="width:90px;"
							name="<?php echo esc_attr( $n ); ?>[alert_cooldown_min]"
							value="<?php echo esc_attr( $s['alert_cooldown_min'] ); ?>" />
						<span> minutes</span>
						<p class="description">
							Prevents alert flooding during sustained high-memory periods.
							One email will be sent per cooldown window. Default: 60 minutes.
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( 'Save Settings' ); ?>
		</form>
	</div>
	<?php
}

// =========================================================
// Helpers
// =========================================================

/**
 * Return the top $limit plugins sorted by memory delta, highest first.
 *
 * @param  array<string,float> $deltas
 * @param  int                 $limit
 * @return array<string,float>
 */
private function top_plugins( array $deltas, int $limit ): array {
	arsort( $deltas );
	return array_slice( $deltas, 0, $limit, true );
}

/**
 * Turn "woocommerce/woocommerce.php" → "Woocommerce".
 */
private function plugin_label( string $path ): string {
	$parts = explode( '/', $path );
	$slug  = count( $parts ) > 1
		? $parts[0]
		: pathinfo( $parts[0], PATHINFO_FILENAME );

	return ucwords( str_replace( [ '-', '_' ], ' ', $slug ) );
}

/**
 * Parse a PHP memory_limit string ("256M", "1G", "512K") to megabytes.
 */
private function limit_to_mb( string $raw ): float {
	if ( ! preg_match( '/^(\d+)\s*(G|M|K)?$/i', trim( $raw ), $m ) ) {
		return 256.0;
	}

	$val  = (float) $m[1];
	$unit = strtoupper( $m[2] ?? 'M' );

	if ( 'G' === $unit ) {
		return $val * 1024;
	}
	if ( 'K' === $unit ) {
		return $val / 1024;
	}

	return $val;
}

/**
 * Sanitise and validate a comma-separated list of email addresses.
 *
 * @return string[]
 */
private function parse_emails( string $raw ): array {
	return array_values(
		array_filter(
			array_map( 'sanitize_email', array_map( 'trim', explode( ',', $raw ) ) )
		)
	);
}

private function request_type(): string {
	if ( defined( 'DOING_CRON' )  && DOING_CRON )                     return 'cron';
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST )                    return 'rest';
	if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )       return 'ajax';
	if ( is_admin() )                                                    return 'admin';
	return 'frontend';
}

private function memory_limit(): string {
	return ini_get( 'memory_limit' ) ?: 'unknown';
}
}

new Konclude_Memory_Monitor();