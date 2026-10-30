<?php
defined( 'ABSPATH' ) || exit;

/**
 * Appearance: admin-built color/border/interaction themes for the tuner +
 * charts, mirroring the Crabs in a Barrel theme-builder pattern.
 *
 * Themes live in wp_options (no DB tables). Each theme fills the seven
 * color slots plus structural border settings and interaction style
 * (hover glow, press effect) that the front-end CSS reads as custom
 * properties / a data-press attribute. The active theme is emitted as
 * scoped inline CSS after the stylesheet, so it wins.
 */
class CB_Radio_Themes {

	const OPT_THEMES = 'cb_radio_themes';
	const OPT_ACTIVE = 'cb_radio_active_theme';
	const OPT_BRAND  = 'cb_radio_branding';

	/** slot => [ label, description ] */
	public static function slots() {
		return [
			'hull'    => [ __( 'Hull', 'cb-radio' ),      __( 'Main background', 'cb-radio' ) ],
			'panel'   => [ __( 'Panel', 'cb-radio' ),     __( 'Raised surfaces: buttons, art frame', 'cb-radio' ) ],
			'brass'   => [ __( 'Brass', 'cb-radio' ),     __( 'Hardware accents: artist links, masthead, art frame', 'cb-radio' ) ],
			'signal'  => [ __( 'Signal', 'cb-radio' ),    __( 'The beacon: live dot, tuned preset, #1 rank, active states', 'cb-radio' ) ],
			'ink'     => [ __( 'Ink', 'cb-radio' ),       __( 'Primary text', 'cb-radio' ) ],
			'ink_dim' => [ __( 'Ink (dim)', 'cb-radio' ), __( 'Muted text: labels, listener counts, channels', 'cb-radio' ) ],
			'border'  => [ __( 'Border', 'cb-radio' ),    __( 'Structural borders: panel edges, dividers, button outlines', 'cb-radio' ) ],
		];
	}

	public static function border_styles() {
		return [
			'solid'  => __( 'Solid', 'cb-radio' ),
			'dashed' => __( 'Dashed', 'cb-radio' ),
			'dotted' => __( 'Dotted', 'cb-radio' ),
			'double' => __( 'Double', 'cb-radio' ),
			'none'   => __( 'None', 'cb-radio' ),
		];
	}

	public static function press_options() {
		return [
			'none'     => __( 'None — browser default only', 'cb-radio' ),
			'scale'    => __( 'Press down (scale)', 'cb-radio' ),
			'glow'     => __( 'Glow', 'cb-radio' ),
			'brighten' => __( 'Brighten', 'cb-radio' ),
			'invert'   => __( 'Invert', 'cb-radio' ),
		];
	}

	public static function hover_options() {
		return [
			'0'    => __( 'Off', 'cb-radio' ),
			'0.2'  => __( 'Subtle', 'cb-radio' ),
			'0.35' => __( 'Normal', 'cb-radio' ),
			'0.6'  => __( 'Strong', 'cb-radio' ),
			'1'    => __( 'Maximum', 'cb-radio' ),
		];
	}

	/**
	 * key => [ label, css stack, Google Fonts family spec or null for
	 * system-only stacks ]. The Google-backed options only cost a network
	 * request on pages that actually enqueue the tuner — see google_fonts_url().
	 */
	public static function font_options() {
		return [
			'mono'       => [
				__( 'Digital (default)', 'cb-radio' ),
				'ui-monospace, "SF Mono", "Courier New", monospace',
				null,
			],
			'typewriter' => [
				__( 'Typewriter', 'cb-radio' ),
				'"Courier New", Courier, monospace',
				null,
			],
			'sans'       => [
				__( 'Clean Sans', 'cb-radio' ),
				'-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif',
				null,
			],
			'serif'      => [
				__( 'Serif', 'cb-radio' ),
				'Georgia, "Times New Roman", serif',
				null,
			],
			'condensed'  => [
				__( 'Condensed Display', 'cb-radio' ),
				'"Oswald", sans-serif',
				'Oswald:wght@400;700',
			],
			'vintage'    => [
				__( 'Vintage Typewriter', 'cb-radio' ),
				'"Special Elite", cursive',
				'Special+Elite',
			],
		];
	}

