<?php
defined( 'ABSPATH' ) || exit;

/**
 * Voting reconciler: keeps cb_radio_submissions in step with AzuraCast.
 *
 * The now-playing endpoint (CB_Radio_Rest) resolves the playing track back
 * to a submission row by exact title + artist match, and the deck only
 * enables the vote buttons when that resolution succeeds. Tracks seeded
 * straight into AzuraCast (the manifest pipeline, a hand upload in the
 * AzuraCast UI, a station move) never went through the submission form, so
 * historically they had no row and could never be voted on.
 *
 * This reconciler closes that gap for good. On a quarter-hourly cron (and
 * on demand via `wp cb-radio reconcile-votes`) it walks every channel's
 * AzuraCast library, works out which files are actually in rotation (a
 * member of at least one enabled, default-type playlist — bumpers, ads and
 * other once-per-x playlists are interstitials, not votable tracks), and
 * makes sure each one has a correct 'live' row:
 *
 *   - No row anywhere for the media id: insert one, using the same field
 *     conventions as the seed importer (status live, media + station ids
 *     recorded, file path copied from AzuraCast, license fields untouched).
 *   - A live row exists but under the wrong channel (the track moved
 *     stations in AzuraCast): move that row to the right channel rather
 *     than duplicating it. Votes stay attached to the row.
 *   - A row exists in a non-live status (pending, approved, retiring,
 *     retired, failed): left completely alone. Human/artist workflow wins.
 *   - Rows are matched by AzuraCast media id, so the run is idempotent:
 *     it never duplicates a row and never touches votes.
 *
 * Artist linking: the gate also needs the row's artist_post_id to point at
 * a cb_artist post whose title matches the artist string AzuraCast reports.
 * The reconciler links by exact title match (the table collation is case
 * and accent insensitive), falling back to a punctuation-insensitive match
 * (e.g. AzuraCast "JAYSWANN" vs the page "Jay Swann"). When a post exists
 * but its name differs from the AzuraCast string only by case, spacing,
 * punctuation or an HTML entity, the AzuraCast artist field is aligned to
 * the page's canonical name through the API (the same direction the
 * submission queue writes names: page name wins), and a post title that
 * literally contains an entity like "&amp;" is rewritten to the plain
 * character so the gate's exact comparison can succeed. When no artist
 * post exists at all, the row is still inserted with artist_post_id 0 and
 * re-checked on every later run, so voting opens by itself the moment the
 * artist's page is built.
 */
class CB_Radio_Reconciler {

	const HOOK   = 'cb_radio_reconcile_voting';
	const LOCK   = 'cb_radio_reconcile_lock';
	const LAST   = 'cb_radio_reconcile_last';
	const SCHEDULE = 'cb_radio_quarter_hourly';

