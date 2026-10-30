<?php
defined( 'ABSPATH' ) || exit;

/**
 * REST API:
 *  GET /cb-radio/v1/now-playing/{slug} (public, cached proxy to AzuraCast)
 *
 * There used to be a second route here (a callback n8n posted to when a
 * handoff finished). That's gone — CB_Radio_Queue talks to AzuraCast
 * directly and updates the database in the same request, so there's nothing
 * external left to call back.
 */
class CB_Radio_Rest {

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	public static function routes() {
		register_rest_route( 'cb-radio/v1', '/now-playing/(?P<slug>[a-z0-9\-_]+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'now_playing' ],
			'permission_callback' => '__return_true',
		] );
	}

	/**
	 * Cached proxy to AzuraCast now-playing, enriched with the artist profile.
	 * Browser polls this; AzuraCast never sees the public.
	 */
	public static function now_playing( WP_REST_Request $req ) {
		$slug    = $req->get_param( 'slug' );
		$channel = CB_Radio_Helpers::channel( $slug );
		if ( ! $channel ) {
			return new WP_Error( 'no_channel', 'Unknown channel', [ 'status' => 404 ] );
		}

		$cache_key = 'cb_radio_np_' . $slug;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$settings = CB_Radio_Helpers::settings();

		// ---- PLACEHOLDER MODE ----
		// Until AzuraCast is configured, return demo data so the tuner UI is viewable.
		if ( empty( $settings['azuracast_url'] ) ) {
			$demo = self::demo_payload( $channel );
			set_transient( $cache_key, $demo, 12 );
			return $demo;
		}

		$url = trailingslashit( $settings['azuracast_url'] ) . 'api/nowplaying/' . absint( $channel['station_id'] );
		$res = wp_remote_get( $url, [ 'timeout' => 8 ] );

		if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
			$fallback = [ 'channel' => $slug, 'live' => false, 'error' => 'station_unreachable' ];
			set_transient( $cache_key, $fallback, 12 );
			return $fallback;
		}

		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		$song = $data['now_playing']['song'] ?? [];

		$payload = [
			'channel'    => $slug,
			'label'      => $channel['label'] ?? $slug,
			'stream_url' => $channel['stream_url'] ?? '',
			'live'       => true,
			'listeners'  => (int) ( $data['listeners']['current'] ?? 0 ),
			'song'       => [
				'title'  => $song['title'] ?? '',
				'artist' => $song['artist'] ?? '',
				'art'    => $song['art'] ?? '',
			],
			'artist'     => null,
		];

		// Resolve the playing track back to a submission row, purely by exact
		// title+artist match against our own 'live' rows. No AzuraCast-side
		// setup (custom fields, matching IDs) required — the title/artist we
		// matched on is exactly what we told AzuraCast to set during upload,
		// so it's always in sync by construction.
		global $wpdb;
		$submission_id = 0;
		if ( ! empty( $song['title'] ) ) {
			$submission_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT s.id FROM " . CB_Radio_Helpers::table() . " s
				 LEFT JOIN {$wpdb->posts} p ON p.ID = s.artist_post_id
				 WHERE s.status = 'live' AND s.channel_slug = %s
				   AND s.track_title = %s
				   AND ( p.post_title = %s OR %s = '' )
				 ORDER BY s.reviewed_at DESC LIMIT 1",
				$slug,
				sanitize_text_field( $song['title'] ),
				sanitize_text_field( $song['artist'] ?? '' ),
				sanitize_text_field( $song['artist'] ?? '' )
			) );
		}
		if ( $submission_id ) {
			$row = CB_Radio_Helpers::get_submission( $submission_id );
			if ( $row && $row->artist_post_id ) {
				$payload['artist'] = CB_Radio_Artists::payload( $row->artist_post_id );
			}
		}

		$payload['submission_id'] = $submission_id;
		// Deliberately no aggregate vote counts here — this response is
		// cached and shared across every visitor, so anything in it is
		// effectively public to all of them. Each browser fetches its own
		// vote state separately via /my-vote/{id}, which is never cached.

		set_transient( $cache_key, $payload, 12 );
		return $payload;
	}

	/** Demo now-playing data so the dial renders before AzuraCast exists. */
	private static function demo_payload( $channel ) {
		$demo_tracks = [
			[ 'title' => 'Harbor Lights', 'artist' => 'Demo Artist', 'hood' => 'Hampden' ],
			[ 'title' => 'Crab Mallet Anthem', 'artist' => 'The Placeholder Trio', 'hood' => 'Fells Point' ],
			[ 'title' => '695 Loop', 'artist' => 'Test Pattern', 'hood' => 'Pigtown' ],
		];
		$t = $demo_tracks[ array_rand( $demo_tracks ) ];
		return [
			'channel'       => $channel['slug'],
			'label'         => $channel['label'] ?? $channel['slug'],
			'stream_url'    => $channel['stream_url'] ?? '',
			'live'          => true,
			'demo'          => true,
			'listeners'     => wp_rand( 3, 42 ),
			'song'          => [ 'title' => $t['title'], 'artist' => $t['artist'], 'art' => '' ],
			'submission_id' => 0, // demo tracks aren't votable
			'artist'        => [
				'name'  => $t['artist'],
				'url'   => '#',
				'photo' => '',
				'hood'  => $t['hood'],
				'links' => [],
			],
		];
	}
}