	/** One combined Google Fonts URL covering every font option that needs one. */
	public static function google_fonts_url() {
		$families = [];
		foreach ( self::font_options() as $opt ) {
			if ( ! empty( $opt[2] ) ) {
				$families[] = 'family=' . $opt[2];
			}
		}
		return 'https://fonts.googleapis.com/css2?' . implode( '&', $families ) . '&display=swap';
	}

	/** Border/interaction defaults shared by both built-ins unless overridden. */
	private static function default_border() {
		return [ 'width' => 1, 'style' => 'solid', 'radius' => 10, 'radius_sm' => 6 ];
	}

	/** Built-in, non-deletable default — the shipped palette. */
	public static function default_theme() {
		return [
			'id'     => 'harbor-night',
			'name'   => __( 'Harbor Night', 'cb-radio' ),
			'locked' => true,
			'colors' => [
				'hull'    => '#10151f',
				'panel'   => '#1a2230',
				'brass'   => '#c9a35c',
				'signal'  => '#ff5c2b',
				'ink'     => '#e8e4d8',
				'ink_dim' => '#8d94a3',
				'border'  => '#2a3446',
			],
			'border' => self::default_border(),
			'press'  => 'scale',
			'hover'  => '0.35',
			'font'   => 'mono',
		];
	}

	/** Built-in high-contrast light theme for accessibility. Also locked. */
	public static function daymark_theme() {
		return [
			'id'     => 'daymark',
			'name'   => __( 'Daymark (High Contrast)', 'cb-radio' ),
			'locked' => true,
			'colors' => [
				'hull'    => '#ffffff',
				'panel'   => '#efefe6',
				'brass'   => '#6b4e14', // dark gold — ~8:1 on white
				'signal'  => '#b32400', // dark signal red — ~6.5:1 on white
				'ink'     => '#111111',
				'ink_dim' => '#3d3d3d', // still ~10:1, "dim" without being faint
				'border'  => '#111111',
			],
			// Thicker border + stronger hover/press feedback: easier to track
			// focus and confirm a tap landed when contrast is doing the work.
			'border' => [ 'width' => 2, 'style' => 'solid', 'radius' => 10, 'radius_sm' => 6 ],
			'press'  => 'brighten',
			'hover'  => '0.6',
			// A plain sans-serif reads more reliably than the digital/monospace
			// style at low vision — the whole point of this theme.
			'font'   => 'sans',
		];
	}

	/** Backfill any field a theme saved by an older version of the plugin is missing. */
	private static function normalize( $t ) {
		$d           = self::default_theme();
		$t['colors'] = wp_parse_args( is_array( $t['colors'] ?? null ) ? $t['colors'] : [], $d['colors'] );
		$t['border'] = wp_parse_args( is_array( $t['border'] ?? null ) ? $t['border'] : [], $d['border'] );
		$t['press']  = in_array( $t['press'] ?? '', array_keys( self::press_options() ), true ) ? $t['press'] : $d['press'];
		$t['hover']  = is_numeric( $t['hover'] ?? null ) ? (string) $t['hover'] : $d['hover'];
		$t['font']   = array_key_exists( $t['font'] ?? '', self::font_options() ) ? $t['font'] : $d['font'];
		return $t;
	}

	public static function themes() {
		$custom = get_option( self::OPT_THEMES, [] );
		$all    = [
			'harbor-night' => self::default_theme(),
			'daymark'      => self::daymark_theme(),
		];
		foreach ( (array) $custom as $t ) {
			if ( ! empty( $t['id'] ) ) {
				$all[ $t['id'] ] = self::normalize( $t );
			}
		}
		return $all;
	}

	public static function active_theme() {
		$themes = self::themes();
		$active = get_option( self::OPT_ACTIVE, 'harbor-night' );
		return $themes[ $active ] ?? $themes['harbor-night'];
	}

