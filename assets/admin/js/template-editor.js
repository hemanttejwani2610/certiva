/* global certivaTemplateEditor, jQuery, wp */
( function ( $ ) {
	'use strict';

	if ( typeof certivaTemplateEditor === 'undefined' ) {
		return;
	}

	var PAGE_MM = {
		A4: [ 210, 297 ],
		Letter: [ 215.9, 279.4 ],
		Legal: [ 215.9, 355.6 ],
	};

	var STAGE_MAX_WIDTH = 720;

	var state = {
		fields: [],
		selectedId: 0,
		nextId: 1,
	};

	var $stage, $fieldsLayer, $hiddenInput, $inspector;

	function pageDims() {
		var size = $( '#certiva_page_size' ).val() || 'A4';
		var orientation = $( '#certiva_orientation' ).val() || 'L';
		var dims = PAGE_MM[ size ] || PAGE_MM.A4;
		var w = dims[ 0 ];
		var h = dims[ 1 ];
		return 'P' === orientation ? [ Math.min( w, h ), Math.max( w, h ) ] : [ Math.max( w, h ), Math.min( w, h ) ];
	}

	function resizeStage() {
		var dims = pageDims();
		var widthMm = dims[ 0 ];
		var heightMm = dims[ 1 ];
		var widthPx = Math.min( STAGE_MAX_WIDTH, 900 );
		var heightPx = widthPx * ( heightMm / widthMm );

		$stage.css( { width: widthPx + 'px', height: heightPx + 'px' } );

		var bgUrl = $( '#certiva-designer' ).data( 'bg-url' );
		if ( bgUrl ) {
			$stage.css( {
				backgroundImage: 'url(' + bgUrl + ')',
				backgroundSize: '100% 100%',
				backgroundRepeat: 'no-repeat',
			} );
		} else {
			$stage.css( 'background-image', 'none' );
		}

		renderAllFields();
	}

	function stagePxPerMm() {
		var widthMm = pageDims()[ 0 ];
		return $stage.width() / widthMm;
	}

	function fieldDisplayText( field ) {
		if ( 'static' === field.key ) {
			return field.text || '(static text)';
		}
		return field.label || field.key;
	}

	function renderField( field ) {
		var pxPerMm = stagePxPerMm();
		var widthMm = pageDims()[ 0 ];
		var heightMm = pageDims()[ 1 ];

		var left = ( field.x / 100 ) * widthMm * pxPerMm;
		var top = ( field.y / 100 ) * heightMm * pxPerMm;
		var width = ( field.width / 100 ) * widthMm * pxPerMm;

		// Approximate on-screen font size (pt -> px at stage scale).
		var fontPx = field.font_size * pxPerMm * 0.3528; // 1pt = 0.3528mm

		var $el = $fieldsLayer.find( '[data-field-id="' + field._id + '"]' );
		if ( ! $el.length ) {
			$el = $( '<div class="certiva-field-box" tabindex="0"><span class="certiva-field-label"></span><button type="button" class="certiva-field-remove" title="Remove field" aria-label="Remove field">&times;</button></div>' )
				.attr( 'data-field-id', field._id );
			$fieldsLayer.append( $el );
		}

		$el.find( '.certiva-field-label' ).text( fieldDisplayText( field ) );

		$el
			.css( {
				left: left + 'px',
				top: top + 'px',
				width: width + 'px',
				fontSize: fontPx + 'px',
				color: field.color,
				textAlign: field.align,
				fontWeight: field.bold ? 'bold' : 'normal',
				fontStyle: field.italic ? 'italic' : 'normal',
			} )
			.toggleClass( 'is-selected', field._id === state.selectedId );
	}

	function renderAllFields() {
		$fieldsLayer.empty();
		state.fields.forEach( renderField );
	}

	function serialize() {
		var out = state.fields.map( function ( f ) {
			return {
				key: f.key,
				label: f.label,
				text: f.text || '',
				x: f.x,
				y: f.y,
				width: f.width,
				font_size: f.font_size,
				font_family: f.font_family,
				color: f.color,
				align: f.align,
				bold: !! f.bold,
				italic: !! f.italic,
			};
		} );
		$hiddenInput.val( JSON.stringify( out ) );
	}

	function loadFields() {
		var raw = [];
		try {
			raw = JSON.parse( $hiddenInput.val() || '[]' );
		} catch ( e ) {
			raw = [];
		}
		state.fields = raw.map( function ( f ) {
			f._id = state.nextId++;
			return f;
		} );
	}

	function selectField( id ) {
		state.selectedId = id;
		renderAllFields();
		renderInspector();
	}

	function findField( id ) {
		var found = null;
		state.fields.forEach( function ( f ) {
			if ( f._id === id ) {
				found = f;
			}
		} );
		return found;
	}

	function renderInspector() {
		var field = findField( state.selectedId );
		if ( ! field ) {
			$inspector.hide().empty();
			return;
		}

		var fontOptions = [ 'NotoSans', 'LohitDevanagari' ]
			.map( function ( f ) {
				return '<option value="' + f + '"' + ( f === field.font_family ? ' selected' : '' ) + '>' + f + '</option>';
			} )
			.join( '' );

		var html = '<h4>' + fieldDisplayText( field ) + '</h4>';

		if ( 'static' === field.key ) {
			html += '<p><label>' + 'Text' + '<br/><input type="text" class="certiva-insp-text" value="' + escapeAttr( field.text || '' ) + '" style="width:100%;" /></label></p>';
		}

		html += '' +
			'<p><label>Font size<br/><input type="number" min="6" max="200" class="certiva-insp-font-size" value="' + field.font_size + '" /></label></p>' +
			'<p><label>Font family<br/><select class="certiva-insp-font-family">' + fontOptions + '</select></label></p>' +
			'<p><label>Color<br/><input type="color" class="certiva-insp-color" value="' + field.color + '" /></label></p>' +
			'<p><label>Align<br/><select class="certiva-insp-align">' +
			[ 'left', 'center', 'right' ].map( function ( a ) {
				return '<option value="' + a + '"' + ( a === field.align ? ' selected' : '' ) + '>' + a + '</option>';
			} ).join( '' ) +
			'</select></label></p>' +
			'<p><label><input type="checkbox" class="certiva-insp-bold"' + ( field.bold ? ' checked' : '' ) + ' /> Bold</label> ' +
			'<label><input type="checkbox" class="certiva-insp-italic"' + ( field.italic ? ' checked' : '' ) + ' /> Italic</label></p>' +
			'<p><label>Box width (%)<br/><input type="number" min="1" max="100" class="certiva-insp-width" value="' + field.width + '" /></label></p>' +
			'<p><button type="button" class="button certiva-insp-remove">' + certivaTemplateEditor.i18n.removeField + '</button></p>';

		$inspector.html( html ).show();
	}

	function escapeAttr( s ) {
		return String( s ).replace( /"/g, '&quot;' );
	}

	function addField( presetKey ) {
		if ( ! presetKey ) {
			return;
		}

		var field;

		if ( presetKey === 'extra:' ) {
			var label = window.prompt( 'Placeholder label (must match a student\'s extra field label):' );
			if ( ! label ) {
				return;
			}
			field = { key: 'extra:' + label, label: label, text: '', x: 50, y: 50, width: 50, font_size: 12, font_family: 'NotoSans', color: '#000000', align: 'center', bold: false, italic: false };
		} else if ( presetKey === 'static' ) {
			var text = window.prompt( 'Static text to display:' );
			if ( ! text ) {
				return;
			}
			field = { key: 'static', label: certivaTemplateEditor.i18n.customLabel, text: text, x: 50, y: 50, width: 50, font_size: 12, font_family: 'NotoSans', color: '#000000', align: 'center', bold: false, italic: false };
		} else {
			var exists = state.fields.some( function ( f ) {
				return f.key === presetKey;
			} );
			if ( exists ) {
				window.alert( 'That field is already on the canvas.' );
				return;
			}
			var preset = certivaTemplateEditor.fieldPresets.filter( function ( p ) {
				return p.key === presetKey;
			} )[ 0 ];
			field = { key: presetKey, label: preset ? preset.label : presetKey, text: '', x: 50, y: 50, width: 50, font_size: 14, font_family: 'NotoSans', color: '#000000', align: 'center', bold: false, italic: false };
		}

		field._id = state.nextId++;
		state.fields.push( field );
		serialize();
		selectField( field._id );
	}

	function initAddFieldSelect() {
		var $select = $( '#certiva-add-field-select' );
		certivaTemplateEditor.fieldPresets.forEach( function ( p ) {
			$select.append( $( '<option></option>' ).attr( 'value', p.key ).text( p.label ) );
		} );
	}

	function initDragging() {
		var dragging = null;

		$fieldsLayer.on( 'mousedown', '.certiva-field-box', function ( e ) {
			if ( $( e.target ).hasClass( 'certiva-field-remove' ) ) {
				return; // Let the click handler below remove it instead of starting a drag.
			}

			var id = parseInt( $( this ).attr( 'data-field-id' ), 10 );
			selectField( id );

			var field = findField( id );
			if ( ! field ) {
				return;
			}

			dragging = {
				id: id,
				startX: e.pageX,
				startY: e.pageY,
				origX: field.x,
				origY: field.y,
			};
			e.preventDefault();
		} );

		$( document ).on( 'mousemove', function ( e ) {
			if ( ! dragging ) {
				return;
			}
			var field = findField( dragging.id );
			if ( ! field ) {
				return;
			}

			var pxPerMm = stagePxPerMm();
			var widthMm = pageDims()[ 0 ];
			var heightMm = pageDims()[ 1 ];

			var dxMm = ( e.pageX - dragging.startX ) / pxPerMm;
			var dyMm = ( e.pageY - dragging.startY ) / pxPerMm;

			field.x = clamp( dragging.origX + ( dxMm / widthMm ) * 100, 0, 100 );
			field.y = clamp( dragging.origY + ( dyMm / heightMm ) * 100, 0, 100 );

			renderField( field );
		} );

		$( document ).on( 'mouseup', function () {
			if ( dragging ) {
				dragging = null;
				serialize();
			}
		} );

		$fieldsLayer.on( 'click', '.certiva-field-remove', function ( e ) {
			e.preventDefault();
			e.stopPropagation();

			var id = parseInt( $( this ).closest( '.certiva-field-box' ).attr( 'data-field-id' ), 10 );
			state.fields = state.fields.filter( function ( f ) {
				return f._id !== id;
			} );
			if ( state.selectedId === id ) {
				state.selectedId = 0;
			}

			serialize();
			renderAllFields();
			renderInspector();
		} );
	}

	function clamp( v, min, max ) {
		return Math.max( min, Math.min( max, v ) );
	}

	function initInspectorEvents() {
		$inspector.on( 'input change', '.certiva-insp-text', function () {
			updateSelected( { text: $( this ).val() } );
		} );
		$inspector.on( 'input change', '.certiva-insp-font-size', function () {
			updateSelected( { font_size: parseInt( $( this ).val(), 10 ) || 12 } );
		} );
		$inspector.on( 'change', '.certiva-insp-font-family', function () {
			updateSelected( { font_family: $( this ).val() } );
		} );
		$inspector.on( 'input change', '.certiva-insp-color', function () {
			updateSelected( { color: $( this ).val() } );
		} );
		$inspector.on( 'change', '.certiva-insp-align', function () {
			updateSelected( { align: $( this ).val() } );
		} );
		$inspector.on( 'change', '.certiva-insp-bold', function () {
			updateSelected( { bold: $( this ).is( ':checked' ) } );
		} );
		$inspector.on( 'change', '.certiva-insp-italic', function () {
			updateSelected( { italic: $( this ).is( ':checked' ) } );
		} );
		$inspector.on( 'input change', '.certiva-insp-width', function () {
			updateSelected( { width: parseFloat( $( this ).val() ) || 50 } );
		} );
		$inspector.on( 'click', '.certiva-insp-remove', function () {
			state.fields = state.fields.filter( function ( f ) {
				return f._id !== state.selectedId;
			} );
			state.selectedId = 0;
			serialize();
			renderAllFields();
			renderInspector();
		} );
	}

	function updateSelected( props ) {
		var field = findField( state.selectedId );
		if ( ! field ) {
			return;
		}
		$.extend( field, props );
		serialize();
		renderField( field );
	}

	function initBackgroundPicker() {
		var frame;

		$( '#certiva-select-bg' ).on( 'click', function ( e ) {
			e.preventDefault();
			if ( frame ) {
				frame.open();
				return;
			}
			frame = wp.media( {
				title: certivaTemplateEditor.i18n.selectImage,
				button: { text: certivaTemplateEditor.i18n.useImage },
				multiple: false,
				library: { type: 'image' },
			} );
			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				$( '#certiva_bg_attachment_id' ).val( attachment.id );
				$( '#certiva-bg-preview' ).html( '<img src="' + attachment.url + '" style="max-width:300px;height:auto;" />' );
				$( '#certiva-remove-bg' ).show();
				$( '#certiva-designer' ).data( 'bg-url', attachment.url );
				resizeStage();
			} );
			frame.open();
		} );

		$( '#certiva-remove-bg' ).on( 'click', function ( e ) {
			e.preventDefault();
			$( '#certiva_bg_attachment_id' ).val( '0' );
			$( '#certiva-bg-preview' ).empty();
			$( this ).hide();
			$( '#certiva-designer' ).data( 'bg-url', '' );
			resizeStage();
		} );
	}

	function initPreview() {
		$( '#certiva-preview-btn' ).on( 'click', function () {
			var $btn = $( this );
			$btn.prop( 'disabled', true );

			$.post( certivaTemplateEditor.ajaxUrl, {
				action: 'certiva_preview_template',
				nonce: certivaTemplateEditor.previewNonce,
				page_size: $( '#certiva_page_size' ).val(),
				orientation: $( '#certiva_orientation' ).val(),
				bg_attachment_id: $( '#certiva_bg_attachment_id' ).val(),
				fields_json: $hiddenInput.val(),
			} )
				.done( function ( response ) {
					if ( ! response.success ) {
						window.alert( ( response.data && response.data.message ) || certivaTemplateEditor.i18n.previewError );
						return;
					}
					var byteChars = atob( response.data.pdf_base64 );
					var byteNumbers = new Array( byteChars.length );
					for ( var i = 0; i < byteChars.length; i++ ) {
						byteNumbers[ i ] = byteChars.charCodeAt( i );
					}
					var blob = new Blob( [ new Uint8Array( byteNumbers ) ], { type: 'application/pdf' } );
					window.open( URL.createObjectURL( blob ), '_blank' );
				} )
				.fail( function () {
					window.alert( certivaTemplateEditor.i18n.previewError );
				} )
				.always( function () {
					$btn.prop( 'disabled', false );
				} );
		} );
	}

	$( function () {
		var $designer = $( '#certiva-designer' );
		if ( ! $designer.length ) {
			return;
		}

		$stage = $( '#certiva-designer-stage' );
		$fieldsLayer = $( '#certiva-designer-fields' );
		$hiddenInput = $( '#certiva_fields_json' );
		$inspector = $( '#certiva-field-inspector' );

		loadFields();
		initAddFieldSelect();
		resizeStage();
		initDragging();
		initInspectorEvents();
		initBackgroundPicker();
		initPreview();

		$( '#certiva_page_size, #certiva_orientation' ).on( 'change', resizeStage );

		$( '#certiva-add-field-btn' ).on( 'click', function () {
			addField( $( '#certiva-add-field-select' ).val() );
		} );

		$( document.getElementById( 'post' ) ).on( 'submit', serialize );
	} );
} )( jQuery );
