<?php
defined( 'ABSPATH' ) || exit;

/**
 * Settings screen under the "Pirate Radio" menu.
 * v0.1: channel map is edited as JSON for speed. TODO: replace with a repeater UI.
 * v0.8: no more n8n/shared-secret fields — AzuraCast URL + API key are the
 * only integration settings, since WordPress talks to AzuraCast directly.
 */
class CB_Radio_Settings {

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 20 );
		add_action( 'admin_post_cb_radio_save_settings', [ __CLASS__, 'save' ] );
		add_action( 'admin_post_cb_radio_test_connection', [ __CLASS__, 'test_connection' ] );
	}

	public static function menu() {
		add_submenu_page(
			'cb-pirate-radio',
			__( 'Settings', 'cb-radio' ),
			__( 'Settings', 'cb-radio' ),
			'manage_options',
			'cb-radio-settings',
			[ __CLASS__, 'render' ]
		);
	}

	public static function render() {
		$s = CB_Radio_Helpers::settings();
		?>
		<div class="wrap">
			<h1>📻 Pirate Radio Settings</h1>
			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Settings saved.', 'cb-radio' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['cb_test'] ) ) : ?>
				<?php if ( 'ok' === $_GET['cb_test'] ) : ?>
					<div class="notice notice-success"><p>
						✅ <?php esc_html_e( 'Connected!', 'cb-radio' ); ?>
						<?php
						$stations = isset( $_GET['stations'] ) ? sanitize_text_field( wp_unslash( $_GET['stations'] ) ) : '';
						if ( $stations ) {
							printf(
								/* translators: %s: comma-separated station names */
								esc_html__( 'Your API key can see: %s', 'cb-radio' ),
								esc_html( $stations )
							);
						}
						?>
					</p></div>
				<?php else : ?>
					<div class="notice notice-error"><p>
						❌ <?php esc_html_e( 'Connection failed:', 'cb-radio' ); ?>
						<?php echo esc_html( isset( $_GET['error'] ) ? wp_unslash( $_GET['error'] ) : '' ); ?>
					</p></div>
				<?php endif; ?>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cb_radio_save_settings">
				<?php wp_nonce_field( 'cb_radio_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="azuracast_url">AzuraCast Base URL</label></th>
						<td><input name="azuracast_url" id="azuracast_url" type="url" class="regular-text" required
							value="<?php echo esc_attr( $s['azuracast_url'] ); ?>" placeholder="https://radio.captainbaltimore.com">
							<p class="description"><?php esc_html_e( 'Your AzuraCast install\'s URL — no trailing slash needed.', 'cb-radio' ); ?></p></td>
					</tr>
					<tr>
						<th><label for="azuracast_key">AzuraCast API Key</label></th>
						<td><input name="azuracast_key" id="azuracast_key" type="password" class="regular-text" required
							value="<?php echo esc_attr( $s['azuracast_key'] ); ?>" autocomplete="new-password">
							<p class="description">
								<?php esc_html_e( 'Server-side only, never shown to visitors. In AzuraCast: click your username (top right) → My API Keys → Generate New Key. Give it permission to manage station media.', 'cb-radio' ); ?>
							</p></td>
					</tr>
					<tr>
						<th></th>
						<td>
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cb_radio_test_connection' ), 'cb_radio_test_connection' ) ); ?>" class="button">
								<?php esc_html_e( 'Test Connection', 'cb-radio' ); ?>
							</a>
							<p class="description"><?php esc_html_e( 'Save your settings first, then use this to confirm WordPress can reach AzuraCast before submitting a real track.', 'cb-radio' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="max_file_mb">Max File Size (MB)</label></th>
						<td><input name="max_file_mb" id="max_file_mb" type="number" min="1" max="200"
							value="<?php echo esc_attr( $s['max_file_mb'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="max_duration">Max Duration (seconds)</label></th>
						<td><input name="max_duration" id="max_duration" type="number" min="10"
							value="<?php echo esc_attr( $s['max_duration'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="license_version">License Version</label></th>
						<td><input name="license_version" id="license_version" type="text" class="small-text"
							value="<?php echo esc_attr( $s['license_version'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="license_text">License Text</label></th>
						<td><textarea name="license_text" id="license_text" class="large-text" rows="3"><?php echo esc_textarea( $s['license_text'] ); ?></textarea></td>
					</tr>
					<tr>
						<th><label for="channels">Channel Map (JSON)</label></th>
						<td>
							<textarea name="channels" id="channels" class="large-text code" rows="10"><?php echo esc_textarea( wp_json_encode( $s['channels'], JSON_PRETTY_PRINT ) ); ?></textarea>
							<p class="description"><code>station_id</code> is the numeric ID from your AzuraCast station's URL (e.g. <code>/station/3/...</code> → <code>3</code>). <code>music_playlist_id</code> / <code>interstitial_playlist_id</code> come from that station's Playlists page. <code>stream_url</code> accepts MP3, AAC, OGG, or HLS (.m3u8) — the player auto-detects the format. <code>freq</code> is the big digital readout number (e.g. "94.7") — auto-generated if omitted. <code>preset</code> is the station-button slot, 1–6 — the first six channels without one get auto-assigned in order. Example:</p>
<pre>[
  { "slug": "bmore-club", "label": "Baltimore Club", "station_id": 1,
    "music_playlist_id": 1, "interstitial_playlist_id": 2,
    "stream_url": "https://radio.captainbaltimore.com/listen/bmore_club/radio.mp3",
    "freq": "87.90", "preset": 1 }
]</pre>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Settings', 'cb-radio' ) ); ?>
			</form>
		</div>
		<?php
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Nope.' );
		}
		check_admin_referer( 'cb_radio_settings' );

		$channels = json_decode( wp_unslash( $_POST['channels'] ?? '[]' ), true );

		update_option( CB_Radio_Helpers::OPT, [
			'azuracast_url'   => esc_url_raw( wp_unslash( $_POST['azuracast_url'] ?? '' ) ),
			'azuracast_key'   => sanitize_text_field( wp_unslash( $_POST['azuracast_key'] ?? '' ) ),
			'max_file_mb'     => absint( $_POST['max_file_mb'] ?? 25 ),
			'max_duration'    => absint( $_POST['max_duration'] ?? 600 ),
			'license_version' => sanitize_text_field( wp_unslash( $_POST['license_version'] ?? 'v1' ) ),
			'license_text'    => sanitize_textarea_field( wp_unslash( $_POST['license_text'] ?? '' ) ),
			'channels'        => is_array( $channels ) ? $channels : [],
		] );

		wp_safe_redirect( add_query_arg( 'saved', 1, admin_url( 'admin.php?page=cb-radio-settings' ) ) );
		exit;
	}

	/** "Test Connection" button — confirms the URL + API key actually work before anyone submits a real track. */
	public static function test_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Nope.' );
		}
		check_admin_referer( 'cb_radio_test_connection' );

		$result = CB_Radio_AzuraCast::test_connection();
		$args   = [ 'page' => 'cb-radio-settings' ];

		if ( is_wp_error( $result ) ) {
			$args['cb_test'] = 'fail';
			$args['error']   = $result->get_error_message();
		} else {
			$args['cb_test']  = 'ok';
			$args['stations'] = $result ? implode( ', ', $result ) : __( '(no stations visible to this key)', 'cb-radio' );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
