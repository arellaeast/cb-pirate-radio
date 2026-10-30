<?php
defined( 'ABSPATH' ) || exit;

/**
 * Minutely cron: claim 'approved' and 'retiring' rows and push them to
 * AzuraCast directly via CB_Radio_AzuraCast. No external workflow tool —
 * this is the entire handoff, synchronous, in one PHP process.
 *
 * A small batch per tick (not "all pending at once") keeps each cron run
 * short on modest hosting, since uploads can take real seconds each.
 * Failures increment an attempts counter on the row rather than retrying
 * forever; after MAX_ATTEMPTS a row moves to 'failed' with the error message
 * visible in the moderation queue, and re-approving it resets the counter.
 *
 * "Retiring" is non-destructive: it takes a track out of rotation on
 * AzuraCast but leaves the actual file there — see
 * CB_Radio_AzuraCast::unassign_playlists(). That's what makes it safe for
 * CB_Radio_MyTracks to let artists trigger it on their own live tracks.
 */
class CB_Radio_Queue {

	const BATCH_SIZE   = 3;
	const MAX_ATTEMPTS = 5;

	public static function init() {
		add_action( 'cb_radio_process_queue', [ __CLASS__, 'process' ] );
	}

	public static function process() {
		global $wpdb;
		$settings = CB_Radio_Helpers::settings();

		if ( empty( $settings['azuracast_url'] ) || empty( $settings['azuracast_key'] ) ) {
			return; // Not configured yet.
		}

		$rows = $wpdb->get_results(
			'SELECT * FROM ' . CB_Radio_Helpers::table()
			. " WHERE status IN ('approved','retiring') ORDER BY reviewed_at ASC LIMIT " . self::BATCH_SIZE
		);

		foreach ( $rows as $row ) {
			if ( 'approved' === $row->status ) {
				self::handle_approved( $row );
			} else {
				self::handle_retiring( $row );
			}
		}
	}

	private static function handle_approved( $row ) {
		global $wpdb;

		$channel = CB_Radio_Helpers::channel( $row->channel_slug );
		if ( ! $channel || empty( $channel['station_id'] ) ) {
			self::fail_or_retry( $row, __( 'Channel is missing or has no AzuraCast station_id configured.', 'cb-radio' ) );
			return;
		}

		$path = trailingslashit( CB_Radio_Helpers::protected_dir() ) . basename( $row->file_path );
		$ext  = pathinfo( $row->file_path, PATHINFO_EXTENSION ) ?: 'mp3';
		// Namespaced + unique so re-approving a previously-failed row can't
		// collide with a partial upload left over from an earlier attempt.
		$remote_filename = 'sub-' . $row->id . '-' . substr( md5( $row->file_path ), 0, 8 ) . '.' . $ext;

		$uploaded = CB_Radio_AzuraCast::upload( $channel['station_id'], $path, $remote_filename );
		if ( is_wp_error( $uploaded ) ) {
			self::fail_or_retry( $row, 'Upload failed: ' . $uploaded->get_error_message() );
			return;
		}
		$file_id = $uploaded['id'] ?? null;
		if ( ! $file_id ) {
			self::fail_or_retry( $row, 'Upload succeeded but AzuraCast did not return a file ID.' );
			return;
		}

		$artist      = CB_Radio_Artists::payload( $row->artist_post_id );
		$playlist_id = 'track' === $row->submission_type
			? ( $channel['music_playlist_id'] ?? 0 )
			: ( $channel['interstitial_playlist_id'] ?? 0 );

		$meta = CB_Radio_AzuraCast::update_metadata(
			$channel['station_id'],
			$file_id,
			$row->track_title,
			$artist['name'] ?? 'Unknown',
			'Captain Baltimore Radio',
			$playlist_id
		);
		if ( is_wp_error( $meta ) ) {
			// File is up but metadata/playlist assignment didn't take — still
			// record the file ID so a retry updates it in place rather than
			// uploading a duplicate copy.
			$wpdb->update( CB_Radio_Helpers::table(), [
				'azuracast_media_id'   => $file_id,
				'azuracast_station_id' => $channel['station_id'],
			], [ 'id' => $row->id ] );
			self::fail_or_retry( $row, 'Uploaded, but setting metadata/playlist failed: ' . $meta->get_error_message() );
			return;
		}

		$wpdb->update( CB_Radio_Helpers::table(), [
			'status'               => 'live',
			'azuracast_media_id'   => $file_id,
			'azuracast_station_id' => $channel['station_id'],
			'review_notes'         => null,
			'attempts'             => 0,
		], [ 'id' => $row->id ] );
	}

	private static function handle_retiring( $row ) {
		global $wpdb;

		if ( ! $row->azuracast_station_id || ! $row->azuracast_media_id ) {
			// Nothing was ever actually uploaded (failed before getting a
			// file ID) — there's nothing to unassign, so just finish retiring.
			$wpdb->update( CB_Radio_Helpers::table(), [ 'status' => 'retired' ], [ 'id' => $row->id ] );
			return;
		}

		// Deliberately non-destructive: takes the track out of rotation but
		// leaves the file sitting in AzuraCast. Reversible, and safe for an
		// artist to trigger on their own tracks without risking anything.
		$result = CB_Radio_AzuraCast::unassign_playlists( $row->azuracast_station_id, $row->azuracast_media_id );
		if ( is_wp_error( $result ) ) {
			self::fail_or_retry( $row, 'Take-down failed: ' . $result->get_error_message(), 'retiring' );
			return;
		}
		$wpdb->update( CB_Radio_Helpers::table(), [
			'status'       => 'retired',
			'review_notes' => null,
			'attempts'     => 0,
		], [ 'id' => $row->id ] );
	}

	/** Record the error; after MAX_ATTEMPTS, stop retrying and surface it in the queue. */
	private static function fail_or_retry( $row, $message, $retry_status = 'approved' ) {
		global $wpdb;
		$attempts = (int) $row->attempts + 1;
		error_log( '[cb-radio] submission ' . $row->id . ': ' . $message ); // phpcs:ignore -- self-hoster diagnostic trail

		$wpdb->update( CB_Radio_Helpers::table(), [
			'attempts'     => $attempts,
			'review_notes' => $message,
			'status'       => $attempts >= self::MAX_ATTEMPTS ? 'failed' : $retry_status,
		], [ 'id' => $row->id ] );
	}
}
