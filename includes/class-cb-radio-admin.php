<?php
defined( 'ABSPATH' ) || exit;

/**
 * "Pirate Radio" admin menu + moderation queue (WP_List_Table).
 */
class CB_Radio_Admin {

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_post_cb_radio_moderate', [ __CLASS__, 'moderate' ] );
	}

	public static function menu() {
		add_menu_page(
			__( 'Pirate Radio', 'cb-radio' ),
			__( 'Pirate Radio', 'cb-radio' ),
			'cb_moderate_radio',
			'cb-pirate-radio',
			[ __CLASS__, 'render_queue' ],
			'dashicons-controls-volumeon',
			26
		);
		add_submenu_page(
			'cb-pirate-radio',
			__( 'Submission Queue', 'cb-radio' ),
			__( 'Queue', 'cb-radio' ),
			'cb_moderate_radio',
			'cb-pirate-radio',
			[ __CLASS__, 'render_queue' ]
		);
	}

	public static function render_queue() {
		if ( ! class_exists( 'WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}
		require_once CB_RADIO_DIR . 'includes/class-cb-radio-queue-table.php';
		$table = new CB_Radio_Queue_Table();
		$table->prepare_items();
		?>
		<style>
			/* Defensive: guarantee these are visible even if something else on
			   the site has broad CSS touching row-actions (a generic class-name
			   collision is the usual cause if an action link goes missing). */
			.row-actions .cb_approve, .row-actions .cb_reject,
			.row-actions .cb_retire, .row-actions .cb_retry {
				display: inline !important;
				visibility: visible !important;
				opacity: 1 !important;
				position: static !important;
				width: auto !important;
				height: auto !important;
			}
		</style>
		<div class="wrap">
			<h1>📻 Submission Queue</h1>
			<?php if ( isset( $_GET['moderated'] ) ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Done.', 'cb-radio' ); ?></p></div>
			<?php endif; ?>
			<?php $table->views(); ?>
			<form method="get">
				<input type="hidden" name="page" value="cb-pirate-radio">
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/** Approve / Reject / Retire handler (PRG). */
	public static function moderate() {
		if ( ! current_user_can( 'cb_moderate_radio' ) ) {
			wp_die( 'Nope.' );
		}
		$id     = absint( $_GET['id'] ?? 0 );
		$action = sanitize_key( $_GET['do'] ?? '' );
		check_admin_referer( 'cb_radio_moderate_' . $id );

		global $wpdb;
		$now = current_time( 'mysql', true );

		switch ( $action ) {
			case 'approve':
				$wpdb->update( CB_Radio_Helpers::table(),
					[ 'status' => 'approved', 'reviewer_id' => get_current_user_id(), 'reviewed_at' => $now ],
					[ 'id' => $id, 'status' => 'pending' ]
				);
				// Handoff happens on the next cron tick — see CB_Radio_Queue.
				break;

			case 'reject':
				$note = sanitize_textarea_field( wp_unslash( $_GET['note'] ?? '' ) );
				$wpdb->update( CB_Radio_Helpers::table(),
					[ 'status' => 'rejected', 'review_notes' => $note, 'reviewer_id' => get_current_user_id(), 'reviewed_at' => $now ],
					[ 'id' => $id ]
				);
				// TODO: email the artist with $note.
				break;

			case 'retire':
				$wpdb->update( CB_Radio_Helpers::table(),
					[ 'status' => 'retiring', 'reviewer_id' => get_current_user_id(), 'reviewed_at' => $now ],
					[ 'id' => $id, 'status' => 'live' ]
				);
				// Queue picks up 'retiring' rows and takes them out of
				// AzuraCast rotation via CB_Radio_AzuraCast::unassign_playlists()
				// — the file itself stays in AzuraCast, this is reversible.
				break;

			case 'retry':
				// Reset a 'failed' row so the queue picks it back up — used
				// after fixing whatever the review_notes error described
				// (bad API key, station_id typo, AzuraCast was down, etc).
				$wpdb->update( CB_Radio_Helpers::table(),
					[ 'status' => 'approved', 'attempts' => 0, 'review_notes' => null ],
					[ 'id' => $id, 'status' => 'failed' ]
				);
				break;
		}

		wp_safe_redirect( add_query_arg( 'moderated', 1, admin_url( 'admin.php?page=cb-pirate-radio' ) ) );
		exit;
	}
}
