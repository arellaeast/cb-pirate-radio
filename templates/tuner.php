<?php
defined( 'ABSPATH' ) || exit;

$channels = CB_Radio_Helpers::channels_display();
$presets  = array_fill( 1, 6, null );
$overflow = [];
foreach ( $channels as $c ) {
	if ( $c['preset'] && ! $presets[ $c['preset'] ] ) {
		$presets[ $c['preset'] ] = $c;
	} else {
		$overflow[] = $c;
	}
}
$first = $channels[0] ?? null;
?>
<div class="cb-tuner" id="cb-tuner" data-press="<?php echo esc_attr( CB_Radio_Themes::active_press() ); ?>">
	<div class="cb-tuner__mast">
		<span class="cb-tuner__beacon" aria-hidden="true"></span>
		<span class="cb-tuner__callsign"><?php echo esc_html( CB_Radio_Themes::branding()['callsign'] ); ?></span>
		<button class="cb-display-toggle" type="button" aria-haspopup="true" aria-expanded="false"
			aria-label="<?php esc_attr_e( 'Display options', 'cb-radio' ); ?>">Aa</button>
	</div>

	<?php if ( empty( $channels ) ) : ?>
		<p class="cb-tuner__empty"><?php esc_html_e( 'No channels on the dial yet. Add some in Pirate Radio → Settings.', 'cb-radio' ); ?></p>
	<?php else : ?>

	<div class="cb-tuner__unit">

		<div class="cb-tuner__readout">
			<button class="cb-tuner__seek" id="cb-seek-prev" aria-label="<?php esc_attr_e( 'Previous channel', 'cb-radio' ); ?>">◀◀</button>
			<div class="cb-tuner__display">
				<span class="cb-tuner__display-label" id="cb-display-label"><?php echo esc_html( $first['label'] ?? '' ); ?></span>
				<span class="cb-tuner__digits" id="cb-display-freq"><?php echo esc_html( $first['freq'] ?? '--.--' ); ?></span>
				<span class="cb-tuner__band">FM</span>
			</div>
			<button class="cb-tuner__seek" id="cb-seek-next" aria-label="<?php esc_attr_e( 'Next channel', 'cb-radio' ); ?>">▶▶</button>
		</div>

		<div class="cb-tuner__scale" aria-hidden="true">
			<div class="cb-tuner__ticks">
				<?php for ( $t = 0; $t < 46; $t++ ) : ?><span class="cb-tuner__tick"></span><?php endfor; ?>
			</div>
			<div class="cb-tuner__needle" id="cb-scale-needle"></div>
		</div>

		<div class="cb-tuner__presets" role="tablist" aria-label="<?php esc_attr_e( 'Station presets', 'cb-radio' ); ?>">
			<?php for ( $p = 1; $p <= 6; $p++ ) :
				$c = $presets[ $p ]; ?>
				<button class="cb-tuner__preset<?php echo ( $c && $first && $c['slug'] === $first['slug'] ) ? ' is-tuned' : ''; ?>"
					role="tab"
					<?php if ( $c ) : ?>
						data-channel="<?php echo esc_attr( $c['slug'] ); ?>"
						data-stream="<?php echo esc_attr( $c['stream_url'] ?? '' ); ?>"
					<?php else : ?>
						disabled
					<?php endif; ?>>
					<span class="cb-tuner__preset-n">P<?php echo (int) $p; ?></span>
					<span class="cb-tuner__preset-freq"><?php echo $c ? esc_html( $c['freq'] ) : '—'; ?></span>
				</button>
			<?php endfor; ?>
		</div>

		<?php if ( $overflow ) : ?>
		<details class="cb-tuner__more">
			<summary><?php esc_html_e( 'More channels', 'cb-radio' ); ?></summary>
			<div class="cb-tuner__more-list">
				<?php foreach ( $overflow as $c ) : ?>
					<button data-channel="<?php echo esc_attr( $c['slug'] ); ?>"
						data-stream="<?php echo esc_attr( $c['stream_url'] ?? '' ); ?>">
						<?php echo esc_html( $c['label'] ); ?> · <?php echo esc_html( $c['freq'] ); ?>
					</button>
				<?php endforeach; ?>
			</div>
		</details>
		<?php endif; ?>

	</div>

	<div class="cb-tuner__deck">
		<div class="cb-tuner__art">
			<img id="cb-np-art" src="" alt="" hidden>
			<div class="cb-tuner__art-fallback" id="cb-np-art-fallback">🏴‍☠️</div>
		</div>
		<div class="cb-tuner__meta">
			<div class="cb-tuner__nowlabel"><span class="cb-tuner__dot"></span> <?php esc_html_e( 'CURRENTLY PLAYING', 'cb-radio' ); ?></div>
			<div class="cb-tuner__track" id="cb-np-title">—</div>
			<a class="cb-tuner__artist" id="cb-np-artist" href="#">—</a>
			<div class="cb-tuner__hood" id="cb-np-hood"></div>
			<div class="cb-tuner__listeners" id="cb-np-listeners"></div>
			<div class="cb-tuner__votes" id="cb-votes" data-submission="0">
				<button class="cb-tuner__vote" id="cb-vote-up" data-vote="1" aria-pressed="false" aria-label="<?php esc_attr_e( 'Thumbs up', 'cb-radio' ); ?>">👍</button>
				<button class="cb-tuner__vote" id="cb-vote-down" data-vote="-1" aria-pressed="false" aria-label="<?php esc_attr_e( 'Thumbs down', 'cb-radio' ); ?>">👎</button>
			</div>
		</div>
		<div class="cb-tuner__controls">
			<button class="cb-tuner__play" id="cb-play" aria-label="<?php esc_attr_e( 'Tune in', 'cb-radio' ); ?>">▶</button>
			<input type="range" id="cb-volume" min="0" max="1" step="0.05" value="0.9"
				aria-label="<?php esc_attr_e( 'Volume', 'cb-radio' ); ?>">
		</div>
	</div>

	<audio id="cb-audio" preload="none"></audio>
	<?php endif; ?>
</div>
