<?php
defined( 'ABSPATH' ) || exit;

/**
 * Moderation list table. Only loaded from CB_Radio_Admin::render_queue(),
 * after WP_List_Table is guaranteed to exist. Never load at bootstrap —
 * WP_List_Table is undefined on the front end and extending it there fatals.
 */
class CB_Radio_Queue_Table extends WP_List_Table {

	public function get_columns() {
		return [
			'art'      => __( 'Art', 'cb-radio' ),
			'title'    => __( 'Track', 'cb-radio' ),
			'artist'   => __( 'Artist', 'cb-radio' ),
			'channel'  => __( 'Channel', 'cb-radio' ),
			'type'     => __( 'Type', 'cb-radio' ),
			'duration' => __( 'Length', 'cb-radio' ),
			'status'   => __( 'Status', 'cb-radio' ),
			'date'     => __( 'Submitted', 'cb-radio' ),
		];
	}

	public function get_views() {
		global $wpdb;
		$counts = $wpdb->get_results(
			'SELECT status, COUNT(*) n FROM ' . CB_Radio_Helpers::table() . ' GROUP BY status', OBJECT_K
		);
		$current = sanitize_key( $_GET['status'] ?? 'pending' );
		$views   = [];
		foreach ( [ 'pending', 'approved', 'live', 'failed', 'rejected', 'retired', 'all' ] as $s ) {
			$n = 'all' === $s
				? array_sum( wp_list_pluck( $counts, 'n' ) )
				: (int) ( $counts[ $s ]->n ?? 0 );
			$url = add_query_arg( [ 'page' => 'cb-pirate-radio', 'status' => $s ], admin_url( 'admin.php' ) );
			$views[ $s ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( $url ),
				$current === $s ? ' class="current"' : '',
				esc_html( ucfirst( $s ) ),
				$n
			);
		}
		return $views;
	}

	public function prepare_items() {
		global $wpdb;
		$status = sanitize_key( $_GET['status'] ?? 'pending' );
		$where  = 'all' === $status ? '1=1' : $wpdb->prepare( 'status = %s', $status );

		$this->items = $wpdb->get_results(
			'SELECT * FROM ' . CB_Radio_Helpers::table() . " WHERE {$where} ORDER BY submitted_at DESC LIMIT 100"
		);
		$this->_column_headers = [ $this->get_columns(), [], [] ];
	}

	public function column_art( $row ) {
		return $row->art_attachment_id
			? wp_get_attachment_image( $row->art_attachment_id, [ 48, 48 ] )
			: '<span class="dashicons dashicons-format-audio" style="font-size:32px;color:#999"></span>';
	}

	public function column_title( $row ) {
		$preview = wp_nonce_url(
			add_query_arg( 'cb_radio_dl', $row->id, home_url( '/' ) ),
			'cb_radio_preview_' . $row->id
		);
		$actions = [];
		$base    = admin_url( 'admin-post.php?action=cb_radio_moderate&id=' . $row->id );

		if ( 'pending' === $row->status ) {
			$actions['cb_approve'] = '<a href="' . esc_url( wp_nonce_url( $base . '&do=approve', 'cb_radio_moderate_' . $row->id ) ) . '">✅ Approve</a>';
			$actions['cb_reject']  = '<a href="' . esc_url( wp_nonce_url( $base . '&do=reject', 'cb_radio_moderate_' . $row->id ) ) . '" onclick="var n=prompt(\'Reason (sent to artist):\');if(n===null)return false;this.href+=\'&note=\'+encodeURIComponent(n);">❌ Reject</a>';
		}
		if ( 'live' === $row->status ) {
			$actions['cb_retire'] = '<a href="' . esc_url( wp_nonce_url( $base . '&do=retire', 'cb_radio_moderate_' . $row->id ) ) . '">🪦 Retire</a>';
		}
		if ( 'failed' === $row->status ) {
			$actions['cb_retry'] = '<a href="' . esc_url( wp_nonce_url( $base . '&do=retry', 'cb_radio_moderate_' . $row->id ) ) . '">🔁 Retry</a>';
		}
		$error = '';
		if ( $row->review_notes && in_array( $row->status, [ 'approved', 'retiring', 'failed' ], true ) ) {
			$error = sprintf(
				'<br><span style="color:#b32d2e;font-size:12px">⚠️ (attempt %d) %s</span>',
				(int) $row->attempts,
				esc_html( $row->review_notes )
			);
		}
		return sprintf(
			'<strong>%s</strong><br><audio controls preload="none" src="%s" style="height:28px;max-width:220px"></audio>%s%s',
			esc_html( $row->track_title ),
			esc_url( $preview ),
			$this->row_actions( $actions ),
			$error
		);
	}

	public function column_artist( $row ) {
		$post = get_post( $row->artist_post_id );
		return $post
			? '<a href="' . esc_url( get_edit_post_link( $post ) ) . '">' . esc_html( $post->post_title ) . '</a>'
			: '—';
	}

	public function column_channel( $row ) {
		$c = CB_Radio_Helpers::channel( $row->channel_slug );
		return esc_html( $c['label'] ?? $row->channel_slug );
	}

	public function column_type( $row ) {
		$icons = [ 'track' => '🎵', 'spot' => '📢', 'skit' => '🎭', 'station_id' => '🏴‍☠️' ];
		return ( $icons[ $row->submission_type ] ?? '' ) . ' ' . esc_html( $row->submission_type );
	}

	public function column_duration( $row ) {
		return $row->duration_secs ? gmdate( 'i:s', $row->duration_secs ) : '—';
	}

	public function column_status( $row ) {
		return '<code>' . esc_html( $row->status ) . '</code>';
	}

	public function column_date( $row ) {
		return esc_html( mysql2date( 'M j, Y g:ia', $row->submitted_at ) );
	}

	public function column_default( $row, $col ) {
		return '—';
	}
}
