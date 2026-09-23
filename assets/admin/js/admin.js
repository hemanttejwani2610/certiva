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

	// Student type-ahead search on the Registrations page. Queries a small,
	// indexed result set from the server instead of preloading every
	// student — sites that bulk-import students via CSV can easily have
	// thousands, far too many for a single <select>.
	( function () {
		var $search = $( '#certiva-student-search' );
		if ( ! $search.length ) {
			return;
		}

		var $hidden = $( '#student_id' );
		var $list = $( '#certiva-student-results' );
		var $collegeFilter = $( '#certiva-college-filter-select' );
		var debounceTimer = null;
		var activeIndex = -1;

		function runSearch( term ) {
			$.post( certivaAdmin.ajaxUrl, {
				action: 'certiva_search_students',
				nonce: certivaAdmin.nonce,
				term: term,
				college_id: $collegeFilter.length ? $collegeFilter.val() : 0,
			} ).done( function ( response ) {
				if ( response.success ) {
					renderResults( response.data.results );
				}
			} );
		}

		function closeList() {
			$list.attr( 'hidden', true ).empty();
			$search.attr( 'aria-expanded', 'false' );
			activeIndex = -1;
		}

		function renderResults( results ) {
			$list.empty();

			if ( ! results.length ) {
				$list.append( $( '<li></li>' ).addClass( 'certiva-no-results' ).text( certivaAdmin.i18n.noStudents || 'No matches.' ) );
			} else {
				results.forEach( function ( item ) {
					$( '<li></li>' )
						.attr( { role: 'option', tabindex: '-1', 'data-id': item.id, 'data-label': item.label } )
						.text( item.label )
						.appendTo( $list );
				} );
			}

			$list.removeAttr( 'hidden' );
			$search.attr( 'aria-expanded', 'true' );
			activeIndex = -1;
		}

		function selectItem( $item ) {
			if ( ! $item || ! $item.length ) {
				return;
			}
			$hidden.val( $item.data( 'id' ) );
			$search.val( $item.data( 'label' ) );
			closeList();
		}

		$search.on( 'input', function () {
			var term = $search.val();
			$hidden.val( '' ); // Require an explicit pick from the list before this can be submitted.

			window.clearTimeout( debounceTimer );

			var collegeSelected = $collegeFilter.length && '0' !== $collegeFilter.val();
			if ( term.length < 2 && ! collegeSelected ) {
				closeList();
				return;
			}

			debounceTimer = window.setTimeout( function () {
				runSearch( term );
			}, 300 );
		} );

		// Selecting a college re-runs the search immediately (even with no
		// typed term yet, so picking a college alone browses its roster),
		// and clears any previously picked student since it may not be in
		// the newly chosen college.
		$collegeFilter.on( 'change', function () {
			$hidden.val( '' );
			$search.val( '' );

			if ( '0' === $collegeFilter.val() ) {
				closeList();
				return;
			}

			runSearch( '' );
		} );

		$search.on( 'keydown', function ( e ) {
			var $items = $list.find( '[role="option"]' );
			if ( ! $items.length ) {
				return;
			}

			if ( 'ArrowDown' === e.key ) {
				e.preventDefault();
				activeIndex = Math.min( activeIndex + 1, $items.length - 1 );
				$items.removeClass( 'is-active' ).eq( activeIndex ).addClass( 'is-active' );
			} else if ( 'ArrowUp' === e.key ) {
				e.preventDefault();
				activeIndex = Math.max( activeIndex - 1, 0 );
				$items.removeClass( 'is-active' ).eq( activeIndex ).addClass( 'is-active' );
			} else if ( 'Enter' === e.key ) {
				if ( activeIndex > -1 ) {
					e.preventDefault();
					selectItem( $items.eq( activeIndex ) );
				}
			} else if ( 'Escape' === e.key ) {
				closeList();
			}
		} );

		$list.on( 'click', '[role="option"]', function () {
			selectItem( $( this ) );
		} );

		$( document ).on( 'click', function ( e ) {
			if ( ! $( e.target ).closest( '.certiva-student-picker' ).length ) {
				closeList();
			}
		} );
	} )();
} )( jQuery );
