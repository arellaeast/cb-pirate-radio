<?php
defined( 'ABSPATH' ) || exit;

/**
 * [cb_radio_submit] — artist submission form.
 * PRG via admin-post.php, protected file storage, getID3 duration check.
 */
class CB_Radio_Form {

	public static function init() {
		add_shortcode( 'cb_radio_submit', [ __CLASS__, 'shortcode' ] );
		add_shortcode( 'cb_radio_tuner', [ __CLASS__, 'tuner_shortcode' ] );
		add_action( 'admin_post_cb_radio_submit', [ __CLASS__, 'handle' ] );
		add_action( 'admin_post_nopriv_cb_radio_submit', [ __CLASS__, 'handle' ] );
		add_action( 'init', [ __CLASS__, 'maybe_serve_preview_download' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ] );
	}

	public static function assets() {
		// One combined Google Fonts request covering every font option a theme
		// (including one a visitor switches to client-side) might use. Only
		// loads on pages that actually enqueue cb-radio-tuner, via the
		// dependency below — pages without the tuner/charts shortcode pay
		// nothing for this.
		wp_register_style( 'cb-radio-google-fonts', CB_Radio_Themes::google_fonts_url(), [], null );
		wp_register_style( 'cb-radio-tuner', CB_RADIO_URL . 'assets/tuner.css', [ 'cb-radio-google-fonts' ], CB_RADIO_VERSION );
		wp_register_style( 'cb-radio-form', CB_RADIO_URL . 'assets/form.css', [], CB_RADIO_VERSION );
		// hls.js: decodes HLS (.m3u8) streams for browsers that don't support it natively
		// (everything except Safari). Loaded from cdnjs; only actually fetched by the
		// browser when a channel's stream turns out to need it — see tuner.js.
		wp_register_script( 'cb-radio-hlsjs', 'https://cdnjs.cloudflare.com/ajax/libs/hls.js/1.5.15/hls.min.js', [], '1.5.15', true );
		wp_register_script( 'cb-radio-tuner', CB_RADIO_URL . 'assets/tuner.js', [ 'cb-radio-hlsjs' ], CB_RADIO_VERSION, true );
	}

	public static function shortcode() {
		wp_enqueue_style( 'cb-radio-form' );
		// Guest submissions allowed since 0.11.0 (Jacob's call): artists can
		// submit without an account. Submissions land in the pending review
		// queue either way; a honeypot field filters the dumb bots.
		ob_start();
		include CB_RADIO_DIR . 'templates/submit-form.php';
		return ob_get_clean();
	}

