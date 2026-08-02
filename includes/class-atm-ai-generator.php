<?php
/**
 * Wraps the WordPress 7 AI Client to generate alt text for a single
 * attachment, and hooks new uploads for automatic generation.
 *
 * @package AltTextManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATM_AI_Generator {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'add_attachment', array( $this, 'maybe_generate_on_upload' ) );
	}

	/**
	 * True if WordPress 7's AI Client is present and a provider is
	 * actually configured for text generation. This never makes a
	 * network request — is_supported_for_text_generation() is a local
	 * capability check only.
	 */
	public function is_available() {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return false;
		}
		$builder = wp_ai_client_prompt( 'availability check' );
		if ( ! method_exists( $builder, 'is_supported_for_text_generation' ) ) {
			// Be permissive if the capability-check method isn't present
			// in this core version — let the real call fail with a
			// WP_Error instead of hiding the feature outright.
			return true;
		}
		return (bool) $builder->is_supported_for_text_generation();
	}

	/**
	 * Generate alt text for one image attachment.
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $context       Optional extra context (e.g. surrounding post title) to steer the description.
	 * @return string|WP_Error
	 */
	public function generate_for_attachment( $attachment_id, $context = '' ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error( 'atm_no_ai_client', __( 'The WordPress AI Client is not available on this site (requires WordPress 7.0+).', 'alt-text-manager' ) );
		}

		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return new WP_Error( 'atm_not_image', __( 'This attachment is not an image.', 'alt-text-manager' ) );
		}

		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error( 'atm_missing_file', __( 'Could not find the image file on disk.', 'alt-text-manager' ) );
		}

		$mime_type = get_post_mime_type( $attachment_id );
		$settings  = ATM_Settings::get();

		$prompt = __( 'Generate concise, descriptive alt text for this image.', 'alt-text-manager' );
		if ( $context ) {
			$prompt .= ' ' . sprintf(
				/* translators: %s: page/post title the image appears on */
				__( 'For additional context, this image is used on: %s.', 'alt-text-manager' ),
				$context
			);
		}

		$builder = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $settings['system_instruction'] )
			->with_file( $file_path, $mime_type );

		// Replace model preference handling in generate_for_attachment():
		$provider = ATM_Settings::get_option( 'ai_provider' );
		$model    = ATM_Settings::get_option( 'ai_model' );

		if ( $provider && 'auto' !== $provider ) {
			$builder = $builder->using_provider( $provider );
		}

		if ( $model && 'auto' !== $model ) {
			$builder = $builder->using_model_preference( $model );
		}

		$result = $builder->generate_text();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$alt_text = trim( wp_strip_all_tags( (string) $result ) );
		// Belt-and-braces length guard in case a model ignores the instruction.
		if ( mb_strlen( $alt_text ) > 250 ) {
			$alt_text = mb_substr( $alt_text, 0, 250 );
		}

		return $alt_text;
	}

	/**
	 * Generate and save alt text for an attachment in one step. Used by
	 * both the upload hook and the AJAX batch processor.
	 *
	 * @return string|WP_Error The alt text that was saved, or an error.
	 */
	public function generate_and_save( $attachment_id, $context = '' ) {
		$alt_text = $this->generate_for_attachment( $attachment_id, $context );
		if ( is_wp_error( $alt_text ) ) {
			return $alt_text;
		}
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
		return $alt_text;
	}

	/**
	 * Fires on every new attachment. Only acts on images, only if the
	 * setting is enabled, and only if alt text isn't already set (so we
	 * never clobber something the uploader typed in manually just before
	 * this hook runs, e.g. via the media modal's alt text field on
	 * upload-and-attach flows).
	 */
	public function maybe_generate_on_upload( $attachment_id ) {
		if ( ! ATM_Settings::get_option( 'auto_generate_on_upload' ) ) {
			return;
		}
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return;
		}
		if ( ! $this->is_available() ) {
			return;
		}

		$existing_alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( '' !== trim( (string) $existing_alt ) ) {
			return;
		}

		// Run generation late so we don't slow down the upload response;
		// this still runs within the same request but after WordPress has
		// finished its own metadata processing.
		$result = $this->generate_and_save( $attachment_id );

		if ( is_wp_error( $result ) ) {
			// Fail silently for the uploader — log for the admin instead.
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional, admin-visible only via server logs.
				sprintf( 'Alt Text Manager: auto-generation failed for attachment %d: %s', $attachment_id, $result->get_error_message() )
			);
		}
	}
}
