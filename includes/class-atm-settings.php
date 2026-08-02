<?php
/**
 * Settings storage + the Settings admin screen (including the AI
 * provider/model picker).
 *
 * @package AltTextManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATM_Settings {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Default option values.
	 */
	public static function get_defaults() {
		return array(
			'auto_generate_on_upload' => 1,
			'post_types'              => array( 'post', 'page' ),
			'scan_content'            => 1,
			'scan_featured'           => 1,
			'scan_seo'                => 1,
			'scan_branding'           => 1,
			'model_choice'            => 'auto', // 'auto' or 'manual'.
			'model_preference'        => 'claude-sonnet-4-6, gpt-5.1, gemini-3.1-pro-preview',
			'system_instruction'      => "You are an accessibility expert that writes alt text for website images. Keep it under 125 characters. Describe the visible content objectively and in plain language. Do not start with \"Image of\" or \"Photo of\". Mention text visible in the image if any. Return plain text only, no punctuation at the end.",
			'batch_size'              => 5,
		);
	}

	public static function get() {
		$saved = get_option( ATM_OPTION_KEY, array() );
		return wp_parse_args( $saved, self::get_defaults() );
	}

	public static function get_option( $key ) {
		$settings = self::get();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Register everything via the Settings API so we get sanitisation and
	 * a standard settings form for free.
	 */
	public function register_settings() {
		register_setting(
			'atm_settings_group',
			ATM_OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::get_defaults(),
			)
		);
	}

	public function sanitize( $input ) {
		$defaults = self::get_defaults();
		$output   = array();

		$output['auto_generate_on_upload'] = ! empty( $input['auto_generate_on_upload'] ) ? 1 : 0;
		$output['scan_content']            = ! empty( $input['scan_content'] ) ? 1 : 0;
		$output['scan_featured']           = ! empty( $input['scan_featured'] ) ? 1 : 0;
		$output['scan_seo']                = ! empty( $input['scan_seo'] ) ? 1 : 0;
		$output['scan_branding']           = ! empty( $input['scan_branding'] ) ? 1 : 0;

		$post_types = array();
		if ( ! empty( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
			foreach ( $input['post_types'] as $pt ) {
				$pt = sanitize_key( $pt );
				if ( post_type_exists( $pt ) ) {
					$post_types[] = $pt;
				}
			}
		}
		$output['post_types'] = $post_types ? $post_types : $defaults['post_types'];

		$output['model_choice'] = ( isset( $input['model_choice'] ) && 'manual' === $input['model_choice'] ) ? 'manual' : 'auto';

		$pref = isset( $input['model_preference'] ) ? sanitize_text_field( wp_unslash( $input['model_preference'] ) ) : '';
		// Keep only comma separated word-ish tokens (model ids).
		$output['model_preference'] = $pref ? $pref : $defaults['model_preference'];

		$instruction = isset( $input['system_instruction'] ) ? sanitize_textarea_field( wp_unslash( $input['system_instruction'] ) ) : '';
		$output['system_instruction'] = $instruction ? $instruction : $defaults['system_instruction'];

		$batch_size            = isset( $input['batch_size'] ) ? absint( $input['batch_size'] ) : $defaults['batch_size'];
		$output['batch_size']  = min( 20, max( 1, $batch_size ) );

		// Clear the used-images cache whenever scan scope settings change.
		delete_transient( ATM_SCAN_TRANSIENT );

		return $output;
	}

	/**
	 * Turn the stored comma separated model preference string into an
	 * array of model ids, in priority order.
	 */
	public static function get_model_preference_list() {
		if ( 'manual' !== self::get_option( 'model_choice' ) ) {
			return array();
		}
		$raw = (string) self::get_option( 'model_preference' );
		$ids = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		return array_values( $ids );
	}

	/**
	 * Render the Settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings         = self::get();
		$ai_available     = function_exists( 'wp_ai_client_prompt' );
		$vision_supported = null;

		if ( $ai_available ) {
			// Feature-detection call only — this performs no API request.
			$builder          = wp_ai_client_prompt( 'test' );
			$vision_supported = method_exists( $builder, 'is_supported_for_text_generation' )
				? $builder->is_supported_for_text_generation()
				: null;
		}

		$public_post_types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $public_post_types['attachment'] );
		?>
		<div class="wrap atm-wrap">
			<h1><?php esc_html_e( 'Alt Text Manager — Settings', 'alt-text-manager' ); ?></h1>

			<div class="atm-status-box <?php echo $ai_available ? 'atm-status-ok' : 'atm-status-warn'; ?>">
				<?php if ( ! $ai_available ) : ?>
					<p>
						<strong><?php esc_html_e( 'WordPress AI Client not detected.', 'alt-text-manager' ); ?></strong>
						<?php esc_html_e( 'This plugin needs WordPress 7.0+ with the built-in AI Client. Manual alt text auditing below will still work, but AI generation will not.', 'alt-text-manager' ); ?>
					</p>
				<?php elseif ( false === $vision_supported ) : ?>
					<p>
						<strong><?php esc_html_e( 'No AI provider is configured yet.', 'alt-text-manager' ); ?></strong>
						<?php
						printf(
							/* translators: %s: link to Settings > Connectors */
							esc_html__( 'Go to %s and connect at least one provider (Anthropic, Google or OpenAI) to enable AI-generated alt text.', 'alt-text-manager' ),
							'<a href="' . esc_url( admin_url( 'options-general.php?page=connectors' ) ) . '">' . esc_html__( 'Settings → Connectors', 'alt-text-manager' ) . '</a>'
						);
						?>
					</p>
				<?php else : ?>
					<p>
						<strong><?php esc_html_e( 'AI Client is available and a provider is configured.', 'alt-text-manager' ); ?></strong>
						<?php esc_html_e( 'You can generate alt text with AI from the Image Library and Missing Alt Text screens.', 'alt-text-manager' ); ?>
					</p>
				<?php endif; ?>
				<p class="description">
					<?php
					printf(
						/* translators: %s: link to Connectors screen */
						esc_html__( 'Providers, API keys and which specific models are enabled are managed by WordPress core itself under %s — this plugin only lets you choose a preference from what you have already connected there.', 'alt-text-manager' ),
						'<a href="' . esc_url( admin_url( 'options-general.php?page=connectors' ) ) . '">' . esc_html__( 'Settings → Connectors', 'alt-text-manager' ) . '</a>'
					);
					?>
				</p>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'atm_settings_group' ); ?>

				<h2 class="title"><?php esc_html_e( 'AI Model', 'alt-text-manager' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Model selection', 'alt-text-manager' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="radio" name="<?php echo esc_attr( ATM_OPTION_KEY ); ?>[model_choice]" value="auto" <?php checked( 'auto', $settings['model_choice'] ); ?> />
									<?php esc_html_e( 'Let WordPress choose the model automatically', 'alt-text-manager' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'WordPress will pick the best available model from whatever providers are connected under Settings → Connectors.', 'alt-text-manager' ); ?></p>
								<br />
								<label>
									<input type="radio" name="<?php echo esc_attr( ATM_OPTION_KEY ); ?>[model_choice]" value="manual" <?php checked( 'manual', $settings['model_choice'] ); ?> />
									<?php esc_html_e( 'Prefer specific models, in order', 'alt-text-manager' ); ?>
								</label>
								<p>
									<input type="text" class="regular-text" style="width:32em;" name="<?php echo esc_attr( ATM_OPTION_KEY ); ?>[model_preference]" value="<?php echo esc_attr( $settings['model_preference'] ); ?>" />
									<br />
									<span class="description">
										<?php esc_html_e( 'Comma-separated model IDs, most preferred first, e.g. claude-sonnet-4-6, gpt-5.1, gemini-3.1-pro-preview. WordPress uses the first one that is actually available and falls back automatically if none of them are configured.', 'alt-text-manager' ); ?>
									</span>
								</p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="atm_system_instruction"><?php esc_html_e( 'Prompt instructions', 'alt-text-manager' ); ?></label></th>
						<td>
							<textarea id="atm_system_instruction" name="<?php echo esc_attr( ATM_OPTION_KEY ); ?>[system_instruction]" rows="4" class="large-text"><?php echo esc_textarea( $settings['system_instruction'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'The system instruction sent to the AI model when generating alt text.', 'alt-text-manager' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Automatic generation', 'alt-text-manager' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'New uploads', 'alt-text-manager' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( ATM_OPTION_KEY ); ?>[auto_generate_on_upload]" value="1" <?php checked( 1, $settings['auto_generate_on_upload'] ); ?> />
								<?php esc_html_e( 'Automatically generate alt text with AI for newly uploaded images that don\'t already have alt text', 'alt-text-manager' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="atm_batch_size"><?php esc_html_e( 'Batch size', 'alt-text-manager' ); ?></label></th>
						<td>
							<input type="number" id="atm_batch_size" min="1" max="20" name="<?php echo esc_attr( ATM_OPTION_KEY ); ?>[batch_size]" value="<?php echo esc_attr( $settings['batch_size'] ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'How many images to process per batch step when running the bulk AI generator (1–20). Lower this if you hit provider rate limits.', 'alt-text-manager' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'What counts as "used on the site"', 'alt-text-manager' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Scan locations', 'alt-text-manager' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( ATM_OPTION_KEY ); ?>[scan_content]" value="1" <?php checked( 1, $settings['scan_content'] ); ?> />
								<?php esc_html_e( 'Images embedded in post/page content', 'alt-text-manager' ); ?>
							</label><br />
							<label>
								<input type="checkbox" name="<?php echo esc_attr( ATM_OPTION_KEY ); ?>[scan_featured]" value="1" <?php checked( 1, $settings['scan_featured'] ); ?> />
								<?php esc_html_e( 'Featured images', 'alt-text-manager' ); ?>
							</label><br />
							<label>
								<input type="checkbox" name="<?php echo esc_attr( ATM_OPTION_KEY ); ?>[scan_seo]" value="1" <?php checked( 1, $settings['scan_seo'] ); ?> />
								<?php esc_html_e( 'SEO images (Yoast SEO, Rank Math, SEOPress social/OG images)', 'alt-text-manager' ); ?>
							</label><br />
							<label>
								<input type="checkbox" name="<?php echo esc_attr( ATM_OPTION_KEY ); ?>[scan_branding]" value="1" <?php checked( 1, $settings['scan_branding'] ); ?> />
								<?php esc_html_e( 'Site logo & site icon', 'alt-text-manager' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Post types to scan', 'alt-text-manager' ); ?></th>
						<td>
							<?php foreach ( $public_post_types as $pt ) : ?>
								<label style="display:inline-block;margin-right:1.5em;">
									<input type="checkbox" name="<?php echo esc_attr( ATM_OPTION_KEY ); ?>[post_types][]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, (array) $settings['post_types'], true ) ); ?> />
									<?php echo esc_html( $pt->labels->name ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Custom post types (e.g. Notices, Events) will appear here too once registered as public.', 'alt-text-manager' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
