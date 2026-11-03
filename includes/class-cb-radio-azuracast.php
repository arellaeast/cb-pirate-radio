<?php
defined( 'ABSPATH' ) || exit;

/**
 * Thin client for the AzuraCast REST API. This is the entire "handoff" now —
 * no external workflow tool, no webhook, no callback. WordPress reads the
 * uploaded file straight off disk and talks to AzuraCast directly.
 *
 * Endpoints used (confirmed against AzuraCast's documented REST conventions
 * and real-world usage reports — every AzuraCast install also serves its own
 * exact-version API docs at {your-azuracast-url}/api/, worth checking if a
 * call here ever comes back with an unexpected shape on your version):
 *   POST   /api/station/{id}/files            upload (JSON: path + base64 file)
 *   PUT    /api/station/{id}/files/{file_id}   update metadata / playlists
 *   DELETE /api/station/{id}/files/{file_id}   remove
 *   GET    /api/stations                       connectivity/auth check
 */
class CB_Radio_AzuraCast {

	/** @return array|WP_Error Decoded JSON body, or WP_Error on failure. */
	private static function request( $method, $path, $body = null, $timeout = 30 ) {
		$settings = CB_Radio_Helpers::settings();
		if ( empty( $settings['azuracast_url'] ) || empty( $settings['azuracast_key'] ) ) {
			return new WP_Error( 'cb_radio_not_configured', __( 'AzuraCast URL and API key are not set in Pirate Radio → Settings.', 'cb-radio' ) );
		}

		$url  = trailingslashit( $settings['azuracast_url'] ) . 'api/' . ltrim( $path, '/' );
		$args = [
			'method'  => $method,
			'timeout' => $timeout,
			'headers' => [
				'X-API-Key'    => $settings['azuracast_key'],
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			],
		];
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code >= 300 ) {
			$message = is_array( $data ) && ! empty( $data['message'] ) ? $data['message'] : $raw;
			return new WP_Error(
				'cb_radio_azuracast_http_' . $code,
				sprintf( 'AzuraCast returned HTTP %d: %s', $code, wp_strip_all_tags( (string) $message ) )
			);
		}

		return is_array( $data ) ? $data : [];
	}

	/**
	 * Upload a local file to a station's media library.
	 * $remote_path is where it lands in AzuraCast's file manager — namespaced
	 * under cb-radio/ so it's easy to spot and doesn't collide with anything
	 * an admin uploads by hand through the AzuraCast UI.
	 *
	 * @return array|WP_Error The created file record (has an 'id') or WP_Error.
	 */
	public static function upload( $station_id, $local_path, $filename ) {
		if ( ! file_exists( $local_path ) ) {
			return new WP_Error( 'cb_radio_file_missing', __( 'Local file no longer exists.', 'cb-radio' ) );
		}
		$bytes = file_get_contents( $local_path ); // phpcs:ignore -- local protected-dir file, not a remote fetch
		if ( false === $bytes ) {
			return new WP_Error( 'cb_radio_file_unreadable', __( 'Could not read the local file.', 'cb-radio' ) );
		}
		// Uploads can run large and base64 inflates size ~33%; give this more
		// room than the default request budget.
		return self::request( 'POST', "station/{$station_id}/files", [
			'path' => 'cb-radio/' . $filename,
			'file' => base64_encode( $bytes ), // phpcs:ignore -- required transport encoding for this endpoint
		], 60 );
	}

	/**
	 * Set title/artist/album and (optionally) which playlist(s) a file belongs to.
	 * $playlist_id may be 0/empty to skip playlist assignment (e.g. if the
	 * channel has none configured yet) — the file still uploads either way.
	 */
	public static function update_metadata( $station_id, $file_id, $title, $artist, $album, $playlist_id = 0 ) {
		$body = [
			'title'  => $title,
			'artist' => $artist,
			'album'  => $album,
		];
		if ( $playlist_id ) {
			$body['playlists'] = [ (int) $playlist_id ];
		}
		return self::request( 'PUT', "station/{$station_id}/files/{$file_id}", $body );
	}

	/**
	 * Take a file out of rotation WITHOUT deleting it from AzuraCast — clears
	 * its playlist assignment(s) so AutoDJ stops picking it up, but the media
	 * file itself stays in AzuraCast's library. This is the default "retire"
	 * behavior: reversible (re-assign a playlist in AzuraCast to bring it
	 * back), and safe for an artist to trigger themselves without any risk of
	 * destroying something by mistake.
	 */
	public static function unassign_playlists( $station_id, $file_id ) {
		return self::request( 'PUT', "station/{$station_id}/files/{$file_id}", [
			'playlists' => [],
		] );
	}

	/**
	 * Permanently remove a file from AzuraCast. Not wired to any button in
	 * this plugin by default (retiring uses unassign_playlists() instead) —
	 * kept available for a future "actually delete from AzuraCast" admin
	 * action, since some self-hosters will want real storage cleanup.
	 */
	public static function delete( $station_id, $file_id ) {
		return self::request( 'DELETE', "station/{$station_id}/files/{$file_id}" );
	}

	/**
	 * List every media file in a station's library, playlists included.
	 * Used by the voting reconciler to see what is actually on the station.
	 *
	 * @return array|WP_Error List of file records (each has id, title,
	 *                        artist, path, playlists).
	 */
	public static function files( $station_id ) {
		return self::request( 'GET', "station/{$station_id}/files", null, 60 );
	}

	/** List a station's playlists (id, name, type, is_enabled, ...). */
	public static function playlists( $station_id ) {
		return self::request( 'GET', "station/{$station_id}/playlists", null, 30 );
	}

	/**
	 * Set ONLY the artist field on an existing file, leaving its title,
	 * album and playlist assignments exactly as they are.
	 *
	 * Note the singular route: on current AzuraCast builds the media-edit
	 * endpoint is /station/{id}/file/{file_id}; the plural form used by
	 * update_metadata() above answers 405 on those builds. The reconciler
	 * uses this method to align an artist string with the artist's page
	 * name so the now-playing vote gate can resolve the track.
	 */
	public static function update_file_artist( $station_id, $file_id, $artist ) {
		return self::request( 'PUT', "station/{$station_id}/file/{$file_id}", [
			'artist' => $artist,
		] );
	}

	/**
	 * Lightweight connectivity + auth check for the Settings screen's "Test
	 * Connection" button — lists stations visible to the key without touching
	 * any specific station or file.
	 *
	 * @return array|WP_Error List of station names on success.
	 */
	public static function test_connection() {
		$result = self::request( 'GET', 'stations', null, 15 );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$names = [];
		foreach ( $result as $station ) {
			if ( ! empty( $station['name'] ) ) {
				$names[] = $station['name'];
			}
		}
		return $names;
	}
}