	public static function init() {
		add_action( self::HOOK, [ __CLASS__, 'run' ] );
		self::ensure_scheduled();
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'cb-radio reconcile-votes', [ __CLASS__, 'cli' ] );
		}
	}

	/** Schedule the recurring run if it is not already on the calendar. */
	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 120, self::SCHEDULE, self::HOOK );
		}
	}

	/** WP-CLI manual run: `wp cb-radio reconcile-votes`. */
	public static function cli() {
		$stats = self::run( true );
		if ( isset( $stats['error'] ) ) {
			WP_CLI::error( $stats['error'] );
		}
		foreach ( $stats['channels'] as $slug => $s ) {
			if ( isset( $s['error'] ) ) {
				WP_CLI::warning( "{$slug}: {$s['error']}" );
				continue;
			}
			WP_CLI::log( sprintf(
				'%s: %d rotating, %d inserted, %d moved, %d relinked, %d aligned, %d skipped',
				$slug, $s['rotating'], $s['inserted'], $s['moved'], $s['relinked'], $s['aligned'], $s['skipped']
			) );
		}
		WP_CLI::success( sprintf(
			'Reconcile complete: %d inserted, %d moved, %d relinked, %d aligned across %d channels.',
			$stats['totals']['inserted'], $stats['totals']['moved'], $stats['totals']['relinked'],
			$stats['totals']['aligned'], count( $stats['channels'] )
		) );
	}

	/**
	 * Cron entry point. $force bypasses the overlap lock (manual runs only).
	 *
	 * @return array Run statistics, also stored in the LAST option.
	 */
	public static function run( $force = false ) {
		if ( ! $force && get_transient( self::LOCK ) ) {
			return [ 'error' => 'A reconcile run is already in progress.' ];
		}
		set_transient( self::LOCK, 1, 10 * MINUTE_IN_SECONDS );
		try {
			$stats = self::reconcile();
		} finally {
			delete_transient( self::LOCK );
		}
		update_option( self::LAST, [ 'at' => current_time( 'mysql' ), 'stats' => $stats ], false );
		return $stats;
	}

	private static function reconcile() {
		global $wpdb;
		$settings = CB_Radio_Helpers::settings();
		if ( empty( $settings['azuracast_url'] ) || empty( $settings['azuracast_key'] ) ) {
			return [ 'error' => 'AzuraCast is not configured.', 'channels' => [], 'totals' => self::blank_totals() ];
		}

		$posts     = self::artist_maps();
		$by_media  = [];
		$all_rows  = $wpdb->get_results(
			'SELECT id, channel_slug, status, artist_post_id, azuracast_media_id FROM ' . CB_Radio_Helpers::table()
			. " WHERE azuracast_media_id IS NOT NULL AND azuracast_media_id <> ''"
		);
		foreach ( (array) $all_rows as $r ) {
			$by_media[ (string) $r->azuracast_media_id ][] = $r;
		}

		$out = [ 'channels' => [], 'totals' => self::blank_totals() ];

		foreach ( CB_Radio_Helpers::channels() as $channel ) {
			$slug       = $channel['slug'] ?? '';
			$station_id = (int) ( $channel['station_id'] ?? 0 );
			if ( ! $slug || ! $station_id ) {
				continue;
			}
			$cs = [ 'rotating' => 0, 'inserted' => 0, 'moved' => 0, 'relinked' => 0, 'aligned' => 0, 'skipped' => 0 ];

			$files     = CB_Radio_AzuraCast::files( $station_id );
			$playlists = CB_Radio_AzuraCast::playlists( $station_id );
			if ( is_wp_error( $files ) || is_wp_error( $playlists ) ) {
				$err            = is_wp_error( $files ) ? $files->get_error_message() : $playlists->get_error_message();
				$cs['error']    = $err;
				$out['channels'][ $slug ] = $cs;
				error_log( '[cb-radio] reconcile ' . $slug . ': ' . $err ); // phpcs:ignore -- self-hoster diagnostic trail
				continue;
			}

			$rotation_playlists = [];
			foreach ( (array) $playlists as $p ) {
				if ( ! empty( $p['is_enabled'] ) && ( $p['type'] ?? '' ) === 'default' ) {
					$rotation_playlists[ (int) $p['id'] ] = true;
				}
			}

			foreach ( (array) $files as $file ) {
				if ( ! self::in_rotation( $file, $rotation_playlists ) ) {
					continue;
				}
				$cs['rotating']++;
				$media_id = (string) ( $file['id'] ?? '' );
				if ( '' === $media_id ) {
					continue;
				}
				$rows       = $by_media[ $media_id ] ?? [];
				$on_channel = array_values( array_filter( $rows, function ( $r ) use ( $slug ) {
					return $r->channel_slug === $slug;
				} ) );

				if ( $on_channel ) {
					$row = self::prefer_live( $on_channel );
					if ( $row && 'live' === $row->status ) {
						self::after_link( $row, $file, $station_id, $posts, $cs );
					} else {
						// Pending/approved/retiring/retired/failed: human workflow wins.
						$cs['skipped']++;
					}
					continue;
				}

				$live_elsewhere = array_values( array_filter( $rows, function ( $r ) {
					return 'live' === $r->status;
				} ) );
				if ( $live_elsewhere ) {
					$row = $live_elsewhere[0];
					$wpdb->update( CB_Radio_Helpers::table(), [
						'channel_slug'         => $slug,
						'azuracast_station_id' => $station_id,
					], [ 'id' => (int) $row->id ] );
					$row->channel_slug         = $slug;
					$row->azuracast_station_id = $station_id;
					$cs['moved']++;
					self::after_link( $row, $file, $station_id, $posts, $cs );
					continue;
				}

				if ( $rows ) {
					// Exists only in a non-live status: leave it alone.
					$cs['skipped']++;
					continue;
				}

				$title = (string) ( $file['title'] ?? '' );
				if ( '' === $title || mb_strlen( $title ) > 191 ) {
					// A row whose title can never match the gate is worse than none.
					$cs['skipped']++;
					error_log( '[cb-radio] reconcile: skipping media ' . $media_id . ' (unusable title)' ); // phpcs:ignore -- self-hoster diagnostic trail
					continue;
				}

				$post_id = self::resolve_post( (string) ( $file['artist'] ?? '' ), $posts );
				$now     = current_time( 'mysql' );
				$wpdb->insert( CB_Radio_Helpers::table(), [
					'artist_post_id'       => $post_id,
					'track_title'          => $title,
					'channel_slug'         => $slug,
					'submission_type'      => 'track',
					'file_path'            => (string) ( $file['path'] ?? '' ),
					'art_attachment_id'    => null,
					'duration_secs'        => null,
					'status'               => 'live',
					'azuracast_media_id'   => $media_id,
					'azuracast_station_id' => $station_id,
					'license_accepted'     => 0,
					'license_text_version' => '',
					'reviewer_id'          => null,
					'review_notes'         => null,
					'submitted_at'         => $now,
					'reviewed_at'          => $now,
					'attempts'             => 0,
				] );
				$cs['inserted']++;
				if ( $wpdb->insert_id ) {
					$row = (object) [
						'id'             => (int) $wpdb->insert_id,
						'artist_post_id' => $post_id,
						'status'         => 'live',
					];
					self::align_artist( $row, $file, $station_id, $posts, $cs );
				}
			}

			$out['channels'][ $slug ] = $cs;
			foreach ( [ 'inserted', 'moved', 'relinked', 'aligned' ] as $k ) {
				$out['totals'][ $k ] += $cs[ $k ];
			}
		}

		error_log( sprintf( // phpcs:ignore -- self-hoster diagnostic trail
			'[cb-radio] reconcile done: %d inserted, %d moved, %d relinked, %d aligned',
			$out['totals']['inserted'], $out['totals']['moved'], $out['totals']['relinked'], $out['totals']['aligned']
		) );
		return $out;
	}

	private static function blank_totals() {
		return [ 'inserted' => 0, 'moved' => 0, 'relinked' => 0, 'aligned' => 0 ];
	}

	/** A file rotates when it sits in at least one enabled default-type playlist. */
	private static function in_rotation( $file, $rotation_playlists ) {
		foreach ( (array) ( $file['playlists'] ?? [] ) as $p ) {
			if ( isset( $rotation_playlists[ (int) ( $p['id'] ?? 0 ) ] ) ) {
				return true;
			}
		}
		return false;
	}

	private static function prefer_live( $rows ) {
		foreach ( $rows as $r ) {
			if ( 'live' === $r->status ) {
				return $r;
			}
		}
		return $rows[0] ?? null;
	}

	/** Link an unlinked live row if its artist page exists by now, then align names. */
	private static function after_link( $row, $file, $station_id, $posts, &$cs ) {
		if ( 0 === (int) $row->artist_post_id ) {
			$post_id = self::resolve_post( (string) ( $file['artist'] ?? '' ), $posts );
			if ( $post_id ) {
				global $wpdb;
				$wpdb->update( CB_Radio_Helpers::table(), [ 'artist_post_id' => $post_id ], [ 'id' => (int) $row->id ] );
				$row->artist_post_id = $post_id;
				$cs['relinked']++;
			}
		}
		self::align_artist( $row, $file, $station_id, $posts, $cs );
	}

	/**
	 * Make the gate's exact artist comparison succeed for a linked row.
	 * Only fires when the page name and the AzuraCast string are the same
	 * name modulo case, spacing, punctuation or HTML entities, so it can
	 * never retag one artist's track to a different artist.
	 */
	private static function align_artist( $row, $file, $station_id, $posts, &$cs ) {
		$post_id = (int) $row->artist_post_id;
		if ( ! $post_id || ! isset( $posts['titles'][ $post_id ] ) ) {
			return;
		}
		$raw   = $posts['titles'][ $post_id ];
		$canon = html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$az    = (string) ( $file['artist'] ?? '' );

		if ( $canon === $az ) {
			if ( $raw !== $az ) {
				// Page title literally stores "&amp;" etc.; store the plain
				// character instead (renders identically, matches the gate).
				wp_update_post( [ 'ID' => $post_id, 'post_title' => $canon ] );
				$cs['aligned']++;
			}
			return;
		}
		if ( '' !== $az && self::normalize( $canon ) === self::normalize( $az ) ) {
			$result = CB_Radio_AzuraCast::update_file_artist( $station_id, (int) $file['id'], $canon );
			if ( ! is_wp_error( $result ) ) {
				$cs['aligned']++;
				if ( $raw !== $canon ) {
					wp_update_post( [ 'ID' => $post_id, 'post_title' => $canon ] );
				}
			} else {
				error_log( '[cb-radio] reconcile align failed for media ' . ( $file['id'] ?? '?' ) . ': ' . $result->get_error_message() ); // phpcs:ignore -- self-hoster diagnostic trail
			}
		}
	}

	/** Find the cb_artist post for an AzuraCast artist string, or 0. */
	private static function resolve_post( $artist, $posts ) {
		$artist = trim( (string) $artist );
		if ( '' === $artist ) {
			return 0;
		}
		$key = mb_strtolower( $artist );
		if ( isset( $posts['exact'][ $key ] ) ) {
			return $posts['exact'][ $key ];
		}
		$norm = self::normalize( $artist );
		if ( '' !== $norm && isset( $posts['norm'][ $norm ] ) ) {
			return $posts['norm'][ $norm ];
		}
		return 0;
	}

	/**
	 * Lookup maps over all published cb_artist posts: by lowercased title
	 * (raw and entity-decoded forms) and by punctuation-free normal form.
	 */
	private static function artist_maps() {
		global $wpdb;
		$maps = [ 'exact' => [], 'norm' => [], 'titles' => [] ];
		$rows = $wpdb->get_results(
			"SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'cb_artist' AND post_status = 'publish' ORDER BY ID ASC"
		);
		foreach ( (array) $rows as $r ) {
			$id    = (int) $r->ID;
			$title = (string) $r->post_title;
			$maps['titles'][ $id ] = $title;
			$maps['exact'][ mb_strtolower( trim( $title ) ) ] = $maps['exact'][ mb_strtolower( trim( $title ) ) ] ?? $id;
			$decoded = html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$maps['exact'][ mb_strtolower( trim( $decoded ) ) ] = $maps['exact'][ mb_strtolower( trim( $decoded ) ) ] ?? $id;
			$norm = self::normalize( $decoded );
			if ( '' !== $norm ) {
				$maps['norm'][ $norm ] = $maps['norm'][ $norm ] ?? $id;
			}
		}
		return $maps;
	}

	/** Case, spacing and punctuation-insensitive name form ("Ms.TiQ" == "Ms TiQ" == "MS TIQ"). */
	private static function normalize( $name ) {
		$name = html_entity_decode( (string) $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$name = mb_strtolower( $name );
		return preg_replace( '/[^\p{L}\p{N}]+/u', '', $name ) ?? '';
	}
}
