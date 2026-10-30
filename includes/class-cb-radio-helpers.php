<?php
defined( 'ABSPATH' ) || exit;

class CB_Radio_Helpers {

	const OPT = 'cb_radio_settings';

	/** Fetch settings with sane defaults. */
	public static function settings() {
		return wp_parse_args( get_option( self::OPT, [] ), [
			'azuracast_url'    => '',        // e.g. https://radio.captainbaltimore.com — required
			'azuracast_key'    => '',        // API key from AzuraCast: user menu -> My API Keys — required
			'max_file_mb'      => 25,
			'max_duration'     => 600,       // seconds
			'license_version'  => 'v1',
			'license_text'     => 'I own or control all rights to this recording and grant Captain Baltimore a non-exclusive license to stream it.',
			'channels'         => [],        // [ { slug, label, station_id, music_playlist_id, interstitial_playlist_id, stream_url } ]
		] );
	}

	public static function channels() {
		$channels = self::settings()['channels'];
		return is_array( $channels ) ? $channels : [];
	}

	public static function channel( $slug ) {
		foreach ( self::channels() as $c ) {
			if ( isset( $c['slug'] ) && $c['slug'] === $slug ) {
				return $c;
			}
		}
		return null;
	}

	/**
	 * Channels with display fields (freq, preset) resolved.
	 * A channel's admin-set 'freq' / 'preset' win; unset ones are auto-filled so
	 * the dial always has something sane to show. Presets are 1-6 (matches a
	 * standard head-unit preset row) and are only auto-assigned to the first six
	 * channels that don't already claim one — this is the single source of truth
	 * both the initial PHP render and the JS localization read from, so they
	 * can never disagree on numbering.
	 */
	public static function channels_display() {
		$channels = self::channels();
		$used     = [];
		foreach ( $channels as $c ) {
			if ( ! empty( $c['preset'] ) ) {
				$used[ (int) $c['preset'] ] = true;
			}
		}
		$next = 1;
		$out  = [];
		foreach ( $channels as $i => $c ) {
			$freq = ( isset( $c['freq'] ) && '' !== $c['freq'] )
				? $c['freq']
				: number_format( 87.9 + $i * 2.2, 2 );

			$preset = ! empty( $c['preset'] ) ? (int) $c['preset'] : null;
			if ( null === $preset ) {
				while ( $next <= 6 && isset( $used[ $next ] ) ) {
					$next++;
				}
				if ( $next <= 6 ) {
					$preset       = $next;
					$used[ $next ] = true;
					$next++;
				}
			}

			$out[] = array_merge( $c, [ 'freq' => $freq, 'preset' => $preset ] );
		}
		return $out;
	}

	public static function protected_dir() {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . 'cb-radio-protected';
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . CB_RADIO_TABLE;
	}

	public static function get_submission( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id
		) );
	}
}
