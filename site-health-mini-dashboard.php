<?php
/**
 * Plugin Name:       Site Health Mini Dashboard
 * Plugin URI:        https://github.com/Aliakbar45480/site-health-mini-dashboard
 * Description:       One dashboard widget that shows what needs attention on a WordPress site: pending updates, heavy images, missing alt text, inactive plugins, PHP version, SSL and debug mode — with a simple health score.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Ali Akbar (BD Webhut)
 * Author URI:        https://github.com/Aliakbar45480
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       site-health-mini
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SHMD_Dashboard {

	const CACHE       = 'shmd_report';
	const HEAVY_BYTES = 307200; // 300 KB.
	const SCAN_LIMIT  = 400;    // Newest images scanned.

	public static function init() {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register_widget' ) );
		add_action( 'admin_post_shmd_refresh', array( __CLASS__, 'refresh' ) );
		foreach ( array( 'upgrader_process_complete', 'activated_plugin', 'deactivated_plugin', 'add_attachment', 'delete_attachment' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush' ) );
		}
	}

	public static function flush() {
		delete_transient( self::CACHE );
	}

	public static function register_widget() {
		if ( current_user_can( 'manage_options' ) ) {
			wp_add_dashboard_widget( 'shmd_widget', '🩺 Site Health Mini Dashboard', array( __CLASS__, 'render' ) );
		}
	}

	public static function refresh() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'shmd_refresh' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'site-health-mini' ) );
		}
		self::flush();
		wp_safe_redirect( admin_url( 'index.php#shmd_widget' ) );
		exit;
	}

	/** Build (or read cached) report. */
	public static function report() {
		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$plugin_updates = get_site_transient( 'update_plugins' );
		$theme_updates  = get_site_transient( 'update_themes' );
		$core           = get_core_updates();
		$core_update    = is_array( $core ) && isset( $core[0]->response ) && 'upgrade' === $core[0]->response;

		$all_plugins = get_plugins();
		$inactive    = 0;
		foreach ( array_keys( $all_plugins ) as $file ) {
			if ( ! is_plugin_active( $file ) ) {
				$inactive++;
			}
		}

		// Media scan: newest images only, to stay fast on big libraries.
		$ids   = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'post_status'    => 'inherit',
				'posts_per_page' => self::SCAN_LIMIT,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);
		$heavy      = array();
		$no_alt     = 0;
		$heavy_size = 0;
		foreach ( $ids as $id ) {
			$file = get_attached_file( $id );
			$size = ( $file && file_exists( $file ) ) ? filesize( $file ) : 0;
			if ( $size > self::HEAVY_BYTES ) {
				$heavy_size += $size;
				$heavy[]     = array( 'id' => $id, 'size' => $size, 'name' => wp_basename( $file ) );
			}
			if ( '' === trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ) {
				$no_alt++;
			}
		}
		usort( $heavy, function ( $a, $b ) { return $b['size'] <=> $a['size']; } );

		$r = array(
			'time'           => time(),
			'wp'             => get_bloginfo( 'version' ),
			'php'            => PHP_VERSION,
			'core_update'    => $core_update,
			'plugin_updates' => ( is_object( $plugin_updates ) && ! empty( $plugin_updates->response ) ) ? count( $plugin_updates->response ) : 0,
			'theme_updates'  => ( is_object( $theme_updates ) && ! empty( $theme_updates->response ) ) ? count( $theme_updates->response ) : 0,
			'plugins'        => count( $all_plugins ),
			'inactive'       => $inactive,
			'images'         => count( $ids ),
			'heavy'          => array_slice( $heavy, 0, 5 ),
			'heavy_count'    => count( $heavy ),
			'heavy_size'     => $heavy_size,
			'no_alt'         => $no_alt,
			'ssl'            => is_ssl() || 0 === strpos( home_url(), 'https://' ),
			'debug'          => defined( 'WP_DEBUG' ) && WP_DEBUG && ( ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY ),
			'search_off'     => '0' === (string) get_option( 'blog_public' ),
		);
		$r['score'] = self::score( $r );
		set_transient( self::CACHE, $r, 12 * HOUR_IN_SECONDS );
		return $r;
	}

	private static function score( $r ) {
		$s  = 100;
		$s -= $r['core_update'] ? 15 : 0;
		$s -= min( 25, $r['plugin_updates'] * 5 );
		$s -= min( 10, $r['theme_updates'] * 5 );
		$s -= min( 10, $r['inactive'] * 2 );
		$s -= min( 15, $r['heavy_count'] );
		$s -= $r['images'] ? (int) round( 10 * $r['no_alt'] / $r['images'] ) : 0;
		$s -= version_compare( $r['php'], '8.1', '<' ) ? 10 : 0;
		$s -= $r['ssl'] ? 0 : 15;
		$s -= $r['debug'] ? 10 : 0;
		return max( 0, $s );
	}

	private static function row( $ok, $label, $value, $link = '' ) {
		$icon = 'ok' === $ok ? '✅' : ( 'warn' === $ok ? '⚠️' : '❌' );
		echo '<li class="shmd-' . esc_attr( $ok ) . '"><span>' . $icon . ' ' . esc_html( $label ) . '</span><span>';
		echo $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $value ) . '</a>' : esc_html( $value );
		echo '</span></li>';
	}

	public static function render() {
		$r     = self::report();
		$color = $r['score'] >= 80 ? '#16a34a' : ( $r['score'] >= 60 ? '#d97706' : '#dc2626' );
		$upd   = $r['plugin_updates'] + $r['theme_updates'] + ( $r['core_update'] ? 1 : 0 );
		?>
		<style>
			.shmd-top{display:flex;align-items:center;gap:16px;margin-bottom:12px}
			.shmd-score{width:74px;height:74px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:700;color:#fff;flex:none}
			.shmd-list{margin:0;border-top:1px solid #eee}.shmd-list li{display:flex;justify-content:space-between;gap:10px;padding:7px 0;margin:0;border-bottom:1px solid #f0f0f0}
			.shmd-list li span:last-child{font-weight:600;text-align:right}
			.shmd-warn span:last-child{color:#b45309}.shmd-bad span:last-child{color:#b91c1c}
			.shmd-heavy{margin:10px 0 0;font-size:12px;color:#555}.shmd-heavy li{margin:2px 0}
			.shmd-foot{display:flex;justify-content:space-between;align-items:center;margin-top:12px;color:#777;font-size:12px}
		</style>
		<div class="shmd-top">
			<div class="shmd-score" style="background:<?php echo esc_attr( $color ); ?>"><?php echo (int) $r['score']; ?></div>
			<div><strong><?php echo esc_html( $r['score'] >= 80 ? 'Looking healthy' : ( $r['score'] >= 60 ? 'A few things need attention' : 'Needs attention soon' ) ); ?></strong><br />
			WordPress <?php echo esc_html( $r['wp'] ); ?> · PHP <?php echo esc_html( $r['php'] ); ?></div>
		</div>
		<ul class="shmd-list">
			<?php
			self::row( $upd ? 'warn' : 'ok', 'Pending updates', $upd ? sprintf( '%d (core %s, plugins %d, themes %d)', $upd, $r['core_update'] ? 'yes' : 'no', $r['plugin_updates'], $r['theme_updates'] ) : 'All up to date', $upd ? admin_url( 'update-core.php' ) : '' );
			self::row( $r['inactive'] ? 'warn' : 'ok', 'Inactive plugins', $r['inactive'] ? $r['inactive'] . ' of ' . $r['plugins'] . ' — delete if unused' : 'None', $r['inactive'] ? admin_url( 'plugins.php?plugin_status=inactive' ) : '' );
			self::row( $r['heavy_count'] ? 'warn' : 'ok', 'Heavy images (>300 KB)', $r['heavy_count'] ? $r['heavy_count'] . ' · ' . size_format( $r['heavy_size'] ) : 'None', $r['heavy_count'] ? admin_url( 'upload.php?mode=list' ) : '' );
			self::row( $r['no_alt'] ? 'warn' : 'ok', 'Images missing alt text', $r['no_alt'] ? $r['no_alt'] . ' of ' . $r['images'] : 'None' );
			self::row( version_compare( $r['php'], '8.1', '<' ) ? 'warn' : 'ok', 'PHP version', version_compare( $r['php'], '8.1', '<' ) ? $r['php'] . ' — ask host to upgrade' : $r['php'] );
			self::row( $r['ssl'] ? 'ok' : 'bad', 'HTTPS / SSL', $r['ssl'] ? 'Active' : 'Not active' );
			self::row( $r['debug'] ? 'bad' : 'ok', 'Debug output on live site', $r['debug'] ? 'ON — turn off WP_DEBUG_DISPLAY' : 'Off' );
			self::row( $r['search_off'] ? 'bad' : 'ok', 'Search engines', $r['search_off'] ? 'Blocked (Settings → Reading)' : 'Allowed', $r['search_off'] ? admin_url( 'options-reading.php' ) : '' );
			?>
		</ul>
		<?php if ( $r['heavy'] ) : ?>
			<ol class="shmd-heavy">
				<?php foreach ( $r['heavy'] as $h ) : ?>
					<li><a href="<?php echo esc_url( get_edit_post_link( $h['id'] ) ); ?>"><?php echo esc_html( $h['name'] ); ?></a> — <?php echo esc_html( size_format( $h['size'] ) ); ?></li>
				<?php endforeach; ?>
			</ol>
			<p class="shmd-heavy">Tip: compress them for free with <a href="https://aliakbar45480.github.io/squeezy-image-compressor/" target="_blank" rel="noopener">Squeezy</a>.</p>
		<?php endif; ?>
		<div class="shmd-foot">
			<span>Checked <?php echo esc_html( human_time_diff( $r['time'] ) ); ?> ago · newest <?php echo (int) self::SCAN_LIMIT; ?> images scanned</span>
			<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=shmd_refresh' ), 'shmd_refresh' ) ); ?>">Re-check</a>
		</div>
		<?php
	}
}

add_action( 'plugins_loaded', array( 'SHMD_Dashboard', 'init' ) );
