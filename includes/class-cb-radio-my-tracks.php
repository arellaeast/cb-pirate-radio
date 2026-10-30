<?php
defined( 'ABSPATH' ) || exit;

/**
 * [cb_radio_my_tracks] — lets an artist see everything they've submitted
 * and manage it themselves:
 *   - Withdraw: pull a pending/approved/rejected/failed submission. Nothing
 *     is airing yet, so this just removes the WP record (and the local
 *     file). If AzuraCast somehow already has a partial upload (a failed
 *     row that got as far as uploading before a later step broke), we
 *     best-effort unassign it there too so nothing orphaned keeps existing.
 *   - Take down: pull a LIVE track out of rotation. This reuses the same
 *     'retiring' queue path an admin's Retire button uses — non-destructive,
 *     the file stays in AzuraCast, just stops being scheduled to play.
 *
 * Every action re-verifies the submission belongs to the current user's
 * artist profile before touching anything — this is the only place in the
 * plugin where a non-moderator can change another row's status, so the
 * ownership check is load-bearing, not a formality.
 */
class CB_Radio_MyTracks {

	public static function init() {
		add_shortcode( 'cb_radio_my_tracks', [ __CLASS__, 'shortcode' ] );
		add_action( 'admin_post_cb_radio_self_action', [ __CLASS__, 'handle_action' ] );
	}

	public static function shortcode() {
		if ( ! is_user_logged_in() ) {
			return '<p>' . sprintf(
				/* translators: %s: login url */
				esc_html__( 'Log in to see your tracks. %s', 'cb-radio' ),
				'<a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'Log in', 'cb-radio' ) . '</a>'
			) . '</p>';
		}

		wp_enqueue_style( 'cb-radio-tuner' );

		$artist = CB_Radio_Artists::for_user( get_current_user_id() );
		if ( ! $artist ) {
			return '<p>' . esc_html__( 'You haven\'t submitted anything yet.', 'cb-radio' ) . '</p>';
		}

		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . CB_Radio_Helpers::table() . ' WHERE artist_post_id = %d ORDER BY submitted_at DESC',
			$artist->ID
		) );

		ob_start();
		include CB_RADIO_DIR . 'templates/my-tracks.php';
		return ob_get_clean();
	}

	public static function handle_action() {
		if ( ! is_user_logged_in() ) {
			wp_die( __( 'Log in first.', 'cb-radio' ) );
		}

		$id     = absint( $_GET['id'] ?? 0 );
		$action = sanitize_key( $_GET['do'] ?? '' );
		check_admin_referer( 'cb_radio_self_' . $action . '_' . $id );

		$artist = CB_Radio_Artists::for_user( get_current_user_id() );
		$row    = CB_Radio_Helpers::get_submission( $id );

		// Ownership check — the entire security boundary for this endpoint.
		if ( ! $artist || ! $row || (int) $row->artist_post_id !== $artist->ID ) {
			wp_die( __( 'That\'s not your track.', 'cb-radio' ) );
		}

		if ( 'withdraw' === $action ) {
			self::withdraw( $row );
		} elseif ( 'takedown' === $action ) {
			self::takedown( $row );
		}

		wp_safe_redirect( add_query_arg( 'cb_radio_updated', 1, wp_get_referer() ?: home_url( '/' ) ) );
		exit;
	}

	/** Pull something that never made it to air (or died trying). */
	private static function withdraw( $row ) {
		if ( ! in_array( $row->status, [ 'pending', 'approved', 'rejected', 'failed' ], true ) ) {
			return; // Only takedown() may touch a 'live' track.
		}
		global $wpdb;

		// A 'failed' row can have gotten as far as uploading before a later
		// step (metadata/playlist) broke — best-effort clean that up too so
		// nothing orphaned lingers in AzuraCast. Not fatal if this fails;
		// the WP record is what the artist actually asked to remove.
		if ( $row->azuracast_station_id && $row->azuracast_media_id ) {
			CB_Radio_AzuraCast::unassign_playlists( $row->azuracast_station_id, $row->azuracast_media_id );
		}

		$path = trailingslashit( CB_Radio_Helpers::protected_dir() ) . basename( $row->file_path );
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}

		$wpdb->delete( CB_Radio_Helpers::table(), [ 'id' => $row->id ] );
	}

	/** Pull a live track out of rotation — the file stays in AzuraCast. */
	private static function takedown( $row ) {
		if ( 'live' !== $row->status ) {
			return;
		}
		global $wpdb;
		$wpdb->update( CB_Radio_Helpers::table(), [
			'status'       => 'retiring',
			'reviewed_at'  => current_time( 'mysql', true ),
			'review_notes' => null,
			'attempts'     => 0,
		], [ 'id' => $row->id, 'status' => 'live' ] );
		// Picked up by the same cron tick that handles admin-triggered retires.
	}
}
