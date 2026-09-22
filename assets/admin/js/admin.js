/* global certivaAdmin, jQuery */
( function ( $ ) {
	'use strict';

	if ( typeof certivaAdmin === 'undefined' ) {
		return;
	}

	function call( op, id, extra ) {
		var data = $.extend(
			{
				action: 'certiva_admin_action',
				nonce: certivaAdmin.nonce,
				op: op,
				id: id,
			},
			extra || {}
		);

		return $.post( certivaAdmin.ajaxUrl, data );
	}

	function handleFailure( xhr ) {
		var message = certivaAdmin.i18n.error;
		if ( xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
			message = xhr.responseJSON.data.message;
		}
		window.alert( message );
	}

	$( document ).on( 'change', '.certiva-toggle-eligible', function () {
		var $row = $( this ).closest( 'tr' );
		var id = $row.data( 'registration-id' );
		var $checkbox = $( this );
		var eligible = $checkbox.is( ':checked' );

		$checkbox.prop( 'disabled', true );
		call( 'set_eligible', id, { eligible: eligible ? 1 : 0 } )
			.fail( handleFailure )
			.always( function () {
				$checkbox.prop( 'disabled', false );
			} );
	} );

	$( document ).on( 'click', '.certiva-generate, .certiva-regenerate', function () {
		var $btn = $( this );
		var $row = $btn.closest( 'tr' );
		var id = $row.data( 'registration-id' );
		var op = $btn.hasClass( 'certiva-generate' ) ? 'generate' : 'regenerate';

		$btn.prop( 'disabled', true ).text( certivaAdmin.i18n.working );

		call( op, id )
			.done( function () {
				window.location.reload();
			} )
			.fail( function ( xhr ) {
				handleFailure( xhr );
				$btn.prop( 'disabled', false );
			} );
	} );

	$( document ).on( 'click', '.certiva-resend', function () {
		var $btn = $( this );
		var $row = $btn.closest( 'tr' );
		var id = $row.data( 'registration-id' );

		$btn.prop( 'disabled', true ).text( certivaAdmin.i18n.working );

		call( 'resend', id )
			.done( function ( response ) {
				window.alert( response.data && response.data.message ? response.data.message : 'Sent.' );
			} )
			.fail( handleFailure )
			.always( function () {
				$btn.prop( 'disabled', false ).text( 'Resend' );
			} );
	} );

	$( document ).on( 'click', '.certiva-delete', function () {
		var $btn = $( this );
		var $row = $btn.closest( 'tr' );
		var id = $row.data( 'registration-id' );

		if ( ! window.confirm( certivaAdmin.i18n.confirmDelete ) ) {
			return;
		}

		$btn.prop( 'disabled', true );

		call( 'delete', id )
			.done( function () {
				$row.fadeOut( 200, function () {
					$row.remove();
				} );
			} )
			.fail( handleFailure );
	} );

	// Student edit screen: "Additional Certificate Placeholders" repeatable rows.
	$( document ).on( 'click', '#certiva-add-extra-field', function ( e ) {
		e.preventDefault();

		var template = document.getElementById( 'certiva-extra-field-row-template' );
		var body = document.querySelector( '#certiva-extra-fields-table tbody' );
		if ( ! template || ! body ) {
			return;
		}

		body.appendChild( template.content.cloneNode( true ) );
	} );

	$( document ).on( 'click', '.certiva-remove-row', function ( e ) {
		e.preventDefault();
		$( this ).closest( 'tr' ).remove();
	} );

	// Simple client-side filter for the student <select> on the Registrations page.
	$( document ).on( 'input', '#certiva-student-search', function () {
		var term = $( this ).val().toLowerCase();
		var $select = $( '#student_id' );

		$select.find( 'option' ).each( function () {
			var $option = $( this );
			if ( '' === $option.val() ) {
				return;
			}
			var text = $option.text().toLowerCase();
			$option.toggle( -1 !== text.indexOf( term ) );
		} );
	} );
} )( jQuery );
