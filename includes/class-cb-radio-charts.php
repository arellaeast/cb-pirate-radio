<?php
defined( 'ABSPATH' ) || exit;

/**
 * Charts: thumbs up/down votes on live tracks, rolled into Week / Month Top 10.
 *
 *  POST /cb-radio/v1/vote                     (public, nonce + fingerprint dedupe)
 *  GET  /cb-radio/v1/charts/{week|month}      (public, cached)
 *  [cb_radio_charts]                          Top 10 shortcode with period toggle
 *
 * Score = upvotes − downvotes inside the window. Ties break on raw upvotes,
 * then most-recent vote. Only status='live', submission_type='track' rows chart.
 */
class CB_Radio_Charts {

	const VOTES_TABLE = 'cb_radio_votes';

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
		add_shortcode( 'cb_radio_charts', [ __CLASS__, 'shortcode' ] );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::VOTES_TABLE;
	}

	/** Called from CB_Radio_Install::activate() and the upgrade routine. */
	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		// voter_key: "u:{user_id}" for logged-in, "a:{sha256(ip|ua|salt)}" for anonymous.
		// One row per voter per track — re-voting updates the row (change your mind).
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			submission_id BIGINT UNSIGNED NOT NULL,
			voter_key VARCHAR(72) NOT NULL,
			vote TINYINT NOT NULL,
			voted_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY voter_track (submission_id, voter_key),
			KEY track_time (submission_id, voted_at)
		) {$charset};";

		dbDelta( $sql );
	}

	public static function routes() {
		register_rest_route( 'cb-radio/v1', '/vote', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'cast_vote' ],
			'permission_callback' => '__return_true', // fingerprint + rate limit below
			'args'                => [
				'submission_id' => [ 'required' => true, 'type' => 'integer' ],
				'vote'          => [ 'required' => true, 'enum' => [ 1, -1 ] ],
			],
		] );

		register_rest_route( 'cb-radio/v1', '/charts/(?P<period>week|month)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'chart' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( 'cb-radio/v1', '/my-vote/(?P<submission_id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'my_vote_route' ],
			'permission_callback' => '__return_true', // scoped to the requester's own voter_key — reveals nothing about anyone else
		] );
	}

	/** REST wrapper for my_vote() — never cached, since caching would leak one visitor's vote state to another. */
	public static function my_vote_route( WP_REST_Request $req ) {
		return [ 'vote' => self::my_vote( absint( $req->get_param( 'submission_id' ) ) ) ];
	}

	/** Stable-but-anonymous voter identity. */
	private static function voter_key() {
		if ( is_user_logged_in() ) {
			return 'u:' . get_current_user_id();
		}
		$ip = $_SERVER['REMOTE_ADDR'] ?? '';
		$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
		// Salted with WordPress's own auth salt (always present, unique per
		// install) so keys aren't reversible rainbow-table style — no plugin
		// setting required for this to be secure.
		// NOTE: anonymous dedupe is best-effort — shared IPs (dorms, offices)
		// collide, and determined ballot-stuffers can rotate. Fine for a local
		// chart; if it ever matters, require login to vote (flip a setting, TODO).
		return 'a:' . hash( 'sha256', $ip . '|' . $ua . '|' . wp_salt() );
	}

	public static function cast_vote( WP_REST_Request $req ) {
		global $wpdb;

		$submission_id = absint( $req->get_param( 'submission_id' ) );
		$vote          = (int) $req->get_param( 'vote' );
		$voter         = self::voter_key();

		// Soft rate limit: 30 votes / 10 min per voter.
		$rl_key = 'cb_radio_rl_' . md5( $voter );
		$count  = (int) get_transient( $rl_key );
		if ( $count > 30 ) {
			return new WP_Error( 'slow_down', 'Easy on the dial, sailor.', [ 'status' => 429 ] );
		}
		set_transient( $rl_key, $count + 1, 10 * MINUTE_IN_SECONDS );

		$row = CB_Radio_Helpers::get_submission( $submission_id );
		if ( ! $row || 'live' !== $row->status || 'track' !== $row->submission_type ) {
			return new WP_Error( 'not_votable', 'That track is not on the air.', [ 'status' => 404 ] );
		}

		// Upsert: new vote, or change of heart.
		$wpdb->query( $wpdb->prepare(
			'INSERT INTO ' . self::table() . ' (submission_id, voter_key, vote, voted_at)
			 VALUES (%d, %s, %d, %s)
			 ON DUPLICATE KEY UPDATE vote = VALUES(vote), voted_at = VALUES(voted_at)',
			$submission_id, $voter, $vote, current_time( 'mysql', true )
		) );

		// Charts change, so drop chart caches.
		delete_transient( 'cb_radio_chart_week' );
		delete_transient( 'cb_radio_chart_month' );
		if ( ! empty( $row->channel_slug ) ) {
			delete_transient( 'cb_radio_chart_week_' . $row->channel_slug );
			delete_transient( 'cb_radio_chart_month_' . $row->channel_slug );
		}

		// Deliberately NOT returning aggregate up/down totals here. The
		// whole point of hiding vote counts is that nobody — including the
		// person who just voted — should see the tally and be nudged by it.
		// Only the Top 10 chart (an explicit ranking feature) shows numbers.
		return [
			'ok'   => true,
			'vote' => $vote,
		];
	}

	/** The requesting visitor's own vote on a submission: 1, -1, or 0 if they haven't voted. */
	public static function my_vote( $submission_id ) {
		global $wpdb;
		$vote = $wpdb->get_var( $wpdb->prepare(
			'SELECT vote FROM ' . self::table() . ' WHERE submission_id = %d AND voter_key = %s',
			$submission_id, self::voter_key()
		) );
		return null === $vote ? 0 : (int) $vote;
	}

	public static function chart( WP_REST_Request $req ) {
		$period = $req->get_param( 'period' );

		// Optional genre view: ?channel=<slug> narrows the same chart to one
		// station. Unknown slugs fall back to the overall chart.
		$channel = sanitize_key( (string) $req->get_param( 'channel' ) );
		if ( '' !== $channel && null === CB_Radio_Helpers::channel( $channel ) ) {
			$channel = '';
		}

		$cache_key = 'cb_radio_chart_' . $period . ( '' !== $channel ? '_' . $channel : '' );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$days  = 'week' === $period ? 7 : 30;
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		$votes = self::table();
		$subs  = CB_Radio_Helpers::table();

		$channel_sql = '';
		if ( '' !== $channel ) {
			$channel_sql = $wpdb->prepare( ' AND s.channel_slug = %s', $channel );
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.id, s.track_title, s.channel_slug, s.artist_post_id, s.art_attachment_id,
				SUM(v.vote = 1)  AS up,
				SUM(v.vote = -1) AS down,
				SUM(v.vote)      AS score,
				MAX(v.voted_at)  AS last_vote
			 FROM {$votes} v
			 INNER JOIN {$subs} s ON s.id = v.submission_id
			 WHERE v.voted_at >= %s
			   AND s.status = 'live'
			   AND s.submission_type = 'track'{$channel_sql}
			 GROUP BY v.submission_id
			 ORDER BY score DESC, up DESC, last_vote DESC
			 LIMIT 10",
			$since
		) );

		$entries = [];
		foreach ( $rows as $i => $r ) {
			$channel  = CB_Radio_Helpers::channel( $r->channel_slug );
			$entries[] = [
				'rank'    => $i + 1,
				'id'      => (int) $r->id,
				'title'   => $r->track_title,
				'channel' => $channel['label'] ?? $r->channel_slug,
				'artist'  => CB_Radio_Artists::payload( $r->artist_post_id ),
				'art'     => $r->art_attachment_id ? wp_get_attachment_image_url( $r->art_attachment_id, 'thumbnail' ) : '',
				'up'      => (int) $r->up,
				'down'    => (int) $r->down,
				'score'   => (int) $r->score,
			];
		}

		// ---- PLACEHOLDER MODE ----
		// No real votes yet: demo chart so the page is viewable end-to-end.
		// Overall chart only — a genre view with no votes stays empty rather
		// than dressing demo rows up as that genre's chart.
		$demo = false;
		if ( empty( $entries ) && '' === $channel ) {
			$entries = self::demo_chart();
			$demo    = true;
		}

		$payload = [
			'period'  => $period,
			'days'    => $days,
			'demo'    => $demo,
			'channel' => $channel,
			'entries' => $entries,
		];

		set_transient( $cache_key, $payload, 5 * MINUTE_IN_SECONDS );
		return $payload;
	}

	private static function demo_chart() {
		$demo = [
			[ 'Harbor Lights', 'Demo Artist', 'Baltimore Club', 41, 3 ],
			[ 'Crab Mallet Anthem', 'The Placeholder Trio', 'Hip-Hop', 33, 5 ],
			[ '695 Loop', 'Test Pattern', 'Baltimore Club', 28, 2 ],
			[ 'Natty Boh Nights', 'Sample Signal', 'Rock', 22, 4 ],
			[ 'Formstone Dreams', 'Rowhouse Choir', 'R&B', 19, 1 ],
			[ 'Duckpin Strike', 'Lane 7', 'Punk', 17, 6 ],
			[ 'Arabber Song', 'Fruit Cart Collective', 'Folk', 14, 2 ],
			[ 'Chessie Sighting', 'Bay Cryptids', 'Electronic', 12, 3 ],
			[ 'Snowball Stand Summer', 'Egg Custard', 'Pop', 9, 1 ],
			[ 'Last Call at the Horse', 'Fells Pt. Irregulars', 'Rock', 7, 2 ],
		];
		$entries = [];
		foreach ( $demo as $i => $d ) {
			$entries[] = [
				'rank'    => $i + 1,
				'id'      => 0,
				'title'   => $d[0],
				'channel' => $d[2],
				'artist'  => [ 'name' => $d[1], 'url' => '#', 'photo' => '', 'hood' => '', 'links' => [] ],
				'art'     => '',
				'up'      => $d[3],
				'down'    => $d[4],
				'score'   => $d[3] - $d[4],
			];
		}
		return $entries;
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts( [ 'period' => 'week' ], $atts, 'cb_radio_charts' );

		wp_enqueue_style( 'cb-radio-tuner' );
		wp_enqueue_script( 'cb-radio-tuner' );
		wp_localize_script( 'cb-radio-tuner', 'CB_RADIO', [
			'apiBase'   => esc_url_raw( rest_url( 'cb-radio/v1/now-playing/' ) ),
			'voteUrl'   => esc_url_raw( rest_url( 'cb-radio/v1/vote' ) ),
			'chartBase' => esc_url_raw( rest_url( 'cb-radio/v1/charts/' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'poll'      => 15000,
		] );

		ob_start();
		$default_period = in_array( $atts['period'], [ 'week', 'month' ], true ) ? $atts['period'] : 'week';
		include CB_RADIO_DIR . 'templates/charts.php';
		return ob_get_clean();
	}
}
