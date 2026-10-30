<?php
defined( 'ABSPATH' ) || exit;
/** @var string $default_period set by CB_Radio_Charts::shortcode() */
?>
<div class="cb-charts" id="cb-charts" data-period="<?php echo esc_attr( $default_period ); ?>" data-press="<?php echo esc_attr( CB_Radio_Themes::active_press() ); ?>">
	<div class="cb-charts__head">
		<h2 class="cb-charts__title">🏴‍☠️ <?php echo esc_html( CB_Radio_Themes::branding()['chart_title'] ); ?></h2>
		<div class="cb-charts__toggle" role="tablist" aria-label="<?php esc_attr_e( 'Chart period', 'cb-radio' ); ?>">
			<button role="tab" data-period="week" class="<?php echo 'week' === $default_period ? 'is-active' : ''; ?>"><?php esc_html_e( 'This Week', 'cb-radio' ); ?></button>
			<button role="tab" data-period="month" class="<?php echo 'month' === $default_period ? 'is-active' : ''; ?>"><?php esc_html_e( 'This Month', 'cb-radio' ); ?></button>
			<button class="cb-display-toggle" type="button" aria-haspopup="true" aria-expanded="false"
				aria-label="<?php esc_attr_e( 'Display options', 'cb-radio' ); ?>">Aa</button>
		</div>
	</div>
	<p class="cb-charts__demo-flag" id="cb-charts-demo" hidden><?php esc_html_e( 'DEMO SIGNAL — sample chart until real votes land', 'cb-radio' ); ?></p>
	<ol class="cb-charts__list" id="cb-charts-list">
		<li class="cb-charts__loading"><?php esc_html_e( 'Tuning…', 'cb-radio' ); ?></li>
	</ol>
</div>
