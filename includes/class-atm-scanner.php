<?php
/**
 * Scans the site to work out which Media Library images are actually
 * "in use" (post content, featured images, SEO images, branding), and
 * cross-references that against which ones are missing alt text.
 *
 * @package AltTextManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATM_Scanner {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Keep the usage cache fresh: clear it whenever relevant content changes.
		add_action( 'save_post', array( $this, 'clear_cache' ) );
		add_action( 'delete_post', array( $this, 'clear_cache' ) );
		add_action( 'customize_save_after', array( $this, 'clear_cache' ) );
	}

	public function clear_cache() {
		delete_transient( ATM_SCAN_TRANSIENT );
	}

	/**
	 * All image attachments, alt text included. Powers the "Image Library" view.
	 *
	 * @return array<int,array>
	 */
	public function get_all_images() {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
			)
		);

		$usage = $this->get_used_images_map();
		$images = array();

		foreach ( $query->posts as $id ) {
			$images[ $id ] = $this->build_image_row( $id, isset( $usage[ $id ] ) ? $usage[ $id ] : array() );
		}

		return $images;
	}

	/**
	 * Only images that are (a) used somewhere on the site and (b) missing alt text.
	 *
	 * @return array<int,array>
	 */
	public function get_missing_alt_used_images() {
		$usage  = $this->get_used_images_map();
		$images = array();

		foreach ( $usage as $id => $locations ) {
			$alt = get_post_meta( $id, '_wp_attachment_image_alt', true );
			if ( '' === trim( (string) $alt ) ) {
				$images[ $id ] = $this->build_image_row( $id, $locations );
			}
		}

		return $images;
	}

	private function build_image_row( $id, $locations ) {
		$alt = get_post_meta( $id, '_wp_attachment_image_alt', true );
		return array(
			'id'        => $id,
			'title'     => get_the_title( $id ),
			'filename'  => wp_basename( get_attached_file( $id ) ),
			'thumb'     => wp_get_attachment_image_url( $id, array( 60, 60 ) ),
			'edit_link' => get_edit_post_link( $id, 'raw' ),
			'alt'       => $alt,
			'used'      => ! empty( $locations ),
			'locations' => $locations,
		);
	}

	/**
	 * Build (and cache) a map of attachment_id => [ usage descriptors ].
	 *
	 * @return array<int,array>
	 */
	public function get_used_images_map( $force_refresh = false ) {
		if ( ! $force_refresh ) {
			$cached = get_transient( ATM_SCAN_TRANSIENT );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$settings = ATM_Settings::get();
		$map      = array();

		$post_types = ! empty( $settings['post_types'] ) ? (array) $settings['post_types'] : array( 'post', 'page' );

		$query = new WP_Query(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		foreach ( $query->posts as $post_id ) {
			if ( ! empty( $settings['scan_content'] ) ) {
				$this->collect_content_images( $post_id, $map );
			}
			if ( ! empty( $settings['scan_featured'] ) ) {
				$this->collect_featured_image( $post_id, $map );
			}
			if ( ! empty( $settings['scan_seo'] ) ) {
				$this->collect_seo_images( $post_id, $map );
			}
		}

		if ( ! empty( $settings['scan_branding'] ) ) {
			$this->collect_branding_images( $map );
		}

		set_transient( ATM_SCAN_TRANSIENT, $map, HOUR_IN_SECONDS );

		return $map;
	}

	private function add_usage( &$map, $attachment_id, $descriptor ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			return;
		}
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return;
		}
		if ( ! isset( $map[ $attachment_id ] ) ) {
			$map[ $attachment_id ] = array();
		}
		$map[ $attachment_id ][] = $descriptor;
	}

	/**
	 * Parse post content for <img> tags and resolve them to attachment IDs,
	 * first via the wp-image-{ID} class WordPress adds, then by matching
	 * the src URL as a fallback (covers images inserted by page builders,
	 * pasted HTML, etc.).
	 */
	private function collect_content_images( $post_id, &$map ) {
		$post = get_post( $post_id );
		if ( ! $post || empty( $post->post_content ) ) {
			return;
		}

		if ( false === stripos( $post->post_content, '<img' ) ) {
			return;
		}

		if ( ! preg_match_all( '/<img[^>]+>/i', $post->post_content, $tags ) ) {
			return;
		}

		$link  = get_permalink( $post_id );
		$title = get_the_title( $post_id );

		foreach ( $tags[0] as $tag ) {
			$attachment_id = 0;

			if ( preg_match( '/wp-image-(\d+)/i', $tag, $class_match ) ) {
				$attachment_id = (int) $class_match[1];
			} elseif ( preg_match( '/src=["\']([^"\']+)["\']/i', $tag, $src_match ) ) {
				$attachment_id = attachment_url_to_postid( $src_match[1] );
			}

			if ( $attachment_id ) {
				$this->add_usage(
					$map,
					$attachment_id,
					array(
						'type'  => 'content',
						'label' => __( 'In content', 'alt-text-manager' ),
						/* translators: %s: post title */
						'post'  => sprintf( __( '%s', 'alt-text-manager' ), $title ),
						'link'  => $link,
						'edit'  => get_edit_post_link( $post_id, 'raw' ),
					)
				);
			}
		}
	}

	private function collect_featured_image( $post_id, &$map ) {
		$thumb_id = get_post_thumbnail_id( $post_id );
		if ( ! $thumb_id ) {
			return;
		}
		$this->add_usage(
			$map,
			$thumb_id,
			array(
				'type'  => 'featured',
				'label' => __( 'Featured image', 'alt-text-manager' ),
				'post'  => get_the_title( $post_id ),
				'link'  => get_permalink( $post_id ),
				'edit'  => get_edit_post_link( $post_id, 'raw' ),
			)
		);
	}

	/**
	 * Known per-post SEO plugin meta keys for social/OG images. Covers
	 * Yoast SEO, Rank Math and SEOPress. Falls back gracefully if none
	 * of these plugins are active — get_post_meta() just returns empty.
	 */
	private function collect_seo_images( $post_id, &$map ) {
		$title = get_the_title( $post_id );
		$link  = get_permalink( $post_id );
		$edit  = get_edit_post_link( $post_id, 'raw' );

		$id_meta_keys = array(
			'_yoast_wpseo_opengraph-image-id' => 'Yoast SEO — OG image',
			'_yoast_wpseo_twitter-image-id'   => 'Yoast SEO — Twitter image',
			'rank_math_facebook_image_id'     => 'Rank Math — Facebook image',
			'rank_math_twitter_image_id'      => 'Rank Math — Twitter image',
			'_seopress_social_fb_img_id'      => 'SEOPress — Facebook image',
			'_seopress_social_twitter_img_id' => 'SEOPress — Twitter image',
		);

		foreach ( $id_meta_keys as $meta_key => $label ) {
			$attachment_id = (int) get_post_meta( $post_id, $meta_key, true );
			if ( $attachment_id ) {
				$this->add_usage(
					$map,
					$attachment_id,
					array(
						'type'  => 'seo',
						'label' => $label,
						'post'  => $title,
						'link'  => $link,
						'edit'  => $edit,
					)
				);
			}
		}

		// Some SEO plugins store a URL instead of an ID. Resolve those too.
		$url_meta_keys = array(
			'_yoast_wpseo_opengraph-image' => 'Yoast SEO — OG image',
			'_yoast_wpseo_twitter-image'   => 'Yoast SEO — Twitter image',
			'_seopress_social_fb_img'      => 'SEOPress — Facebook image',
		);

		foreach ( $url_meta_keys as $meta_key => $label ) {
			$url = get_post_meta( $post_id, $meta_key, true );
			if ( $url ) {
				$attachment_id = attachment_url_to_postid( $url );
				if ( $attachment_id ) {
					$this->add_usage(
						$map,
						$attachment_id,
						array(
							'type'  => 'seo',
							'label' => $label,
							'post'  => $title,
							'link'  => $link,
							'edit'  => $edit,
						)
					);
				}
			}
		}
	}

	/**
	 * Site-wide branding: custom logo and site icon (favicon).
	 */
	private function collect_branding_images( &$map ) {
		$logo_id = get_theme_mod( 'custom_logo' );
		if ( $logo_id ) {
			$this->add_usage(
				$map,
				$logo_id,
				array(
					'type'  => 'branding',
					'label' => __( 'Site logo', 'alt-text-manager' ),
					'post'  => __( 'Site-wide', 'alt-text-manager' ),
					'link'  => home_url( '/' ),
					'edit'  => admin_url( 'customize.php' ),
				)
			);
		}

		$icon_id = get_option( 'site_icon' );
		if ( $icon_id ) {
			$this->add_usage(
				$map,
				$icon_id,
				array(
					'type'  => 'branding',
					'label' => __( 'Site icon', 'alt-text-manager' ),
					'post'  => __( 'Site-wide', 'alt-text-manager' ),
					'link'  => home_url( '/' ),
					'edit'  => admin_url( 'options-general.php' ),
				)
			);
		}
	}
}
