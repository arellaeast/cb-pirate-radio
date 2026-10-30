<?php
defined( 'ABSPATH' ) || exit;
/** @var array $rows set by CB_Radio_MyTracks::shortcode() */

$status_labels = [
	'pending'   => __( 'Pending review', 'cb-radio' ),
	'approved'  => __( 'Approved — sending to AzuraCast…', 'cb-radio' ),
	'live'      => __( 'On the air', 'cb-radio' ),
	'rejected'  => __( 'Not approved', 'cb-radio' ),
	'retiring'  => __( 'Taking down…', 'cb-radio' ),
	'retired'   => __( 'Taken down', 'cb-radio' ),
	'failed'    => __( 'Something went wrong', 'cb-radio' ),
];
?>
<div class="cb-my-tracks cb-tuner">

	<?php if ( isset( $_GET['cb_radio_updated'] ) ) : ?>
		<p class="cb-radio-notice cb-radio-notice--ok" style="margin:0 0 14px"><?php esc_html_e( 'Updated.', 'cb-radio' ); ?></p>
	<?php endif; ?>

	<?php if ( empty( $rows ) ) : ?>
		<p class="cb-tuner__empty"><?php esc_html_e( 'Nothing here yet.', 'cb-radio' ); ?></p>
	<?php else : ?>

	<ul class="cb-my-tracks__list">
		<?php foreach ( $rows as $row ) :
			$status = $status_labels[ $row->status ] ?? $row->status;
			$channel = CB_Radio_Helpers::channel( $row->channel_slug );
			$nonce_action = 'live' === $row->status ? 'takedown' : 'withdraw';
			$action_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=cb_radio_self_action&id=' . $row->id . '&do=' . $nonce_action ),
				'cb_radio_self_' . $nonce_action . '_' . $row->id
			);
			?>
			<li class="cb-my-tracks__row">
				<span class="cb-my-tracks__art">
					<?php if ( $row->art_attachment_id ) : ?>
						<?php echo wp_get_attachment_image( $row->art_attachment_id, [ 48, 48 ] ); ?>
					<?php else : ?>
						<span class="cb-my-tracks__art-fallback">🏴‍☠️</span>
					<?php endif; ?>
				</span>
				<span class="cb-my-tracks__info">
					<span class="cb-my-tracks__title"><?php echo esc_html( $row->track_title ); ?></span>
					<span class="cb-my-tracks__meta">
						<?php echo esc_html( $channel['label'] ?? $row->channel_slug ); ?>
						· <?php echo esc_html( mysql2date( 'M j, Y', $row->submitted_at ) ); ?>
					</span>
				</span>
				<span class="cb-my-tracks__status cb-my-tracks__status--<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( $status ); ?></span>
				<span class="cb-my-tracks__action">
					<?php if ( in_array( $row->status, [ 'pending', 'approved', 'rejected', 'failed' ], true ) ) : ?>
						<a href="<?php echo esc_url( $action_url ); ?>"
							onclick="return confirm('<?php esc_attr_e( 'Withdraw this submission?', 'cb-radio' ); ?>');">
							<?php esc_html_e( 'Withdraw', 'cb-radio' ); ?>
						</a>
					<?php elseif ( 'live' === $row->status ) : ?>
						<a href="<?php echo esc_url( $action_url ); ?>"
							onclick="return confirm('<?php esc_attr_e( 'Take this track off the air? It stops airing right away — you can ask the Captain to bring it back later if you change your mind.', 'cb-radio' ); ?>');">
							<?php esc_html_e( 'Take down', 'cb-radio' ); ?>
						</a>
					<?php endif; ?>
				</span>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php endif; ?>
</div>