	public static function branding() {
		return wp_parse_args( get_option( self::OPT_BRAND, [] ), [
			'callsign'          => 'CAPT·BLTMR — PIRATE RADIO',
			'chart_title'       => __( 'The Captain’s Top 10', 'cb-radio' ),
			'default_text_size' => '1', // 1 | 1.2 | 1.4
		] );
	}

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 15 );
		add_action( 'admin_post_cb_radio_save_theme', [ __CLASS__, 'save_theme' ] );
		add_action( 'admin_post_cb_radio_delete_theme', [ __CLASS__, 'delete_theme' ] );
		add_action( 'admin_post_cb_radio_activate_theme', [ __CLASS__, 'activate_theme' ] );
		add_action( 'admin_post_cb_radio_save_branding', [ __CLASS__, 'save_branding' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'admin_assets' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'output_css' ], 20 );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'localize_display_data' ], 21 );
	}

	/** The active theme's press mode, for templates to print as a data-press attribute. */
	public static function active_press() {
		return self::active_theme()['press'] ?? 'scale';
	}

	/** Ship every theme's full styling to the front end so switching needs no reload. */
	public static function localize_display_data() {
		$themes = [];
		foreach ( self::themes() as $t ) {
			$font_opt = self::font_options()[ $t['font'] ] ?? self::font_options()['mono'];
			$themes[] = [
				'id'        => $t['id'],
				'name'      => $t['name'],
				'colors'    => $t['colors'],
				'border'    => $t['border'],
				'press'     => $t['press'],
				'hover'     => $t['hover'],
				'fontStack' => $font_opt[1],
			];
		}
		wp_localize_script( 'cb-radio-tuner', 'CB_RADIO_DISPLAY', [
			'themes'          => $themes,
			'defaultTheme'    => self::active_theme()['id'],
			'defaultTextSize' => self::branding()['default_text_size'],
			'labels'          => [
				'display'  => __( 'Display', 'cb-radio' ),
				'theme'    => __( 'Theme', 'cb-radio' ),
				'textSize' => __( 'Text size', 'cb-radio' ),
				'normal'   => __( 'Normal', 'cb-radio' ),
				'large'    => __( 'Large', 'cb-radio' ),
				'xlarge'   => __( 'Extra large', 'cb-radio' ),
				'reset'    => __( 'Use site default', 'cb-radio' ),
			],
		] );
	}

	/** Scoped variable overrides, appended after the registered stylesheet. */
	public static function output_css() {
		$theme = self::active_theme();
		$css   = '';
		if ( 'harbor-night' !== $theme['id'] ) {
			$css .= '.cb-tuner, .cb-charts {';
			foreach ( self::slots() as $slot => $meta ) {
				$hex = sanitize_hex_color( $theme['colors'][ $slot ] ?? '' );
				if ( $hex ) {
					$css .= '--cb-' . str_replace( '_', '-', $slot ) . ':' . $hex . ';';
				}
			}
			$b = $theme['border'] ?? [];
			if ( isset( $b['width'] ) ) {
				$css .= '--cb-bw:' . max( 0, min( 6, (int) $b['width'] ) ) . 'px;';
			}
			if ( isset( $b['style'] ) && array_key_exists( $b['style'], self::border_styles() ) ) {
				$css .= '--cb-border-style:' . $b['style'] . ';';
			}
			if ( isset( $b['radius'] ) ) {
				$css .= '--cb-radius:' . max( 0, min( 40, (int) $b['radius'] ) ) . 'px;';
			}
			if ( isset( $b['radius_sm'] ) ) {
				$css .= '--cb-radius-sm:' . max( 0, min( 40, (int) $b['radius_sm'] ) ) . 'px;';
			}
			if ( isset( $theme['hover'] ) && is_numeric( $theme['hover'] ) ) {
				$css .= '--cb-hover:' . max( 0, min( 1, (float) $theme['hover'] ) ) . ';';
			}
			$font_opt = self::font_options()[ $theme['font'] ?? 'mono' ] ?? null;
			if ( $font_opt ) {
				$css .= '--cb-font:' . $font_opt[1] . ';';
			}
			$css .= '}';
		}
		$size = self::branding()['default_text_size'];
		if ( in_array( $size, [ '1.2', '1.4' ], true ) ) {
			$css .= '.cb-tuner, .cb-charts { --cb-fs: ' . $size . '; }';
		}
		if ( $css ) {
			wp_add_inline_style( 'cb-radio-tuner', $css );
		}
	}

	public static function admin_assets( $hook ) {
		if ( false === strpos( $hook, 'cb-radio-appearance' ) ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		// The tuner stylesheet itself, so the live preview renders with real
		// component styles rather than a re-implementation of them in admin CSS.
		// Google Fonts loads as its dependency, same as the front end, so every
		// font option previews correctly without a page reload.
		wp_enqueue_style( 'cb-radio-google-fonts', self::google_fonts_url(), [], null );
		wp_enqueue_style( 'cb-radio-tuner', CB_RADIO_URL . 'assets/tuner.css', [ 'cb-radio-google-fonts' ], CB_RADIO_VERSION );
		wp_enqueue_script( 'wp-color-picker' );
		// Route color-picker changes into the same preview updater the other
		// fields use (defined inline in render()) so every control — color,
		// border, press, hover — updates the same live preview consistently.
		wp_add_inline_script( 'wp-color-picker', 'jQuery(function($){
			$(".cb-color-field").wpColorPicker({
				change: function(){ setTimeout(function(){ if (window.cbUpdateThemePreview) window.cbUpdateThemePreview(); }, 1); },
				clear: function(){ if (window.cbUpdateThemePreview) window.cbUpdateThemePreview(); }
			});
		});' );
	}

	public static function menu() {
		add_submenu_page(
			'cb-pirate-radio',
			__( 'Appearance', 'cb-radio' ),
			__( 'Appearance', 'cb-radio' ),
			'manage_options',
			'cb-radio-appearance',
			[ __CLASS__, 'render' ]
		);
	}

	public static function render() {
		$themes  = self::themes();
		$active  = get_option( self::OPT_ACTIVE, 'harbor-night' );
		$brand   = self::branding();
		$editing = null;
		if ( isset( $_GET['edit'] ) ) {
			$editing = $themes[ sanitize_key( $_GET['edit'] ) ] ?? null;
			if ( $editing && ! empty( $editing['locked'] ) ) {
				$editing = null; // the default is a reference point, not editable
			}
		}
		$form = $editing ?: [
			'id'     => '',
			'name'   => '',
			'colors' => self::default_theme()['colors'], // start from the shipped palette
			'border' => self::default_theme()['border'],
			'press'  => self::default_theme()['press'],
			'hover'  => self::default_theme()['hover'],
			'font'   => self::default_theme()['font'],
		];
		?>
		<div class="wrap">
			<h1>🎨 <?php esc_html_e( 'Pirate Radio Appearance', 'cb-radio' ); ?></h1>
			<?php if ( isset( $_GET['done'] ) ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Saved.', 'cb-radio' ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Themes', 'cb-radio' ); ?></h2>
			<table class="widefat striped" style="max-width:820px">
				<thead><tr>
					<th><?php esc_html_e( 'Theme', 'cb-radio' ); ?></th>
					<th><?php esc_html_e( 'Palette', 'cb-radio' ); ?></th>
					<th><?php esc_html_e( 'Press', 'cb-radio' ); ?></th>
					<th><?php esc_html_e( 'Status', 'cb-radio' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'cb-radio' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $themes as $t ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $t['name'] ); ?></strong>
							<?php if ( ! empty( $t['locked'] ) ) : ?><em>(<?php esc_html_e( 'built-in', 'cb-radio' ); ?>)</em><?php endif; ?></td>
						<td>
							<?php foreach ( self::slots() as $slot => $meta ) :
								$hex = $t['colors'][ $slot ] ?? '#000'; ?>
								<span title="<?php echo esc_attr( $meta[0] ); ?>" style="display:inline-block;width:20px;height:20px;border-radius:3px;border:1px solid #ccc;vertical-align:middle;background:<?php echo esc_attr( $hex ); ?>"></span>
							<?php endforeach; ?>
						</td>
						<td><?php echo esc_html( self::press_options()[ $t['press'] ] ?? $t['press'] ); ?></td>
						<td><?php echo $active === $t['id'] ? '<strong>✅ ' . esc_html__( 'Active', 'cb-radio' ) . '</strong>' : '—'; ?></td>
						<td>
							<?php if ( $active !== $t['id'] ) : ?>
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cb_radio_activate_theme&theme=' . $t['id'] ), 'cb_radio_theme' ) ); ?>"><?php esc_html_e( 'Activate', 'cb-radio' ); ?></a>
							<?php endif; ?>
							<?php if ( empty( $t['locked'] ) ) : ?>
								| <a href="<?php echo esc_url( admin_url( 'admin.php?page=cb-radio-appearance&edit=' . $t['id'] ) ); ?>"><?php esc_html_e( 'Edit', 'cb-radio' ); ?></a>
								| <a style="color:#b32d2e" onclick="return confirm('<?php esc_attr_e( 'Delete this theme?', 'cb-radio' ); ?>')"
									href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cb_radio_delete_theme&theme=' . $t['id'] ), 'cb_radio_theme' ) ); ?>"><?php esc_html_e( 'Delete', 'cb-radio' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2 style="margin-top:2em"><?php echo $editing ? esc_html__( 'Edit Theme', 'cb-radio' ) : esc_html__( 'New Theme', 'cb-radio' ); ?></h2>

			<div style="display:flex; gap:32px; flex-wrap:wrap; align-items:flex-start; margin-top:12px;">

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:460px; flex:1 1 400px;">
					<input type="hidden" name="action" value="cb_radio_save_theme">
					<input type="hidden" name="theme_id" value="<?php echo esc_attr( $form['id'] ); ?>">
					<?php wp_nonce_field( 'cb_radio_theme' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th><label for="theme_name"><?php esc_html_e( 'Theme name', 'cb-radio' ); ?></label></th>
							<td><input type="text" name="theme_name" id="theme_name" class="regular-text" required
								value="<?php echo esc_attr( $form['name'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Old Bay Sunset', 'cb-radio' ); ?>"></td>
						</tr>

						<tr><th colspan="2"><h3 style="margin-bottom:0"><?php esc_html_e( 'Colors', 'cb-radio' ); ?></h3></th></tr>
						<?php foreach ( self::slots() as $slot => $meta ) : ?>
						<tr>
							<th><label for="color_<?php echo esc_attr( $slot ); ?>"><?php echo esc_html( $meta[0] ); ?></label></th>
							<td><input type="text" class="cb-color-field" name="colors[<?php echo esc_attr( $slot ); ?>]" id="color_<?php echo esc_attr( $slot ); ?>"
								value="<?php echo esc_attr( $form['colors'][ $slot ] ?? '' ); ?>">
								<p class="description"><?php echo esc_html( $meta[1] ); ?></p></td>
						</tr>
						<?php endforeach; ?>

						<tr><th colspan="2"><h3 style="margin-bottom:0"><?php esc_html_e( 'Typography', 'cb-radio' ); ?></h3></th></tr>
						<tr>
							<th><label for="font"><?php esc_html_e( 'Font', 'cb-radio' ); ?></label></th>
							<td><select name="font" id="font">
								<?php foreach ( self::font_options() as $key => $opt ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" data-stack="<?php echo esc_attr( $opt[1] ); ?>"
										<?php selected( $form['font'] ?? 'mono', $key ); ?>><?php echo esc_html( $opt[0] ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( '"Condensed Display" and "Vintage Typewriter" load a small Google Fonts file the first time a visitor sees them.', 'cb-radio' ); ?></p></td>
						</tr>

						<tr><th colspan="2"><h3 style="margin-bottom:0"><?php esc_html_e( 'Borders', 'cb-radio' ); ?></h3></th></tr>
						<tr>
							<th><label for="border_width"><?php esc_html_e( 'Border width', 'cb-radio' ); ?></label></th>
							<td><input type="number" name="border[width]" id="border_width" min="0" max="6" step="1" style="width:80px"
								value="<?php echo esc_attr( $form['border']['width'] ?? 1 ); ?>"> px</td>
						</tr>
						<tr>
							<th><label for="border_style"><?php esc_html_e( 'Border style', 'cb-radio' ); ?></label></th>
							<td><select name="border[style]" id="border_style">
								<?php foreach ( self::border_styles() as $val => $label ) : ?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $form['border']['style'] ?? 'solid', $val ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Applies to panel edges and button outlines. Decorative dividers stay dashed regardless, for visual consistency.', 'cb-radio' ); ?></p></td>
						</tr>
						<tr>
							<th><label for="radius"><?php esc_html_e( 'Corner roundness (large)', 'cb-radio' ); ?></label></th>
							<td><input type="number" name="border[radius]" id="radius" min="0" max="40" step="1" style="width:80px"
								value="<?php echo esc_attr( $form['border']['radius'] ?? 10 ); ?>"> px
								<p class="description"><?php esc_html_e( 'Outer panel, the Display popover.', 'cb-radio' ); ?></p></td>
						</tr>
						<tr>
							<th><label for="radius_sm"><?php esc_html_e( 'Corner roundness (small)', 'cb-radio' ); ?></label></th>
							<td><input type="number" name="border[radius_sm]" id="radius_sm" min="0" max="40" step="1" style="width:80px"
								value="<?php echo esc_attr( $form['border']['radius_sm'] ?? 6 ); ?>"> px
								<p class="description"><?php esc_html_e( 'Buttons, presets, art frame. Pills (vote buttons, chart toggle) always stay fully rounded.', 'cb-radio' ); ?></p></td>
						</tr>

						<tr><th colspan="2"><h3 style="margin-bottom:0"><?php esc_html_e( 'Interaction', 'cb-radio' ); ?></h3></th></tr>
						<tr>
							<th><label for="hover"><?php esc_html_e( 'Hover glow', 'cb-radio' ); ?></label></th>
							<td><select name="hover" id="hover">
								<?php foreach ( self::hover_options() as $val => $label ) : ?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( (string) ( $form['hover'] ?? '0.35' ), $val ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select></td>
						</tr>
						<tr>
							<th><label for="press"><?php esc_html_e( 'Press effect', 'cb-radio' ); ?></label></th>
							<td><select name="press" id="press">
								<?php foreach ( self::press_options() as $val => $label ) : ?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $form['press'] ?? 'scale', $val ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'What happens while a button is held down. Try it in the preview.', 'cb-radio' ); ?></p></td>
						</tr>
					</table>
					<?php submit_button( $editing ? __( 'Update Theme', 'cb-radio' ) : __( 'Create Theme', 'cb-radio' ) ); ?>
				</form>

				<div style="flex:1 1 340px; position:sticky; top:40px;">
					<h3><?php esc_html_e( 'Live Preview', 'cb-radio' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Updates as you edit. Click and hold a button to see the press effect.', 'cb-radio' ); ?></p>
					<div id="cb-theme-preview" class="cb-tuner" data-press="<?php echo esc_attr( $form['press'] ?? 'scale' ); ?>"
						style="max-width:360px; font-size:13px; --cb-font: <?php echo esc_attr( ( self::font_options()[ $form['font'] ?? 'mono' ] ?? self::font_options()['mono'] )[1] ); ?>;">
						<div class="cb-tuner__mast">
							<span class="cb-tuner__beacon" aria-hidden="true"></span>
							<span class="cb-tuner__callsign">PREVIEW</span>
							<button type="button" class="cb-display-toggle">Aa</button>
						</div>
						<div class="cb-tuner__unit">
							<div class="cb-tuner__readout">
								<button type="button" class="cb-tuner__seek">◀◀</button>
								<div class="cb-tuner__display">
									<span class="cb-tuner__display-label">Baltimore Club</span>
									<span class="cb-tuner__digits">94.70</span>
									<span class="cb-tuner__band">FM</span>
								</div>
								<button type="button" class="cb-tuner__seek">▶▶</button>
							</div>
							<div class="cb-tuner__scale" aria-hidden="true">
								<div class="cb-tuner__ticks"><?php for ( $t = 0; $t < 24; $t++ ) : ?><span class="cb-tuner__tick"></span><?php endfor; ?></div>
								<div class="cb-tuner__needle" style="left:28%"></div>
							</div>
							<div class="cb-tuner__presets">
								<button type="button" class="cb-tuner__preset is-tuned"><span class="cb-tuner__preset-n">P1</span><span class="cb-tuner__preset-freq">94.70</span></button>
								<button type="button" class="cb-tuner__preset"><span class="cb-tuner__preset-n">P2</span><span class="cb-tuner__preset-freq">98.10</span></button>
								<button type="button" class="cb-tuner__preset"><span class="cb-tuner__preset-n">P3</span><span class="cb-tuner__preset-freq">101.30</span></button>
							</div>
						</div>
						<div class="cb-tuner__deck">
							<div class="cb-tuner__art"><div class="cb-tuner__art-fallback">🏴‍☠️</div></div>
							<div class="cb-tuner__meta">
								<div class="cb-tuner__nowlabel"><span class="cb-tuner__dot"></span> CURRENTLY PLAYING</div>
								<div class="cb-tuner__track">Harbor Lights</div>
								<a class="cb-tuner__artist" href="#" onclick="return false;">Demo Artist</a>
								<div class="cb-tuner__votes">
									<button type="button" class="cb-tuner__vote is-mine">👍 12</button>
									<button type="button" class="cb-tuner__vote">👎 2</button>
								</div>
							</div>
							<div class="cb-tuner__controls">
								<button type="button" class="cb-tuner__play">▶</button>
							</div>
						</div>
					</div>
				</div>
			</div>

			<script>
			(function () {
				var preview = document.getElementById('cb-theme-preview');
				if (!preview) return;
				var slots = ['hull', 'panel', 'brass', 'signal', 'ink', 'ink_dim', 'border'];

				function val(id, fallback) {
					var el = document.getElementById(id);
					return el ? el.value : fallback;
				}
				function px(id, fallback) {
					var v = parseInt(val(id, fallback), 10);
					return isNaN(v) ? fallback : v;
				}

				window.cbUpdateThemePreview = function () {
					slots.forEach(function (slot) {
						var el = document.getElementById('color_' + slot);
						if (el && el.value) {
							preview.style.setProperty('--cb-' + slot.replace('_', '-'), el.value);
						}
					});
					preview.style.setProperty('--cb-bw', px('border_width', 1) + 'px');
					preview.style.setProperty('--cb-border-style', val('border_style', 'solid'));
					preview.style.setProperty('--cb-radius', px('radius', 10) + 'px');
					preview.style.setProperty('--cb-radius-sm', px('radius_sm', 6) + 'px');
					preview.style.setProperty('--cb-hover', val('hover', '0.35'));
					preview.dataset.press = val('press', 'scale');
					var fontEl = document.getElementById('font');
					if (fontEl && fontEl.selectedOptions[0]) {
						preview.style.setProperty('--cb-font', fontEl.selectedOptions[0].dataset.stack);
					}
				};

				['input', 'change'].forEach(function (evt) {
					document.querySelectorAll(
						'.cb-color-field, #border_width, #border_style, #radius, #radius_sm, #press, #hover, #font'
					).forEach(function (el) {
						el.addEventListener(evt, window.cbUpdateThemePreview);
					});
				});

				window.cbUpdateThemePreview();
			})();
			</script>

			<h2 style="margin-top:2em"><?php esc_html_e( 'Branding Text', 'cb-radio' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:760px">
				<input type="hidden" name="action" value="cb_radio_save_branding">
				<?php wp_nonce_field( 'cb_radio_theme' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="callsign"><?php esc_html_e( 'Masthead call sign', 'cb-radio' ); ?></label></th>
						<td><input type="text" name="callsign" id="callsign" class="regular-text" maxlength="80"
							value="<?php echo esc_attr( $brand['callsign'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="chart_title"><?php esc_html_e( 'Chart title', 'cb-radio' ); ?></label></th>
						<td><input type="text" name="chart_title" id="chart_title" class="regular-text" maxlength="80"
							value="<?php echo esc_attr( $brand['chart_title'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="default_text_size"><?php esc_html_e( 'Default text size', 'cb-radio' ); ?></label></th>
						<td><select name="default_text_size" id="default_text_size">
							<option value="1" <?php selected( $brand['default_text_size'], '1' ); ?>><?php esc_html_e( 'Normal', 'cb-radio' ); ?></option>
							<option value="1.2" <?php selected( $brand['default_text_size'], '1.2' ); ?>><?php esc_html_e( 'Large', 'cb-radio' ); ?></option>
							<option value="1.4" <?php selected( $brand['default_text_size'], '1.4' ); ?>><?php esc_html_e( 'Extra large', 'cb-radio' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Visitors can override this from the Display panel on the tuner and charts.', 'cb-radio' ); ?></p></td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Branding', 'cb-radio' ) ); ?>
			</form>
		</div>
		<?php
	}

	public static function save_theme() {
		self::guard();
		$id   = sanitize_key( $_POST['theme_id'] ?? '' );
		$name = sanitize_text_field( wp_unslash( $_POST['theme_name'] ?? '' ) );
		if ( '' === $name ) {
			self::back();
		}
		if ( '' === $id ) {
			$id = sanitize_key( sanitize_title( $name ) );
			if ( '' === $id || 'harbor-night' === $id ) {
				$id = 'theme-' . wp_generate_password( 6, false, false );
			}
		}

		$defaults = self::default_theme();

		$colors = [];
		foreach ( self::slots() as $slot => $meta ) {
			$hex = sanitize_hex_color( wp_unslash( $_POST['colors'][ $slot ] ?? '' ) );
			$colors[ $slot ] = $hex ?: $defaults['colors'][ $slot ];
		}

		$style = sanitize_key( wp_unslash( $_POST['border']['style'] ?? 'solid' ) );
		$border = [
			'width'     => max( 0, min( 6, absint( $_POST['border']['width'] ?? 1 ) ) ),
			'style'     => array_key_exists( $style, self::border_styles() ) ? $style : 'solid',
			'radius'    => max( 0, min( 40, absint( $_POST['border']['radius'] ?? 10 ) ) ),
			'radius_sm' => max( 0, min( 40, absint( $_POST['border']['radius_sm'] ?? 6 ) ) ),
		];

		$press = sanitize_key( wp_unslash( $_POST['press'] ?? 'scale' ) );
		$press = array_key_exists( $press, self::press_options() ) ? $press : 'scale';

		$hover = wp_unslash( $_POST['hover'] ?? '0.35' );
		$hover = array_key_exists( (string) $hover, self::hover_options() ) ? (string) $hover : '0.35';

		$font = sanitize_key( wp_unslash( $_POST['font'] ?? 'mono' ) );
		$font = array_key_exists( $font, self::font_options() ) ? $font : 'mono';

		$themes = get_option( self::OPT_THEMES, [] );
		$themes = is_array( $themes ) ? $themes : [];
		$themes[ $id ] = [
			'id'     => $id,
			'name'   => $name,
			'colors' => $colors,
			'border' => $border,
			'press'  => $press,
			'hover'  => $hover,
			'font'   => $font,
		];
		update_option( self::OPT_THEMES, $themes );
		self::back();
	}

	public static function delete_theme() {
		self::guard();
		$id     = sanitize_key( $_GET['theme'] ?? '' );
		$themes = get_option( self::OPT_THEMES, [] );
		unset( $themes[ $id ] );
		update_option( self::OPT_THEMES, $themes );
		if ( get_option( self::OPT_ACTIVE ) === $id ) {
			update_option( self::OPT_ACTIVE, 'harbor-night' ); // never leave the dial unpainted
		}
		self::back();
	}

	public static function activate_theme() {
		self::guard();
		$id = sanitize_key( $_GET['theme'] ?? '' );
		if ( isset( self::themes()[ $id ] ) ) {
			update_option( self::OPT_ACTIVE, $id );
		}
		self::back();
	}

	public static function save_branding() {
		self::guard();
		$size = wp_unslash( $_POST['default_text_size'] ?? '1' );
		update_option( self::OPT_BRAND, [
			'callsign'          => sanitize_text_field( wp_unslash( $_POST['callsign'] ?? '' ) ),
			'chart_title'       => sanitize_text_field( wp_unslash( $_POST['chart_title'] ?? '' ) ),
			'default_text_size' => in_array( $size, [ '1', '1.2', '1.4' ], true ) ? $size : '1',
		] );
		self::back();
	}

	private static function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Nope.' );
		}
		check_admin_referer( 'cb_radio_theme' );
	}

	private static function back() {
		wp_safe_redirect( add_query_arg( 'done', 1, admin_url( 'admin.php?page=cb-radio-appearance' ) ) );
		exit;
	}
}
