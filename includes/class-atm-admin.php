<?php
/**
 * Registers the admin menu and renders the Image Library / Missing Alt
 * Text / Settings screens.
 *
 * @package AltTextManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATM_Admin {

	private static $instance = null;
	const MENU_SLUG = 'alt-text-manager';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_menu() {
		$capability = 'upload_files';

		add_menu_page(
			__( 'Alt Text Manager', 'alt-text-manager' ),
			__( 'Alt Text', 'alt-text-manager' ),
			$capability,
			self::MENU_SLUG,
			array( $this, 'render_library_page' ),
			'dashicons-format-image',
			80
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Image Library', 'alt-text-manager' ),
			__( 'Image Library', 'alt-text-manager' ),
			$capability,
			self::MENU_SLUG,
			array( $this, 'render_library_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Missing Alt Text', 'alt-text-manager' ),
			__( 'Missing Alt Text', 'alt-text-manager' ),
			$capability,
			'alt-text-manager-missing',
			array( $this, 'render_missing_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'alt-text-manager' ),
			__( 'Settings', 'alt-text-manager' ),
			'manage_options',
			'alt-text-manager-settings',
			array( ATM_Settings::instance(), 'render_page' )
		);
	}

	public function enqueue_assets( $hook ) {
		if ( strpos( $hook, self::MENU_SLUG ) === false ) {
			return;
		}

		wp_enqueue_style( 'atm-admin', ATM_PLUGIN_URL . 'assets/css/admin.css', array(), ATM_VERSION );
		wp_enqueue_script( 'atm-admin', ATM_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), ATM_VERSION, true );

		wp_localize_script(
			'atm-admin',
			'ATM_Data',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'atm_ajax_nonce' ),
				'i18n'    => array(
					'generating'   => __( 'Generating…', 'alt-text-manager' ),
					'generate'     => __( 'Generate with AI', 'alt-text-manager' ),
					'saved'        => __( 'Saved', 'alt-text-manager' ),
					'saving'       => __( 'Saving…', 'alt-text-manager' ),
					'error'        => __( 'Error', 'alt-text-manager' ),
					'scanning'     => __( 'Rescanning…', 'alt-text-manager' ),
					'rescan'       => __( 'Rescan site', 'alt-text-manager' ),
					'runBatch'     => __( 'Generate all missing (AI)', 'alt-text-manager' ),
					'processing'   => __( 'Processing…', 'alt-text-manager' ),
					'stop'         => __( 'Stop', 'alt-text-manager' ),
					'batchDone'    => __( 'All done.', 'alt-text-manager' ),
				),
			)
		);
	}

	public function render_library_page() {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}
		$this->render_page_shell(
			__( 'Image Library', 'alt-text-manager' ),
			__( 'Every image in your Media Library, whether it is currently used on the site or not.', 'alt-text-manager' ),
			'all',
			false
		);
	}

	public function render_missing_page() {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}
		$this->render_page_shell(
			__( 'Missing Alt Text', 'alt-text-manager' ),
			__( 'Images that are used somewhere on the site (content, featured image, SEO image, or branding) but currently have no alt text.', 'alt-text-manager' ),
			'missing',
			true
		);
	}

	private function render_page_shell( $title, $intro, $mode, $show_batch_button ) {
		$list_table = new ATM_List_Table( $mode );
		$list_table->prepare_items();

		$ai_available = ATM_AI_Generator::instance()->is_available();
		?>
		<div class="wrap atm-wrap">
			<h1><?php echo esc_html( $title ); ?></h1>
			<p><?php echo esc_html( $intro ); ?></p>

			<?php if ( ! $ai_available ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php
						printf(
							/* translators: %s: link to plugin settings page */
							esc_html__( 'No AI provider is currently connected — see %s to enable AI-generated alt text.', 'alt-text-manager' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=alt-text-manager-settings' ) ) . '">' . esc_html__( 'Settings', 'alt-text-manager' ) . '</a>'
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<div class="atm-toolbar">
				<button type="button" class="button" id="atm-rescan-btn"><?php esc_html_e( 'Rescan site', 'alt-text-manager' ); ?></button>
				<?php if ( $show_batch_button && $ai_available ) : ?>
					<button type="button" class="button button-primary" id="atm-batch-btn"><?php esc_html_e( 'Generate all missing (AI)', 'alt-text-manager' ); ?></button>
					<span id="atm-batch-progress"></span>
				<?php endif; ?>
				<span id="atm-rescan-status"></span>
			</div>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : self::MENU_SLUG ); ?>" />
				<?php $list_table->search_box( __( 'Search images', 'alt-text-manager' ), 'atm-search' ); ?>
				<?php $list_table->display(); ?>
			</form>
		</div>
		<?php
	}
}
