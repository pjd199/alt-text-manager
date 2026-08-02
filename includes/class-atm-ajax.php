<?php
/**
 * AJAX endpoints backing the admin screens: inline alt text save, single
 * AI generation, batch AI processing, and rescanning image usage.
 *
 * @package AltTextManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATM_Ajax {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_atm_save_alt', array( $this, 'save_alt' ) );
		add_action( 'wp_ajax_atm_generate_single', array( $this, 'generate_single' ) );
		add_action( 'wp_ajax_atm_batch_process', array( $this, 'batch_process' ) );
		add_action( 'wp_ajax_atm_rescan', array( $this, 'rescan' ) );
	}

	private function check_permissions() {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'alt-text-manager' ) ), 403 );
		}
		check_ajax_referer( 'atm_ajax_nonce', 'nonce' );
	}

	/**
	 * Save a manually-typed (or AI-generated-then-edited) alt text value.
	 */
	public function save_alt() {
		$this->check_permissions();

		$attachment_id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$alt_text      = isset( $_POST['alt'] ) ? sanitize_text_field( wp_unslash( $_POST['alt'] ) ) : '';

		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid image.', 'alt-text-manager' ) ) );
		}

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );

		wp_send_json_success( array( 'alt' => $alt_text ) );
	}

	/**
	 * Generate alt text for one image via the "Generate with AI" button.
	 */
	public function generate_single() {
		$this->check_permissions();

		$attachment_id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid image.', 'alt-text-manager' ) ) );
		}

		$context = $this->get_context_for_attachment( $attachment_id );
		$result  = ATM_AI_Generator::instance()->generate_and_save( $attachment_id, $context );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'alt' => $result ) );
	}

	/**
	 * Process one batch of N images missing alt text. The browser calls
	 * this repeatedly (see assets/js/admin.js) until it reports done=true,
	 * so a single slow AI request never times out the whole run and
	 * progress is visible throughout.
	 */
	public function batch_process() {
		$this->check_permissions();

		$settings   = ATM_Settings::get();
		$batch_size = max( 1, (int) $settings['batch_size'] );

		$missing = ATM_Scanner::instance()->get_missing_alt_used_images();
		$ids     = array_keys( $missing );

		if ( empty( $ids ) ) {
			wp_send_json_success(
				array(
					'done'      => true,
					'processed' => array(),
					'remaining' => 0,
				)
			);
		}

		$batch_ids = array_slice( $ids, 0, $batch_size );
		$processed = array();

		foreach ( $batch_ids as $attachment_id ) {
			$context = $this->get_context_for_attachment( $attachment_id );
			$result  = ATM_AI_Generator::instance()->generate_and_save( $attachment_id, $context );

			$processed[] = array(
				'id'      => $attachment_id,
				'success' => ! is_wp_error( $result ),
				'alt'     => is_wp_error( $result ) ? '' : $result,
				'error'   => is_wp_error( $result ) ? $result->get_error_message() : '',
			);
		}

		wp_send_json_success(
			array(
				'done'      => count( $ids ) <= $batch_size,
				'processed' => $processed,
				'remaining' => max( 0, count( $ids ) - $batch_size ),
			)
		);
	}

	public function rescan() {
		$this->check_permissions();
		$map = ATM_Scanner::instance()->get_used_images_map( true );
		wp_send_json_success( array( 'used_count' => count( $map ) ) );
	}

	/**
	 * Build a short human-readable context string ("used on: About Us")
	 * for the AI prompt, from wherever the image is first found in use.
	 */
	private function get_context_for_attachment( $attachment_id ) {
		$map = ATM_Scanner::instance()->get_used_images_map();
		if ( empty( $map[ $attachment_id ][0]['post'] ) ) {
			return '';
		}
		return $map[ $attachment_id ][0]['post'];
	}
}
