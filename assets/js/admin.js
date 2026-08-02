/* global jQuery, ATM_Data */
( function ( $ ) {
	'use strict';

	var batchRunning = false;

	function setStatus( $el, text, cls ) {
		$el.attr( 'class', 'atm-alt-status' );
		if ( cls ) {
			$el.addClass( cls );
		}
		$el.text( text );
	}

	/**
	 * Inline alt text saving on blur.
	 */
	$( document ).on( 'blur', '.atm-alt-input', function () {
		var $input = $( this );
		var id = $input.data( 'id' );
		var alt = $input.val();
		var $status = $( '#atm-alt-status-' + id );

		setStatus( $status, ATM_Data.i18n.saving, 'is-saving' );

		$.post( ATM_Data.ajaxUrl, {
			action: 'atm_save_alt',
			nonce: ATM_Data.nonce,
			id: id,
			alt: alt
		} )
			.done( function ( response ) {
				if ( response && response.success ) {
					setStatus( $status, ATM_Data.i18n.saved, 'is-saved' );
					setTimeout( function () {
						setStatus( $status, '', '' );
					}, 2000 );
				} else {
					setStatus( $status, ATM_Data.i18n.error, 'is-error' );
				}
			} )
			.fail( function () {
				setStatus( $status, ATM_Data.i18n.error, 'is-error' );
			} );
	} );

	/**
	 * Single-image "Generate with AI" button.
	 */
	$( document ).on( 'click', '.atm-generate-btn', function () {
		var $btn = $( this );
		var id = $btn.data( 'id' );
		var $row = $btn.closest( 'tr' );
		var $input = $row.find( '.atm-alt-input' );
		var $status = $( '#atm-alt-status-' + id );

		$btn.prop( 'disabled', true ).text( ATM_Data.i18n.generating );

		$.post( ATM_Data.ajaxUrl, {
			action: 'atm_generate_single',
			nonce: ATM_Data.nonce,
			id: id
		} )
			.done( function ( response ) {
				if ( response && response.success ) {
					$input.val( response.data.alt );
					setStatus( $status, ATM_Data.i18n.saved, 'is-saved' );
				} else {
					var msg = ( response && response.data && response.data.message ) ? response.data.message : ATM_Data.i18n.error;
					setStatus( $status, msg, 'is-error' );
				}
			} )
			.fail( function () {
				setStatus( $status, ATM_Data.i18n.error, 'is-error' );
			} )
			.always( function () {
				$btn.prop( 'disabled', false ).text( ATM_Data.i18n.generate );
			} );
	} );

	/**
	 * Settings page: reset the prompt instructions textarea back to the
	 * plugin default (does not save — the user still has to click
	 * "Save Changes" to persist it, same as any other field edit).
	 */
	$( '#atm-reset-prompt-btn' ).on( 'click', function ( e ) {
		e.preventDefault();
		var $textarea = $( '#atm_system_instruction' );
		$textarea.val( $textarea.data( 'default' ) );
	} );

	/**
	 * Rescan button — clears the usage cache and reloads the list.
	 */
	$( '#atm-rescan-btn' ).on( 'click', function () {
		var $btn = $( this );
		var $status = $( '#atm-rescan-status' );

		$btn.prop( 'disabled', true );
		$status.text( ATM_Data.i18n.scanning );

		$.post( ATM_Data.ajaxUrl, {
			action: 'atm_rescan',
			nonce: ATM_Data.nonce
		} ).always( function () {
			window.location.reload();
		} );
	} );

	/**
	 * Bulk "Generate all missing" — repeatedly calls the batch endpoint
	 * until the server reports done, updating a live progress counter
	 * and each row as results come back. Can be stopped mid-run.
	 */
	function runBatchStep( $btn, $progress ) {
		if ( ! batchRunning ) {
			return;
		}

		$.post( ATM_Data.ajaxUrl, {
			action: 'atm_batch_process',
			nonce: ATM_Data.nonce
		} )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					$progress.text( ATM_Data.i18n.error );
					stopBatch( $btn );
					return;
				}

				var data = response.data;

				( data.processed || [] ).forEach( function ( item ) {
					var $input = $( '.atm-alt-input[data-id="' + item.id + '"]' );
					var $status = $( '#atm-alt-status-' + item.id );
					if ( item.success ) {
						$input.val( item.alt );
						var $row = $input.closest( 'tr' );
						$row.fadeOut( 400, function () {
							$row.remove();
						} );
					} else {
						setStatus( $status, item.error || ATM_Data.i18n.error, 'is-error' );
					}
				} );

				if ( data.done ) {
					$progress.text( ATM_Data.i18n.batchDone );
					stopBatch( $btn );
					return;
				}

				$progress.text( data.remaining + ' ' + ATM_Data.i18n.processing );
				runBatchStep( $btn, $progress );
			} )
			.fail( function () {
				$progress.text( ATM_Data.i18n.error );
				stopBatch( $btn );
			} );
	}

	function stopBatch( $btn ) {
		batchRunning = false;
		$btn.text( ATM_Data.i18n.runBatch ).prop( 'disabled', false );
	}

	$( '#atm-batch-btn' ).on( 'click', function () {
		var $btn = $( this );
		var $progress = $( '#atm-batch-progress' );

		if ( batchRunning ) {
			stopBatch( $btn );
			$progress.text( '' );
			return;
		}

		batchRunning = true;
		$btn.text( ATM_Data.i18n.stop );
		$progress.text( ATM_Data.i18n.processing );
		runBatchStep( $btn, $progress );
	} );
}( jQuery ) );
