<?php
defined( 'ABSPATH' ) || exit;

$settings   = CB_Radio_Helpers::settings();
$channels   = CB_Radio_Helpers::channels();
$has_artist = (bool) CB_Radio_Artists::for_user( get_current_user_id() );
?>
<div class="cb-radio-submit">

	<?php if ( isset( $_GET['cb_radio_success'] ) ) : ?>
		<p class="cb-radio-notice cb-radio-notice--ok">🏴‍☠️ Track received. It'll hit the airwaves once the Captain gives it a listen.</p>
	<?php elseif ( isset( $_GET['cb_radio_error'] ) ) : ?>
		<p class="cb-radio-notice cb-radio-notice--err"><?php echo esc_html( rawurldecode( wp_unslash( $_GET['cb_radio_error'] ) ) ); ?></p>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
		<input type="hidden" name="action" value="cb_radio_submit">
		<?php wp_nonce_field( 'cb_radio_submit' ); ?>

		<?php if ( ! $has_artist ) : ?>
			<fieldset>
				<legend><?php esc_html_e( 'Who are you?', 'cb-radio' ); ?></legend>
				<p><label><?php esc_html_e( 'Artist / band name', 'cb-radio' ); ?><br>
					<input type="text" name="artist_name" required maxlength="120"></label></p>
				<p><label><?php esc_html_e( 'Short bio', 'cb-radio' ); ?><br>
					<textarea name="artist_bio" rows="3" maxlength="1000"></textarea></label></p>
				<p><label><?php esc_html_e( 'Neighborhood you rep', 'cb-radio' ); ?><br>
					<input type="text" name="artist_hood" maxlength="80" placeholder="Hampden, Pigtown, Highlandtown…"></label></p>
				<p><label><?php esc_html_e( 'Links (one per line — Bandcamp, IG, etc.)', 'cb-radio' ); ?><br>
					<textarea name="artist_links" rows="3"></textarea></label></p>
			</fieldset>
		<?php endif; ?>

		<fieldset>
			<legend><?php esc_html_e( 'The track', 'cb-radio' ); ?></legend>
			<p><label><?php esc_html_e( 'Track title', 'cb-radio' ); ?><br>
				<input type="text" name="track_title" required maxlength="191"></label></p>
			<p><label><?php esc_html_e( 'Channel', 'cb-radio' ); ?><br>
				<select name="channel_slug" required>
					<option value=""><?php esc_html_e( '— pick a channel —', 'cb-radio' ); ?></option>
					<?php foreach ( $channels as $c ) : ?>
						<option value="<?php echo esc_attr( $c['slug'] ); ?>"><?php echo esc_html( $c['label'] ); ?></option>
					<?php endforeach; ?>
				</select></label></p>
			<input type="hidden" name="submission_type" value="track">
			<p><label><?php printf( esc_html__( 'MP3 file (max %d MB)', 'cb-radio' ), (int) $settings['max_file_mb'] ); ?><br>
				<input type="file" name="audio_file" accept="audio/mpeg,.mp3" required></label></p>
			<p><label><?php esc_html_e( 'Album art (square JPG/PNG, optional)', 'cb-radio' ); ?><br>
				<input type="file" name="album_art" accept="image/jpeg,image/png"></label></p>
		</fieldset>

		<fieldset>
			<legend><?php esc_html_e( 'License', 'cb-radio' ); ?></legend>
			<p><label>
				<input type="checkbox" name="license_accept" value="1" required>
				<?php echo esc_html( $settings['license_text'] ); ?>
				<small>(<?php echo esc_html( $settings['license_version'] ); ?>)</small>
			</label></p>
		</fieldset>

		<p><button type="submit" class="cb-radio-btn"><?php esc_html_e( 'Send it to the Captain', 'cb-radio' ); ?></button></p>
	</form>
</div>