	public static function tuner_shortcode() {
		wp_enqueue_style( 'cb-radio-tuner' );
		wp_enqueue_script( 'cb-radio-tuner' );
		wp_localize_script( 'cb-radio-tuner', 'CB_RADIO', [
			'apiBase'   => esc_url_raw( rest_url( 'cb-radio/v1/now-playing/' ) ),
			'voteUrl'   => esc_url_raw( rest_url( 'cb-radio/v1/vote' ) ),
			'myVoteUrl' => esc_url_raw( rest_url( 'cb-radio/v1/my-vote/' ) ),
			'chartBase' => esc_url_raw( rest_url( 'cb-radio/v1/charts/' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'poll'      => 15000,
			// Same list + order the PHP template rendered from, so seek prev/next
			// and preset highlighting never drift out of sync with the markup.
			'channels'  => array_map( function ( $c ) {
				return [
					'slug'   => $c['slug'],
					'label'  => $c['label'] ?? $c['slug'],
					'freq'   => $c['freq'],
					'preset' => $c['preset'],
					'stream' => $c['stream_url'] ?? '',
				];
			}, CB_Radio_Helpers::channels_display() ),
		] );
		ob_start();
		include CB_RADIO_DIR . 'templates/tuner.php';
		return ob_get_clean();
	}

	public static function handle() {
		if ( is_user_logged_in() && ! current_user_can( 'read' ) ) {
			wp_die( 'Log in first.' );
		}
		check_admin_referer( 'cb_radio_submit' );

		$user_id  = get_current_user_id();
		$settings = CB_Radio_Helpers::settings();
		$redirect = wp_get_referer() ?: home_url( '/' );

		$fail = function ( $msg ) use ( $redirect ) {
			wp_safe_redirect( add_query_arg( 'cb_radio_error', rawurlencode( $msg ), $redirect ) );
			exit;
		};

		// Honeypot: real visitors never see or fill this field. Pretend
		// success so bots don't learn, but store nothing.
		if ( ! empty( $_POST['cb_radio_website'] ) ) {
			wp_safe_redirect( add_query_arg( 'cb_radio_success', 1, $redirect ) );
			exit;
		}

		// --- Artist profile: reuse (signed-in artists) or create ---
		// Guests always create a fresh profile keyed to user 0; for_user()
		// must never match them to somebody else's guest profile.
		$artist = $user_id ? CB_Radio_Artists::for_user( $user_id ) : null;
		if ( ! $artist ) {
			$name = sanitize_text_field( wp_unslash( $_POST['artist_name'] ?? '' ) );
			if ( '' === $name ) {
				$fail( __( 'Artist name is required.', 'cb-radio' ) );
			}
			$links = array_filter( array_map( 'trim', explode( "\n", wp_unslash( $_POST['artist_links'] ?? '' ) ) ) );
			$artist_id = CB_Radio_Artists::create(
				$user_id,
				$name,
				wp_unslash( $_POST['artist_bio'] ?? '' ),
				$links,
				wp_unslash( $_POST['artist_hood'] ?? '' )
			);
			if ( is_wp_error( $artist_id ) ) {
				$fail( $artist_id->get_error_message() );
			}
		} else {
			$artist_id = $artist->ID;
		}

		// --- Basic fields ---
		$title   = sanitize_text_field( wp_unslash( $_POST['track_title'] ?? '' ) );
		$channel = sanitize_key( wp_unslash( $_POST['channel_slug'] ?? '' ) );
		$type    = in_array( $_POST['submission_type'] ?? 'track', [ 'track', 'spot', 'skit', 'station_id' ], true )
			? $_POST['submission_type'] : 'track';

		$stream_link = esc_url_raw( wp_unslash( $_POST['streaming_link'] ?? '' ) );
		if ( strlen( $stream_link ) > 500 ) {
			$stream_link = '';
		}

		if ( '' === $title || ! CB_Radio_Helpers::channel( $channel ) ) {
			$fail( __( 'Track title and a valid channel are required.', 'cb-radio' ) );
		}
		if ( empty( $_POST['license_accept'] ) ) {
			$fail( __( 'You must accept the license grant.', 'cb-radio' ) );
		}

		// --- One pending submission per artist per channel ---
		global $wpdb;
		$pending = $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . CB_Radio_Helpers::table() . " WHERE artist_post_id = %d AND channel_slug = %s AND status = 'pending'",
			$artist_id, $channel
		) );
		if ( $pending > 0 ) {
			$fail( __( 'You already have a track waiting for review on that channel. Hold tight!', 'cb-radio' ) );
		}

		// --- Audio file validation ---
		if ( empty( $_FILES['audio_file']['tmp_name'] ) ) {
			$fail( __( 'No audio file received.', 'cb-radio' ) );
		}
		$file = $_FILES['audio_file'];

		if ( $file['size'] > $settings['max_file_mb'] * MB_IN_BYTES ) {
			$fail( sprintf( __( 'File too big — max %d MB.', 'cb-radio' ), $settings['max_file_mb'] ) );
		}

		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], [ 'mp3' => 'audio/mpeg' ] );
		if ( 'audio/mpeg' !== $check['type'] ) {
			$fail( __( 'MP3 only for now.', 'cb-radio' ) );
		}

		// Duration via getID3 (ships with WP core).
		$duration = null;
		if ( ! class_exists( 'getID3' ) ) {
			require_once ABSPATH . WPINC . '/ID3/getid3.php';
		}
		$id3  = new getID3();
		$info = $id3->analyze( $file['tmp_name'] );
		if ( ! empty( $info['playtime_seconds'] ) ) {
			$duration = (int) round( $info['playtime_seconds'] );
			if ( $duration > (int) $settings['max_duration'] ) {
				$fail( sprintf( __( 'Track is too long — max %d seconds.', 'cb-radio' ), $settings['max_duration'] ) );
			}
		} else {
			$fail( __( 'Could not read that file. Is it a valid MP3?', 'cb-radio' ) );
		}

		// --- Move into protected dir with hashed name ---
		$dir      = CB_Radio_Helpers::protected_dir();
		$filename = wp_hash( $file['name'] . microtime() ) . '.mp3';
		$dest     = trailingslashit( $dir ) . $filename;
		if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
			$fail( __( 'Could not store the file. Try again?', 'cb-radio' ) );
		}

		// --- Album art via media library (public is fine/desired) ---
		// Signed-in artists only: the media library needs an uploading user.
		// Guest art is skipped silently; moderators can add it at review.
		$art_id = null;
		if ( $user_id && ! empty( $_FILES['album_art']['tmp_name'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			$art_id = media_handle_upload( 'album_art', 0 );
			if ( is_wp_error( $art_id ) ) {
				$art_id = null; // Art is optional; don't fail the whole submission.
			}
		}

		// --- Cover art from a Spotify / Apple Music link (v0.13.4) ---
		// Guests can't use the media library upload above, so for them this
		// is the only art route; it also covers anyone without art handy.
		// An artist's own upload always wins, and any failure here stays
		// silent — artwork never sinks a submission.
		$art_from_link = false;
		if ( ! $art_id && '' !== $stream_link ) {
			$art_id        = self::art_from_streaming_link( $stream_link, $title );
			$art_from_link = (bool) $art_id;
		}

		// --- Insert row ---
		$wpdb->insert( CB_Radio_Helpers::table(), [
			'artist_post_id'       => $artist_id,
			'track_title'          => $title,
			'channel_slug'         => $channel,
			'submission_type'      => $type,
			'file_path'            => $filename,
			'art_attachment_id'    => $art_id,
			'streaming_link'       => '' !== $stream_link ? $stream_link : null,
			'duration_secs'        => $duration,
			'status'               => 'pending',
			'license_accepted'     => 1,
			'license_text_version' => $settings['license_version'],
			'submitted_at'         => current_time( 'mysql', true ),
		] );

		// TODO: fire a notification (Slack/Discord webhook, email) here so you know a submission landed.

		$success_args = [ 'cb_radio_success' => 1 ];
		if ( $art_from_link ) {
			$success_args['cb_radio_art'] = 1;
		}
		wp_safe_redirect( add_query_arg( $success_args, $redirect ) );
		exit;
	}

	/**
	 * Resolve a Spotify / Apple Music track link to candidate artwork URLs,
	 * best first. Spotify answers through its public oEmbed (artwork rides
	 * along); Apple Music's pages block plain fetches, so its links go
	 * through the iTunes lookup API on the track ID in the URL. Anything
	 * unrecognized returns an empty list and is simply ignored.
	 */
	private static function streaming_artwork_candidates( $link ) {
		$host = strtolower( (string) wp_parse_url( $link, PHP_URL_HOST ) );
		$ua   = [ 'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36' ];

		if ( in_array( $host, [ 'open.spotify.com', 'embed.spotify.com' ], true ) ) {
			$res = wp_remote_get( 'https://open.spotify.com/oembed?url=' . rawurlencode( $link ), [ 'timeout' => 6, 'headers' => $ua ] );
			if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
				return [];
			}
			$data  = json_decode( wp_remote_retrieve_body( $res ), true );
			$thumb = $data['thumbnail_url'] ?? '';
			if ( ! $thumb ) {
				return [];
			}
			// Spotify CDN size codes: 00001e02 is 300px, 0000b273 is 640px.
			$big = str_replace( '00001e02', '0000b273', $thumb );
			return array_values( array_unique( [ $big, $thumb ] ) );
		}

		if ( 'music.apple.com' === $host ) {
			$path  = trim( (string) wp_parse_url( $link, PHP_URL_PATH ), '/' );
			$parts = explode( '/', $path );
			$storefront = preg_match( '/^[a-z]{2}$/', $parts[0] ?? '' ) ? strtoupper( $parts[0] ) : 'US';
			$query = [];
			wp_parse_str( (string) wp_parse_url( $link, PHP_URL_QUERY ), $query );
			$id = '';
			if ( ! empty( $query['i'] ) && ctype_digit( (string) $query['i'] ) ) {
				$id = (string) $query['i'];
			} else {
				$last = end( $parts );
				if ( $last && ctype_digit( (string) $last ) ) {
					$id = (string) $last;
				}
			}
			if ( '' === $id ) {
				return [];
			}
			foreach ( [ 'song', 'album' ] as $entity ) {
				$res = wp_remote_get( 'https://itunes.apple.com/lookup?id=' . $id . '&entity=' . $entity . '&country=' . $storefront, [ 'timeout' => 6, 'headers' => $ua ] );
				if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
					continue;
				}
				$data = json_decode( wp_remote_retrieve_body( $res ), true );
				$art  = $data['results'][0]['artworkUrl100'] ?? '';
				if ( $art ) {
					return [ str_replace( '100x100bb.jpg', '600x600bb.jpg', $art ) ];
				}
			}
			return [];
		}

		return [];
	}

	/**
	 * Download the artwork behind a streaming link and file it in the media
	 * library as a full attachment (mime set, sizes generated) — the same
	 * shape a hand upload lands in. Returns the attachment ID or null.
	 */
	private static function art_from_streaming_link( $link, $title ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		foreach ( self::streaming_artwork_candidates( $link ) as $img_url ) {
			$tmp = download_url( $img_url, 15 );
			if ( is_wp_error( $tmp ) ) {
				continue;
			}
			$mime = wp_get_image_mime( $tmp );
			$exts = [ 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp' ];
			if ( ! $mime || ! isset( $exts[ $mime ] ) ) {
				@unlink( $tmp );
				continue;
			}
			$uploads  = wp_upload_dir();
			$filename = wp_unique_filename( $uploads['path'], sanitize_file_name( $title ) . '-cover.' . $exts[ $mime ] );
			$dest     = trailingslashit( $uploads['path'] ) . $filename;
			if ( ! @copy( $tmp, $dest ) ) {
				@unlink( $tmp );
				continue;
			}
			@unlink( $tmp );
			$att_id = wp_insert_attachment( [
				'post_mime_type' => $mime,
				'post_title'     => $title . ' (cover art)',
				'post_status'    => 'inherit',
			], $dest );
			if ( ! $att_id || is_wp_error( $att_id ) ) {
				@unlink( $dest );
				continue;
			}
			wp_update_attachment_metadata( $att_id, wp_generate_attachment_metadata( $att_id, $dest ) );
			return (int) $att_id;
		}
		return null;
	}

	/**
	 * Serve a protected audio file for the in-admin preview player only.
	 * Nonce-gated to moderators — WordPress reads submission files straight
	 * off disk itself when handing them to AzuraCast, so this endpoint never
	 * needs to be reachable by anything outside wp-admin.
	 */
	public static function maybe_serve_preview_download() {
		if ( ! isset( $_GET['cb_radio_dl'] ) ) {
			return;
		}
		$id = absint( $_GET['cb_radio_dl'] );

		if ( ! current_user_can( 'cb_moderate_radio' ) || ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'cb_radio_preview_' . $id ) ) {
			status_header( 403 );
			exit( 'Denied.' );
		}

		$row = CB_Radio_Helpers::get_submission( $id );
		if ( ! $row ) {
			status_header( 404 );
			exit;
		}
		$path = trailingslashit( CB_Radio_Helpers::protected_dir() ) . basename( $row->file_path );
		if ( ! file_exists( $path ) ) {
			status_header( 404 );
			exit;
		}

		header( 'Content-Type: audio/mpeg' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: inline; filename="' . rawurlencode( $row->track_title ) . '.mp3"' );
		readfile( $path );
		exit;
	}
}
