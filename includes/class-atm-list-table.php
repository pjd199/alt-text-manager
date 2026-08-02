<?php
/**
 * A single WP_List_Table used for both the "Image Library" (all images)
 * and "Missing Alt Text" (used-but-missing) admin screens.
 *
 * @package AltTextManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class ATM_List_Table extends WP_List_Table {

	/** @var string 'all' or 'missing' */
	private $mode;

	public function __construct( $mode = 'all' ) {
		$this->mode = ( 'missing' === $mode ) ? 'missing' : 'all';

		parent::__construct(
			array(
				'singular' => 'image',
				'plural'   => 'images',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		$columns = array(
			'thumb'    => '',
			'filename' => __( 'Image', 'alt-text-manager' ),
			'alt'      => __( 'Alt text', 'alt-text-manager' ),
			'usage'    => __( 'Used on site', 'alt-text-manager' ),
			'actions'  => __( 'Actions', 'alt-text-manager' ),
		);
		return $columns;
	}

	public function prepare_items() {
		$scanner = ATM_Scanner::instance();

		$data = ( 'missing' === $this->mode )
			? $scanner->get_missing_alt_used_images()
			: $scanner->get_all_images();

		// Optional simple search box support.
		if ( ! empty( $_REQUEST['s'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search filter.
			$search = strtolower( sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) );
			$data   = array_filter(
				$data,
				function ( $row ) use ( $search ) {
					return false !== strpos( strtolower( $row['filename'] ), $search )
						|| false !== strpos( strtolower( $row['title'] ), $search );
				}
			);
		}

		$data = array_values( $data );

		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$total_items  = count( $data );

		$this->items = array_slice( $data, ( $current_page - 1 ) * $per_page, $per_page );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => ceil( $total_items / $per_page ),
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), array() );
	}

	public function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? $item[ $column_name ] : '';
	}

	public function column_thumb( $item ) {
		if ( $item['thumb'] ) {
			printf(
				'<img src="%s" width="40" height="40" style="object-fit:cover;border-radius:3px;" alt="" />',
				esc_url( $item['thumb'] )
			);
		}
	}

	public function column_filename( $item ) {
		printf(
			'<strong><a href="%s">%s</a></strong><br /><span class="description">%s</span>',
			esc_url( $item['edit_link'] ),
			esc_html( $item['filename'] ),
			esc_html( $item['title'] )
		);
	}

	public function column_alt( $item ) {
		printf(
			'<input type="text" class="regular-text atm-alt-input" data-id="%1$d" value="%2$s" placeholder="%3$s" />
			<span class="atm-alt-status" id="atm-alt-status-%1$d"></span>',
			(int) $item['id'],
			esc_attr( $item['alt'] ),
			esc_attr__( 'No alt text', 'alt-text-manager' )
		);
	}

	public function column_usage( $item ) {
		if ( empty( $item['locations'] ) ) {
			echo '<span class="description">' . esc_html__( 'Not detected in scanned content', 'alt-text-manager' ) . '</span>';
			return;
		}

		echo '<ul class="atm-usage-list">';
		foreach ( array_slice( $item['locations'], 0, 4 ) as $loc ) {
			printf(
				'<li><span class="atm-badge atm-badge-%1$s">%2$s</span> <a href="%3$s">%4$s</a></li>',
				esc_attr( $loc['type'] ),
				esc_html( $loc['label'] ),
				esc_url( $loc['link'] ? $loc['link'] : '#' ),
				esc_html( $loc['post'] )
			);
		}
		if ( count( $item['locations'] ) > 4 ) {
			printf(
				'<li class="description">%s</li>',
				esc_html(
					sprintf(
						/* translators: %d: number of additional places the image is used */
						__( '+ %d more', 'alt-text-manager' ),
						count( $item['locations'] ) - 4
					)
				)
			);
		}
		echo '</ul>';
	}

	public function column_actions( $item ) {
		printf(
			'<button type="button" class="button button-small atm-generate-btn" data-id="%1$d">%2$s</button>',
			(int) $item['id'],
			esc_html__( 'Generate with AI', 'alt-text-manager' )
		);
	}

	public function no_items() {
		if ( 'missing' === $this->mode ) {
			esc_html_e( 'Nothing missing — every used image currently has alt text. 🎉', 'alt-text-manager' );
		} else {
			esc_html_e( 'No images found in the Media Library.', 'alt-text-manager' );
		}
	}
}
