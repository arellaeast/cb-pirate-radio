<?php
defined( 'ABSPATH' ) || exit;

class CB_Radio_Install {

	public static function activate() {
		self::create_table();
		CB_Radio_Charts::create_table();
		self::create_role();
		self::create_protected_dir();
		update_option( 'cb_radio_db_version', CB_RADIO_VERSION );

		if ( ! wp_next_scheduled( 'cb_radio_process_queue' ) ) {
			wp_schedule_event( time() + 60, 'cb_radio_minutely', 'cb_radio_process_queue' );
		}
		CB_Radio_Reconciler::ensure_scheduled();
		flush_rewrite_rules();
	}

	/** dbDelta is idempotent, so upgrades just re-run the schema. */
	public static function upgrade() {
		self::create_table();
		CB_Radio_Charts::create_table();
		update_option( 'cb_radio_db_version', CB_RADIO_VERSION );
		// Newer versions can bring new recurring work; make sure it is on
		// the calendar without waiting for a re-activation.
		CB_Radio_Reconciler::ensure_scheduled();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'cb_radio_process_queue' );
		wp_clear_scheduled_hook( CB_Radio_Reconciler::HOOK );
		flush_rewrite_rules();
	}

	private static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $wpdb->prefix . CB_RADIO_TABLE;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			artist_post_id BIGINT UNSIGNED NOT NULL,
			track_title VARCHAR(191) NOT NULL,
			channel_slug VARCHAR(64) NOT NULL,
			submission_type VARCHAR(20) NOT NULL DEFAULT 'track',
			file_path VARCHAR(255) NOT NULL,
			art_attachment_id BIGINT UNSIGNED NULL,
			streaming_link VARCHAR(500) NULL,
			duration_secs SMALLINT UNSIGNED NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			azuracast_media_id VARCHAR(64) NULL,
			azuracast_station_id SMALLINT NULL,
			license_accepted TINYINT(1) NOT NULL DEFAULT 0,
			license_text_version VARCHAR(20) NOT NULL DEFAULT '',
			reviewer_id BIGINT UNSIGNED NULL,
			review_notes TEXT NULL,
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			submitted_at DATETIME NOT NULL,
			reviewed_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY status_channel (status, channel_slug)
		) {$charset};";

		dbDelta( $sql );
	}

	private static function create_role() {
		add_role( 'cb_artist', __( 'CB Radio Artist', 'cb-radio' ), [
			'read'                 => true,
			'cb_submit_radio'      => true,
		] );
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( 'cb_moderate_radio' );
			$admin->add_cap( 'cb_submit_radio' );
		}
	}

	private static function create_protected_dir() {
		$dir = CB_Radio_Helpers::protected_dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		// Apache deny rule. TODO: nginx users add a matching `location` deny block.
		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Require all denied\n" );
		}
		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php // silence\n" );
		}
	}
}

// One-minute cron interval for the handoff queue.
add_filter( 'cron_schedules', function ( $schedules ) {
	$schedules['cb_radio_minutely'] = [
		'interval' => 60,
		'display'  => __( 'Every Minute (CB Radio)', 'cb-radio' ),
	];
	$schedules['cb_radio_quarter_hourly'] = [
		'interval' => 900,
		'display'  => __( 'Every 15 Minutes (CB Radio)', 'cb-radio' ),
	];
	return $schedules;
} );
